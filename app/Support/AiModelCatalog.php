<?php

namespace App\Support;

final class AiModelCatalog
{
    /**
     * IDs oficiais permitidos para conversações.
     *
     * Este catálogo é a fonte de verdade da aplicação. A configuração pode
     * apenas alterar os rótulos, nunca reintroduzir modelos legados.
     */
    private const ALLOWED_MODELS = [
        'gpt-6.1-sol' => 'GPT-6.1 Sol',
        'gpt-6-luna' => 'GPT-6 Luna',
    ];

    /**
     * Retorna os modelos que podem ser selecionados em conversações.
     */
    public static function all(): array
    {
        $configuredLabels = config('ai.models', []);
        $models = [];

        foreach (self::ALLOWED_MODELS as $model => $defaultLabel) {
            $models[$model] = is_string($configuredLabels[$model] ?? null)
                ? $configuredLabels[$model]
                : $defaultLabel;
        }

        return $models;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function default(): string
    {
        $configuredDefault = config('ai.default_model');

        return self::isAllowed($configuredDefault)
            ? self::normalize($configuredDefault)
            : 'gpt-6.1-sol';
    }

    public static function isAllowed(?string $model): bool
    {
        if (!is_string($model) || trim($model) === '') {
            return false;
        }

        return in_array(strtolower(trim($model)), array_map('strtolower', self::keys()), true);
    }

    /**
     * Converte valores legados ou inválidos para o modelo padrão.
     */
    public static function normalize(?string $model): string
    {
        if (!is_string($model) || trim($model) === '') {
            return self::default();
        }

        $normalized = strtolower(trim($model));
        foreach (self::keys() as $allowedModel) {
            if (strtolower($allowedModel) === $normalized) {
                return $allowedModel;
            }
        }

        return self::default();
    }

    /**
     * Formato usado pelos selects de modelo do Livewire.
     */
    public static function options(): array
    {
        return array_map(
            static fn (string $name, string $id): array => [
                'id' => $id,
                'name' => $name,
                'description' => $name,
                'platform' => 'OpenAI',
                'platform_slug' => 'openai',
            ],
            self::all(),
            self::keys()
        );
    }
}
