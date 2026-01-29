<?php

namespace App\Http\Controllers;

use App\Models\BonusNonce;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BonusController extends Controller
{
    /**
     * Gera um novo nonce para o device
     * 
     * POST /bonus/nonce
     * Header: X-Device-Id
     */
    public function generateNonce(Request $request)
    {
        $deviceId = $request->header('X-Device-Id');
        
        if (!$deviceId || strlen($deviceId) < 8 || strlen($deviceId) > 100) {
            return response()->json([
                'error' => 'missing_device_id',
                'message' => 'Header X-Device-Id é obrigatório.',
            ], 400);
        }

        $ip = $request->ip();

        // Verificar cooldown (1 bônus a cada 30 min)
        if (!BonusNonce::canCreateForDevice($deviceId)) {
            $minutesLeft = BonusNonce::minutesUntilNextNonce($deviceId);
            
            return response()->json([
                'error' => 'cooldown_active',
                'message' => "Você pode solicitar um novo bônus em {$minutesLeft} minutos.",
                'minutes_remaining' => $minutesLeft,
            ], 429);
        }

        // Criar novo nonce
        $nonce = BonusNonce::create([
            'nonce' => Str::uuid()->toString(),
            'device_id' => $deviceId,
            'ip_address' => $ip,
            'expires_at' => now()->addMinutes(5),
        ]);

        return response()->json([
            'success' => true,
            'nonce' => $nonce->nonce,
            'expires_at' => $nonce->expires_at->toIso8601String(),
            'expires_in_seconds' => 300,
        ]);
    }

    /**
     * Marca um nonce como "claimed" (após ver anúncio)
     * 
     * POST /bonus/claim
     * Header: X-Device-Id, X-Bonus-Nonce
     */
    public function claimNonce(Request $request)
    {
        $deviceId = $request->header('X-Device-Id');
        
        if (!$deviceId || strlen($deviceId) < 8 || strlen($deviceId) > 100) {
            return response()->json([
                'error' => 'missing_device_id',
                'message' => 'Header X-Device-Id é obrigatório.',
            ], 400);
        }

        $nonceValue = $request->header('X-Bonus-Nonce');

        if (!$nonceValue) {
            return response()->json([
                'error' => 'missing_nonce',
                'message' => 'Header X-Bonus-Nonce é obrigatório.',
            ], 400);
        }

        // Buscar nonce
        $nonce = BonusNonce::where('nonce', $nonceValue)
            ->where('device_id', $deviceId)
            ->first();

        if (!$nonce) {
            return response()->json([
                'error' => 'nonce_not_found',
                'message' => 'Nonce não encontrado ou não pertence a este dispositivo.',
            ], 404);
        }

        if (!$nonce->isValid()) {
            return response()->json([
                'error' => 'nonce_expired',
                'message' => 'Este nonce expirou. Solicite um novo.',
            ], 410);
        }

        if ($nonce->isClaimed()) {
            return response()->json([
                'error' => 'nonce_already_claimed',
                'message' => 'Este nonce já foi validado.',
            ], 409);
        }

        // Marcar como claimed
        $nonce->markAsClaimed();

        return response()->json([
            'success' => true,
            'message' => 'Bônus validado! Use o nonce na próxima consulta.',
            'nonce' => $nonce->nonce,
        ]);
    }
}
