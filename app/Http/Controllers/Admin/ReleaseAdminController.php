<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Release;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** LabelDesk releases: version, changelog, package file (kept private; customers download through the portal). */
class ReleaseAdminController extends Controller
{
    public function index(): View
    {
        return view('admin.releases', ['releases' => Release::orderByDesc('released_at')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $release = Release::create($data);
        $this->storeFile($request, $release);

        return back()->with('toast', "Version {$release->version} saved");
    }

    public function update(Request $request, Release $release): RedirectResponse
    {
        $release->update($this->validated($request, $release));
        $this->storeFile($request, $release);

        return back()->with('toast', "Version {$release->version} saved");
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
        $data = $request->validate([
            'version' => ['required', 'regex:/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', 'max:20', Rule::unique('releases', 'version')->ignore($release?->id)],
            'channel' => ['required', Rule::in(['stable', 'beta'])],
            'released_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'req_php' => ['nullable', 'string', 'max:120'],
            'req_database' => ['nullable', 'string', 'max:120'],
            'req_extras' => ['nullable', 'string', 'max:120'],
            'package' => ['nullable', 'file', 'mimes:zip', 'max:512000'],
        ], ['version.regex' => 'Use a version like 4.3.0 (optionally 4.3.0-beta.1).']);

        return [
            'version' => $data['version'],
            'channel' => $data['channel'],
            'released_at' => Carbon::parse($data['released_at'])->startOfDay(),
            'notes' => self::parseNotes($data['notes'] ?? ''),
            'requirements' => array_filter(['php' => $data['req_php'] ?? null, 'database' => $data['req_database'] ?? null, 'extras' => $data['req_extras'] ?? null]),
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

    private function storeFile(Request $request, Release $release): void
    {
        if (! $file = $request->file('package')) {
            return;
        }
        if ($release->file_path) {
            Storage::disk('local')->delete($release->file_path);
        }
        $path = $file->storeAs('releases', 'labeldesk-'.$release->version.'.zip', 'local');
        $release->update(['file_path' => $path, 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path))]);
    }
}
