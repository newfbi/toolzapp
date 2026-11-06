<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WhatsappLayout extends Model
{
    protected $table = 'whatsapp_layouts';

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'message',
        'media_type',
        'media_source',
        'status',
    ];

    protected static function booted()
    {
        static::creating(function ($layout) {
            if (empty($layout->uuid)) {
                $layout->uuid = Str::limit(Str::uuid(), 8, '');
            }
        });
    }

    /**
     * Relacionamento com o usuário dono do layout
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    /**
     * Escopo: layouts de um usuário específico
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Variáveis suportadas nas mensagens (ligadas aos campos da user_list)
     */
    public static function availableVariables(): array
    {
        return [
            ['key' => 'nome', 'label' => 'Nome completo', 'example' => 'João Silva'],
            ['key' => 'primeiro_nome', 'label' => 'Primeiro nome', 'example' => 'João'],
            ['key' => 'email', 'label' => 'E-mail', 'example' => 'joao@email.com'],
            ['key' => 'telefone', 'label' => 'Telefone', 'example' => '(11) 99999-8888'],
            ['key' => 'status', 'label' => 'Status do lead', 'example' => 'Ativo'],
        ];
    }

    /**
     * Substitui as variáveis {{chave}} no conteúdo pelos valores informados.
     * Variáveis desconhecidas são mantidas como estão.
     */
    public static function renderContent(string $content, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn ($match) => (string) ($variables[$match[1]] ?? $match[0]),
            $content
        );
    }

    /**
     * Renderiza o layout para um lead específico da user_list
     */
    public function renderForLead(UserList $lead): string
    {
        $nameParts = explode(' ', trim($lead->name));

        return self::renderContent($this->message, [
            'nome' => $lead->name,
            'primeiro_nome' => $nameParts[0] ?? $lead->name,
            'email' => $lead->email ?? '',
            'telefone' => $lead->phone ?? '',
            'status' => $lead->status === 'active' ? 'Ativo' : 'Inativo',
        ]);
    }

    /**
     * Indica se o layout possui imagem anexa
     */
    public function hasImage(): bool
    {
        return $this->media_type === 'image' && !empty($this->media_source);
    }

    /**
     * Indica se a mídia é um arquivo local (disco público) — e não URL externa
     */
    public function isLocalMedia(): bool
    {
        if (!$this->hasImage()) {
            return false;
        }

        return !str_starts_with((string) $this->media_source, 'http://')
            && !str_starts_with((string) $this->media_source, 'https://');
    }

    /**
     * URL da imagem para exibição no navegador:
     * - Caminho local (disco público) -> via link simbólico /storage
     * - URL externa (http/https)      -> usada diretamente
     */
    public function getImageDisplayUrl(): ?string
    {
        if (!$this->hasImage()) {
            return null;
        }

        if (str_starts_with($this->media_source, 'http://') || str_starts_with($this->media_source, 'https://')) {
            return $this->media_source;
        }

        return \Storage::disk('public')->url($this->media_source);
    }

    /**
     * Caminho absoluto no disco para envio via Baileys (imagens locais).
     * URLs externas retornam null — devem ser baixadas no momento do envio.
     */
    public function getMediaAbsolutePath(): ?string
    {
        if (!$this->hasImage()) {
            return null;
        }

        if (str_starts_with($this->media_source, 'http://') || str_starts_with($this->media_source, 'https://')) {
            return null;
        }

        return \Storage::disk('public')->path($this->media_source);
    }

    /**
     * Formata texto no estilo WhatsApp para exibição em HTML (prévia).
     *
     * Ordem: escapa HTML, aplica formatação (*negrito*, _itálico_, ~riscado~,
     * ```monoespaçado```) e converte URLs em links clicáveis — como o
     * WhatsApp renderiza as mensagens.
     */
    public static function formatForPreview(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Monoespaçado primeiro (blocos ``` não devem receber negrito/itálico)
        $escaped = preg_replace('/```(.+?)```/s', '<code class="d-block p-1 bg-white bg-opacity-75 rounded">$1</code>', $escaped);
        $escaped = preg_replace('/\*(.+?)\*/s', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('/_(.+?)_/s', '<em>$1</em>', $escaped);
        $escaped = preg_replace('/~(.+?)~/s', '<del>$1</del>', $escaped);

        // Links: http/https viram âncoras clicáveis (o WhatsApp faz o mesmo)
        $escaped = preg_replace(
            '/(https?:\/\/[^\s<]+)/',
            '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-primary text-decoration-underline">$1</a>',
            $escaped
        );

        // Quebras de linha
        return nl2br($escaped);
    }
}
