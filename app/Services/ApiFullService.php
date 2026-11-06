<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Models\Settings;
use Exception;

class ApiFullService
{
    private $client;
    private $settings;
    private $baseUrl = 'https://api-full.com';
    private $timeout = 30;
    private $maxRetries = 3;
    private $retryDelay = 1000; // milliseconds

    public function __construct()
    {
        $this->initializeClient();
        $this->settings = $this->getSettings();

        if (!empty($this->settings->apifull_base_url)) {
            $this->baseUrl = rtrim($this->settings->apifull_base_url, '/');
        }
    }

    private function initializeClient()
    {
        $this->client = new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
            'verify' => config('services.http.verify_ssl', true),
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'ToolZapp-ApiFull/1.0',
                'Accept' => 'application/json',
            ]
        ]);
    }

    private function getSettings()
    {
        return Cache::remember('apifull_settings', 300, function () {
            return Settings::first();
        });
    }

    public function sendTextMessage(string $phone, string $message, array $options = []): array
    {
        $payload = [
            'phone' => $this->formatPhoneNumber($phone),
            'message' => $message,
        ] + $options;

        return $this->makeRequest('POST', 'whatsapp/message/text', $payload);
    }

    public function sendImageMessage(string $phone, string $imageUrl, string $caption = '', array $options = []): array
    {
        $payload = [
            'phone' => $this->formatPhoneNumber($phone),
            'image' => $imageUrl,
            'caption' => $caption,
        ] + $options;

        return $this->makeRequest('POST', 'whatsapp/message/image', $payload);
    }

    public function sendDocumentMessage(string $phone, string $documentUrl, string $fileName, ?string $extension = null, array $options = []): array
    {
        $detectedExtension = $extension
            ?: pathinfo($fileName, PATHINFO_EXTENSION)
            ?: pathinfo(parse_url($documentUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);

        $normalizedExtension = $detectedExtension ? strtolower(ltrim($detectedExtension, '.')) : 'pdf';

        $payload = [
            'phone' => $this->formatPhoneNumber($phone),
            'document' => $documentUrl,
            'fileName' => $fileName,
            'extension' => $normalizedExtension,
        ] + $options;

        return $this->makeRequest('POST', 'whatsapp/message/document', $payload);
    }

    public function sendAudioMessage(string $phone, string $audioUrl, array $options = []): array
    {
        $payload = [
            'phone' => $this->formatPhoneNumber($phone),
            'audio' => $audioUrl,
        ] + $options;

        return $this->makeRequest('POST', 'whatsapp/message/audio', $payload);
    }

    public function sendTemplateMessage(string $phone, string $templateName, array $parameters = [], array $options = []): array
    {
        $payload = [
            'phone' => $this->formatPhoneNumber($phone),
            'template' => $templateName,
            'parameters' => $parameters,
        ] + $options;

        return $this->makeRequest('POST', 'whatsapp/message/template', $payload);
    }

    public function markAsRead(string $messageId): array
    {
        return $this->makeRequest('POST', 'whatsapp/message/read', [
            'messageId' => $messageId,
        ]);
    }

    public function checkInstanceStatus(string $instanceId): array
    {
        return $this->makeRequest('GET', "whatsapp/instance/{$instanceId}/status");
    }

    public function getQrCode(string $instanceId): array
    {
        return $this->makeRequest('GET', "whatsapp/instance/{$instanceId}/qrcode");
    }

    public function restartInstance(string $instanceId): array
    {
        return $this->makeRequest('POST', "whatsapp/instance/{$instanceId}/restart");
    }

    public function disconnectInstance(string $instanceId): array
    {
        return $this->makeRequest('POST', "whatsapp/instance/{$instanceId}/disconnect");
    }

    public function setWebhook(string $instanceId, string $webhookUrl, bool $enabled = true): array
    {
        $payload = [
            'url' => $webhookUrl,
            'enabled' => $enabled,
        ];
        return $this->makeRequest('POST', "whatsapp/instance/{$instanceId}/webhook", $payload);
    }

    public function getWebhook(string $instanceId): array
    {
        return $this->makeRequest('GET', "whatsapp/instance/{$instanceId}/webhook");
    }

    public function updateWebhook(string $instanceId, string $webhookUrl, bool $enabled = true): array
    {
        $payload = [
            'url' => $webhookUrl,
            'enabled' => $enabled,
        ];
        return $this->makeRequest('PUT', "whatsapp/instance/{$instanceId}/webhook", $payload);
    }

    public function deleteWebhook(string $instanceId): array
    {
        return $this->makeRequest('DELETE', "whatsapp/instance/{$instanceId}/webhook");
    }

    public function testConnection(): array
    {
        try {
            $response = $this->client->get($this->buildUrl('status'), [
                'timeout' => 10,
                'headers' => $this->authHeaders()
            ]);

            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->buildUrl($endpoint);
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            try {
                $startTime = microtime(true);

                $options = [
                    'headers' => $this->authHeaders(),
                ];

                if ($method !== 'GET') {
                    $options['json'] = $data;
                }

                $response = $this->client->request($method, $url, $options);

                $duration = (microtime(true) - $startTime) * 1000;
                $responseBody = $response->getBody()->getContents();
                $responseData = json_decode($responseBody, true);

                $this->logRequest($method, $endpoint, $data, $response->getStatusCode(), $duration);

                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    return $responseData ?: [];
                }

                if ($responseData && is_array($responseData)) {
                    return $responseData;
                }

                if ($response->getStatusCode() !== 429) {
                    $attempt++;
                    if ($attempt < $this->maxRetries) {
                        usleep($this->retryDelay * 1000 * $attempt);
                        continue;
                    }
                }

                return [
                    'error' => true,
                    'status_code' => $response->getStatusCode(),
                    'message' => $responseBody
                ];
            } catch (RequestException $e) {
                $attempt++;

                if ($attempt >= $this->maxRetries) {
                    Log::error('ApiFull Request failed after retries', [
                        'method' => $method,
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                        'attempts' => $attempt
                    ]);

                    return [
                        'error' => true,
                        'message' => 'Falha na comunicação com ApiFull: ' . $e->getMessage()
                    ];
                }

                usleep($this->retryDelay * 1000 * pow(2, $attempt - 1));
            }
        }

        return ['error' => true, 'message' => 'Máximo de tentativas excedido'];
    }

    private function buildUrl(string $endpoint): string
    {
        return $this->baseUrl . '/' . ltrim($endpoint, '/');
    }

    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (!str_starts_with($phone, '55')) {
            $phone = '55' . $phone;
        }
        return $phone; // ApiFull usa formatos variados; manter limpo sem sufixo
    }

    private function authHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->settings->apifull_token)) {
            $headers['Authorization'] = 'Bearer ' . $this->settings->apifull_token;
        }

        return $headers;
    }

    private function logRequest(string $method, string $endpoint, array $data, int $statusCode, float $duration): void
    {
        $logData = [
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'duration_ms' => round($duration, 2),
            'data_size' => strlen(json_encode($data))
        ];

        if ($statusCode >= 200 && $statusCode < 300) {
            Log::info('ApiFull Request Success', $logData);
        } else {
            Log::warning('ApiFull Request Warning', $logData);
        }

        $this->updatePerformanceMetrics($duration, $statusCode);
    }

    private function updatePerformanceMetrics(float $duration, int $statusCode): void
    {
        $key = 'apifull_performance_' . date('Y-m-d-H');

        $metrics = Cache::get($key, [
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'avg_response_time' => 0,
            'total_response_time' => 0
        ]);

        $metrics['total_requests']++;
        $metrics['total_response_time'] += $duration;
        $metrics['avg_response_time'] = $metrics['total_response_time'] / $metrics['total_requests'];

        if ($statusCode >= 200 && $statusCode < 300) {
            $metrics['successful_requests']++;
        } else {
            $metrics['failed_requests']++;
        }

        Cache::put($key, $metrics, 3600);
    }

    public function getPerformanceMetrics(): array
    {
        $key = 'apifull_performance_' . date('Y-m-d-H');
        return Cache::get($key, []);
    }

    public function clearSettingsCache(): void
    {
        Cache::forget('apifull_settings');
    }
}
