<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SmsLayout extends Model
{
    protected $table = 'sms_layouts';

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'template_name',
        'message',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $layout): void {
            $layout->uuid ??= Str::limit(Str::uuid(), 8, '');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public static function availableVariables(): array
    {
        return [
            ['key' => 'nome', 'label' => 'Nome completo', 'example' => 'João Silva'],
            ['key' => 'primeiro_nome', 'label' => 'Primeiro nome', 'example' => 'João'],
            ['key' => 'email', 'label' => 'E-mail', 'example' => 'joao@email.com'],
            ['key' => 'telefone', 'label' => 'Telefone', 'example' => '11999998888'],
        ];
    }

    public static function renderContent(string $content, array $variables): string
    {
        $rendered = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn (array $match) => (string) ($variables[$match[1]] ?? $match[0]),
            $content
        );

        // SMS não suporta a formatação visual do WhatsApp. Preserva o texto,
        // mas elimina quebras e espaços repetidos para não gastar segmentos.
        return trim((string) preg_replace('/\s+/u', ' ', $rendered));
    }

    public function renderForLead(UserList $lead): string
    {
        $nameParts = preg_split('/\s+/', trim((string) $lead->name));

        return self::renderContent($this->message, [
            'nome' => $lead->name,
            'primeiro_nome' => $nameParts[0] ?? $lead->name,
            'email' => $lead->email ?? '',
            'telefone' => $lead->phone ?? '',
        ]);
    }
}
