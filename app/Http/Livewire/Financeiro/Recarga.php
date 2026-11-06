<?php

namespace App\Http\Livewire\Financeiro;

use App\Helpers\Utils as Util;
use App\Models\Payments;
use App\Models\Settings;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Livewire\Component;

class Recarga extends Component
{
    public $user;
    public $valor;
    public $paymentOpenPix;
    public $settings;
    public $name;
    public $cpf;

    // Informações do plano atual
    public $ultimoPlano;
    public $statusPlano;
    public $vencimentoPlano;
    public $valorPlano;

    // Cálculo de créditos
    public $creditosCalculados;

    public function mount(): void
    {
        View::share('title', 'Recarga de Créditos');
        $this->user = User::where('uuid', Auth::user()->uuid)->first();
        $this->settings = Settings::first();
        $this->valor = (float) ($this->settings->recharge ?? 0);
        $this->name = $this->user->name;
        $this->cpf = $this->user->cpf;

        // Buscar informações do último plano ativo
        $this->loadPlanoInfo();

        // Calcular créditos iniciais
        $this->calcularCreditos();

        $this->paymentOpenPix = Payments::where([
            'user_id' => $this->user->uuid,
            'tipo' => 'recarga',
            'billingType' => 'PIX',
        ])->whereIn('status', ['aberto', 'pendente'])->orderBy('id', 'desc')->first();
    }

    public function render()
    {
        return view('livewire.financeiro.recarga');
    }

    public function updatedValor(): void
    {
        $this->valor = (float) preg_replace('/[^0-9.,]/', '', (string) $this->valor);
        $this->valor = (float) str_replace(['.', ','], ['', '.'], (string) $this->valor);

        // Recalcular créditos quando o valor muda
        $this->calcularCreditos();
    }

    public function generatePayment()
    {
        if (!isset($this->user->cpf)) {
            return redirect()->route('profile')->with('error', 'Por favor, complete seu CPF para continuar com o pagamento.');
        }

        if (!$this->settings || (float) ($this->settings->recharge ?? 0) <= 0) {
            session()->flash('error', 'Configuração de valor da consulta inválida. Contate o suporte.');
            return;
        }

        if ((float) $this->valor < (float) $this->settings->recharge) {
            session()->flash('error', 'Valor mínimo para recarga: R$ ' . Util::MoedaBr($this->settings->recharge) . '.');
            return;
        }

        // Criar pagamento temporário para usar com o Helper
        $payment = new Payments();
        $payment->uuid = Str::limit(Str::uuid(), 8, '');
        $payment->user_id = $this->user->uuid;
        $payment->spon_id = $this->user->spon_id;
        $payment->tipo = 'recarga';
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

    public function cancelPayment(): void
    {
        if ($this->paymentOpenPix) {
            $this->paymentOpenPix->status = 'cancelado';
            $this->paymentOpenPix->save();
        }
        $this->paymentOpenPix = null;
        $this->dispatch('resetTable');
        session()->flash('success', 'Pagamento cancelado com sucesso.');
    }

    /**
     * Verificar status do pagamento PIX
     */
    /**
     * Verificar status do pagamento PIX
     * Apenas atualiza interface - processamento real é feito pelo webhook
     */
    public function checkPaymentStatus()
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
            // O processamento real (créditos, status, etc.) é feito pelo webhook
            $this->user->refresh();
            $this->paymentOpenPix = null;
            $this->dispatch('resetTable');

            session()->flash('success', 'Pagamento confirmado! Aguarde enquanto processamos seus créditos.');
        }
    }

    /**
     * Carregar informações do último plano ativo do usuário
     */
    private function loadPlanoInfo(): void
    {
        // Buscar o último pagamento de mensalidade pago/creditado
        $this->ultimoPlano = Payments::where([
            'user_id' => $this->user->uuid,
            'tipo' => 'mensalidade'
        ])->whereIn('status', ['pago', 'creditado'])
            ->orderBy('data_compra', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($this->ultimoPlano) {
            $this->valorPlano = $this->ultimoPlano->valor;

            // Determinar status do plano
            $validade = $this->ultimoPlano->validade;
            if ($validade) {
                $this->vencimentoPlano = Carbon::parse($validade)->format('d/m/Y');
                $this->statusPlano = Carbon::parse($validade)->isFuture() ? 'ativo' : 'vencido';
            } else {
                // Fallback para cálculo baseado na data de compra + 1 mês
                $this->vencimentoPlano = Carbon::parse($this->ultimoPlano->data_compra)->addMonth()->format('d/m/Y');
                $this->statusPlano = Carbon::parse($this->ultimoPlano->data_compra)->addMonth()->isFuture() ? 'ativo' : 'vencido';
            }
        } else {
            $this->valorPlano = $this->settings->mensalidade ?? null; // Mostrar valor padrão para primeiro plano
            $this->statusPlano = 'inativo';
            $this->vencimentoPlano = 'N/A';
        }
    }

    /**
     * Calcular quantos créditos serão creditados baseado no valor depositado
     */
    private function calcularCreditos(): void
    {
        // Cálculo baseado no token_value: valor / token_value
        // Exemplo: R$ 1,00 / 0,09 = 11,11 créditos
        $tokenValue = floatval($this->settings->token_value ?? 0.09);

        if ($tokenValue > 0) {
            $this->creditosCalculados = floatval($this->valor) / $tokenValue;
        } else {
            // Fallback se token_value não estiver configurado
            $this->creditosCalculados = floatval($this->valor);
        }
    }
}
