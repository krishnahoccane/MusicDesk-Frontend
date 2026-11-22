<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licensed add-ons: optional LabelDesk modules sold annual or lifetime, or included free
 * with some plans. Every add-on a customer has is its own license (own key, own domains)
 * attached to a main license. Releases can belong to an add-on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_addons', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 80);
            $t->string('description', 300)->nullable();
            $t->string('icon', 30)->default('layers');
            $t->decimal('price_annual', 12, 2);              // USD per year
            $t->decimal('price_lifetime', 12, 2)->nullable(); // USD one-time; null = not sold as lifetime
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });

        // Plans that include the add-on free.
        Schema::create('license_addon_plan', function (Blueprint $t) {
            $t->foreignId('license_addon_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $t->primary(['license_addon_id', 'plan_id']);
        });

        Schema::table('licenses', function (Blueprint $t) {
            $t->foreignId('addon_id')->nullable()->after('plan_id')->constrained('license_addons')->restrictOnDelete();
            $t->foreignId('parent_id')->nullable()->after('addon_id')->constrained('licenses')->cascadeOnDelete(); // the main license
            $t->boolean('included')->default(false)->after('parent_id');   // free with the parent's plan
            $t->index(['parent_id', 'addon_id']);
        });

        Schema::table('releases', function (Blueprint $t) {
            $t->dropUnique(['version']);
        });
        Schema::table('releases', function (Blueprint $t) {
            $t->foreignId('addon_id')->nullable()->after('id')->constrained('license_addons')->restrictOnDelete(); // null = LabelDesk itself
            $t->index(['addon_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $t) {
            $t->dropIndex(['addon_id', 'version']);
            $t->dropConstrainedForeignId('addon_id');
        });
        Schema::table('releases', function (Blueprint $t) {
            $t->unique('version');
        });
        Schema::table('licenses', function (Blueprint $t) {
            $t->dropIndex(['parent_id', 'addon_id']);
            $t->dropConstrainedForeignId('parent_id');
            $t->dropConstrainedForeignId('addon_id');
            $t->dropColumn('included');
        });
        Schema::dropIfExists('license_addon_plan');
        Schema::dropIfExists('license_addons');
    }
};
