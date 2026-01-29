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
        
        $city = $request->route('city');
        
        if (!$city) {
            return $next($request);
        }

        $normalizedCity = mb_strtolower(trim($city));
        
        $key = 'rate_limit:tidal:cities:' . md5($deviceId . $ip);
        $maxCities = 2;

        $ttlSeconds = (int) Carbon::now()->diffInSeconds(Carbon::now()->endOfDay());
        
        // Obtém as cidades já consultadas
        $consultedCities = Redis::smembers($key);
        $currentCityCount = count($consultedCities);
        
        // Verifica se a cidade já foi consultada antes
        $cityAlreadyConsulted = in_array($normalizedCity, $consultedCities);
        
        // Se a cidade não foi consultada e já atingiu o limite
        if (!$cityAlreadyConsulted && $currentCityCount >= $maxCities) {
            $ttl = Redis::ttl($key);
            Log::warning("Rate limit atingido para ID: {$deviceId}, IP: {$ip}. Cidades consultadas: " . implode(', ', $consultedCities));

            return response()->json([
                'error'   => 'rate_limit_exceeded',
                'message' => "Limite de {$maxCities} cidades diferentes atingido hoje. Tente novamente amanhã.",
                'consulted_cities' => $consultedCities
            ], 429)->header('Retry-After', max(1, (int) $ttl));
        }

        Redis::sadd($key, $normalizedCity);
        
        // Garante que a expiração seja definida na primeira vez ou se estiver faltando
        if ($currentCityCount === 0 || Redis::ttl($key) === -1) {
            Redis::expire($key, $ttlSeconds);
        }

        $response = $next($request);
        
        // Recalcula as cidades após adicionar a nova
        $updatedCities = Redis::smembers($key);
        $remaining = max(0, $maxCities - count($updatedCities));
        $ttl = Redis::ttl($key);

        $response->headers->set('X-RateLimit-Limit', $maxCities);
        $response->headers->set('X-RateLimit-Remaining', $remaining);
        $response->headers->set('X-RateLimit-Reset', max(1, (int) $ttl));
        $response->headers->set('X-RateLimit-Cities', implode(',', $updatedCities));

        return $response;
    }
}
