<?php

namespace App\Services\Gateways;

use Illuminate\Http\Request;
use App\Models\User;
use App\Services\AsaasService;
use App\Helpers\Utils;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AsaasGateway implements GatewayInterface
{
    public function getName(): string
    {
        return 'asaas';
    }

    /**
     * ✅ Gera PIX usando AsaasService (API real do Asaas)
     * Fluxo: Criar/Buscar Customer → Criar Pagamento PIX → Obter QR Code
     */
    public function generatePix(User $user, $payment, float $amount): array
    {
        try {
            // Validar dados do usuário
            if (empty($user->cpf) && empty($user->document)) {
                return [
                    'success' => false,
                    'error' => 'CPF do usuário é obrigatório para gerar PIX',
                    'gateway' => 'asaas'
                ];
            }

            if (empty($user->name) && empty($user->username)) {
                return [
                    'success' => false,
                    'error' => 'Nome do usuário é obrigatório para gerar PIX',
                    'gateway' => 'asaas'
                ];
            }

            $asaas = new AsaasService();
            $document = preg_replace('/[^0-9]/', '', $user->cpf ?? $user->document ?? '');
            $name = $user->name ?? $user->username ?? 'Cliente';
            $externalId = $payment->uuid ?? Str::limit(Str::uuid(), 8, '');

            // 1. Criar ou buscar customer no Asaas
            $customer = $asaas->createCustomer([
                'name' => $name,
                'cpfCnpj' => $document,
                'email' => $user->email ?? null,
                'phone' => $user->phone ?? null,
            ]);

            if (isset($customer['error'])) {
                Log::error('AsaasGateway: Erro ao criar/buscar customer', [
                    'error' => $customer['message'] ?? 'Erro desconhecido',
                    'user_id' => $user->uuid
                ]);
                return [
                    'success' => false,
                    'error' => $customer['message'] ?? 'Erro ao criar cliente no Asaas',
                    'gateway' => 'asaas'
                ];
            }

            $customerId = $customer['id'] ?? null;
            if (!$customerId) {
                return [
                    'success' => false,
                    'error' => 'Customer ID não retornado pelo Asaas',
                    'gateway' => 'asaas'
                ];
            }

            // 2. Criar pagamento PIX no Asaas
            $paymentData = [
                'customer' => $customerId,
                'billingType' => 'PIX',
                'value' => floatval($amount),
                'dueDate' => now()->format('Y-m-d'),
                'description' => $payment->tipo ?? 'Pagamento PIX',
                'externalReference' => $externalId,
            ];

            $createdPayment = $asaas->createPayment($paymentData);

            if (isset($createdPayment['error'])) {
                Log::error('AsaasGateway: Erro ao criar pagamento PIX', [
                    'error' => $createdPayment['message'] ?? 'Erro desconhecido',
                    'customer_id' => $customerId,
                    'amount' => $amount
                ]);
                return [
                    'success' => false,
                    'error' => $createdPayment['message'] ?? 'Erro ao criar pagamento PIX no Asaas',
                    'gateway' => 'asaas'
                ];
            }

            $paymentId = $createdPayment['id'] ?? null;
            if (!$paymentId) {
                return [
                    'success' => false,
                    'error' => 'Payment ID não retornado pelo Asaas',
                    'gateway' => 'asaas'
                ];
            }

            // 3. Obter QR Code do PIX
            $qrCodeResponse = $asaas->getPixQrCode($paymentId);

            if (isset($qrCodeResponse['error'])) {
                Log::error('AsaasGateway: Erro ao obter QR Code PIX', [
                    'error' => $qrCodeResponse['message'] ?? 'Erro desconhecido',
                    'payment_id' => $paymentId
                ]);
                // Mesmo sem QR Code, retornar sucesso com payment_id (pode ser gerado depois)
                return [
                    'success' => true,
                    'payment_id' => $paymentId,
                    'qr_code' => null,
                    'pix_code' => null,
                    'gateway' => 'asaas',
                    'warning' => 'Pagamento criado, mas QR Code não disponível no momento'
                ];
            }

            // Extrair dados do QR Code PIX
            // Asaas retorna: payload (código PIX), encodedImage (QR Code base64 opcional)
            $pixCode = $qrCodeResponse['payload'] ?? null;
            $qrBase64 = null;

            // Se tem encodedImage (base64), usar direto
            if (isset($qrCodeResponse['encodedImage']) && !empty($qrCodeResponse['encodedImage'])) {
                $encodedImg = $qrCodeResponse['encodedImage'];
                $qrBase64 = (Str::startsWith($encodedImg, ['data:image/png;base64,', 'data:image/svg+xml;base64,']))
                    ? $encodedImg
                    : ('data:image/png;base64,' . $encodedImg);
            } elseif ($pixCode && strlen($pixCode) >= 20) {
                // Se tem payload (código PIX válido), gerar QR Code
                $qrBase64 = Utils::generateQrCodeBase64($pixCode) ?? null;
            }

            // Validar se temos pelo menos código PIX ou QR Code
            if (empty($pixCode) && empty($qrBase64)) {
                return [
                    'success' => false,
                    'error' => 'Resposta inválida do Asaas: sem código PIX ou QR Code',
                    'gateway' => 'asaas',
                    'raw' => $qrCodeResponse
                ];
            }

            return [
                'success' => true,
                'payment_id' => $paymentId,
                'qr_code' => $qrBase64,
                'pix_code' => $pixCode,
                'gateway' => 'asaas'
            ];

        } catch (\Exception $e) {
            Log::error('AsaasGateway: Exceção ao gerar PIX', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->uuid ?? null
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao gerar PIX: ' . $e->getMessage(),
                'gateway' => 'asaas'
            ];
        }
    }

    public function validateWebhook(Request $request): array
    {
        $configuredToken = config('services.asaas.webhook_token');
        if ($configuredToken) {
            $receivedToken = (string) $request->header('asaas-access-token', '');
            if ($receivedToken === '' || !hash_equals((string) $configuredToken, $receivedToken)) {
                Log::warning('ASAAS webhook rejeitado: token inválido.');
                return ['success' => false, 'processable' => false];
            }
        }

        $data = $request->all();
        $event = $data['event'] ?? null;
        if (!$event) {
            return ['success' => false, 'processable' => false];
        }
        $paymentId = $data['payment']['installment'] ?? ($data['payment']['id'] ?? null);
        $billingType = $data['payment']['billingType'] ?? null;
        $status = 'pending';
        $processable = false;
        if ($event === 'PAYMENT_RECEIVED' || $event === 'PAYMENT_CONFIRMED') {
            $status = 'paid';
            $processable = true;
        } elseif ($event === 'PAYMENT_REFUNDED') {
            $status = 'refunded';
        } elseif ($event === 'PAYMENT_OVERDUE') {
            $status = 'canceled';
        }
        return [
            'success' => true,
            'processable' => $processable,
            'external_id' => $paymentId,
            'transaction_id' => $data['payment']['id'] ?? null,
            'status' => $status,
            'billingType' => $billingType,
        ];
    }
}
