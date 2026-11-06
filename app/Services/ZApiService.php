<?php

namespace App\Services;

use App\Models\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente da REST API da Z-API.
 *
 * A Z-API usa duas credenciais diferentes: instanceId + token identificam a
 * sessão; Client-Token protege a conta Z-API. O Client-Token pode vir das
 * configurações globais do administrador ou de settings da instância.
 */
class ZApiService
{
    private Client $client;
    private ?Settings $settings;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries = 3;
    private int $retryDelayMs = 500;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.zapi.base_url', 'https://api.z-api.io'), '/');
        $this->timeout = max(5, (int) config('services.zapi.timeout', 30));
        $this->settings = Cache::remember('zapi_settings', 300, fn () => Settings::first());

        $this->client = new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => max(3, (int) config('services.zapi.connect_timeout', 10)),
            'verify' => config('services.http.verify_ssl', true),
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'ToolZapp-ZAPI/2.0',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function sendTextMessage(
        string $instanceId,
        string $instanceToken,
        string $phone,
        string $message,
        array $options = [],
        ?string $clientToken = null
    ): array {
        return $this->request('POST', $this->instanceEndpoint($instanceId, $instanceToken, 'send-text'), [
            'phone' => $this->formatPhoneNumber($phone),
            'message' => $message,
            ...$options,
        ], $clientToken);
    }

    public function sendImageMessage(
        string $instanceId,
        string $instanceToken,
        string $phone,
        string $imageUrl,
        string $caption = '',
        array $options = [],
        ?string $clientToken = null
    ): array {
        $payload = [
            'phone' => $this->formatPhoneNumber($phone),
            'image' => $imageUrl,
        ];

        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        return $this->request('POST', $this->instanceEndpoint($instanceId, $instanceToken, 'send-image'), [
            ...$payload,
            ...$options,
        ], $clientToken);
    }

    public function sendAudioMessage(
        string $instanceId,
        string $instanceToken,
        string $phone,
        string $audioUrl,
        array $options = [],
        ?string $clientToken = null
    ): array {
        return $this->request('POST', $this->instanceEndpoint($instanceId, $instanceToken, 'send-audio'), [
            'phone' => $this->formatPhoneNumber($phone),
            'audio' => $audioUrl,
            ...$options,
        ], $clientToken);
    }

    public function sendDocumentMessage(
        string $instanceId,
        string $instanceToken,
        string $phone,
        string $documentUrl,
        string $filename,
        array $options = [],
        ?string $extension = null,
        ?string $clientToken = null
    ): array {
        $extension ??= pathinfo($filename, PATHINFO_EXTENSION);
        $extension = strtolower(ltrim((string) $extension, '.')) ?: 'pdf';

        return $this->request('POST', $this->instanceEndpoint($instanceId, $instanceToken, "send-document/{$extension}"), [
            'phone' => $this->formatPhoneNumber($phone),
            'document' => $documentUrl,
            'fileName' => $filename,
            ...$options,
        ], $clientToken);
    }

    public function sendTemplateMessage(
        string $instanceId,
        string $instanceToken,
        string $phone,
        string $templateName,
        array $parameters = [],
        array $options = [],
        ?string $clientToken = null
    ): array {
        return $this->request('POST', $this->instanceEndpoint($instanceId, $instanceToken, 'send-template'), [
            'phone' => $this->formatPhoneNumber($phone),
            'template' => $templateName,
            'parameters' => $parameters,
            ...$options,
        ], $clientToken);
    }

    public function checkInstanceStatus(string $instanceId, string $instanceToken, ?string $clientToken = null): array
    {
        return $this->request('GET', $this->instanceEndpoint($instanceId, $instanceToken, 'status'), [], $clientToken);
    }

    public function getQrCode(string $instanceId, string $instanceToken, ?string $clientToken = null): array
    {
        return $this->request('GET', $this->instanceEndpoint($instanceId, $instanceToken, 'qr-code/image'), [], $clientToken);
    }

    public function restartInstance(string $instanceId, string $instanceToken, ?string $clientToken = null): array
    {
        return $this->request('GET', $this->instanceEndpoint($instanceId, $instanceToken, 'restart'), [], $clientToken);
    }

    public function disconnectInstance(string $instanceId, string $instanceToken, ?string $clientToken = null): array
    {
        return $this->request('GET', $this->instanceEndpoint($instanceId, $instanceToken, 'disconnect'), [], $clientToken);
    }

    public function getInstanceInfo(string $instanceId, string $instanceToken, ?string $clientToken = null): array
    {
        return $this->request('GET', $this->instanceEndpoint($instanceId, $instanceToken, 'me'), [], $clientToken);
    }

    public function markAsRead(string $instanceId, string $instanceToken, string $messageId, ?string $clientToken = null): array
    {
        return $this->request('POST', $this->instanceEndpoint($instanceId, $instanceToken, 'read'), [
            'messageId' => $messageId,
        ], $clientToken);
    }

    /** Atualiza todos os webhooks da instância para a mesma URL. */
    public function setWebhook(string $instanceId, string $instanceToken, string $webhookUrl, ?string $clientToken = null): array
    {
        if (!str_starts_with(strtolower($webhookUrl), 'https://')) {
            return ['error' => true, 'message' => 'A Z-API exige uma URL HTTPS para o webhook.'];
        }

        return $this->request('PUT', $this->instanceEndpoint($instanceId, $instanceToken, 'update-every-webhooks'), [
            'value' => $webhookUrl,
        ], $clientToken);
    }

    public function updateWebhookEvent(
        string $instanceId,
        string $instanceToken,
        string $event,
        string $webhookUrl,
        ?string $clientToken = null
    ): array {
        $paths = [
            'delivery' => 'update-webhook-delivery',
            'received' => 'update-webhook-received',
            'received_by_me' => 'update-webhook-received-by-me',
            'disconnected' => 'update-webhook-disconnected',
            'status' => 'update-webhook-message-status',
            'connected' => 'update-webhook-connected',
        ];

        if (!isset($paths[$event])) {
            return ['error' => true, 'message' => "Evento de webhook inválido: {$event}"];
        }

        if (!str_starts_with(strtolower($webhookUrl), 'https://')) {
            return ['error' => true, 'message' => 'A Z-API exige uma URL HTTPS para o webhook.'];
        }

        return $this->request('PUT', $this->instanceEndpoint($instanceId, $instanceToken, $paths[$event]), [
            'value' => $webhookUrl,
        ], $clientToken);
    }

    public function debugConfig(?string $clientToken = null): array
    {
        $token = $this->clientToken($clientToken);

        return [
            'base_url' => $this->baseUrl,
            'has_token' => filled($token),
            'token_length' => strlen((string) $token),
            'settings_loaded' => $this->settings !== null,
        ];
    }

    public function clearSettingsCache(): void
    {
        Cache::forget('zapi_settings');
    }

    private function instanceEndpoint(string $instanceId, string $instanceToken, string $path): string
    {
        return "instances/{$instanceId}/token/{$instanceToken}/{$path}";
    }

    private function clientToken(?string $clientToken): ?string
    {
        return $clientToken
            ?: ($this->settings?->zapi_token ?: null)
            ?: (config('services.zapi.client_token') ?: null);
    }

    /** A Z-API documenta telefone sem @c.us; JIDs especiais são preservados. */
    private function formatPhoneNumber(string $phone): string
    {
        $phone = trim($phone);
        foreach (['@g.us', '@newsletter', '@c.us'] as $suffix) {
            if (str_ends_with($phone, $suffix)) {
                $value = preg_replace('/[^0-9]/', '', substr($phone, 0, -strlen($suffix)));
                return $value . $suffix;
            }
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits === '') {
            return '';
        }

        return str_starts_with($digits, '55') ? $digits : '55' . $digits;
    }

    private function request(
        string $method,
        string $endpoint,
        array $data = [],
        ?string $clientToken = null
    ): array {
        $token = $this->clientToken($clientToken);
        if (!$token) {
            return ['error' => true, 'message' => 'Client-Token da Z-API não configurado.'];
        }

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $isSafeToRetry = in_array(strtoupper($method), ['GET', 'PUT', 'DELETE'], true);
        $attempts = $isSafeToRetry ? $this->maxRetries : 1;
        $logEndpoint = preg_replace(
            '#(instances/[^/]+/token/)[^/]+#',
            '$1[REDACTED]',
            $endpoint
        ) ?: '[REDACTED]';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $options = [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Client-Token' => $token,
                    ],
                ];

                if ($data !== [] && strtoupper($method) !== 'GET') {
                    $options['json'] = $data;
                }

                $startedAt = microtime(true);
                $response = $this->client->request($method, $url, $options);
                $duration = (microtime(true) - $startedAt) * 1000;
                $body = (string) $response->getBody();
                $decoded = json_decode($body, true);
                $payload = is_array($decoded) ? $decoded : ($body !== '' ? ['value' => $body] : []);
                $status = $response->getStatusCode();

                Log::log($status >= 200 && $status < 300 ? 'info' : 'warning', 'Z-API request', [
                    'method' => $method,
                    'endpoint' => $logEndpoint,
                    'status_code' => $status,
                    'duration_ms' => round($duration, 2),
                    'payload_size' => strlen(json_encode($data)),
                ]);

                if ($status >= 200 && $status < 300) {
                    return $payload;
                }

                $payload['error'] = $payload['error'] ?? true;
                $payload['status_code'] = $status;
                $payload['message'] = $payload['message'] ?? ($body ?: "HTTP {$status}");

                if (!$isSafeToRetry || ($status !== 429 && $status < 500)) {
                    return $payload;
                }
            } catch (RequestException|Throwable $exception) {
                if ($attempt === $attempts) {
                    Log::error('Z-API request failed', [
                        'method' => $method,
                        'endpoint' => $logEndpoint,
                        'error' => $exception->getMessage(),
                    ]);

                    return [
                        'error' => true,
                        'message' => 'Falha na comunicação com a Z-API: ' . $exception->getMessage(),
                    ];
                }
            }

            usleep($this->retryDelayMs * 1000 * $attempt);
        }

        return ['error' => true, 'message' => 'Máximo de tentativas da Z-API excedido.'];
    }
}
