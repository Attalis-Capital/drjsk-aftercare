<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emit a noindex directive on every response for non-production environments
 * (staging, local). The James pilot staging instance (mission #1723) must never
 * be indexed by search engines. Production (APP_ENV=production) is left
 * untouched so a future prod deploy is not accidentally de-indexed.
 *
 * This is defence-in-depth: the Railway nginx config also emits X-Robots-Tag at
 * the edge, and the SPA shell carries a robots meta tag. Any one of the three
 * suffices; together they cover header-stripping proxies and meta-only crawlers.
 */
class NoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (strtolower((string) config('app.env')) !== 'production') {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
