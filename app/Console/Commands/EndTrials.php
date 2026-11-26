<?php

namespace App\Console\Commands;

use App\Licensing\LicenseService;
use App\Mail\TrialNoticeMail;
use App\Models\License;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Free trials follow the same steps as an expired annual license:
 *   3 days before the end   "ending" email
 *   end date                LabelDesk turns read-only (token "expires"); "ended" email
 *   end + 10 days           LabelDesk locks; the trial license is suspended ("Free trial ended"); "suspended" email
 * Each email is recorded as a 'trial_notice' event so it is sent once.
 */
class EndTrials extends Command
{
    protected $signature = 'licenses:end-trials {--dry-run : List what would happen}';

    protected $description = 'Remind, then suspend free trial licenses whose trial (and read-only period) is over';

    public function handle(LicenseService $licenses): int
    {
        $done = 0;
        License::query()->core()
            ->with(['organisation.users', 'plan', 'events' => fn ($q) => $q->where('event', 'trial_notice')])
            ->where('billing_type', License::TRIAL)
            ->where('status', License::ACTIVE)
            ->where('expires_at', '<=', now()->addDays(3))
            ->each(function (License $license) use ($licenses, &$done) {
                $stage = match (true) {
                    $license->expires_at->copy()->addDays(License::READ_ONLY_DAYS)->isPast() => 'suspended',
                    $license->expires_at->isPast() => 'ended',
                    default => 'ending',
                };
                $sent = $license->events->contains(fn ($e) => ($e->details['stage'] ?? null) === $stage);
                if ($stage !== 'suspended' && $sent) {
                    return;
                }
                $this->line(sprintf('  %s  %s  %s', $license->number, $license->organisation->name, $stage));
                if ($this->option('dry-run')) {
                    return;
                }

                if ($stage === 'suspended') {
                    $license = $licenses->endTrial($license, 'system')->load('organisation.users', 'plan');
                }
                try {
                    Mail::to($license->organisation->email)
                        ->cc($license->organisation->users->whereIn('role', ['owner', 'billing'])->pluck('email')->reject(fn ($e) => $e === $license->organisation->email)->values()->all())
                        ->send(new TrialNoticeMail($license, $stage));
                    $licenses->event($license, 'trial_notice', ['stage' => $stage], 'system');
                } catch (Throwable $e) {
                    $this->error("    email failed: {$e->getMessage()}");
                }
                $done++;
            });

        $this->info("{$done} trial(s) handled.");

        return self::SUCCESS;
    }
}
