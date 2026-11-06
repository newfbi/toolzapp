<?php

namespace App\Services\Gateways;

use Illuminate\Http\Request;
use App\Models\User;
use App\Services\OndaPayService;
use App\Helpers\Utils;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class OndaPayGateway implements GatewayInterface
{
    public function getName(): string
    {
        return 'ondapay';
    }

    public function generatePix(User $user, $payment, float $amount): array
    {
        $payer = [
            'name' => $user->name ?? ($user->username ?? null),
            'document' => $user->document ?? ($user->cpf ?? null),
            'email' => $user->email ?? null,
        ];
        if (empty($payer['name']) || empty($payer['document'])) {
            return [
                'success' => false,
                'error' => 'Dados do pagador ausentes (nome e documento são obrigatórios)',
                'gateway' => 'ondapay'
            ];
        }
        $externalId = $payment->uuid ?? Str::limit(Str::uuid(), 8, '');
        $service = new OndaPayService();
        $data = $service->formatQrCodeData($amount, $externalId, 'Pagamento PIX', $payer);
        $resp = $service->generatePixQrCode($data);

        // Verificar se houve erro na resposta
        if (isset($resp['error']) && $resp['error'] === true) {
            return [
                'success' => false,
                'error' => $resp['message'] ?? 'Erro ao gerar PIX no OndaPay',
                'gateway' => 'ondapay',
                'raw' => $resp,
            ];
        }

        $pix = $resp['emv']
            ?? ($resp['qrcode'] ?? null)
            ?? ($resp['payload']['emv'] ?? null)
            ?? null;
        $qrBase64 = $resp['qrcode_base64']
            ?? ($resp['payload']['qrcode_base64'] ?? null)
            ?? null;
        $transactionId = $resp['id_transaction']
            ?? ($resp['transaction_id'] ?? null);

        if ((empty($pix) || strlen($pix) < 20) && empty($qrBase64)) {
            return [
                'success' => false,
                'error' => $resp['message'] ?? 'Falha ao gerar PIX: resposta inválida (sem código PIX ou QR Code)',
                'gateway' => 'ondapay',
                'raw' => $resp,
            ];
        }
        if (!empty($qrBase64)) {
            $qr = (Str::startsWith($qrBase64, ['data:image/png;base64,', 'data:image/svg+xml;base64,']))
                ? $qrBase64
                : ('data:image/png;base64,' . $qrBase64);
        } else {
            $qr = Utils::generateQrCodeBase64($pix) ?? '';
        }
        return [
            'success' => true,
            'payment_id' => $transactionId ?? ($payment->uuid ?? $externalId),
            'qr_code' => $qr,
            'pix_code' => $pix,
            'gateway' => 'ondapay',
            'raw' => $resp,
        ];
    }

    public function validateWebhook(Request $request): array
    {
        $rawPayload = (string) $request->getContent();
        $configuredSecret = config('services.ondapay.webhook_secret');
        if ($configuredSecret) {
            $signature = (string) ($request->header('x-webhook-signature')
                ?: $request->header('x-signature')
                ?: $request->input('signature', ''));
            $expectedSignature = hash_hmac('sha256', $rawPayload, (string) $configuredSecret);

            if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
                Log::warning('ONDAPAY webhook rejeitado: assinatura inválida.');
                return ['success' => false, 'processable' => false];
            }
        }

        $data = json_decode($rawPayload, true);
        if (!$data) {
            return ['success' => false, 'processable' => false];
        }
        $status = strtoupper($data['status'] ?? '');
        $type = strtoupper($data['type_transaction'] ?? '');
        $ok = ($type === 'CASH_IN') && in_array($status, ['PAID_OUT', 'PAID', 'CONFIRMED', 'COMPLETED']);
        $amountRaw = $data['amount'] ?? null;
        $netAmountRaw = $data['net_amount'] ?? null;
        $normalize = function ($val) {
            if (!is_numeric($val)) return null;
            $v = (float) $val;
            if ($v >= 1000 && floor($v) === $v) {
                $v = $v / 100.0;
            }
            return round($v, 2);
        };
        $amount = $normalize($amountRaw);
        $netAmount = $normalize($netAmountRaw);
        return [
            'success' => true,
            'processable' => $ok,
            'external_id' => $data['external_id'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? ($data['id_transaction'] ?? null),
            'status' => $ok ? 'paid' : 'pending',
            'amount' => $amount,
            'net_amount' => $netAmount,
        ];
    }
}
