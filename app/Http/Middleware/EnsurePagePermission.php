<?php

namespace App\Http\Middleware;

use App\Support\PagePermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePagePermission
{
    public function handle(Request $request, Closure $next, string $page): Response
    {
        abort_unless(PagePermissions::allows($request->user(), $page), 403);

        return $next($request);
    }
}
