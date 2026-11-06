<?php

namespace App\Services;

use App\Contracts\SmsProvider;
use App\Helpers\PhoneHelper;
use App\Services\Sms\SmsProviderException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adaptador da API oficial de SMS da Infobip.
 *
 * O envio é feito pelo endpoint SMS API v3. Cada fila da aplicação mantém o
 * bulkId retornado pela Infobip e concilia os DLRs por telefone/messageId.
 * O POST não é repetido automaticamente: em um timeout a requisição pode ter
 * sido aceita pelo provedor e repetir poderia duplicar a mensagem.
 */
class InfobipSmsService implements SmsProvider
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.infobip.base_url'), '/');
    }

    public function key(): string
    {
        return 'infobip';
    }

    public function label(): string
    {
        return 'Infobip SMS';
    }

    public function isConfigured(): bool
    {
        return trim((string) config('services.infobip.api_key')) !== ''
            && trim((string) config('services.infobip.sender')) !== ''
            && $this->baseUrl !== '';
    }

    /** @param array<int, array{phone:string,message:string}> $recipients */
    public function sendBatch(array $recipients, ?string $templateName = null): array
    {
        if ($recipients === []) {
            throw new SmsProviderException('Nenhum destinatário foi informado para a Infobip.');
        }

        $max = max(1, (int) config('services.infobip.max_destinations_per_request', 50000));
        if (count($recipients) > $max) {
            throw new SmsProviderException("A Infobip aceita até {$max} destinos por requisição.");
        }

        $messages = [];
        foreach (array_values($recipients) as $recipient) {
            $phone = $this->normalizeProviderPhone((string) ($recipient['phone'] ?? ''));
            $message = trim((string) ($recipient['message'] ?? ''));

            if (!$phone || $message === '') {
                throw new SmsProviderException('A Infobip recebeu um telefone ou conteúdo vazio.');
            }

            $messagePayload = [
                'sender' => (string) config('services.infobip.sender'),
                'destinations' => [['to' => $phone]],
                'content' => ['text' => $message],
            ];

            if (filled($templateName)) {
                $messagePayload['campaignReferenceId'] = mb_substr(trim($templateName), 0, 100);
            }

            $messages[] = $messagePayload;
        }

        $payload = ['messages' => $messages];

        $response = $this->request('POST', '/sms/3/messages', $payload);
        $bulkId = (string) ($response['bulkId'] ?? '');
        if ($bulkId === '') {
            throw new SmsProviderException('A Infobip não retornou bulkId; o lote não pode ser conciliado.');
        }

        $providerMessages = [];
        foreach ((array) ($response['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $status = $this->canonicalStatus(data_get($message, 'status.groupName'));
            $providerMessages[] = [
                'phone' => (string) ($message['to'] ?? ''),
                'provider_message_id' => $message['messageId'] ?? null,
                'status' => $status,
                'provider_status' => data_get($message, 'status.name')
                    ?: data_get($message, 'status.groupName'),
                'error' => data_get($message, 'status.description'),
                'sms_count' => $message['smsCount'] ?? $message['messageCount'] ?? null,
            ];
        }

        return [
            'batch_id' => $bulkId,
            'status' => 'accepted',
            'messages' => $providerMessages,
            'total_parts' => collect($providerMessages)->sum(fn (array $message): int => (int) ($message['sms_count'] ?? 0)),
            'cost' => null,
        ];
    }

    public function getBatchStatus(string $batchId): array
    {
        return $this->request('GET', '/sms/3/reports', [], ['bulkId' => $batchId], true);
    }

    /** @return array<int, array<string, mixed>> */
    public function extractEvents(array $payload): array
    {
        $results = $payload['results'] ?? [];
        if (!is_array($results)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($result): ?array {
            if (!is_array($result) || !isset($result['to'])) {
                return null;
            }

            $statusGroup = data_get($result, 'status.groupName')
                ?: data_get($result, 'status.name')
                ?: 'PENDING';

            return [
                'batch_id' => $result['bulkId'] ?? null,
                'phone' => (string) $result['to'],
                'provider_message_id' => $result['messageId'] ?? null,
                'status' => $this->canonicalStatus($statusGroup),
                'provider_status' => data_get($result, 'status.name') ?: $statusGroup,
                'error' => data_get($result, 'error.description')
                    ?: data_get($result, 'status.description'),
                'price_per_message' => data_get($result, 'price.pricePerMessage'),
                'currency' => data_get($result, 'price.currency'),
                'sms_count' => $result['messageCount'] ?? $result['smsCount'] ?? null,
            ];
        }, $results), static fn ($event): bool => is_array($event)));
    }

    public function normalizeProviderPhone(string $phone): ?string
    {
        $normalized = PhoneHelper::normalize($phone);
        if ($normalized['phone']) {
            $digits = (string) $normalized['phone'];

            return PhoneHelper::isBrazilianLocalValid($digits) ? '55' . $digits : $digits;
        }

        // Para SMS internacional, também aceitamos E.164 sem o sinal de +,
        // formato usado pela própria Infobip nos retornos de status.
        $digits = preg_replace('/[^0-9]/', '', $phone) ?: '';
        $digits = ltrim($digits, '0');
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    /**
     * A assinatura é configurada no Infobip Subscriptions Management como
     * HMAC_SHA_256. O header padrão é X-Hub-Signature. Sem segredo local, o
     * endpoint permanece fechado e a fila usa polling como fallback.
     *
     * @param array<string, mixed> $headers
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $secret = trim((string) config('services.infobip.webhook_secret'));
        $signature = $this->headerValue($headers, 'x-hub-signature');
        if ($secret === '' || $signature === '') {
            return false;
        }

        $signature = preg_replace('/^sha256=/i', '', trim($signature)) ?: '';
        $expectedHex = hash_hmac('sha256', $rawPayload, $secret);
        $expectedBase64 = base64_encode(hash_hmac('sha256', $rawPayload, $secret, true));

        return hash_equals($expectedHex, $signature) || hash_equals($expectedBase64, $signature);
    }

    private function canonicalStatus(?string $status): string
    {
        $status = strtoupper((string) $status);

        return match (true) {
            str_contains($status, 'DELIVER') => 'delivered',
            str_contains($status, 'REJECT'),
            str_contains($status, 'FAIL'),
            str_contains($status, 'UNDELIVER'),
            str_contains($status, 'EXPIRED'),
            str_contains($status, 'BLOCK') => 'failed',
            default => 'accepted',
        };
    }

    /** @param array<string, mixed> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? $headers[strtolower($name)] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $value === null ? null : (string) $value;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $query */
    private function request(
        string $method,
        string $path,
        array $payload = [],
        array $query = [],
        bool $retryRead = false,
    ): array {
        if (!$this->isConfigured()) {
            throw new SmsProviderException('Infobip não configurada: informe INFOBIP_API_KEY e INFOBIP_SENDER.');
        }

        $request = Http::acceptJson()
            ->asJson()
            ->withHeaders(['Authorization' => 'App ' . config('services.infobip.api_key')])
            ->timeout(max(5, (int) config('services.infobip.timeout', 45)))
            ->connectTimeout(max(3, (int) config('services.infobip.connect_timeout', 10)))
            ->withOptions(['verify' => config('services.http.verify_ssl', true)]);

        // Apenas leituras podem ser repetidas automaticamente. Repetir um
        // POST após timeout pode gerar SMS duplicado se a Infobip já o aceitou.
        if ($retryRead) {
            $request = $request->retry(3, function (int $attempt): int {
                return [1000, 2500, 5000][$attempt - 1] ?? 5000;
            }, function ($exception, $request): bool {
                $status = $exception instanceof RequestException
                    ? $exception->response?->status()
                    : null;

                return $status === 429 || ($status !== null && $status >= 500);
            });
        }

        $response = strtoupper($method) === 'GET'
            ? $request->get($this->baseUrl . $path, $query)
            : $request->post($this->baseUrl . $path, $payload);

        $data = $response->json();
        $data = is_array($data) ? $data : [];

        if (!$response->successful()) {
            $message = $data['description']
                ?? $data['message']
                ?? data_get($data, 'requestError.serviceException.text')
                ?? 'A Infobip recusou a requisição.';
            $code = $response->status();

            Log::warning('Infobip recusou requisição SMS', [
                'method' => strtoupper($method),
                'path' => $path,
                'status' => $code,
                'message' => $message,
            ]);

            throw new SmsProviderException("{$message} (HTTP_{$code})", $code);
        }

        return $data;
    }
}
