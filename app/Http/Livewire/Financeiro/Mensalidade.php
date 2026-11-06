<?php

namespace App\Http\Livewire\Financeiro;

use App\Helpers\Utils as Util;
use App\Models\Payments;
use App\Models\Settings;
use App\Models\User;
use App\Services\AsaasService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Livewire\Component;

class Mensalidade extends Component
{
    public $user;
    public $status;
    public $valor;
    public $isActive = true;
    public $mensalidade = 1;
    public $venc_mensalid;
    public $vencimento;
    public $paymentOpenPix;
    public $paymentOpenCard;
    public $pagamento;
    public $pagamentos;
    public $paymentCard;
    public $name;
    public $cardNumber;
    public $expireDate;
    public $cvv;
    public $cpf;

    public function mount()
    {
        $utils = new Util();
        View::share('title', 'Mensalidade');
        $settings = Settings::first();
        $this->user = User::where('uuid', Auth::User()->uuid)->first();
        $utils->checkPaymentUser($this->user->uuid);
        $this->user->refresh();
        $this->name = $this->user->name;
        $this->cpf = $this->user->cpf;

        // Buscar todos os pagamentos de mensalidade pagos/creditados para histórico
        $this->pagamentos = Payments::where(['user_id' => $this->user->uuid, 'tipo' => 'mensalidade'])
            ->whereIn('status', ['pago', 'creditado'])
            ->orderBy('data_compra', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // Pegar o último pagamento (mais recente) - sempre disponível para mostrar valor histórico
        $this->pagamento = $this->pagamentos->first();

        // Determinar o status baseado no último plano ativo
        $this->determineUserStatus();

        // Definir valor baseado no último plano pago OU valor padrão das configurações
        // Mesmo usuários inativos verão o valor do último plano para permitir reativação
        $this->valor = $this->pagamento ? $this->pagamento->valor : $settings->mensalidade;

        $this->paymentOpenPix = Payments::where(['user_id' => $this->user->uuid, 'tipo' => 'mensalidade', 'billingType' => 'PIX'])->whereIn('status', ['aberto', 'pendente'])->first();
        $this->paymentOpenCard = Payments::where(['user_id' => $this->user->uuid, 'tipo' => 'mensalidade', 'billingType' => 'CREDIT_CARD'])->whereIn('status', ['aberto', 'pendente'])->first();

        // Calcular vencimento baseado no último plano
        $this->calculateVencimento();

        $this->checkPaymentStatus();
    }

    // Função que verifica o status geral dos pagamentos (usado para polling)
    public function checkPaymentStatus()
    {
        $utils = new Util();
        $payment = Payments::where(['user_id' => $this->user->uuid, 'tipo' => 'mensalidade'])->whereIn('status', ['pago', 'creditado'])->first();
        if ($payment) {
            $utils->checkPaymentUser($this->user->uuid); // Atualiza status geral do usuário
            if ($this->pagamento) {
                $this->vencimento = Carbon::parse($this->pagamento->data_compra)->addMonth()->format('d/m/Y');
            }
            $this->isActive = false;
        }
        $this->user->refresh();
        $this->dispatch('resetTable');
    }

    /**
     * Verificar status do pagamento PIX
     * Apenas atualiza interface - processamento real é feito pelo webhook
     */
    public function checkPixPaymentStatus()
    {
        if (!$this->paymentOpenPix) {
            return;
        }

        $result = Util::checkPaymentStatus(
            $this->paymentOpenPix->uuid,
            $this->user->uuid
        );

        if ($result['success'] && $result['status'] === 'paid') {
            // Pagamento confirmado - atualizar interface
            // O processamento real (validade, status, etc.) é feito pelo webhook
            $this->user->refresh();
            $this->paymentOpenPix = null;
            $this->isActive = false;
            $this->dispatch('resetTable');

            session()->flash('success', 'Pagamento confirmado! Aguarde enquanto processamos sua mensalidade.');
        }
    }

    public function updatedMensalidade()
    {
        $settings = Settings::first();
        $mensalidade = intval($this->mensalidade);

        // Se tem plano ativo, usar o valor do último plano como base, senão usar o padrão
        $baseValue = $this->pagamento ? $this->pagamento->valor : $settings->mensalidade;
        $this->valor = $baseValue * $mensalidade;

        // Calcular vencimento baseado na data atual + quantidade de meses
        $this->vencimento = Carbon::now()->addMonths($mensalidade)->format('d/m/Y');
    }

    public function render()
    {
        return view('livewire.financeiro.mensalidade');
    }

    /**
     * Generar pagamento do processo.
     */
    public function generatePayment()
    {
        if (empty($this->paymentOpenPix)) {
            if (!isset($this->user->cpf)) {
                return redirect()->route('profile')->with('error', 'Por favor, complete seu CPF para continuar com o pagamento.');
            }

            // Criar pagamento temporário para usar com o Helper
            $payment = new Payments();
            $payment->uuid = Str::limit(Str::uuid(), 8, '');
            $payment->user_id = $this->user->uuid;
            $payment->client_id = null;
            $payment->spon_id = $this->user->spon_id;
            $payment->tipo = 'mensalidade';
            $payment->status = 'aberto';
            $payment->billingType = 'PIX';
            $payment->valor = floatval($this->valor);
            $payment->data_compra = now()->format('Y-m-d');
            $payment->save();

            // Usar Helper para gerar PIX
            $result = Util::generatePix($this->user, $payment, $this->valor);

            if (isset($result['error'])) {
                $payment->delete(); // Remove o pagamento se houve erro
                session()->flash('error', $result['error']);
                return;
            }

            // Atualizar pagamento com dados do PIX
            $payment->codigo = $result['payment_id'];
            $payment->pix_code = $result['pix_code'];
            $payment->qr_code = $result['qr_code'] ?? null;
            $payment->gateway = $result['gateway'];
            $payment->save();

            $this->paymentOpenPix = $payment;
            $this->dispatch('resetTable');
            session()->flash('success', 'Código PIX gerado com sucesso.');
            return;
        }
        session()->flash('error', 'Já existe um pagamento em andamento.');
        return;
    }

    /**
     * Processar pagamento via Cartão de Crédito
     */
    public function processCardPayment()
    {
        $asaas = new AsaasService();
        $settings = Settings::first();

        // Remover formatação antes da validação
        $this->cardNumber = preg_replace('/[^0-9]/', '', (string) $this->cardNumber);
        $this->cpf = preg_replace('/[^0-9]/', '', (string) $this->cpf);

        // Validação dos dados
        $this->validate([
            'name' => 'required|string',
            'cpf' => 'required|digits_between:11,14',
            'cardNumber' => 'required|digits_between:13,20',
            'expireDate' => ['required', 'regex:/^(0[1-9]|1[0-2])\/(\d{2})$/'],
            'cvv' => 'required|numeric|digits_between:3,4',
        ], [
            'name.required' => 'O nome é obrigatório.',
            'cardNumber.required' => 'O número do cartão é obrigatório.',
            'cpf.required' => 'O CPF é obrigatório.',
            'expireDate.required' => 'A data de validade é obrigatória.',
            'cvv.required' => 'O CVV é obrigatório.',
            'cpf.digits_between' => 'O CPF deve ter entre 11 e 14 dígitos.',
            'cardNumber.digits_between' => 'O número do cartão deve ter entre 13 e 19 dígitos.',
            'expireDate.regex' => 'A data de validade deve estar no formato MM/AA.',
            'cvv.digits_between' => 'O CVV deve ter 3 ou 4 dígitos.',
        ]);

        // Criar cliente no Asaas
        $customer = $asaas->createCustomer([
            'name' => $this->name,
            'cpfCnpj' => preg_replace('/\D/', '', (string) $this->cpf)
        ]);
        $customer_id = $customer['id'] ?? null;
        if (is_null($customer_id)) {
            session()->flash('error', $customer['message'] ?? 'Erro ao criar cliente');
            return;
        }

        // MM/AA
        [$expiryMonth, $expiryYear] = explode('/', (string) $this->expireDate);
        $expiryYear = '20' . $expiryYear;

        $data = [
            'customer' => $customer_id,
            'billingType' => 'CREDIT_CARD',
            'creditCard' => [
                'holderName' => $this->name,
                'number' => preg_replace('/\D/', '', (string) $this->cardNumber),
                'expiryMonth' => $expiryMonth,
                'expiryYear' => $expiryYear,
                'ccv' => $this->cvv,
            ],
            'value' => floatval($this->valor),
            'dueDate' => now()->format('Y-m-d'),
            'description' => 'Mensalidade - Soft Crédito',
            'creditCardHolderInfo' => [
                'name' => $this->name,
                'cpfCnpj' => preg_replace('/\D/', '', (string) $this->cpf),
                'email' => $this->user->email ?? 'financeiro@softcredito.com.br',
                'phone' => $this->user->phone ?? '47999999999',
                'postalCode' => '88316301',
                'addressNumber' => '123',
            ],
        ];

        // Processa pagamento cartão via createPayment
        $payment = $asaas->createPayment($data);
        if (isset($payment['error'])) {
            session()->flash('error', $payment['message'] ?? 'Erro ao processar pagamento');
            return;
        }

        $paymentId = $payment['id'] ?? null;
        $status = $payment['status'] ?? null;

        // Calcula quantidade de meses pela razão do valor com a mensalidade do plano
        $planMensalidade = (float) ($settings->mensalidade ?? 0);
        $months = $planMensalidade > 0 ? max(1, (int) floor(((float) $this->valor) / $planMensalidade)) : 1;
        $finalStatus = ($status === 'RECEIVED' || $status === 'CONFIRMED') ? 'creditado' : 'pendente';
        $validade = $finalStatus === 'creditado' ? now()->addMonths($months)->format('Y-m-d') : null;

        Payments::create([
            'uuid' => Str::limit(Str::uuid(), 8, ''),
            'user_id' => $this->user->uuid,
            'client_id' => null,
            'spon_id' => $this->user->spon_id,
            'tipo' => 'mensalidade',
            'status' => $finalStatus,
            'billingType' => 'CREDIT_CARD',
            'valor' => floatval($this->valor),
            'data_compra' => now()->format('Y-m-d'),
            'codigo' => $paymentId,
            'validade' => $validade,
            'gateway' => 'asaas', // Cartão sempre usa ASAAS
        ]);

        session()->flash('success', 'Pagamento iniciado com sucesso! Aguarde a confirmação.');
        $this->dispatch('resetTable');
        return;
    }

    public function cancelPayment()
    {
        $this->paymentOpenPix->status = 'cancelado';
        $this->paymentOpenPix->save();
        $this->paymentOpenPix = null;
        $this->isActive = false;
        $this->dispatch('resetTable');
        session()->flash('success', 'Pagamento cancelado com sucesso.');
    }

    /**
     * Determinar o status do usuário baseado no último plano ativo
     */
    private function determineUserStatus()
    {
        if (!$this->pagamento) {
            $this->status = 'inativo';
            $this->isActive = true; // Permitir primeiro pagamento
            return;
        }

        // Verificar se o plano ainda está válido
        $validade = $this->pagamento->validade;
        if ($validade && Carbon::parse($validade)->isFuture()) {
            $this->status = 'ativo';
            $this->isActive = false; // Não permitir novos pagamentos se já tem plano ativo
        } else {
            $this->status = 'vencido';
            $this->isActive = true; // Permitir renovação se venceu
        }
    }

    /**
     * Calcular o vencimento baseado no último plano
     */
    private function calculateVencimento()
    {
        if (!$this->pagamento) {
            $this->venc_mensalid = "Sem plano ativo - Realize o primeiro pagamento";
            $this->vencimento = "N/A";
            return;
        }

        $validade = $this->pagamento->validade;
        if ($validade) {
            $this->vencimento = Carbon::parse($validade)->format('d/m/Y');
            if (Carbon::parse($validade)->isFuture()) {
                $this->venc_mensalid = "Vence em " . $this->vencimento;
            } else {
                $this->venc_mensalid = "Vencido em " . $this->vencimento . " - Renove seu plano";
            }
        } else {
            // Fallback para cálculo baseado na data de compra + 1 mês
            $this->vencimento = Carbon::parse($this->pagamento->data_compra)->addMonth()->format('d/m/Y');
            if (Carbon::parse($this->pagamento->data_compra)->addMonth()->isFuture()) {
                $this->venc_mensalid = "Vence em " . $this->vencimento;
            } else {
                $this->venc_mensalid = "Vencido em " . $this->vencimento . " - Renove seu plano";
            }
        }
    }
}
