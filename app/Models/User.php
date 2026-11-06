<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Models\Traits\UserACLTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use UserACLTrait, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'spon_id',
        'name',
        'email',
        'phone',
        'cpf',
        'escritorio',
        'rede_social',
        'password',
        'saldo',
        'credits',
        'combo',
        'status',
        'escritorio',
        'rede_social',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = Str::limit(Str::uuid(), 8, '');
            }
        });
    }

    public function sponsor()
    {
        return $this->hasOne(User::class, 'uuid', 'spon_id');
    }

    public function children()
    {
        return $this->hasMany(User::class, 'spon_id', 'uuid');
    }

    public function agents()
    {
        return $this->hasMany(Agents::class, 'user_id', 'uuid')->where('status', 'enabled');
    }

    public function chats()
    {
        return $this->hasManyThrough(
            Chats::class,       // Modelo final
            Agents::class,      // Modelo intermediário
            'user_id',          // Foreign key em Agents referenciando User (users.uuid)
            'agent_id',         // Foreign key em Chats referenciando Agents
            'uuid',             // Local key em User (users.uuid)
            'uuid'              // Local key em Agents (agents.uuid)
        );
    }

    public function convertedChats()
    {
        return $this->chats()->where('conversion', true);
    }

    public function messages()
    {
        return $this->hasManyThrough(
            Messages::class,
            Agents::class,
            'user_id',     // Agents.user_id → User.uuid
            'agent_id',    // Messages.agent_id → Agents.uuid
            'uuid',        // User.uuid
            'uuid'         // Agents.uuid
        );
    }

    public function whats()
    {
        return $this->hasOne(WhatsappInstance::class, 'user_id', 'uuid');
    }

    public function commission()
    {
        return $this->hasMany(CommissionLog::class, 'user_id', 'uuid');
    }

    public function api()
    {
        return $this->hasOne(UserApi::class, 'user_id', 'uuid');
    }

    public function empresa()
    {
        return $this->hasOne(Empresas::class, 'user_id', 'uuid');
    }

    public function plan()
    {
        return $this->hasOneThrough(
            Plans::class,
            PlanUser::class,
            'user_id',   // Foreign key on plan_user table
            'id',        // Foreign key on plans table
            'uuid',      // Local key on users table
            'plan_id'    // Local key on plan_user table
        );
    }

    /**
     * Saques
     */
    public function Saques()
    {
        return $this->hasMany(Saques::class, 'user_id', 'uuid');
    }

    public function planRelation()
    {
        return $this->hasOne(PlanUser::class, 'user_id', 'uuid');
    }

    /**
     * Ultima Mensalidade
     */
    protected function lastMensalidade(): Attribute
    {
        return Attribute::get(function () {
            return $this->pagamentos()
                ->whereIn('tipo', ['mensalidade', 'plan_consultor', 'plan_micro_franquia', 'plan_franquia'])
                ->whereIn('status', ['pago', 'creditado'])
                ->orderByDesc('data_compra')
                ->first();
        });
    }

    /**
     * Mensalidade
     */
    public function mensalidade()
    {
        $lastPayment = Payments::where('user_id', $this->uuid)
            ->whereIn('status', ['pago', 'creditado'])
            ->whereIn('tipo', ['mensalidade', 'plan_consultor', 'plan_micro_franquia', 'plan_franquia'])
            ->orderBy('data_compra', 'desc')
            ->first();

        if (!$lastPayment) {
            return 'vencido';
        }

        if (empty($lastPayment->validade)) {
            return 'vencido';
        }

        $paymentDate = Carbon::parse($lastPayment->validade);
        $isValid = now()->lessThanOrEqualTo($paymentDate);
        if (!$isValid) {
            return 'vencido';
        }

        return Carbon::parse($lastPayment->validade)->format('d/m/Y');
    }

    /**
     * Pagamentos
     */
    public function Payments()
    {
        return $this->hasMany(Payments::class, 'user_id', 'uuid');
    }

    /**
     * Saldo em Créditos
     */
    public function balance(): int
    {
        // Verifica se o usuario existe
        if (!$this) return 0;

        $planCredits = $this->credits ?? 0;
        $comboCredits = $this->combo ?? 0;

        return $planCredits + $comboCredits;
    }

    /**
     * Proximo vencimento do plano
     */
    public function getVencimentoAttribute()
    {
        return $this->payments()
            ->whereIn('status', ['pago', 'creditado'])
            ->orderBy('validade', 'desc')
            ->value('validade');
    }

    /**
     * Vefirica a disponibilidade de Crédito
     */
    public function verifyLimit(int $requiredCredits = 1): bool
    {
        // Verifica se o usuario tem um Plano Ativo
        if (!$this->plan) return false;

        // Total de créditos disponíveis: plano + combo extra
        $availableCredits = ($this->plan->credits ?? 0) + ($this->combo ?? 0);

        // Créditos já utilizados
        $usedCredits = $this->credits ?? 0;

        // Retorna true se ainda tiver crédito suficiente
        return ($availableCredits - $usedCredits) >= $requiredCredits;
    }

    public function teamMembers()
    {
        return $this->belongsToMany(
            User::class,
            'team_members',
            'leader_uuid',
            'member_uuid',
            'uuid',
            'uuid'
        )->withTimestamps();
    }

    public function teamLeaders()
    {
        return $this->belongsToMany(
            User::class,
            'team_members',
            'member_uuid',
            'leader_uuid',
            'uuid',
            'uuid'
        )->withTimestamps();
    }

    /**
     * APIs configuradas do usuário (uma para cada plataforma)
     */
    public function userApis()
    {
        return $this->hasMany(UserApi::class, 'user_id', 'uuid');
    }

    public function whatsappInstances()
    {
        return $this->hasMany(WhatsappInstance::class, 'user_id', 'uuid');
    }

    /**
     * Busca a API de uma plataforma específica
     */
    public function getApiForPlatform(string $platformSlug): ?UserApi
    {
        return $this->userApis()
            ->where('platform_slug', $platformSlug)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Verifica se o usuário tem API configurada para uma plataforma
     */
    public function hasApiForPlatform(string $platformSlug): bool
    {
        return $this->userApis()
            ->where('platform_slug', $platformSlug)
            ->where('is_active', true)
            ->exists();
    }
}
