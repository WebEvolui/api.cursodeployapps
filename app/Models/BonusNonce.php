<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BonusNonce extends Model
{
    protected $fillable = [
        'nonce',
        'device_id',
        'ip_address',
        'expires_at',
        'claimed_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Scope para nonces válidos (não expirados)
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope para nonces já "claimed" (após ver anúncio)
     */
    public function scopeClaimed($query)
    {
        return $query->whereNotNull('claimed_at');
    }

    /**
     * Scope para nonces não usados
     */
    public function scopeUnused($query)
    {
        return $query->whereNull('used_at');
    }

    /**
     * Verifica se o nonce ainda é válido (não expirou)
     */
    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }

    /**
     * Verifica se o nonce foi claimed
     */
    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }

    /**
     * Verifica se o nonce já foi usado
     */
    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Marca o nonce como claimed (após ver anúncio)
     */
    public function markAsClaimed(): bool
    {
        if ($this->isClaimed() || !$this->isValid()) {
            return false;
        }

        $this->claimed_at = now();
        return $this->save();
    }

    /**
     * Marca o nonce como usado (após consulta gratuita)
     */
    public function markAsUsed(): bool
    {
        if ($this->isUsed() || !$this->isClaimed() || !$this->isValid()) {
            return false;
        }

        $this->used_at = now();
        return $this->save();
    }

    /**
     * Verifica se o nonce pode ser usado para consulta gratuita
     */
    public function canBeUsed(): bool
    {
        return $this->isValid() && $this->isClaimed() && !$this->isUsed();
    }

    /**
     * Verifica se um device pode criar um novo nonce (regra: 1 a cada 30 min)
     */
    public static function canCreateForDevice(string $deviceId): bool
    {
        $lastNonce = self::where('device_id', $deviceId)
            ->where('created_at', '>', now()->subMinutes(30))
            ->first();

        return $lastNonce === null;
    }

    /**
     * Retorna quantos minutos faltam para poder criar novo nonce
     */
    public static function minutesUntilNextNonce(string $deviceId): int
    {
        $lastNonce = self::where('device_id', $deviceId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastNonce) {
            return 0;
        }

        $nextAvailable = $lastNonce->created_at->addMinutes(30);
        
        if ($nextAvailable->isPast()) {
            return 0;
        }

        return (int) now()->diffInMinutes($nextAvailable, false);
    }
}
