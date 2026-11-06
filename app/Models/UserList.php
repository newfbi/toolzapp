<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class UserList extends Model
{
    protected $table = 'user_list';

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'email',
        'phone',
        'status',
        'whatsapp_opt_in',
        'sms_opt_in',
    ];

    protected $casts = [
        'whatsapp_opt_in' => 'boolean',
        'sms_opt_in' => 'boolean',
    ];

    protected static function booted()
    {
        static::creating(function ($contact) {
            if (empty($contact->uuid)) {
                $contact->uuid = Str::limit(Str::uuid(), 8, '');
            }
        });
    }

    /**
     * Relacionamento com o usuário dono do contato
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    /**
     * Escopo: contatos de um usuário específico
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Envios já realizados para este contato (histórico de recebimentos)
     */
    public function dispatchItems(): HasMany
    {
        return $this->hasMany(WhatsappDispatchItem::class, 'user_list_id', 'uuid');
    }

    /**
     * Quantidade de mensagens efetivamente RECEBIDAS por este contato
     */
    public function receivedMessagesCount(): int
    {
        return $this->dispatchItems()->where('status', 'sent')->count();
    }
}
