<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WhatsappDispatch extends Model
{
    protected $table = 'whatsapp_dispatches';

    protected $fillable = [
        'uuid',
        'user_id',
        'layout_id',
        'agent_id',
        'total_items',
        'sent_count',
        'failed_count',
        'skipped_count',
        'status',
        'started_at',
        'last_progress_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_progress_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($dispatch) {
            if (empty($dispatch->uuid)) {
                $dispatch->uuid = Str::limit(Str::uuid(), 8, '');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(WhatsappLayout::class, 'layout_id', 'uuid');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agents::class, 'agent_id', 'uuid');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WhatsappDispatchItem::class, 'dispatch_id');
    }

    /**
     * Itens prontos para envio agora (agendados e pendentes)
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereHas('items', function ($q) {
            $q->where('status', 'pending')->where('scheduled_at', '<=', now());
        });
    }

    /**
     * Progresso percentual (0-100) para exibição na UI
     */
    public function getProgressPercentAttribute(): int
    {
        if ($this->total_items === 0) {
            return 100;
        }

        $done = $this->sent_count + $this->failed_count + $this->skipped_count;

        return (int) floor(($done / $this->total_items) * 100);
    }
}
