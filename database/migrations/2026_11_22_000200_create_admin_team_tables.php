<?php

use App\Models\AdminRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administration team: roles with permissions, and a role on each staff user.
 * Existing site admins become Owners so nobody loses access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->string('description', 255)->nullable();
            $t->json('permissions');                          // ["licenses", "support", …] see AdminRole::PERMISSIONS
            $t->boolean('is_owner')->default(false);          // Owner: everything, can't be edited or deleted
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('admin_role_id')->nullable()->after('is_admin')->constrained('admin_roles')->nullOnDelete();
            $t->foreignId('invited_by')->nullable()->after('admin_role_id')->constrained('users')->nullOnDelete();
            $t->timestamp('invited_at')->nullable()->after('invited_by');
            $t->timestamp('invite_accepted_at')->nullable()->after('invited_at');
            $t->timestamp('last_login_at')->nullable()->after('invite_accepted_at');
        });

        $now = now();
        foreach (AdminRole::DEFAULTS as $i => $role) {
            DB::table('admin_roles')->insert([
                'name' => $role['name'], 'description' => $role['description'], 'permissions' => json_encode($role['permissions']),
                'is_owner' => $role['is_owner'] ?? false, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $owner = DB::table('admin_roles')->where('is_owner', true)->value('id');
        DB::table('users')->where('is_admin', true)->update(['admin_role_id' => $owner, 'invite_accepted_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('admin_role_id');
            $t->dropConstrainedForeignId('invited_by');
            $t->dropColumn(['invited_at', 'invite_accepted_at', 'last_login_at']);
        });
        Schema::dropIfExists('admin_roles');
    }
};
