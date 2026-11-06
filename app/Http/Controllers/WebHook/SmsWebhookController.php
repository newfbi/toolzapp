<?php

namespace App\Http\Controllers\WebHook;

use App\Http\Controllers\Controller;
use App\Models\SmsDispatch;
use App\Services\Sms\SmsProviderException;
use App\Services\Sms\SmsProviderManager;
use App\Services\SmsDispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SmsWebhookController extends Controller
{
    public function __invoke(Request $request, SmsProviderManager $providers, SmsDispatchService $service)
    {
        $providerKey = (string) $request->route('provider', 'comprarsms');

        try {
            $provider = $providers->driver($providerKey);
        } catch (SmsProviderException $e) {
            Log::warning('Webhook SMS recebido para provedor não disponível', [
                'provider' => $providerKey,
            ]);
            return response()->json(['error' => 'Provedor SMS não disponível'], 404);
        }

        $rawPayload = $request->getContent();
        if (!$provider->verifyWebhookSignature($rawPayload, $request->headers->all())) {
            Log::warning('Webhook SMS rejeitado: assinatura inválida', [
                'provider' => $provider->key(),
            ]);
            return response()->json(['error' => 'Assinatura inválida'], 401);
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            return response()->json(['error' => 'JSON inválido'], 400);
        }

        $events = $provider->extractEvents($payload);
        $batchIds = collect($events)
            ->map(fn (array $event): string => (string) ($event['batch_id'] ?? $payload['batch_id'] ?? ''))
            ->filter()
            ->unique()
            ->values();

        if ($batchIds->isEmpty()) {
            return response()->json(['success' => true], 200);
        }

        $dispatch = SmsDispatch::where('provider', $provider->key())
            ->whereHas('items', fn ($query) => $query->whereIn('provider_batch_id', $batchIds->all()))
            ->first();

        if (!$dispatch) {
            // Eventos de campanhas antigas/deletadas não devem provocar retry
            // infinito no provedor.
            return response()->json(['success' => true], 200);
        }

        foreach ($events as $event) {
            $service->applyProviderEvent($dispatch, $event);
        }
        $service->refreshDispatchCounters($dispatch);

        return response()->json(['success' => true], 200);
    }
}
