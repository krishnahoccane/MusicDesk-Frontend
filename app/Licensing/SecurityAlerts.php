<?php

namespace App\Licensing;

use App\Mail\SecurityAlertMail;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseAlert;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Failed license checks → one alert per domain + an email to the site admins.
 *
 * Emails go out when a domain is first seen, when a dismissed/issued domain fails
 * again, or once a day while it keeps failing. Blocked domains are counted silently.
 * At most 30 alert emails an hour so a flood of fake reports can't spam the inbox.
 */
class SecurityAlerts
{
    public const RENOTIFY_HOURS = 24;

    public const MAX_EMAILS_PER_HOUR = 30;

    public function __construct(private LicenseService $licenses) {}

    /**
     * @param  array{ip?: ?string, version?: ?string, php_version?: ?string, instance_id?: ?string, message?: ?string, details?: ?array}  $context
     */
    public function record(string $domain, string $reason, array $context = [], string $source = 'install', ?License $license = null): ?LicenseAlert
    {
        $domain = ActivationService::normalise($domain);
        if (! ActivationService::validDomain($domain)) {
            return null;
        }
        $reason = array_key_exists($reason, LicenseAlert::REASONS) ? $reason : 'other';
        $now = now();

        [$alert, $notify] = DB::transaction(function () use ($domain, $reason, $context, $source, $license, $now) {
            $alert = LicenseAlert::query()->where('domain', $domain)->lockForUpdate()->first()
                ?? new LicenseAlert(['domain' => $domain, 'status' => LicenseAlert::OPEN, 'first_seen_at' => $now, 'attempts' => 0]);

            $isNew = ! $alert->exists;
            $reopened = in_array($alert->status, [LicenseAlert::DISMISSED, LicenseAlert::ISSUED], true)
                && ($alert->resolved_at === null || $alert->resolved_at->lt($now->copy()->subMinutes(10)));   // grace for installs still retrying
            if ($reopened) {
                $alert->fill(['status' => LicenseAlert::OPEN, 'resolved_at' => null, 'resolved_by' => null]);
            }

            $reasons = $alert->reasons ?? [];
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

            $alert->fill(array_filter([
                'last_reason' => $reason,
                'reasons' => $reasons,
                'attempts' => $alert->attempts + 1,
                'source' => $source,
                'last_seen_at' => $now,
                'license_id' => $license?->id ?? $alert->license_id,
                'ip' => $context['ip'] ?? null,
                'version' => isset($context['version']) ? Str::limit((string) $context['version'], 20, '') : null,
                'php_version' => isset($context['php_version']) ? Str::limit((string) $context['php_version'], 20, '') : null,
                'instance_id' => isset($context['instance_id']) ? Str::limit((string) $context['instance_id'], 100, '') : null,
                'message' => isset($context['message']) ? Str::limit((string) $context['message'], 490) : null,
                'details' => isset($context['details']) && is_array($context['details']) ? $this->trimDetails($context['details']) : null,
            ], fn ($v) => $v !== null));
            $alert->save();

            $due = $alert->status === LicenseAlert::OPEN
                && ($isNew || $reopened || ! $alert->notified_at || $alert->notified_at->lt($now->copy()->subHours(self::RENOTIFY_HOURS)));

            return [$alert, $due];
        });

        if ($notify) {
            $this->notify($alert, $alert->wasRecentlyCreated);
        }

        return $alert;
    }

    public function block(LicenseAlert $alert, string $actor, ?string $note = null): LicenseAlert
    {
        DB::transaction(function () use ($alert, $actor, $note) {
            $alert->update(['status' => LicenseAlert::BLOCKED, 'resolved_at' => now(), 'resolved_by' => $actor, 'note' => $note ?? $alert->note]);

            // A blocked domain must not keep running on a license either.
            LicenseActivation::query()->with('license')->where('domain', $alert->domain)->where('status', '!=', LicenseActivation::DEACTIVATED)->get()
                ->each(function (LicenseActivation $a) use ($actor) {
                    $a->update(['status' => LicenseActivation::DEACTIVATED, 'deactivated_at' => now()]);
                    $this->licenses->event($a->license, 'domain_blocked', ['domain' => $a->domain], $actor);
                });
        });

        return $alert;
    }

    /** Block a domain that hasn't reported anything yet. */
    public function blockDomain(string $domain, string $actor, ?string $note = null): LicenseAlert
    {
        $domain = ActivationService::normalise($domain);
        $alert = LicenseAlert::query()->firstOrCreate(['domain' => $domain], [
            'status' => LicenseAlert::OPEN, 'last_reason' => 'other', 'reasons' => [], 'attempts' => 0, 'source' => 'server',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'message' => 'Blocked by hand.',
        ]);

        return $this->block($alert, $actor, $note);
    }

    public function resolve(LicenseAlert $alert, string $status, string $actor, ?License $license = null, ?string $note = null): LicenseAlert
    {
        $alert->update(array_filter([
            'status' => $status,
            'resolved_at' => now(),
            'resolved_by' => $actor,
            'license_id' => $license?->id,
            'note' => $note,
        ], fn ($v) => $v !== null));

        return $alert;
    }

    private function notify(LicenseAlert $alert, bool $first): void
    {
        if (! RateLimiter::attempt('security-alert-mail', self::MAX_EMAILS_PER_HOUR, fn () => true, 3600)) {
            return;
        }
        $to = User::staffEmails('security');
        if (! $to) {
            return;
        }

        try {
            Mail::to(array_shift($to))->bcc($to)->send(new SecurityAlertMail($alert->fresh('license.organisation'), $first));
            $alert->forceFill(['notified_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('Security alert email failed', ['domain' => $alert->domain, 'error' => $e->getMessage()]);
        }
    }

    /** Keep reporter-supplied details small: 20 scalar keys, 200 chars each. */
    private function trimDetails(array $details): array
    {
        return collect(Arr::dot($details))->take(20)
            ->mapWithKeys(fn ($v, $k) => [Str::limit((string) $k, 60, '') => is_scalar($v) || $v === null ? Str::limit((string) $v, 200) : null])
            ->all();
    }
}
