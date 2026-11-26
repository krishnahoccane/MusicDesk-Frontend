<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free trials (MusicDesk.tech 2.0.1): a plan can offer an N-day trial with limits. A trial is a
 * license with billing_type "trial"; buying the plan turns it into a paid license (same key and
 * domains). One trial per organisation; a domain that ran a trial can't run another one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->boolean('trial_enabled')->default(false)->after('active');
            $t->unsignedSmallInteger('trial_days')->nullable()->after('trial_enabled');
            $t->json('trial_limits')->nullable()->after('trial_days');   // {domains, users, artists, releases, addons, note}
        });

        Schema::table('licenses', function (Blueprint $t) {
            $t->json('trial_limits')->nullable()->after('max_version');       // set while billing_type = trial
            $t->timestamp('trial_started_at')->nullable()->after('trial_limits');   // kept after conversion
            $t->index('trial_started_at');
        });

        Schema::table('organisations', function (Blueprint $t) {
            $t->timestamp('trial_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $t) {
            $t->dropColumn('trial_used_at');
        });
        Schema::table('licenses', function (Blueprint $t) {
            $t->dropIndex(['trial_started_at']);
            $t->dropColumn(['trial_limits', 'trial_started_at']);
        });
        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn(['trial_enabled', 'trial_days', 'trial_limits']);
        });
    }
};
