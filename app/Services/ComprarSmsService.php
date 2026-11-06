<?php

namespace App\Services;

use App\Contracts\SmsProvider;
use App\Helpers\PhoneHelper;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente isolado da API da ComprarSMS.
 *
 * A API documentada aceita JWT Bearer, lotes de até 100.000 destinatários,
 * templates previamente aprovados e retorna um batch_id para conciliação.
 */
class ComprarSmsService implements SmsProvider
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.comprar_sms.base_url'), '/');
    }

    public function key(): string
    {
        return 'comprarsms';
    }

    public function label(): string
    {
        return 'ComprarSMS';
    }

    public function isConfigured(): bool
    {
        return trim((string) config('services.comprar_sms.token')) !== ''
            || (trim((string) config('services.comprar_sms.email')) !== ''
                && (string) config('services.comprar_sms.password') !== '');
    }

    /** @param array<int, array{phone:string,message:string}> $recipients */
    public function sendBatch(array $recipients, ?string $templateName = null): array
    {
        if (empty($recipients)) {
            throw new ComprarSmsApiException('Nenhum destinatário foi informado para a ComprarSMS.');
        }

        if (count($recipients) > 100000) {
            throw new ComprarSmsApiException('A ComprarSMS aceita no máximo 100.000 destinatários por requisição.');
        }

        $payload = ['recipients' => array_values($recipients)];
        if (filled($templateName)) {
            $payload['template_name'] = trim($templateName);
        }

        return $this->request('POST', '/api-sms-send', $payload);
    }

    public function getBatchStatus(string $batchId): array
    {
        return $this->request('GET', '/api-sms-status', [], ['batch_id' => $batchId]);
    }

    public function extractEvents(array $payload): array
    {
        $events = data_get($payload, 'delivery.events', []);
        if (is_array($events) && $events !== []) {
            return array_values(array_filter(array_map(
                fn ($event): array => array_merge(
                    ['batch_id' => $payload['batch_id'] ?? null],
                    is_array($event) ? $event : []
                ),
                $events
            ), 'is_array'));
        }

        return $payload === [] ? [] : [$payload];
    }

    public function normalizeProviderPhone(string $phone): ?string
    {
        $normalized = PhoneHelper::normalize($phone);
        if (!$normalized['phone']) {
            return null;
        }

        $digits = $normalized['phone'];

        return PhoneHelper::isBrazilianLocalValid($digits) ? '+55' . $digits : '+' . $digits;
    }

    /**
     * Compara a assinatura documentada do webhook sem aceitar timestamp antigo
     * indefinidamente. Quando nenhum segredo foi configurado, rejeita o evento
     * para evitar que a rota pública atualize a fila sem autenticação.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $secret = (string) config('services.comprar_sms.webhook_secret');
        $timestamp = $this->headerValue($headers, 'x-comprarsms-timestamp');
        $signature = $this->headerValue($headers, 'x-comprarsms-signature');
        if ($secret === '' || !$timestamp || !$signature || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);

        return hash_equals($expected, trim($signature));
    }

    private function headerValue(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? $headers[strtolower($name)] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $value === null ? null : (string) $value;
    }

    private function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $response = $this->sendRequest($method, $path, $payload, $query, $this->token());

        // JWT obtido por login pode expirar. Limpa o cache e autentica uma vez
        // novamente; token fixo do painel nunca é substituído automaticamente.
        if ($response->status() === 401 && !filled(config('services.comprar_sms.token'))) {
            $this->forgetCachedToken();
            $response = $this->sendRequest($method, $path, $payload, $query, $this->token());
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];

        if (!$response->successful()) {
            $message = $data['error'] ?? $data['message'] ?? 'A ComprarSMS recusou a requisição.';
            $code = $data['code'] ?? 'HTTP_' . $response->status();
            throw new ComprarSmsApiException("{$message} ({$code})", $response->status(), $data);
        }

        return $data;
    }

    private function sendRequest(string $method, string $path, array $payload, array $query, string $token): Response
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout(max(5, (int) config('services.comprar_sms.timeout', 45)))
            ->connectTimeout(max(3, (int) config('services.comprar_sms.connect_timeout', 10)))
            ->withOptions(['verify' => config('services.http.verify_ssl', true)]);

        return strtoupper($method) === 'GET'
            ? $request->get($this->baseUrl . $path, $query)
            : $request->post($this->baseUrl . $path, $payload);
    }

    private function token(): string
    {
        $configured = trim((string) config('services.comprar_sms.token'));
        if ($configured !== '') {
            return $configured;
        }

        $email = trim((string) config('services.comprar_sms.email'));
        $password = (string) config('services.comprar_sms.password');
        if ($email === '' || $password === '') {
            throw new ComprarSmsApiException(
                'ComprarSMS não configurada: informe COMPRARSMS_API_TOKEN ou COMPRARSMS_API_EMAIL/COMPRARSMS_API_PASSWORD.'
            );
        }

        $cacheKey = 'comprarsms:jwt:' . sha1($email);

        return (string) Cache::remember($cacheKey, now()->addHours(23), function () use ($email, $password): string {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(max(5, (int) config('services.comprar_sms.timeout', 45)))
                ->connectTimeout(max(3, (int) config('services.comprar_sms.connect_timeout', 10)))
                ->withOptions(['verify' => config('services.http.verify_ssl', true)])
                ->post($this->baseUrl . '/api-authenticate', [
                    'email' => $email,
                    'password' => $password,
                ]);

            $data = $response->json();
            $data = is_array($data) ? $data : [];
            $token = $data['access_token'] ?? $data['token'] ?? null;

            if (!$response->successful() || !filled($token)) {
                $message = $data['error'] ?? $data['message'] ?? 'Não foi possível autenticar na ComprarSMS.';
                Log::warning('Falha na autenticação da ComprarSMS', [
                    'status' => $response->status(),
                    'message' => $message,
                ]);
                throw new ComprarSmsApiException($message, $response->status(), $data);
            }

            return (string) $token;
        });
    }

    private function forgetCachedToken(): void
    {
        $email = trim((string) config('services.comprar_sms.email'));
        if ($email !== '') {
            Cache::forget('comprarsms:jwt:' . sha1($email));
        }
    }
}
