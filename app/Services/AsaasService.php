<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AsaasService
{
    protected $baseUrl;
    protected $apiKey;

    public function __construct()
    {
        $baseUrl = config('services.asaas.url');
        $this->apiKey = config('services.asaas.key');

        // Validar configurações essenciais
        if (empty($baseUrl)) {
            throw new \InvalidArgumentException('A URL base do Asaas não está configurada em config/services.php');
        }

        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('A API key do Asaas não está configurada em config/services.php');
        }

        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);
    }

    /**
     * Normalizar URL base para garantir formato correto
     */
    private function normalizeBaseUrl(?string $url): string
    {
        if (empty($url)) {
            throw new \InvalidArgumentException('A URL base do Asaas não está configurada.');
        }

        $url = trim($url);

        if (!Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'https://' . ltrim($url, '/');
        }

        return rtrim($url, '/');
    }

    public function getCustomer($custmerId)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'access_token' => $this->apiKey,
        ])->get("{$this->baseUrl}/customers/{$custmerId}");

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * Criar Cliente no ASAAS
     */
    public function createCustomer($data)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'access_token' => $this->apiKey,
        ])->post("{$this->baseUrl}/customers", $data);

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * Criar Pagamento (Boleto, Pix ou Cartão)
     */
    public function createPayment($data)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'access_token' => $this->apiKey,
        ])->post("{$this->baseUrl}/payments", $data);

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * 💳 Processa pagamento parcelado com Cartão de Crédito
     */
    public function cratePaymentCreditCard(array $data)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'access_token' => $this->apiKey,
        ])->post("{$this->baseUrl}/installments", $data);

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * Gera o QrCode do PIX
     */
    public function getPixQrCode($paymentId)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'access_token' => $this->apiKey,
        ])->get("{$this->baseUrl}/payments/{$paymentId}/pixQrCode");

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * 💳 Processa pagamento com Cartão de Crédito
     */
    public function payWithCreditCard($paymentId, array $data)
    {
        $baseUrl = "{$this->baseUrl}/payments/{$paymentId}/payWithCreditCard";
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'access_token' => $this->apiKey,
        ])->post($baseUrl, $data);

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * 💳 Processa pagamento via Pix para usuários
     */
    public function paymentWithPix(array $data)
    {
        $baseUrl = "{$this->baseUrl}/transfers";
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'access_token' => $this->apiKey,
        ])->post($baseUrl, $data);

        $response = $this->handleResponse($response);

        if (isset($response['error'])) {
            return $response;
        }

        return $response;
    }

    /**
     * Manipular a resposta da API.
     */
    protected function handleResponse($response)
    {
        if ($response->successful()) {
            return $response->json();
        }

        return [
            'error' => true,
            'status' => $response->status(),
            'message' => $response->json()['errors'][0]['description'] ?? 'Erro desconhecido.',
        ];
    }
}
