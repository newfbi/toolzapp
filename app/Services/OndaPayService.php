<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OndaPayService
{
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;
    protected $accessToken;

    public function __construct()
    {
        $this->baseUrl = $this->normalizeBaseUrl(config('services.ondapay.url') ?? 'https://api.ondapay.app');
        $this->clientId = config('services.ondapay.client_id');
        $this->clientSecret = config('services.ondapay.client_secret');
        $this->accessToken = $this->getAccessToken();
    }

    /**
     * Obter Token de Acesso (Basic Auth)
     */
    private function getAccessToken()
    {
        try {
            if (empty($this->clientId) || empty($this->clientSecret)) {
                Log::error('ONDAPAY missing credentials', [
                    'client_id_empty' => empty($this->clientId),
                    'client_secret_empty' => empty($this->clientSecret),
                ]);
                return null;
            }

            $extractToken = function (array $data) {
                return $data['access_token']
                    ?? $data['token']
                    ?? ($data['data']['access_token'] ?? null)
                    ?? ($data['jwt'] ?? null);
            };

            // Tentativa 1: Headers + corpo vazio
            $r1 = Http::withHeaders([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/api/v1/login", []);
            $j1 = $this->handleResponse($r1);
            $t1 = is_array($j1) ? $extractToken($j1) : null;
            if ($t1) {
                return $t1;
            }

            // Tentativa 2: Headers + id/secret no body
            $r2 = Http::withHeaders([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/api/v1/login", [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);
            $j2 = $this->handleResponse($r2);
            $t2 = is_array($j2) ? $extractToken($j2) : null;
            if ($t2) {
                return $t2;
            }

            Log::error('ONDAPAY login failed', [
                'resp1' => $j1,
                'resp2' => $j2,
                'baseUrl' => $this->baseUrl,
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('ONDAPAY auth failed', [
                'message' => $e->getMessage(),
                'baseUrl' => $this->baseUrl,
            ]);
            return null;
        }
    }

    /**
     * Gerar depósito PIX (QR Code) conforme /api/v1/deposit/pix
     */
    public function generatePixQrCode($data)
    {
        if (!$this->accessToken) {
            return [
                'error' => true,
                'message' => 'Token de acesso não disponível'
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/api/v1/deposit/pix", $data);

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * Fazer um Pagamento PIX
     */
    public function makePixPayment($data)
    {
        if (!$this->accessToken) {
            return [
                'error' => true,
                'message' => 'Token de acesso não disponível'
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/v2/pix/payment", $data);

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * Verificar Status do Pagamento
     */
    public function getPaymentStatus($externalId)
    {
        if (!$this->accessToken) {
            return [
                'error' => true,
                'message' => 'Token de acesso não disponível'
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
        ])->get("{$this->baseUrl}/v2/pix/status/{$externalId}");

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * Webhook para verificar status do pagamento
     */
    public function verifyWebhook($payload, $signature)
    {
        $expectedSignature = hash_hmac('sha256', $payload, config('services.ondapay.webhook_secret'));

        if (!hash_equals($expectedSignature, $signature)) {
            return [
                'error' => true,
                'message' => 'Assinatura inválida'
            ];
        }

        return json_decode($payload, true);
    }

    /**
     * Manipular a resposta da API.
     */
    protected function handleResponse($response)
    {
        if ($response->successful()) {
            return $response->json();
        }

        $errorData = $response->json();

        Log::error("OndaPay Error.", ['log' => $errorData]);

        return [
            'error' => true,
            'status' => $response->status(),
            'message' => $errorData['message'] ?? $errorData['error'] ?? 'Erro desconhecido no ondapay.',
            'details' => $errorData['details'] ?? null,
        ];
    }

    /**
     * Formatar dados do QR Code PIX para o ondapay
     */
    public function formatQrCodeData($amount, $externalId, $description, array $payer, $webhook = null, $dueDate = null)
    {
        if (empty($payer['name']) || empty($payer['document'])) {
            throw new \InvalidArgumentException('Os dados do pagador (payer) são obrigatórios para gerar o QR Code.');
        }

        $defaultWebhook = config('services.ondapay.postback_url') ?? url('/webhook/ondapay');
        $due = $dueDate ?: now()->addHours(1)->format('Y-m-d H:i:s');

        $payload = [
            'amount' => is_null($amount) ? null : floatval($amount),
            'external_id' => $externalId,
            'webhook' => $webhook ?? $defaultWebhook,
            'description' => $description,
            'payer' => array_filter([
                'document' => $payer['document'] ?? null,
                'email' => $payer['email'] ?? null,
                'name' => $payer['name'] ?? null,
            ]),
            'dueDate' => $due,
        ];

        return $payload;
    }

    /**
     * Formatar dados do pagamento PIX para o ondapay
     */
    public function formatPaymentData($amount, $externalId, $description, $webhook = null, $dueDate = null, array $payer = [])
    {
        $defaultWebhook = config('services.ondapay.postback_url') ?? url('/webhook/ondapay');

        return [
            'amount' => is_null($amount) ? null : floatval($amount),
            'external_id' => $externalId,
            'webhook' => $webhook ?? $defaultWebhook,
            'description' => $description,
            'payer' => array_filter([
                'document' => $payer['document'] ?? null,
                'email' => $payer['email'] ?? null,
                'name' => $payer['name'] ?? null,
            ]),
            'dueDate' => $dueDate,
        ];
    }

    private function normalizeBaseUrl(?string $url): string
    {
        if (empty($url)) {
            throw new \InvalidArgumentException('A URL base do ondapay não está configurada.');
        }

        $url = trim($url);

        if (!Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'https://' . ltrim($url, '/');
        }

        // Corrigir domínio legado .com.br para .app, se configurado incorretamente
        $url = str_replace('api.ondapay.com.br', 'api.ondapay.app', $url);

        return rtrim($url, '/');
    }
}
