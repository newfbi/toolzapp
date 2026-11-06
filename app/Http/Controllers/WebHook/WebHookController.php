<?php

namespace App\Http\Controllers\WebHook;

use App\Helpers\Utils;
use App\Http\Controllers\Controller;
use App\Models\Payments;
use App\Models\Settings;
use App\Models\User;
use App\Models\WebHookLogs;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Gateways\GatewayFactory;

class WebHookController extends Controller
{
    public function handleAsaasWebhook(Request $request)
    {
        $utils = new Utils();
        $parsed = GatewayFactory::make('asaas')->validateWebhook($request);
        if (!($parsed['success'] ?? false)) {
            return response()->json(['error' => true], 400);
        }
        $paymentId = $parsed['external_id'] ?? null;
        if (!$paymentId) {
            return response()->json(['error' => true], 400);
        }
        $payment = Payments::where('codigo', $paymentId)->first();

        if (!$payment) {
            return response()->json(['success' => true], 200); // Webhook já processado ou não existe
        }

        $user = User::where('uuid', $payment->user_id)->first();
        if (!$user) {
            Log::warning('ASAAS webhook: usuário não encontrado para pagamento', ['payment_id' => $paymentId]);
            return response()->json(['error' => 'Usuário não encontrado'], 404);
        }

        if ($parsed['processable'] ?? false) {
            $nextStatus = $payment->billingType === 'PIX' ? 'pago' : 'creditado';

            // A transição condicional funciona como uma reivindicação do
            // webhook: apenas a primeira entrega pode executar créditos,
            // comissão e ativação do plano.
            if (!$this->transitionPaymentStatus($payment, $nextStatus, ['aberto', 'pendente'])) {
                return response()->json(['success' => true], 200);
            }

            $payment->refresh();

            // Recarga não altera o plano; apenas adiciona créditos uma vez.
            if ($payment->tipo === 'recarga') {
                $settings = Settings::first();
                $tokenValue = floatval($settings->token_value ?? 0.09);
                $creditosParaAdicionar = $tokenValue > 0
                    ? floatval($payment->valor) / $tokenValue
                    : floatval($payment->valor);

                $user->increment('credits', $creditosParaAdicionar);
                return response()->json(['success' => true], 200);
            }

            // ✅ checkPaymentUser vai:
            // - Verificar se o pagamento está válido
            // - Associar plano ao usuário baseado no tipo de pagamento
            // - Aplicar roles do plano
            // - Ativar usuário se pagamento estiver válido
            // - Calcular validade baseado no tipo de pagamento (checkPaymentUser já faz isso)
            $utils->checkPaymentUser($user->uuid);

            // ✅ Calcular comissão após processar pagamento
            Utils::setComission($payment);
        } elseif (($parsed['status'] ?? null) === 'refunded') {
            if (!$this->transitionPaymentStatus($payment, 'estornado', ['pago', 'creditado'])) {
                return response()->json(['success' => true], 200);
            }

            // ✅ Verificar se ainda há pagamentos válidos após estorno
            $utils->checkPaymentUser($user->uuid);
        } elseif (($parsed['status'] ?? null) === 'canceled') {
            if (!$this->transitionPaymentStatus($payment, 'cancelado', ['aberto', 'pendente', 'pago', 'creditado'])) {
                return response()->json(['success' => true], 200);
            }

            // ✅ Verificar se ainda há pagamentos válidos após cancelamento
            $utils->checkPaymentUser($user->uuid);
        }

        return response()->json(['success' => true], 200);
    }

    public function handleOndaPayWebhook(Request $request)
    {
        $parsed = GatewayFactory::make('ondapay')->validateWebhook($request);
        if (!($parsed['success'] ?? false)) {
            Log::warning('ONDAPAY webhook: payload inválido');
            return response()->json(['error' => 'Payload inválido'], 400);
        }
        $externalId = $parsed['external_id'] ?? null;
        if (!$externalId) {
            Log::warning('ONDAPAY webhook: external_id ausente');
            return response()->json(['error' => 'external_id ausente'], 400);
        }
        if (!($parsed['processable'] ?? false)) {
            Log::info('ONDAPAY webhook: status/transação não concluída', ['external_id' => $externalId]);
            return response()->json(['success' => true], 200);
        }

        $payment = Payments::where(function ($q) use ($externalId) {
            $q->where('uuid', $externalId)->orWhere('codigo', $externalId);
        })->first();

        if (!$payment) {
            Log::warning('ONDAPAY webhook: pagamento não encontrado', ['external_id' => $externalId]);
            return response()->json(['error' => 'Pagamento não encontrado'], 404);
        }
        if (!in_array($payment->status, ['aberto', 'pendente'])) {
            Log::info('ONDAPAY webhook: pagamento já processado', ['payment' => $payment->uuid, 'status' => $payment->status]);
            return response()->json(['success' => true], 200);
        }

        $utils = new Utils();
        $settings = Settings::first();
        $user = User::where('uuid', $payment->user_id)->first();

        if (isset($parsed['transaction_id']) && empty($payment->codigo)) {
            $payment->codigo = $parsed['transaction_id'];
            $payment->save();
        }

        if (!$user) {
            Log::warning('ONDAPAY webhook: usuário não encontrado para pagamento', ['payment' => $payment->uuid]);
            return response()->json(['error' => 'Usuário não encontrado'], 404);
        }

        $receivedAmount = $parsed['net_amount'] ?? ($parsed['amount'] ?? null);
        $creditAmount = (float) ($payment->valor ?? 0);
        if (is_numeric($receivedAmount) && (float)$receivedAmount > 0) {
            $creditAmount = (float) $receivedAmount;
            if (empty($payment->valor) || (float) $payment->valor <= 0) {
                $payment->valor = $creditAmount;
                $payment->save();
                $payment->refresh();
            }
        }

        // ✅ RECARGA: Processar separadamente (não afeta plano/status do usuário)
        if ($payment->tipo === 'recarga') {
            $nextStatus = $payment->billingType === 'PIX' ? 'pago' : 'creditado';
            if (!$this->transitionPaymentStatus($payment, $nextStatus, ['aberto', 'pendente'])) {
                return response()->json(['success' => true], 200);
            }

            // Calcular créditos baseado no token_value (mesma lógica do frontend)
            $tokenValue = floatval($settings->token_value ?? 0.09);
            $creditosParaAdicionar = $tokenValue > 0
                ? $creditAmount / $tokenValue
                : $creditAmount;
            $user->increment('credits', $creditosParaAdicionar);

            return response()->json(['success' => true], 200);
        }

        // ✅ PAGAMENTO DE PLANO/MENSALIDADE: Atualizar valor e status antes de processar
        if (is_numeric($receivedAmount) && (float)$receivedAmount > 0) {
            if (empty($payment->valor) || (float) $payment->valor <= 0) {
                $payment->valor = $creditAmount;
            }
        }

        // A primeira entrega reivindica o processamento; reenvios concorrentes
        // saem sem repetir créditos, ativação ou comissão.
        $nextStatus = $payment->billingType === 'PIX' ? 'pago' : 'creditado';
        if (!$this->transitionPaymentStatus($payment, $nextStatus, ['aberto', 'pendente'])) {
            return response()->json(['success' => true], 200);
        }

        $payment->refresh();

        // ✅ checkPaymentUser vai:
        // - Verificar se o pagamento está válido
        // - Associar plano ao usuário baseado no tipo de pagamento
        // - Aplicar roles do plano
        // - Ativar usuário se pagamento estiver válido
        // - Calcular validade baseado no tipo de pagamento (checkPaymentUser já faz isso)
        $utils->checkPaymentUser($user->uuid);

        // ✅ Calcular créditos adicionais baseado no plano (após checkPaymentUser estabelecer o plano)
        try {
            $user->refresh();
            $plan = $user->plan; // checkPaymentUser já estabeleceu o plano
            if ($plan) {
                $tokenValue = floatval($settings->token_value ?? 0.09);
                $baseCredits = $tokenValue > 0 ? ($payment->valor / $tokenValue) * 1000 : 0;
                $planCreditsPercent = (float) ($plan->credits ?? 100);
                $totalCredits = $baseCredits * ($planCreditsPercent / 100);
                if ($totalCredits > 0) {
                    $user->increment('credits', $totalCredits);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ONDAPAY webhook: Erro ao calcular créditos adicionais', [
                'error' => $e->getMessage(),
                'payment_id' => $payment->uuid
            ]);
        }

        // ✅ Calcular comissão após processar pagamento (checkPaymentUser já atualizou plano)
        Utils::setComission($payment);

        return response()->json(['success' => true], 200);
    }

    /**
     * Webhook da Hotmart
     */
    public function handleHotmartWebhook(Request $request)
    {
        $data = $request->all();
        $event = $data['event'] ?? null;
        $email = $data['buyer']['email'] ?? null;
        $product = $data['product']['name'] ?? null;

        if (!$email || !$event) {
            return response()->json(['error' => 'Dados inválidos'], 400);
        }

        if ($event === 'PURCHASE_APPROVED') {
            $this->storeProviderWebhookOnce('hotmart', $event, $email, $product, $data);
        }

        return response()->json(['message' => 'Hotmart webhook recebido com sucesso']);
    }

    /**
     * Webhook da Ticto
     */
    public function handleTictoWebhook(Request $request)
    {
        $data = $request->all();
        // Pegando os dados certos do JSON
        $email = $data['customer']['email'] ?? null;
        $slug = $this->extractSlugFromLabel((string) ($data['item']['product_name'] ?? ''));
        $productName =  $slug ?? null;
        $periodo = isset($data['item']['days_of_access']) ? intval($data['item']['days_of_access']) : 30;

        if (!$email) {
            return response()->json(['error' => 'Dados inválidos'], 400);
        }

        // 💾 Salva o log do webhook
        $this->storeProviderWebhookOnce('ticto', 'sale', $email, $productName, $data, $periodo);

        return response()->json(['message' => 'Ticto webhook recebido com sucesso']);
    }

    /**
     * Webhook da Greenn
     */
    public function handleGreennWebhook(Request $request)
    {
        $data = $request->all();

        $event  = $data['event'] ?? null;
        $client = $data['client'] ?? null;
        $email = $client['email'] ?? null;
        $periodo = isset($data['product']['period']) ? intval($data['product']['period']) : 0;
        $slug = $this->extractSlugFromLabel((string) ($data['oferta'] ?? ''));
        $productName =  $slug ?? null;

        if (!$email || !$event) {
            return response()->json(['error' => 'Dados inválidos'], 400);
        }

        // 💾 Salva o log do webhook
        $this->storeProviderWebhookOnce('greenn', $event, $email, $productName, $data, $periodo);

        return response()->json(['message' => 'Webhook Greenn recebido com sucesso']);
    }

    function extractSlugFromLabel(string $label): ?string
    {
        if (trim($label) === '') {
            return null;
        }

        // Remove qualquer coisa entre parênteses
        $limpo = preg_replace('/\s*\(.*?\)/', '', $label);

        // Remove # se existir
        $limpo = ltrim($limpo, '#');

        // Slugify
        return Str::slug(trim($limpo));
    }

    /**
     * Faz uma transição de pagamento somente se ele ainda estiver em um
     * estado processável. Isso impede crédito duplicado em webhooks
     * concorrentes e mantém os reenvios idempotentes.
     */
    private function transitionPaymentStatus(Payments $payment, string $nextStatus, array $allowedStatuses): bool
    {
        return Payments::whereKey($payment->getKey())
            ->whereIn('status', $allowedStatuses)
            ->update(['status' => $nextStatus]) === 1;
    }

    /**
     * Provedores reenviam o mesmo webhook até receberem confirmação.
     * O hash evita duplicar logs que depois podem gerar uma nova ativação.
     */
    private function storeProviderWebhookOnce(
        string $origin,
        string $event,
        string $email,
        ?string $product,
        array $payload,
        int $period = 30
    ): WebHookLogs {
        $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventHash = hash('sha256', (string) $rawPayload);

        return DB::transaction(function () use ($origin, $event, $email, $product, $payload, $period, $eventHash) {
            $existing = WebHookLogs::where('origin', $origin)
                ->where('event_hash', $eventHash)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return WebHookLogs::create([
                'origin' => $origin,
                'event' => $event,
                'event_hash' => $eventHash,
                'email' => $email,
                'product' => $product ?: 'indefinido',
                'period' => $period,
                'payload' => $payload,
            ]);
        });
    }
}
