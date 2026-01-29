<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RateLimitTidal
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');

        $ip = $request->ip();
        $key = 'rate_limit:tidal:' . md5($deviceId . $ip);
        $limit = 3;

        $ttlSeconds = (int) Carbon::now()->diffInSeconds(Carbon::now()->endOfDay());
        $current = Redis::incr($key);

        // Garante que a expiração seja definida na primeira vez ou se estiver faltando
        if ($current === 1 || Redis::ttl($key) === -1) {
            Redis::expire($key, $ttlSeconds);
        }

        if ($current > $limit) {
            $ttl = Redis::ttl($key);
            Log::warning("Rate limit atingido para ID: {$deviceId}, IP: {$ip}. TTL no Redis: {$ttl}");

            return response()->json([
                'error'   => 'rate_limit_exceeded',
                'message' => "Limite de {$limit} chamadas atingido hoje. Tente novamente amanhã."
            ], 429)->header('Retry-After', max(1, (int) $ttl));
        }

        $response = $next($request);
        
        $remaining = max(0, $limit - $current);
        $ttl = Redis::ttl($key);

        $response->headers->set('X-RateLimit-Limit', $limit);
        $response->headers->set('X-RateLimit-Remaining', $remaining);
        $response->headers->set('X-RateLimit-Reset', max(1, (int) $ttl));

        return $response;
    }
}
