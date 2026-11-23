<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LabelDesk 4.3.2: deactivating an install needs a one-time code emailed to the license owner.
 * The code is stored hashed on the activation (one live code per activation). last_activated_at
 * records every successful /activate, so a domain transfer or key change made by the same
 * installation can release its old activation without a code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_activations', function (Blueprint $t) {
            $t->timestamp('last_activated_at')->nullable()->after('activated_at');
            $t->string('deactivation_code_hash')->nullable()->after('check_in_requested');
            $t->timestamp('deactivation_code_expires_at')->nullable()->after('deactivation_code_hash');
            $t->unsignedTinyInteger('deactivation_code_attempts')->default(0)->after('deactivation_code_expires_at');
            $t->index(['instance_id', 'last_activated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('license_activations', function (Blueprint $t) {
            $t->dropIndex(['instance_id', 'last_activated_at']);
            $t->dropColumn(['last_activated_at', 'deactivation_code_hash', 'deactivation_code_expires_at', 'deactivation_code_attempts']);
        });
    }
};
