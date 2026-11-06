<?php

namespace App\Models;

use App\Support\AiModelCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Platform extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'models',
        'status',
        'api_base_url',
        'api_key_required',
        'rate_limits',
        'supported_features',
        'description',
        'is_active',
        'logo_url',
        'pricing',
        'documentation_url',
        'priority',
    ];

    protected $casts = [
        'models' => 'array',
        'api_key_required' => 'boolean',
        'rate_limits' => 'array',
        'supported_features' => 'array',
        'is_active' => 'boolean',
        'pricing' => 'array',
    ];

    /**
     * O banco pode conter um catálogo histórico da OpenAI. Para evitar que
     * qualquer leitura reutilize esses IDs, o catálogo vigente é retornado
     * sempre que a plataforma for OpenAI.
     */
    public function getModelsAttribute($value): array
    {
        if (($this->attributes['slug'] ?? null) === 'openai') {
            return AiModelCatalog::all();
        }

        return $this->castAttribute('models', $value) ?? [];
    }

    /**
     * Scope para plataformas ativas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para plataformas com chave de API obrigatória
     */
    public function scopeRequiresApiKey($query)
    {
        return $query->where('api_key_required', true);
    }

    /**
     * Verifica se a plataforma suporta um determinado recurso
     */
    public function supportsFeature(string $feature): bool
    {
        $features = $this->supported_features ?? [];
        return in_array($feature, $features);
    }

    /**
     * Obtém os modelos disponíveis formatados
     */
    public function getFormattedModelsAttribute(): array
    {
        if ($this->slug === 'openai') {
            return array_map(
                static fn (string $name, string $id): array => [
                    'id' => $id,
                    'name' => $id,
                    'description' => $name,
                ],
                AiModelCatalog::all(),
                AiModelCatalog::keys()
            );
        }

        $models = $this->models ?? [];
        $formatted = [];

        foreach ($models as $key => $description) {
            $formatted[] = [
                'id' => $key,
                'name' => $key,
                'description' => $description
            ];
        }

        return $formatted;
    }

    /**
     * Verifica se um modelo específico está disponível
     */
    public function hasModel(string $modelName): bool
    {
        if ($this->slug === 'openai') {
            return AiModelCatalog::isAllowed($modelName);
        }

        $models = $this->models ?? [];
        return isset($models[$modelName]);
    }

    /**
     * Obtém a descrição de um modelo específico
     */
    public function getModelDescription(string $modelName): ?string
    {
        if ($this->slug === 'openai') {
            $models = AiModelCatalog::all();

            return $models[$modelName] ?? null;
        }

        $models = $this->models ?? [];
        return $models[$modelName] ?? null;
    }

    /**
     * Scope para ordenar por prioridade
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'asc')->orderBy('name', 'asc');
    }

    /**
     * Relacionamento com UserApi
     */
    public function userApis()
    {
        return $this->hasMany(UserApi::class, 'platform_slug', 'slug');
    }
}
