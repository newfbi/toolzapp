<?php

namespace App\Http\Controllers\Knowledge\Services\AIProviders\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * Trait de Otimizações para AI Providers
 * Responsabilidade: Reduzir código duplicado e complexidade nos providers
 */
trait AIProviderOptimizations
{
    /**
     * Timestamp de início da operação
     */
    protected float $operationStartTime;

    /**
     * Inicia cronômetro da operação
     */
    protected function startOperation(): void
    {
        $this->operationStartTime = microtime(true);
    }

    /**
     * Obtém tempo decorrido da operação
     */
    protected function getOperationTime(): float
    {
        return isset($this->operationStartTime)
            ? microtime(true) - $this->operationStartTime
            : 0.0;
    }

    /**
     * Valida parâmetros comuns de requisição
     */
    protected function validateCommonParameters(array $params): array
    {
        return [
            'model' => $params['model'] ?? $this->getDefaultModel(),
            'temperature' => $this->validateTemperature($params['temperature'] ?? 0.7),
            'max_tokens' => $this->validateMaxTokens($params['max_tokens'] ?? 4000),
            'messages' => $this->validateMessages($params['messages'] ?? [])
        ];
    }

    /**
     * Valida temperatura
     */
    protected function validateTemperature(float $temperature): float
    {
        return min(max($temperature, 0.0), 2.0);
    }

    /**
     * Valida max tokens
     */
    protected function validateMaxTokens(int $maxTokens): int
    {
        return min(max($maxTokens, 1), 8000);
    }

    /**
     * Valida mensagens
     */
    protected function validateMessages(array $messages): array
    {
        if (empty($messages)) {
            throw new \InvalidArgumentException('Mensagens são obrigatórias');
        }

        return array_map(function ($message) {
            return [
                'role' => $message['role'] ?? 'user',
                'content' => trim($message['content'] ?? '')
            ];
        }, $messages);
    }

    /**
     * Constrói headers padrão para requisições HTTP
     */
    protected function buildHeaders(string $apiKey, array $additionalHeaders = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => 'Knowledge-System/1.0'
        ], $additionalHeaders);
    }

    /**
     * Faz requisição HTTP com retry automático usando cURL
     */
    protected function makeHttpRequest(string $url, array $data, array $headers, int $maxRetries = 3): array
    {
        $attempt = 0;
        $timeout = config('knowledge-performance.ai_providers.timeout', 25);

        while ($attempt < $maxRetries) {
            try {
                $ch = curl_init();

                // Configurar cURL
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => $this->formatHeadersForCurl($headers),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($response === false || !empty($error)) {
                    throw new \Exception("cURL Error: " . $error);
                }

                if ($httpCode >= 200 && $httpCode < 300) {
                    $jsonResponse = json_decode($response, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonResponse)) {
                        return $jsonResponse;
                    }
                    throw new \Exception("Resposta inválida: não é um JSON válido");
                }

                throw new \Exception("HTTP {$httpCode}: {$response}");
            } catch (\Exception $e) {
                $attempt++;

                if ($attempt >= $maxRetries) {
                    Log::error("Falha na requisição HTTP após {$maxRetries} tentativas", [
                        'url' => $url,
                        'error' => $e->getMessage(),
                        'provider' => static::class,
                        'attempt' => $attempt
                    ]);
                    throw $e;
                }

                // Backoff exponencial otimizado
                $delay = min(1000000 * pow(2, $attempt - 1), 5000000); // Max 5s
                usleep($delay);
            }
        }

        throw new \Exception("Falha inesperada na requisição HTTP após {$maxRetries} tentativas");
    }

    /**
     * Formata headers para uso com cURL
     */
    private function formatHeadersForCurl(array $headers): array
    {
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        return $curlHeaders;
    }

    /**
     * Processa resposta padrão da API
     */
    protected function processApiResponse(array $response): array
    {
        return [
            'response' => $this->extractResponseText($response),
            'tokens_used' => $this->extractTokenUsage($response),
            'model_used' => $this->extractModelUsed($response),
            'processing_time' => $this->getOperationTime()
        ];
    }

    /**
     * Extrai texto da resposta (implementação padrão)
     */
    protected function extractResponseText(array $response): string
    {
        // Tenta diferentes estruturas de resposta comuns
        $possiblePaths = [
            $response['choices'][0]['message']['content'] ?? null,
            $response['message']['content'] ?? null,
            $response['text'] ?? null,
            $response['content'] ?? null,
            $response['response'] ?? null
        ];

        foreach ($possiblePaths as $text) {
            if (!empty($text) && is_string($text)) {
                return trim($text);
            }
        }

        return '';
    }

    /**
     * Extrai uso de tokens (implementação padrão)
     */
    protected function extractTokenUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];

        return [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0)
        ];
    }

    /**
     * Extrai modelo usado (implementação padrão)
     */
    protected function extractModelUsed(array $response): string
    {
        return $response['model'] ?? $this->getDefaultModel();
    }

    /**
     * Registra métricas de performance
     */
    protected function recordMetrics(string $operation, array $metrics): void
    {
        if (config('knowledge-performance.monitoring.enable_metrics', true)) {
            Log::info("AI Provider Metrics", [
                'provider' => static::class,
                'operation' => $operation,
                'metrics' => $metrics,
                'timestamp' => now()->toISOString()
            ]);
        }
    }

    /**
     * Valida resposta da API
     */
    protected function validateApiResponse(array $response): bool
    {
        // Verifica se a resposta tem estrutura mínima esperada
        return !empty($response) && (
            isset($response['choices']) ||
            isset($response['message']) ||
            isset($response['text']) ||
            isset($response['content'])
        );
    }

    /**
     * Trata erros de API de forma padronizada
     */
    protected function handleApiError(\Exception $e, string $operation): array
    {
        $errorData = [
            'error' => true,
            'message' => $e->getMessage(),
            'operation' => $operation,
            'provider' => static::class,
            'processing_time' => $this->getOperationTime()
        ];

        Log::error('AI Provider Error', $errorData);

        return $errorData;
    }

    /**
     * Prepara dados para requisição com validações
     */
    protected function prepareRequestData(array $params): array
    {
        $validatedParams = $this->validateCommonParameters($params);

        // Remove mensagens vazias
        $validatedParams['messages'] = array_filter(
            $validatedParams['messages'],
            fn($msg) => !empty(trim($msg['content']))
        );

        if (empty($validatedParams['messages'])) {
            throw new \InvalidArgumentException('Nenhuma mensagem válida fornecida');
        }

        return $validatedParams;
    }

    /**
     * Método abstrato para obter modelo padrão
     */
    abstract protected function getDefaultModel(): string;
}
