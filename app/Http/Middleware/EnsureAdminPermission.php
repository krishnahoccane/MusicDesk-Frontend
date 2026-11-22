<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `admin.can:<permission>`: the team member's role must include that admin area. */
class EnsureAdminPermission
{
    /** Settings groups (config/site.php) and the permission each needs. */
    public const SETTINGS = ['branding' => 'storefront', 'home' => 'storefront', 'contact' => 'storefront', 'pricing' => 'storefront', 'invoice' => 'payments', 'general' => 'payments'];

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if ($permission === 'settings') {
            $permission = self::SETTINGS[$request->route('group')] ?? 'payments';
        }
        abort_unless($request->user()?->canAdmin($permission), 403, 'Your team role doesn’t include this area. Ask an Owner for access.');

        return $next($request);
    }
}
