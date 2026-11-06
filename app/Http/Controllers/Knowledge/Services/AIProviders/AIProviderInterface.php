<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders;

use App\Models\Agents;

/**
 * Interface para provedores de IA
 *
 * Define o contrato que todos os provedores de IA devem implementar
 */
interface AIProviderInterface
{
    /**
     * Gera uma resposta usando o provedor de IA
     *
     * @param array $messages Array de mensagens no formato [['role' => 'user', 'content' => 'texto']]
     * @param Agents $agent Agente configurado
     * @param array $options Opções adicionais (temperature, max_completion_tokens, etc.)
     * @return array ['message' => string, 'tokens' => int, 'model' => string]
     */
    public function getCompletion(array $messages, Agents $agent, array $options = []): array;

    /**
     * Transcreve áudio para texto
     *
     * @param string $audioPathOrUrl Caminho local ou URL do arquivo de áudio
     * @param Agents $agent Agente configurado
     * @return string|null Texto transcrito ou null se não suportado
     */
    public function transcribeAudio(string $audioPathOrUrl, Agents $agent): ?string;

    /**
     * Verifica se o provedor suporta processamento de texto
     *
     * @return bool
     */
    public function supportsText(): bool;

    /**
     * Verifica se o provedor suporta transcrição de áudio
     *
     * @return bool
     */
    public function supportsAudio(): bool;

    /**
     * Verifica se o provedor suporta análise de imagens
     *
     * @return bool
     */
    public function supportsImages(): bool;

    /**
     * Verifica se o provedor suporta processamento de documentos
     *
     * @return bool
     */
    public function supportsDocuments(): bool;

    /**
     * Retorna as capacidades do provedor
     *
     * @return array
     */
    public function getCapabilities(): array;

    /**
     * Retorna os modelos disponíveis do provedor
     *
     * @return array ['model_key' => 'Model Name']
     */
    public function getModels(): array;

    /**
     * Valida se um modelo é suportado pelo provedor
     *
     * @param string $model Nome do modelo
     * @return bool
     */
    public function validateModel(string $model): bool;
}
