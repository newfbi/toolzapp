<?php

namespace App\Http\Livewire\Financeiro;

use App\Helpers\Utils as Util;
use App\Models\ClientsList;
use App\Models\Payments;
use App\Services\AsaasService;
use Illuminate\Support\Str;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Checkout extends Component
{
    public $user;
    public $name;
    public $payId;
    public $payment;
    public $settings;
    public $cardNumber;
    public $expireDate;
    public $cvv;
    public $cpf;
    public $isActive = false;
    public $paymentOpenPix;
    public $paymentStatusPix;
    public $valor_servico;
    public $valor_parcelas;
    public $valor_servico_parcela;
    public $parcelas = 1;
    public $jurosParcela = 0;
    public string $currentTab = 'pix';
    public bool $readyForPix = false;

    public function mount($paymentId)
    {
        $this->settings = Settings::first();
        $currentUser = Auth::user();
        $this->payment = Payments::where('uuid', $paymentId)
            ->where('user_id', $currentUser?->uuid)
            ->first();
        if (!$this->payment) {
            return redirect()->route('dashboard')->with('error', 'Pagamento não encontrado, por favor verifique o link e tente novamente.');
        }
        $this->valor_servico = $this->payment->valor;
        $this->valor_servico_parcela = $this->valor_servico;

        $this->user = $currentUser;
        $this->name = $this->user->name;
        $this->cpf = $this->user->cpf;
        $this->paymentOpenPix = Payments::where(['uuid' => $paymentId, 'user_id' => $this->user->uuid, 'billingType' => 'PIX'])
            ->whereIn('status', ['aberto', 'pendente'])
            ->whereNotNull('pix_code')
            ->first();
        $this->payId = $this->payment->uuid;
        $this->isActive = in_array($this->payment->status, ['pago', 'creditado']);
        $this->readyForPix = !empty($this->user->cpf) && !empty($this->user->name) && !empty($this->user->email);
    }

    public function render()
    {
        return view('livewire.financeiro.checkout');
    }

    public function updatedParcelas()
    {
        $settings = Settings::first();
        if ($this->parcelas >= $settings->quant_parcelas && $settings->quant_parcelas > 0) {
            $this->jurosParcela = ($this->valor_servico * $settings->juros_parcela) / 100;
            $this->valor_servico_parcela = $this->valor_servico + $this->jurosParcela;
        } else {
            $this->jurosParcela = 0;
            $this->valor_servico_parcela = $this->valor_servico;
        }
    }

    // Função que verifica o status do pagamento no banco de dados
    public function checkPaymentStatus()
    {
        $payment = Payments::where(['uuid' => $this->payId ?? $this->payment->uuid, 'user_id' => $this->user->uuid])
            ->whereIn('status', ['pago', 'creditado'])
            ->first();
        if ($payment) {
            $this->isActive = true;
            $this->paymentOpenPix = null;
        }
    }

    /**
     * Generar pagamento do processo.
     */
    public function generatePixPayment()
    {
        if (empty($this->paymentOpenPix)) {
            if (!isset($this->user->cpf)) {
                return redirect()->route('profile')->with('error', 'Por favor, complete seu CPF para continuar com o pagamento.');
            }

            // Usar Helper para gerar PIX
            $result = Util::generatePix($this->user, $this->payment, $this->valor_servico);

            if (isset($result['error'])) {
                session()->flash('error', $result['error']);
                return;
            }

            // Salvar dados do pagamento
            $this->payment->billingType = 'PIX';
            $this->payment->data_compra = now()->format('Y-m-d');
            $this->payment->codigo = $result['payment_id'];
            $this->payment->pix_code = $result['pix_code'];
            $this->payment->qr_code = $result['qr_code'] ?? null;
            $this->payment->gateway = $result['gateway'];
            $this->payment->save();
            $this->payment->refresh();
            $this->paymentOpenPix = $this->payment;
            $this->payId = $this->payment->uuid;
            $this->isActive = false;
            $this->dispatch('resetTable');
            session()->flash('success', 'Código PIX gerado com sucesso.');
            return;
        }
    }
}
