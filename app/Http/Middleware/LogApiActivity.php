<?php

namespace App\Http\Middleware;

use App\Services\Activity\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Board 2026-10-31: every state-changing app request (POST / PUT / PATCH / DELETE on the
 * API) is logged with the route, the member, the outcome and the duration — so HQ can
 * follow one distributor's activity end to end. Reads (GET) are not logged.
 */
class LogApiActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $route = $request->route()?->uri() ?? $request->path();
            $code = $response->getStatusCode();
            $body = $request->except(['password', 'otp', 'code', 'token', 'aadhaar', 'pan', 'private_key']);
            $error = null;
            if ($code >= 400) {
                $json = json_decode((string) $response->getContent(), true);
                $error = is_array($json) ? ($json['message'] ?? null) : null;
            }
            ActivityLogger::log('app', 'api ' . strtolower($request->method()) . ' ' . $route,
                strtoupper($request->method()) . ' /' . ltrim($route, '/') . ' → ' . $code . ($error ? ' · ' . $error : ''), [
                    'channel' => 'api',
                    'status' => $code >= 500 ? 'failed' : ($code >= 400 ? 'denied' : 'ok'),
                    'details' => [
                        'http' => $code,
                        'ms' => (int) round((microtime(true) - $started) * 1000),
                        'input' => $body,
                        'error' => $error,
                    ],
                ]);
        }

        return $response;
    }
}
