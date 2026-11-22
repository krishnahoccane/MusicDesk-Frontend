<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A role on the administration team. Permissions are admin areas; Owner has all of them. */
class AdminRole extends Model
{
    /** Admin areas a role can be given. The dashboard is open to every team member. */
    public const PERMISSIONS = [
        'licenses' => ['Licenses', 'List, issue and change licenses, keys and domains'],
        'security' => ['License status & security', 'Live domains, failed license checks, issue or block domains'],
        'customers' => ['Customers', 'Customer organisations, their teams and billing details'],
        'releases' => ['Releases', 'Publish versions and upload packages'],
        'support' => ['Support tickets', 'Answer tickets, internal notes, assign'],
        'sales' => ['Orders & invoices', 'Mark paid, refund, view invoices'],
        'storefront' => ['Storefront', 'Branding, homepage text, pricing plans, legal pages, contact'],
        'payments' => ['Payments & settings', 'Gateways, currencies, invoicing and localisation'],
        'team' => ['Team & roles', 'Invite, change and remove team members; edit roles'],
    ];

    /** Seeded by the migration; admins can add their own. */
    public const DEFAULTS = [
        ['name' => 'Owner', 'description' => 'Full access, including the team. Can’t be changed or deleted.', 'permissions' => [], 'is_owner' => true],
        ['name' => 'Administrator', 'description' => 'Everything except managing the team.', 'permissions' => ['licenses', 'security', 'customers', 'releases', 'support', 'sales', 'storefront', 'payments']],
        ['name' => 'License manager', 'description' => 'Licenses, security alerts, customers and releases.', 'permissions' => ['licenses', 'security', 'customers', 'releases']],
        ['name' => 'Support agent', 'description' => 'Answers support tickets; can look up customers and licenses.', 'permissions' => ['support', 'customers', 'licenses']],
        ['name' => 'Billing', 'description' => 'Orders, invoices, refunds and payment settings.', 'permissions' => ['sales', 'customers', 'payments']],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_owner' => 'boolean'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'admin_role_id')->where('is_admin', true);
    }

    public function allows(string $permission): bool
    {
        return $this->is_owner || in_array($permission, $this->permissions ?? [], true);
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->is_owner ? array_keys(self::PERMISSIONS) : array_values(array_intersect(array_keys(self::PERMISSIONS), $this->permissions ?? []));
    }

    public static function owner(): self
    {
        return static::query()->where('is_owner', true)->firstOrFail();
    }
}
