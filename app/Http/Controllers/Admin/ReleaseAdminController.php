<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Licensing\CorePackager;
use App\Licensing\PluginPackager;
use App\Models\LicenseAddon;
use App\Models\Release;
use Illuminate\Http\File;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Releases of LabelDesk or of a licensed add-on: version, changelog, package file (kept private;
 * customers download through the portal, LabelDesk 4.3.3+ over the air). Packages are signed with the
 * plugin signing key: add-ons as LabelDesk plugin ZIPs (scripts/build-plugin.php), LabelDesk itself as
 * labeldesk-<version>/ with signature.json (scripts/package-release.php --key=…). Unsigned uploads are
 * signed here when the key is installed.
 */
class ReleaseAdminController extends Controller
{
    public function __construct(private PluginPackager $packager, private CorePackager $corePackager) {}

    public function index(Request $request): View
    {
        $product = $request->query('product');

        return view('admin.releases', [
            'releases' => Release::with('addon')->withCount(['downloads as ota_downloads_count' => fn ($q) => $q->where('channel', 'ota')])
                ->when($product === 'core', fn ($q) => $q->whereNull('addon_id'))
                ->when(is_numeric($product), fn ($q) => $q->where('addon_id', (int) $product))
                ->get()->pipe(fn ($c) => Release::newestFirst($c)),
            'addons' => LicenseAddon::orderBy('sort')->orderBy('name')->get(),
            'product' => $product,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $package = $this->preparePackage($request, $data, null);
        $data = $this->withPackageRequirements($data, $package);
        $release = Release::create($data);
        $this->storeFile($request, $release, $package);

        return back()->with('toast', "Version {$release->version} saved".($package['signed_here'] ?? false ? ' (package signed)' : ''));
    }

    public function update(Request $request, Release $release): RedirectResponse
    {
        $data = $this->validated($request, $release);
        $package = $this->preparePackage($request, $data, $release);
        $data = $this->withPackageRequirements($data, $package);
        $release->update($data);
        $this->storeFile($request, $release, $package);

        return back()->with('toast', "Version {$release->version} saved".($package['signed_here'] ?? false ? ' (package signed)' : ''));
    }

    public function destroy(Release $release): RedirectResponse
    {
        if ($release->file_path) {
            Storage::disk('local')->delete($release->file_path);
        }
        $release->delete();

        return back()->with('toast', "Version {$release->version} deleted");
    }

    private function validated(Request $request, ?Release $release): array
    {
        $addonId = $request->filled('addon_id') ? (int) $request->input('addon_id') : null;
        $data = $request->validate([
            'addon_id' => ['nullable', 'integer', 'exists:license_addons,id'],
            // Versions are unique per product (LabelDesk, or each add-on).
            'version' => ['required', 'regex:/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', 'max:20',
                Rule::unique('releases', 'version')->ignore($release?->id)->where(fn ($q) => $addonId ? $q->where('addon_id', $addonId) : $q->whereNull('addon_id'))],
            'channel' => ['required', Rule::in(['stable', 'beta'])],
            'released_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'req_php' => ['nullable', 'string', 'max:120'],
            'req_database' => ['nullable', 'string', 'max:120'],
            'req_extras' => ['nullable', 'string', 'max:120'],
            'requires_php' => ['nullable', 'regex:/^\d+\.\d+(\.\d+)?$/', 'max:20'],
            'requires_labeldesk' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/', 'max:20'],
            'package' => ['nullable', 'file', 'mimes:zip', 'max:512000'],
        ], ['version.regex' => 'Use a version like 4.3.0 (optionally 4.3.0-beta.1).', 'requires_php.regex' => 'Use a PHP version like 8.2.', 'requires_labeldesk.regex' => 'Use a version like 4.3.3.']);

        return [
            'addon_id' => $addonId,
            'version' => $data['version'],
            'channel' => $data['channel'],
            'released_at' => Carbon::parse($data['released_at'])->startOfDay(),
            'notes' => self::parseNotes($data['notes'] ?? ''),
            'requirements' => array_filter(['php' => $data['req_php'] ?? null, 'database' => $data['req_database'] ?? null, 'extras' => $data['req_extras'] ?? null]),
            'requires_php' => $data['requires_php'] ?? null,
            'requires_labeldesk' => $addonId ? ($data['requires_labeldesk'] ?? null) : null,
            'published' => $request->boolean('published'),
        ];
    }

    /** One note per line: "new: …", "improved: …", "fixed: …", "security: …" (no prefix = new). */
    public static function parseNotes(string $text): array
    {
        $notes = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim(ltrim(trim($line), '-•* '));
            if ($line === '') {
                continue;
            }
            $kind = 'new';
            if (preg_match('/^(new|improved|fixed|security)\s*:\s*(.+)$/i', $line, $m)) {
                [$kind, $line] = [strtolower($m[1]), trim($m[2])];
            }
            $notes[] = ['kind' => $kind, 'text' => $line];
        }

        return $notes;
    }

    public static function notesText(?array $notes): string
    {
        return collect($notes ?? [])->map(fn ($n) => ($n['kind'] ?? 'new').': '.($n['text'] ?? ''))->implode("\n");
    }

    /**
     * Check the uploaded ZIP and sign it if it isn't signed yet. Add-ons: plugin.json must name the
     * add-on and version. LabelDesk: the top folder must be labeldesk-<version> (or no top folder),
     * with no .env, logs, backups or links.
     *
     * @return array{path: string, manifest?: array<string, mixed>, signed_here: bool}|null
     */
    private function preparePackage(Request $request, array $data, ?Release $release): ?array
    {
        $file = $request->file('package');
        if (! $file) {
            if ($release?->file_path && ($release->version !== $data['version'] || (int) $release->addon_id !== (int) $data['addon_id'])) {
                throw ValidationException::withMessages(['package' => 'Upload the package for this version: a signed package must match the product and version.']);
            }

            return null;
        }

        try {
            return $data['addon_id']
                ? $this->packager->prepare($file->getRealPath(), (string) LicenseAddon::findOrFail($data['addon_id'])->code, $data['version'])
                : $this->corePackager->prepare($file->getRealPath(), $data['version']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['package' => $e->getMessage()]);
        }
    }

    /** Add-ons: minimum LabelDesk from plugin.json "requires" when the form leaves it empty. */
    private function withPackageRequirements(array $data, ?array $package): array
    {
        $requires = $package['manifest']['requires'] ?? null;
        if ($data['addon_id'] && ! $data['requires_labeldesk'] && is_string($requires) && preg_match('/\d+\.\d+\.\d+/', $requires, $m)) {
            $data['requires_labeldesk'] = $m[0];
        }

        return $data;
    }

    private function storeFile(Request $request, Release $release, ?array $package = null): void
    {
        if (! $file = $request->file('package')) {
            return;
        }
        if ($release->file_path) {
            Storage::disk('local')->delete($release->file_path);
        }
        $name = $release->load('addon')->fileName();
        if ($package && $package['signed_here']) {
            $path = Storage::disk('local')->putFileAs('releases', new File($package['path']), $name);
            @unlink($package['path']);
        } else {
            $path = $file->storeAs('releases', $name, 'local');
        }
        $release->update([
            'file_path' => $path, 'size_bytes' => Storage::disk('local')->size($path), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'signed' => $package !== null,   // verified (or signed) by the packager: installable over the air
        ]);
    }
}
