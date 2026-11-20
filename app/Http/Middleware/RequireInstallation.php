<?php

namespace App\Http\Middleware;

use App\Support\Installer\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Until the app is installed, every page leads to /install; afterwards /install is gone. */
class RequireInstallation
{
    public function handle(Request $request, Closure $next): Response
    {
        $installing = $request->is('install', 'install/*');

        if (Installer::isInstalled()) {
            abort_if($installing, 404);

            return $next($request);
        }

        if (! $installing) {
            return redirect('/install');
        }

        return $next($request);
    }
}
