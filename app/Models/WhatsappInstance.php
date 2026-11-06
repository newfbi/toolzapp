<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WhatsappInstance extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'agent_id',
        'chat_id',
        'instance_id',
        'instance_token',
        'provider',
        'name',
        'description',
        'status',
        'qr_code',
        'phone_number',
        'settings',
        'webhook_url',
        'webhook_enabled',
        'instance_type',
        'connected_at',
        'last_activity',
        'last_auth_time',
        'error_message',
        'is_trial',
        'trial_expires_at'
    ];

    protected $casts = [
        'qr_code' => 'array',
        'settings' => 'array',
        'webhook_enabled' => 'boolean',
        'connected_at' => 'datetime',
        'last_activity' => 'datetime',
        'last_auth_time' => 'datetime',
        'is_trial' => 'boolean',
        'trial_expires_at' => 'datetime'
    ];

    /**
     * A instância possui um único provedor ativo por vez. Os dados do outro
     * provedor ficam preservados em settings para que a troca não destrua a
     * sessão Baileys nem as credenciais da Z-API.
     */
    public function providerSettings(): array
    {
        return is_array($this->settings) ? $this->settings : [];
    }

    public function providerSetting(string $key, mixed $default = null): mixed
    {
        return $this->providerSettings()[$key] ?? $default;
    }

    protected static function booted()
    {
        static::creating(function ($instance) {
            if (empty($instance->uuid)) {
                $instance->uuid = Str::limit(Str::uuid(), 8, '');
            }
        });
    }

    /**
     * Relacionamento com usuário
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    /**
     * Relacionamento com agente
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agents::class, 'agent_id', 'uuid');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chats::class, 'chat_id', 'uuid');
    }

    /**
     * Verifica se a instância está conectada
     */
    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    /**
     * Verifica se a instância está conectando
     */
    public function isConnecting(): bool
    {
        return $this->status === 'connecting';
    }

    /**
     * Verifica se há erro na instância
     */
    public function hasError(): bool
    {
        return $this->status === 'error';
    }

    /**
     * Verifica se a instância é de teste
     */
    public function isTrial(): bool
    {
        return $this->is_trial === true;
    }

    /**
     * Verifica se a instância de teste está expirada
     */
    public function isTrialExpired(): bool
    {
        if (!$this->isTrial()) {
            return false;
        }

        return $this->trial_expires_at && now()->isAfter($this->trial_expires_at);
    }

    /**
     * Verifica se a instância de teste ainda é válida
     */
    public function isTrialValid(): bool
    {
        return $this->isTrial() && !$this->isTrialExpired();
    }

    /**
     * Obtém o tempo restante para instâncias de teste (em horas)
     */
    public function getTrialTimeRemaining(): ?int
    {
        if (!$this->isTrial() || $this->isTrialExpired()) {
            return null;
        }

        return now()->diffInHours($this->trial_expires_at, false);
    }

    /**
     * Atualiza status da instância
     */
    public function updateStatus(string $status, ?string $errorMessage = null): void
    {
        $updateData = ['status' => $status];

        if ($status === 'connected') {
            $updateData['connected_at'] = now();
        }

        if ($errorMessage) {
            $updateData['error_message'] = $errorMessage;
        }

        $updateData['last_activity'] = now();

        $this->update($updateData);
    }

    /**
     * Obtém instâncias ativas do usuário
     */
    public static function activeInstances($userId)
    {
        return static::where('user_id', $userId)
            ->where('status', 'connected')
            ->get();
    }
}
