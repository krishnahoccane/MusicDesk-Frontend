<?php

namespace App\Console\Commands;

use App\Licensing\LicenseService;
use App\Mail\LicenseExpiringMail;
use App\Models\License;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails renewal reminders for annual licenses 30, 14 and 3 days before expiry.
 * Only the most urgent reminder due is sent, and each is recorded as an 'expiry_notice'
 * event (per expiry date) so it is never sent twice.
 */
class NotifyExpiringLicenses extends Command
{
    public const THRESHOLDS = [3, 14, 30];

    protected $signature = 'licenses:notify-expiring {--dry-run : List what would be sent}';

    protected $description = 'Email renewal reminders for annual licenses that expire soon';

    public function handle(LicenseService $licenses): int
    {
        $sent = 0;
        License::query()
            ->with(['organisation.users', 'plan', 'events' => fn ($q) => $q->where('event', 'expiry_notice')])
            ->where('billing_type', 'annual')
            ->where('included', false)   // included add-ons renew with their main license
            ->where('status', License::ACTIVE)
            ->where('auto_renew', true)
            ->whereBetween('expires_at', [now(), now()->addDays(max(self::THRESHOLDS))])
            ->each(function (License $license) use ($licenses, &$sent) {
                $days = (int) ceil(now()->diffInHours($license->expires_at) / 24);
                $threshold = collect(self::THRESHOLDS)->first(fn ($t) => $days <= $t);
                $expiry = $license->expires_at->toDateString();
                $already = $license->events->contains(fn ($e) => ($e->details['expires'] ?? null) === $expiry
                    && ($e->details['threshold'] ?? PHP_INT_MAX) <= $threshold);
                if ($threshold === null || $already) {
                    return;
                }

                $to = $license->organisation->email;
                $cc = $license->organisation->users->whereIn('role', ['owner', 'billing'])->pluck('email')->reject(fn ($e) => $e === $to)->values()->all();
                $this->line(sprintf('  %s  %s  %d days  → %s', $license->number, $license->organisation->name, $days, $to));
                if ($this->option('dry-run')) {
                    return;
                }

                try {
                    Mail::to($to)->cc($cc)->send(new LicenseExpiringMail($license, $days));
                } catch (Throwable $e) {
                    $this->error("    failed: {$e->getMessage()}");

                    return;
                }
                $licenses->event($license, 'expiry_notice', ['days' => $days, 'threshold' => $threshold, 'expires' => $expiry], 'system');
                $sent++;
            });

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
