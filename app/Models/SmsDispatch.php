<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SmsDispatch extends Model
{
    protected $table = 'sms_dispatches';

    protected $fillable = [
        'uuid', 'user_id', 'provider', 'layout_id', 'total_items', 'sent_count',
        'delivered_count', 'failed_count', 'skipped_count', 'provider_cost',
        'status', 'error_message', 'started_at', 'last_progress_at', 'completed_at',
    ];

    protected $casts = [
        'provider_cost' => 'decimal:4',
        'started_at' => 'datetime',
        'last_progress_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $dispatch): void {
            $dispatch->uuid ??= Str::limit(Str::uuid(), 8, '');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(SmsLayout::class, 'layout_id', 'uuid');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SmsDispatchItem::class, 'dispatch_id');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->whereHas('items', fn ($items) => $items
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now()));
    }

    public function getProgressPercentAttribute(): int
    {
        if ((int) $this->total_items === 0) {
            return 100;
        }

        $done = (int) $this->sent_count + (int) $this->failed_count + (int) $this->skipped_count;

        return min(100, (int) floor(($done / $this->total_items) * 100));
    }
}
