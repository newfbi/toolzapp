<?php

namespace App\Services;

use App\Models\WhatsappInstance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolve o provedor ativo de uma instância sem misturar Baileys e Z-API.
 * A instância continua sendo o vínculo único com o agente; provider define
 * qual transporte pode ler status e enviar mensagens.
 */
class WhatsappProviderService
{
    public function __construct(private readonly ZApiService $zApi)
    {
    }

    public function isZapi(WhatsappInstance $instance): bool
    {
        return strtolower((string) $instance->provider) === 'zapi';
    }

    public function credentials(WhatsappInstance $instance): array
    {
        $settings = $instance->providerSettings();

        return [
            'instance_id' => (string) ($settings['zapi_instance_id'] ?? ($this->isZapi($instance) ? $instance->instance_id : '')),
            'instance_token' => (string) ($settings['zapi_instance_token'] ?? ($this->isZapi($instance) ? $instance->instance_token : '')),
            'client_token' => $settings['zapi_client_token'] ?? null,
        ];
    }

    public function isConnected(WhatsappInstance $instance, bool $sync = true): bool
    {
        if ($this->isZapi($instance)) {
            return $this->syncZapiStatus($instance, $sync);
        }

        try {
            $response = $this->internalRequest()
                ->get(rtrim((string) config('services.whatsapp.internal_url'), '/') . "/instance-status/{$instance->agent_id}");

            return $response->ok() && $response->json('connected') === true;
        } catch (\Throwable $e) {
            Log::warning('Falha ao verificar instância Baileys', [
                'agent_id' => $instance->agent_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function syncZapiStatus(WhatsappInstance $instance, bool $sync = true): bool
    {
        if (!$this->isZapi($instance)) {
            return $instance->isConnected();
        }

        $credentials = $this->credentials($instance);
        if ($credentials['instance_id'] === '' || $credentials['instance_token'] === '') {
            if ($sync) {
                $instance->update([
                    'status' => 'error',
                    'error_message' => 'Credenciais da instância Z-API não configuradas.',
                    'last_activity' => now(),
                ]);
            }
            return false;
        }

        $status = $this->zApi->checkInstanceStatus(
            $credentials['instance_id'],
            $credentials['instance_token'],
            $credentials['client_token']
        );

        // A Z-API pode responder HTTP 200 com connected=false e
        // error="You are not connected." enquanto aguarda a leitura do QR.
        // Isso é um estado válido da instância, não uma falha de consulta.
        $hasConnectionState = array_key_exists('connected', $status);
        if (!$hasConnectionState && !empty($status['error'])) {
            if ($sync) {
                // Falhas de rede, timeout e indisponibilidade temporária não
                // significam que a sessão foi encerrada. Mantemos o último
                // status para não transformar uma consulta inconclusiva em
                // uma desconexão falsa no painel e na fila.
                $instance->update([
                    'error_message' => mb_substr((string) ($status['message'] ?? 'Falha ao consultar a Z-API.'), 0, 500),
                    'last_activity' => now(),
                ]);
            }
            return false;
        }

        $connected = filter_var($status['connected'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($sync) {
            $nextStatus = $connected
                ? 'connected'
                : (filled($instance->qr_code) ? 'connecting' : 'disconnected');

            $instance->update([
                'status' => $nextStatus,
                'error_message' => null,
                'connected_at' => $connected ? ($instance->connected_at ?: now()) : null,
                'last_activity' => now(),
            ]);
        }

        return $connected;
    }

    public function sendText(WhatsappInstance $instance, string $phone, string $message, array $options = []): array
    {
        if (!$this->isZapi($instance)) {
            return ['success' => false, 'error' => 'A instância ativa não usa Z-API.'];
        }

        $credentials = $this->credentials($instance);
        $result = $this->zApi->sendTextMessage(
            $credentials['instance_id'],
            $credentials['instance_token'],
            $phone,
            $message,
            $options,
            $credentials['client_token']
        );

        return $this->normalizeSendResult($result);
    }

    public function sendImage(WhatsappInstance $instance, string $phone, string $image, string $caption = '', array $options = []): array
    {
        if (!$this->isZapi($instance)) {
            return ['success' => false, 'error' => 'A instância ativa não usa Z-API.'];
        }

        $credentials = $this->credentials($instance);
        $result = $this->zApi->sendImageMessage(
            $credentials['instance_id'],
            $credentials['instance_token'],
            $phone,
            $image,
            $caption,
            $options,
            $credentials['client_token']
        );

        return $this->normalizeSendResult($result);
    }

    public function sendDocument(WhatsappInstance $instance, string $phone, string $url, string $filename, array $options = []): array
    {
        if (!$this->isZapi($instance)) {
            return ['success' => false, 'error' => 'A instância ativa não usa Z-API.'];
        }

        $credentials = $this->credentials($instance);
        $result = $this->zApi->sendDocumentMessage(
            $credentials['instance_id'],
            $credentials['instance_token'],
            $phone,
            $url,
            $filename,
            $options,
            null,
            $credentials['client_token']
        );

        return $this->normalizeSendResult($result);
    }

    public function getQrCode(WhatsappInstance $instance): array
    {
        $credentials = $this->credentials($instance);
        $result = $this->zApi->getQrCode(
            $credentials['instance_id'],
            $credentials['instance_token'],
            $credentials['client_token']
        );

        if (!empty($result['error'])) {
            return $result;
        }

        $qr = $result['value'] ?? $result['qr'] ?? $result['base64'] ?? $result['image'] ?? null;
        if (is_array($qr)) {
            $qr = $qr['value'] ?? $qr['qr'] ?? $qr['base64'] ?? null;
        }

        return $qr ? ['success' => true, 'qr' => $qr, 'raw' => $result] : [
            'error' => true,
            'message' => 'A Z-API não retornou um QR Code válido.',
            'raw' => $result,
        ];
    }

    public function restart(WhatsappInstance $instance): array
    {
        $credentials = $this->credentials($instance);
        return $this->zApi->restartInstance(
            $credentials['instance_id'],
            $credentials['instance_token'],
            $credentials['client_token']
        );
    }

    public function disconnect(WhatsappInstance $instance): array
    {
        $credentials = $this->credentials($instance);
        $result = $this->zApi->disconnectInstance(
            $credentials['instance_id'],
            $credentials['instance_token'],
            $credentials['client_token']
        );

        if (empty($result['error'])) {
            $instance->update([
                'status' => 'disconnected',
                'phone_number' => null,
                'connected_at' => null,
                'qr_code' => null,
                'last_activity' => now(),
            ]);
        }

        return $result;
    }

    /** Encerra somente o cliente Baileys ao trocar o provedor ativo. */
    public function disconnectLocal(WhatsappInstance $instance): bool
    {
        if (!$instance->agent_id) {
            return true;
        }

        try {
            $response = $this->internalRequest()->post(
                rtrim((string) config('services.whatsapp.internal_url'), '/') . '/disconnect-instance',
                ['agent_id' => $instance->agent_id]
            );

            return $response->ok() && ($response->json('success') ?? false) === true;
        } catch (\Throwable $e) {
            Log::warning('Não foi possível encerrar cliente Baileys antes da troca de provedor', [
                'agent_id' => $instance->agent_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function webhookUrl(WhatsappInstance $instance): string
    {
        $signature = hash_hmac('sha256', (string) $instance->uuid, (string) config('app.key'));

        return route('whatsapp.webhook.zapi', [
            'instanceUuid' => $instance->uuid,
            'signature' => $signature,
        ]);
    }

    public function configureWebhooks(WhatsappInstance $instance): array
    {
        $credentials = $this->credentials($instance);
        $url = $this->webhookUrl($instance);
        $result = $this->zApi->setWebhook(
            $credentials['instance_id'],
            $credentials['instance_token'],
            $url,
            $credentials['client_token']
        );

        if (empty($result['error'])) {
            $instance->update([
                'webhook_url' => $url,
                'webhook_enabled' => true,
                'last_activity' => now(),
            ]);
        }

        return ['url' => $url, 'result' => $result];
    }

    public function normalizeSendResult(array $result): array
    {
        $error = $result['error'] ?? null;
        $hasError = $error !== null && $error !== false && $error !== '';
        $messageId = $result['messageId']
            ?? $result['message_id']
            ?? $result['id']
            ?? $result['zaapId']
            ?? $result['zaap_id']
            ?? null;

        return [
            'success' => !$hasError && !empty($messageId),
            'message_id' => $messageId,
            'delivery_status' => $messageId ? 'accepted' : 'unknown',
            'error' => $hasError ? (string) $error : ($result['message'] ?? null),
            'delivery_uncertain' => false,
            'raw' => $result,
        ];
    }

    private function internalRequest(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::timeout(8);
        $token = config('services.whatsapp.http_token');

        return $token ? $request->withHeaders(['X-Dispatch-Token' => $token]) : $request;
    }
}
