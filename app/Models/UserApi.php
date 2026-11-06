<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserApi extends Model
{
    protected $fillable = [
        'user_id',
        'platform_id',
        'platform_slug',
        'api_key',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class, 'platform_slug', 'slug');
    }

    /**
     * Scope para APIs ativas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Verificar se a plataforma já tem uma API configurada para o usuário
     */
    public static function platformExistsForUser($userId, $platformSlug)
    {
        return self::where('user_id', $userId)
            ->where('platform_slug', $platformSlug)
            ->exists();
    }

    /**
     * Buscar API específica de uma plataforma para um usuário
     */
    public static function findByUserAndPlatform($userId, $platformSlug): ?self
    {
        return self::where('user_id', $userId)
            ->where('platform_slug', $platformSlug)
            ->where('is_active', true)
            ->first();
    }
}
