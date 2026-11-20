<?php

use App\Services\Currency\RateUpdater;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('currency:update-rates {--provider=}', function (RateUpdater $updater) {
    try {
        $updated = $updater->update($this->option('provider') ?: null);
    } catch (Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    foreach ($updated as $code => $rate) {
        $this->line(sprintf('  USD → %s  %s', $code, rtrim(rtrim(number_format($rate, 6), '0'), '.')));
    }
    $this->info(count($updated).' currencies updated.');

    return 0;
})->purpose('Fetch today’s exchange rates (USD base) for all auto-updating currencies');

// Daily at 00:05 UTC. Run `php artisan schedule:work` locally, or a cron calling `schedule:run` every minute in production.
Schedule::command('currency:update-rates')->dailyAt('00:05')->withoutOverlapping()->onOneServer();
