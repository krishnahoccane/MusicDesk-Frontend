<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app software licensing (replaces the billing.twsd.me connection):
 * licenses + keys, domain activations, license history, releases.
 * Orders now point at the license they created or changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();                       // public id in URLs
            $t->string('number', 20)->unique();               // LIC-7QX8KD
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('plan_id')->constrained()->restrictOnDelete();
            $t->string('billing_type', 10);                   // annual | lifetime
            $t->string('status', 12)->default('active');      // pending | active | suspended | revoked
            $t->string('status_reason', 500)->nullable();
            $t->string('key_hash', 64)->unique();             // sha256 of the normalised key (license-server look-up)
            $t->text('key_encrypted');                        // APP_KEY-encrypted, revealed on request (audited)
            $t->string('key_last4', 4);
            $t->unsignedInteger('domains_included');
            $t->unsignedInteger('domains_extra')->default(0);
            $t->timestamp('purchased_at');
            $t->timestamp('expires_at')->nullable();          // annual only
            $t->timestamp('support_until')->nullable();       // updates & support entitlement
            $t->timestamp('pending_until')->nullable();       // bank-transfer grace period
            $t->string('max_version', 20)->nullable();        // newest version allowed when updates lapsed (null = derive)
            $t->boolean('auto_renew')->default(true);
            $t->decimal('price_paid', 14, 2)->default(0);
            $t->char('currency', 3)->default('USD');
            $t->text('notes')->nullable();                    // admin-only
            $t->timestamps();
            $t->index(['organisation_id', 'status']);
            $t->index('expires_at');
        });

        Schema::create('license_activations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('license_id')->constrained()->cascadeOnDelete();
            $t->string('domain', 253);
            $t->string('environment', 12)->default('production'); // production | staging
            $t->string('status', 12)->default('pending');          // pending (pre-approved) | active | deactivated
            $t->string('instance_id', 100)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('version', 20)->nullable();
            $t->string('php_version', 20)->nullable();
            $t->timestamp('last_check_in_at')->nullable();
            $t->boolean('check_in_requested')->default(false);
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('deactivated_at')->nullable();
            $t->timestamps();
            $t->index(['domain', 'status']);
            $t->index(['license_id', 'status']);
        });

        Schema::create('license_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('license_id')->constrained()->cascadeOnDelete();
            $t->string('event', 40);
            $t->json('details')->nullable();
            $t->string('actor', 120)->nullable();             // "Ada Admin (admin)", "customer: x@y", "license server", "system"
            $t->timestamp('created_at')->useCurrent();
            $t->index(['license_id', 'id']);
        });

        Schema::create('releases', function (Blueprint $t) {
            $t->id();
            $t->string('version', 20)->unique();
            $t->string('channel', 10)->default('stable');    // stable | beta
            $t->timestamp('released_at');
            $t->json('notes')->nullable();                   // [{kind: new|improved|fixed|security, text}]
            $t->json('requirements')->nullable();
            $t->string('file_path')->nullable();             // storage/app/private/releases/…
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->boolean('published')->default(true);
            $t->timestamps();
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('license_id')->nullable()->after('plan_id')->constrained()->nullOnDelete();
            $t->string('refund_reference', 120)->nullable()->after('paid_at');
            $t->string('refund_reason', 500)->nullable()->after('refund_reference');
            $t->timestamp('refunded_at')->nullable()->after('refund_reason');
        });

        // The billing.twsd.me connection is gone.
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['billing_license_id', 'license_key']);
        });
        Schema::table('organisations', function (Blueprint $t) {
            $t->dropUnique(['billing_org_id']);
        });
        Schema::table('organisations', function (Blueprint $t) {
            $t->dropColumn(['billing_org_id', 'billing_synced_at']);
        });
        Schema::table('organisations', function (Blueprint $t) {
            $t->text('notes')->nullable();   // admin-only notes on the customer
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $t) {
            $t->dropColumn('notes');
        });
        Schema::table('organisations', function (Blueprint $t) {
            $t->string('billing_org_id')->nullable()->unique();
            $t->timestamp('billing_synced_at')->nullable();
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->string('billing_license_id')->nullable();
            $t->text('license_key')->nullable();
            $t->dropConstrainedForeignId('license_id');
            $t->dropColumn(['refund_reference', 'refund_reason', 'refunded_at']);
        });
        Schema::dropIfExists('releases');
        Schema::dropIfExists('license_events');
        Schema::dropIfExists('license_activations');
        Schema::dropIfExists('licenses');
    }
};
