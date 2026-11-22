<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * License security alerts (failed license checks reported by LabelDesk installs
 * or refused by the license server, one row per domain) and support tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('domain', 253)->unique();
            $t->string('status', 12)->default('open');        // open | blocked | issued | dismissed
            $t->foreignId('license_id')->nullable()->constrained()->nullOnDelete(); // matched license (key/token), or the one issued
            $t->string('last_reason', 40);
            $t->json('reasons')->nullable();                  // {reason: count}
            $t->unsignedInteger('attempts')->default(0);
            $t->string('source', 10)->default('install');     // install (LabelDesk report) | server (refused by this server)
            $t->string('ip', 45)->nullable();
            $t->string('version', 20)->nullable();
            $t->string('php_version', 20)->nullable();
            $t->string('instance_id', 100)->nullable();
            $t->string('message', 500)->nullable();
            $t->json('details')->nullable();
            $t->timestamp('first_seen_at');
            $t->timestamp('last_seen_at');
            $t->timestamp('notified_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->string('resolved_by', 120)->nullable();
            $t->string('note', 500)->nullable();
            $t->timestamps();
            $t->index(['status', 'last_seen_at']);
        });

        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->string('number', 12)->unique();               // TCK-4821
            $t->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();     // who opened it
            $t->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('domain', 253)->nullable();
            $t->string('category', 30);
            $t->string('priority', 10)->default('normal');    // low | normal | high
            $t->string('subject', 200);
            $t->string('status', 12)->default('open');        // open (needs support) | awaiting (needs customer) | resolved
            $t->timestamp('last_reply_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['organisation_id', 'status']);
            $t->index(['status', 'last_reply_at']);
        });

        Schema::create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('author_name', 120);
            $t->boolean('from_staff')->default(false);
            $t->boolean('internal')->default(false);          // staff-only note, never shown to the customer
            $t->text('body');
            $t->json('attachments')->nullable();              // [{id, name, path, size}]
            $t->timestamp('created_at')->useCurrent();
            $t->index(['support_ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('license_alerts');
    }
};
