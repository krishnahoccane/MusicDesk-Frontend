<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LabelDesk 4.3.3 over-the-air updates: minimum PHP / LabelDesk per release, whether the
 * stored package is signed, the last update check per installation, and a download log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $t) {
            $t->string('requires_php', 20)->nullable()->after('requirements');
            $t->string('requires_labeldesk', 20)->nullable()->after('requires_php');   // add-ons: plugin.json "requires"
            $t->boolean('signed')->default(false)->after('sha256');                     // package passes LabelDesk's signature check
        });

        Schema::table('license_activations', function (Blueprint $t) {
            $t->timestamp('update_checked_at')->nullable();
            $t->string('update_check_version', 20)->nullable();
        });

        Schema::create('release_downloads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('release_id')->constrained()->cascadeOnDelete();
            $t->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('license_activation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('domain', 253)->nullable();
            $t->string('channel', 10);   // ota | portal
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['release_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_downloads');
        Schema::table('license_activations', function (Blueprint $t) {
            $t->dropColumn(['update_checked_at', 'update_check_version']);
        });
        Schema::table('releases', function (Blueprint $t) {
            $t->dropColumn(['requires_php', 'requires_labeldesk', 'signed']);
        });
    }
};
