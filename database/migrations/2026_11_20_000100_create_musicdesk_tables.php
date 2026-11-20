<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('billing_org_id')->nullable()->unique();   // id in billing.twsd.me
            $t->string('email')->nullable();
            $t->string('country', 2)->nullable();
            $t->string('vat_id')->nullable();
            $t->json('address')->nullable();
            $t->string('currency', 3)->default('USD');
            $t->string('timezone')->nullable();
            $t->timestamp('billing_synced_at')->nullable();
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('organisation_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $t->string('role')->default('owner')->after('email');        // owner | admin | technical | billing
            $t->boolean('is_admin')->default(false)->after('role');      // musicdesk.tech site admin
            $t->string('timezone')->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('country', 2)->nullable();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->longText('value')->nullable();
            $t->timestamps();
        });

        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('blurb')->nullable();
            $t->decimal('price_annual', 12, 2);              // USD
            $t->decimal('price_lifetime', 12, 2)->nullable(); // USD, null = contact sales
            $t->unsignedInteger('domains')->default(1);
            $t->decimal('extra_domain_price', 12, 2)->default(0); // USD / year
            $t->json('features')->nullable();
            $t->boolean('featured')->default(false);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('addons', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('description')->nullable();
            $t->string('icon')->default('sparkles');
            $t->decimal('price', 12, 2);                     // USD
            $t->string('interval')->nullable();              // null = one-time, 'month', 'year'
            $t->boolean('price_from')->default(false);
            $t->boolean('checkout')->default(true);          // offer at checkout
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('currencies', function (Blueprint $t) {
            $t->string('code', 3)->primary();
            $t->string('name');
            $t->string('symbol', 8);
            $t->unsignedTinyInteger('decimals')->default(2);
            $t->decimal('rate', 18, 8)->default(1);          // units per 1 USD (auto-updated)
            $t->decimal('manual_rate', 18, 8)->nullable();   // admin override
            $t->boolean('auto_update')->default(true);
            $t->boolean('charm_pricing')->default(true);     // round down to ...9
            $t->boolean('enabled')->default(true);
            $t->timestamp('rate_updated_at')->nullable();
            $t->timestamps();
        });

        Schema::create('payment_gateways', function (Blueprint $t) {
            $t->string('code')->primary();                   // razorpay | stripe | paypal | bank_transfer
            $t->string('name');
            $t->boolean('enabled')->default(false);
            $t->string('mode')->default('test');             // test | live
            $t->text('credentials')->nullable();             // encrypted JSON
            $t->json('currencies')->nullable();              // null = all enabled currencies
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind')->default('license');          // license | renewal | addon
            $t->string('billing_type')->default('annual');   // annual | lifetime
            $t->unsignedInteger('extra_domains')->default(0);
            $t->json('lines');                               // [{description, quantity, unit, amount}]
            $t->string('currency', 3);
            $t->decimal('exchange_rate', 18, 8)->default(1); // snapshot vs USD
            $t->decimal('subtotal', 14, 2);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('tax_rate', 6, 4)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->string('coupon')->nullable();
            $t->string('gateway')->nullable();
            $t->string('gateway_order_id')->nullable()->index();
            $t->string('gateway_payment_id')->nullable();
            $t->string('status')->default('pending');        // pending | awaiting_payment | paid | failed | cancelled | refunded
            $t->string('primary_domain')->nullable();
            $t->string('billing_license_id')->nullable();    // license id returned by billing.twsd.me
            $t->text('license_key')->nullable();             // encrypted
            $t->json('meta')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('currency', 3);
            $t->decimal('exchange_rate', 18, 8)->default(1);
            $t->json('lines');
            $t->decimal('subtotal', 14, 2);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('tax_rate', 6, 4)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->string('status')->default('open');           // open | paid | void | refunded
            $t->json('seller');                              // snapshot of our company details
            $t->json('buyer');                               // snapshot of organisation details
            $t->string('payment_method')->nullable();
            $t->timestamp('issued_at');
            $t->timestamp('due_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });

        Schema::create('pages', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('title');
            $t->longText('body')->nullable();                // Markdown
            $t->boolean('published')->default(true);
            $t->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('source');                            // wcc | razorpay | stripe | paypal
            $t->string('event_id');
            $t->string('type')->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['source', 'event_id']);
        });
    }

    public function down(): void
    {
        foreach (['webhook_events', 'pages', 'invoices', 'orders', 'payment_gateways', 'currencies', 'addons', 'plans', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('organisation_id');
            $t->dropColumn(['role', 'is_admin', 'timezone', 'currency', 'country']);
        });
        Schema::dropIfExists('organisations');
    }
};
