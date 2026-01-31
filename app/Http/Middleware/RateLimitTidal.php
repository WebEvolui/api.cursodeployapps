<?php

namespace App\Http\Middleware;

use App\Models\BonusNonce;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
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
        $deviceId = $request->header('X-Device-Id');

        // Deixar passar sem device_id - antigos que pega apenas a localização atual
        // vai continuar funcionando normalmente
        if (!$deviceId) {
            return $next($request);
        }

        $ip = $request->ip();
        
        $city = $request->route('city');
        
        if (!$city) {
            return $next($request);
        }

        // Verificar se tem nonce de bônus válido
        $bonusNonce = $request->header('X-Bonus-Nonce');
        if ($bonusNonce && $this->validateAndUseBonusNonce($bonusNonce, $deviceId, $ip)) {
            Log::info("Bônus usado para ID: {$deviceId}, IP: {$ip}, Nonce: {$bonusNonce}");
            $response = $next($request);
            $response->headers->set('X-Bonus-Used', 'true');
            return $response;
        }

        $normalizedCity = mb_strtolower(trim($city));
        
        // Verificar se é premium
        $isPremium = $this->checkIsPremium($request, $deviceId);
        
        $key = 'rate_limit:tidal:cities:' . md5($deviceId . $ip);
        $maxCities = $isPremium ? 30 : 2;

        $ttlSeconds = (int) Carbon::now()->diffInSeconds(Carbon::now()->endOfDay());
        
        // Obtém as cidades já consultadas
        $consultedCities = Redis::smembers($key);
        $currentCityCount = count($consultedCities);
        
        // Verifica se a cidade já foi consultada antes
        $cityAlreadyConsulted = in_array($normalizedCity, $consultedCities);
        
        // Se a cidade não foi consultada e já atingiu o limite
        if (!$cityAlreadyConsulted && $currentCityCount >= $maxCities) {
            $ttl = Redis::ttl($key);
            Log::info("Rate limit atingido para ID: {$deviceId}, IP: {$ip}. Premium: " . ($isPremium ? 'Sim' : 'Não') . ". Cidades consultadas: " . implode(', ', $consultedCities));

            $message = $isPremium 
                ? "Limite de {$maxCities} cidades diferentes atingido hoje para sua conta Premium. Tente novamente amanhã."
                : "Limite de {$maxCities} cidades diferentes atingido hoje. Torne-se Premium para consultar até 30 cidades!";

            return response()->json([
                'error'   => 'rate_limit_exceeded',
                'message' => $message,
                'consulted_cities' => $consultedCities,
                'is_premium' => $isPremium
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
        $response->headers->set('X-Is-Premium', $isPremium ? 'true' : 'false');

        return $response;
    }

    /**
     * Verifica se o usuário é premium via header, cache ou API RevenueCat
     */
    private function checkIsPremium(Request $request, string $deviceId): bool
    {
        // Só verifica se o app disse que ele é premium
        $headerPremium = strtolower($request->header('X-Premium') ?? '');

        if ($headerPremium !== 'true') {
            return false;
        }

        $cacheKey = "premium_status:{$deviceId}";
        $forceCheck = strtolower($request->header('X-Premium-Force-Check') ?? '') === 'true';
        
        // Tenta pegar do cache primeiro (30 minutos), a menos que seja um Force Check
        $cachedStatus = $forceCheck ? null : Redis::get($cacheKey);

        if ($cachedStatus !== null) {
            return (bool) $cachedStatus;
        }

        // Se não está no cache ou é Force Check, consulta RevenueCat V2
        Log::info("Consultando RevenueCat para ID: {$deviceId}" . ($forceCheck ? " (Force Check)" : " (Cache Miss)"));
        $isPremium = $this->verifyWithRevenueCat($deviceId);

        // Salva no cache: 1 hora em ambos os casos.
        // O Force Check resolve o problema do usuário que acaba de assinar.
        Redis::setex($cacheKey, 3600, $isPremium ? '1' : '0');

        return $isPremium;
    }

    /**
     * Consulta API V2 do RevenueCat
     */
    private function verifyWithRevenueCat(string $appUserId): bool
    {
        $apiKey = env('REVENUECAT_API_KEY');
        $projectId = env('REVENUECAT_PROJECT_ID');
        $entitlementId = env('REVENUECAT_ENTITLEMENT_ID', 'premium');

        if (!$apiKey || !$projectId) {
            Log::error("RevenueCat configuration missing in .env");
            return false;
        }

        try {
            $url = "https://api.revenuecat.com/v2/projects/{$projectId}/customers/{$appUserId}";
            
            $response = Http::withToken($apiKey)
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();
                
                // Na V2 mostrada pelo usuário, o campo é active_entitlements.items
                $items = $data['active_entitlements']['items'] ?? [];
                
                foreach ($items as $item) {
                    // Verificamos o entitlement_id que o usuário configurou ou o lookup_key
                    $id = $item['entitlement_id'] ?? '';
                    
                    if ($id === $entitlementId) {
                        // O expires_at vem em milissegundos no snippet do usuário
                        $expiresAtMs = $item['expires_at'] ?? null;
                        
                        // Se não tem validade (infinito) ou se a validade é futura
                        if (!$expiresAtMs || ($expiresAtMs / 1000) > Carbon::now()->timestamp) {
                            return true;
                        }
                    }
                }
            } else {
                Log::error("RevenueCat API Error: " . $response->status() . " - " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("RevenueCat API Exception: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Valida e usa um nonce de bônus
     * 
     * @param string $nonceValue O valor do nonce
     * @param string|null $deviceId O ID do dispositivo
     * @param string $ip O IP do dispositivo
     * @return bool True se o nonce foi validado e usado com sucesso
     */
    private function validateAndUseBonusNonce(string $nonceValue, ?string $deviceId, string $ip): bool
    {
        if (!$deviceId) {
            return false;
        }

        $nonce = BonusNonce::where('nonce', $nonceValue)
            ->where('device_id', $deviceId)
            ->first();

        if (!$nonce) {
            Log::debug("Nonce não encontrado: {$nonceValue}");
            return false;
        }

        if (!$nonce->canBeUsed()) {
            Log::debug("Nonce não pode ser usado: {$nonceValue} - Valid: {$nonce->isValid()}, Claimed: {$nonce->isClaimed()}, Used: {$nonce->isUsed()}");
            return false;
        }

        // Marcar como usado
        return $nonce->markAsUsed();
    }
}
