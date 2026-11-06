<?php

namespace App\Helpers;

use Illuminate\Pagination\LengthAwarePaginator;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordReader;
use Illuminate\Support\Str;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use App\Models\{
    Payments,
    Plans,
    User,
    UserApi,
    WebHookLogs,
    CommissionLog,
    Agents,
};
use App\Models\Traits\Role as Role;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\Gateways\GatewayFactory;

class Utils
{
    public function __construct() {}

    public static function normalizeDecimal($value): float
    {
        if (is_null($value)) return 0.0;
        if (is_float($value) || is_int($value)) return (float)$value;
        $s = trim((string)$value);
        if ($s === '') return 0.0;

        // If both separators appear, decide decimal by the last occurrence
        $hasComma = strpos($s, ',') !== false;
        $hasDot = strpos($s, '.') !== false;
        if ($hasComma && $hasDot) {
            $lastComma = strrpos($s, ',');
            $lastDot = strrpos($s, '.');
            if ($lastComma !== false && $lastDot !== false) {
                if ($lastComma > $lastDot) {
                    // comma is decimal, dots are thousands
                    $s = str_replace('.', '', $s);
                    $s = str_replace(',', '.', $s);
                } else {
                    // dot is decimal, commas are thousands
                    $s = str_replace(',', '', $s);
                    // keep dot as decimal
                }
            }
        } elseif ($hasComma && !$hasDot) {
            // Only comma present -> decimal
            $s = str_replace(',', '.', $s);
        } else {
            // Only dot or none -> keep dot as decimal, remove spaces
        }

        // Remove any non-numeric except leading minus and single dot
        $s = preg_replace('/[^0-9\.-]/', '', $s);
        // Clean multiple dots: keep first
        if (substr_count($s, '.') > 1) {
            $parts = explode('.', $s);
            $s = array_shift($parts) . '.' . implode('', $parts);
        }
        if ($s === '' || $s === '-' || $s === '.') return 0.0;
        return (float)$s;
    }

    public static function getCommission($user_id, $month = false)
    {
        $user = User::where('uuid', $user_id)->first();
        $totalPagamentos = $user->commission()->sum('valor');
        if ($month) {
            $totalPagamentos = $user->commission()->whereMonth('created_at', date('m'))->sum('valor');
        }
        $commission = $totalPagamentos;
        return $commission ?? 0;
    }

    public static function setComission(Payments $payment): void
    {
        try {
            // ✅ Validações iniciais
            $payer = User::where('uuid', $payment->user_id)->first();
            if (!$payer) {
                Log::warning('setComission: Pagador não encontrado', ['payment_id' => $payment->uuid]);
                return;
            }

            $sponsor = User::where('uuid', $payer->spon_id)->first();
            if (!$sponsor) {
                Log::info('setComission: Sponsor não encontrado - usuário sem indicação', ['payment_id' => $payment->uuid]);
                return;
            }

            // ✅ Validação do valor do pagamento
            $paymentValue = (float)($payment->valor ?? 0);
            if ($paymentValue <= 0) {
                Log::warning('setComission: Valor de pagamento inválido', [
                    'payment_id' => $payment->uuid,
                    'valor' => $paymentValue
                ]);
                return;
            }

            // ✅ Determinação do tipo de comissão
            $tipoLower = strtolower((string)($payment->tipo ?? ''));
            $tipoComissao = Str::startsWith($tipoLower, 'plan_') ? 'indication' : 'comissao';

            // ✅ Busca do plano (conforme versão de referência: apenas se não tem plano E tipo começa com 'plan_')
            $plan = $payer->plan;
            if (!$plan && Str::startsWith($tipoLower, 'plan_')) {
                $slug = substr($tipoLower, 5);
                $plan = Plans::where(function ($q) use ($slug, $tipoLower) {
                    $q->orWhereRaw('LOWER(tipo) = ?', [strtolower($slug)])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%' . strtolower($slug) . '%'])
                        ->orWhereRaw('LOWER(tipo) = ?', [strtolower($tipoLower)])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%' . strtolower($tipoLower) . '%']);
                })->first();

                if ($plan) {
                    Log::info('setComission: Plano encontrado via fallback', [
                        'payment_id' => $payment->uuid,
                        'plan_id' => $plan->id
                    ]);
                }
            }

            // ✅ Determinação da porcentagem de comissão
            $percent = 0.0;
            if ($plan) {
                $percent = (float) ($tipoComissao === 'indication' ? ($plan->indication ?? 0) : ($plan->commission ?? 0));
                Log::info('setComission: Porcentagem definida', [
                    'payment_id' => $payment->uuid,
                    'tipo_comissao' => $tipoComissao,
                    'percent' => $percent,
                    'plan_id' => $plan->id
                ]);
            } else {
                Log::warning('setComission: Nenhum plano encontrado para calcular comissão', [
                    'payment_id' => $payment->uuid,
                    'tipo' => $tipoLower
                ]);
            }

            // ✅ Cálculo e aplicação da comissão (conforme versão de referência)
            $commission = 0.0;

            if (($sponsor->status ?? null) === 'active' && $percent > 0) {
                $commission = round($paymentValue * $percent / 100, 2);

                if ($commission > 0) {
                    $sponsor->increment('saldo', $commission);
                    $sponsor->refresh(); // Garante que o saldo foi atualizado

                    Log::info('setComission: Comissão aplicada com sucesso', [
                        'payment_id' => $payment->uuid,
                        'sponsor_id' => $sponsor->uuid,
                        'commission' => $commission,
                        'new_balance' => $sponsor->saldo
                    ]);
                } else {
                    Log::info('setComission: Comissão calculada mas zerada', [
                        'payment_id' => $payment->uuid,
                        'percent' => $percent,
                        'payment_value' => $paymentValue
                    ]);
                }
            } else {
                $reason = '';
                if (($sponsor->status ?? null) !== 'active') {
                    $reason = 'sponsor inativo';
                } elseif ($percent <= 0) {
                    $reason = 'porcentagem zerada';
                }

                Log::info('setComission: Comissão não aplicada', [
                    'payment_id' => $payment->uuid,
                    'reason' => $reason,
                    'sponsor_status' => $sponsor->status,
                    'percent' => $percent
                ]);
            }

            // ✅ Registro do log de comissão com o status aceito pelo schema.
            CommissionLog::create([
                'user_id' => $sponsor->uuid,
                'client_id' => $payer->uuid,
                'spon_id' => $sponsor->spon_id,
                'payment_id' => $payment->uuid,
                'tipo' => $tipoComissao,
                'servico' => 'Cliente_lista', // Conforme versão de referência
                'valor_servico' => $paymentValue,
                'valor' => $commission,
                // commission_log aceita apenas enabled/disabled.
                'status' => 'enabled',
            ]);

            Log::info('setComission: Log de comissão registrado', [
                'payment_id' => $payment->uuid,
                'tipo' => $tipoComissao,
                'servico' => 'Cliente_lista',
                'valor' => $commission,
                'status' => 'enabled'
            ]);
        } catch (\Throwable $e) {
            Log::error('setComission: Erro ao processar comissão', [
                'payment_id' => $payment->uuid ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public function checkPaymentUser($userId)
    {
        // Carrega usuário com pagamentos relevantes e plano atual
        $user = User::with(['payments' => function ($query) {
            $query->whereIn('status', ['pago', 'creditado'])
                ->whereNotIn('tipo', ['recarga'])
                ->where(function ($q) {
                    $q->whereIn('tipo', ['mensalidade', 'plan_consultor', 'plan_micro_franquia', 'plan_franquia'])
                        ->orWhere('tipo', 'like', 'plan_%');
                })
                ->orderBy('validade', 'desc')
                ->orderBy('updated_at', 'desc');
        }, 'plan'])->where('uuid', $userId)->first();

        if (!$user) return false;

        // ✅ Validar se usuário já está inativo: desativar todas as APIs ativas
        if ($user->status === 'disabled') {
            $this->deactivateUserApis($user);
        }

        // Administrador: ativa e pula todas as validações
        if ($user->hasPermission('administrador')) {
            if ($user->status !== 'active') {
                $user->update(['status' => 'active']);
            }
            return true;
        }

        $lastPayment = $user->payments->first();

        // Há pagamento: calcula data de validade e decide ativação
        if ($lastPayment) {
            $validUntil = null;
            if (!empty($lastPayment->validade)) {
                $validUntil = Carbon::parse($lastPayment->validade)->addDays(0);
            } else {
                $tipo = strtolower((string) ($lastPayment->tipo ?? ''));
                if ($tipo === 'mensalidade' || Str::startsWith($tipo, 'plan_') || in_array($tipo, ['plan_consultor', 'plan_micro_franquia', 'plan_franquia'])) {
                    $base = !empty($lastPayment->data_compra) ? Carbon::parse($lastPayment->data_compra) : now();
                    $validUntil = $base->copy()->addDays(30);
                    $lastPayment->validade = $validUntil->format('Y-m-d');
                    $lastPayment->save();
                }
            }

            // Pagamento válido: ativa usuário, amarra plano e aplica roles
            if ($validUntil && now()->lessThanOrEqualTo($validUntil)) {
                if ($user->status !== 'active') {
                    $user->update(['status' => 'active']);
                }

                $tipo = strtolower((string) ($lastPayment->tipo ?? ''));
                if ($tipo && $tipo !== 'recarga') {
                    $plan = $this->resolvePlanForPayment($lastPayment, $user);

                    // Sempre cria/atualiza o pivot plan_user quando encontra um plano válido
                    if ($plan) {
                        $user->planRelation()->updateOrCreate(
                            ['user_id' => $user->uuid],
                            ['plan_id' => $plan->id]
                        );

                        // Aplica roles do plano (aceita array/JSON/lista)
                        try {
                            $rolesRaw = $plan->roles ?? null;
                            $roleNames = [];
                            if (is_array($rolesRaw)) {
                                $roleNames = $rolesRaw;
                            } elseif (is_string($rolesRaw) && strlen(trim($rolesRaw)) > 0) {
                                $decoded = json_decode($rolesRaw, true);
                                if (is_array($decoded)) {
                                    $roleNames = $decoded;
                                } else {
                                    $roleNames = preg_split('/[\,;\|]+/', $rolesRaw) ?: [];
                                }
                            }
                            $roleNames = array_values(array_filter(array_map(function ($v) {
                                return strtolower(trim($v));
                            }, $roleNames)));
                            $roleIds = [];
                            if (!empty($roleNames)) {
                                $roleIds = Role::where(function ($q) use ($roleNames) {
                                    foreach ($roleNames as $name) {
                                        $q->orWhereRaw('LOWER(name) = ?', [$name]);
                                    }
                                })->pluck('roles.id')->all();
                            }
                            if (empty($roleIds)) {
                                $fallback = Role::whereRaw('LOWER(name) = ?', ['cliente'])->first();
                                if ($fallback) {
                                    $roleIds = [$fallback->id];
                                }
                            }
                            if (!empty($roleIds)) {
                                $user->roles()->sync($roleIds);
                            }
                        } catch (\Throwable $e) {
                            Log::warning('checkPaymentUser: Não foi possível aplicar roles do plano', [
                                'user_id' => $user->uuid,
                                'plan_id' => $plan->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    } elseif ($tipo === 'mensalidade' && $user->plan) {
                        // Pagamentos antigos sem referência mantêm o plano já associado.
                        $user->planRelation()->updateOrCreate(
                            ['user_id' => $user->uuid],
                            ['plan_id' => $user->plan->id]
                        );
                    }
                }

                return true;
            }

            try {
                $isExpired = true;
                if (!empty($lastPayment->validade)) {
                    $validUntilEval = Carbon::parse($lastPayment->validade)->addDays(0);
                    $isExpired = now()->greaterThan($validUntilEval);
                }

                // Pagamento vencido: tenta auto-renovar via saldo do usuário
                if ($isExpired && $user->plan) {
                    $planPrice = (float) ($user->plan->price ?? 0);
                    $planDays = (int) ($user->plan->mensalidade ?? 30);
                    $saldo = (float) ($user->saldo ?? 0);
                    if ($planPrice > 0 && $saldo >= $planPrice) {
                        try {
                            $user->decrement('saldo', $planPrice);
                            $renewPayment = Payments::create([
                                'uuid' => Str::limit(Str::uuid(), 8, ''),
                                'user_id' => $user->uuid,
                                'spon_id' => $user->spon_id,
                                'billingType' => 'BALANCE',
                                'tipo' => 'mensalidade',
                                'valor' => $planPrice,
                                'data_compra' => now()->format('Y-m-d'),
                                'validade' => now()->addDays($planDays)->format('Y-m-d'),
                                'status' => 'pago',
                            ]);

                            // Aplica comissão ao sponsor na renovação por saldo
                            self::setComission($renewPayment);

                            if ($user->status !== 'active') {
                                $user->update(['status' => 'active']);
                            }

                            // Reaplica roles conforme plano vigente
                            try {
                                $rolesRaw = $user->plan->roles ?? null;
                                $roleNames = [];
                                if (is_array($rolesRaw)) {
                                    $roleNames = $rolesRaw;
                                } elseif (is_string($rolesRaw) && strlen(trim($rolesRaw)) > 0) {
                                    $decoded = json_decode($rolesRaw, true);
                                    if (is_array($decoded)) {
                                        $roleNames = $decoded;
                                    } else {
                                        $roleNames = preg_split('/[\,;\|]+/', $rolesRaw) ?: [];
                                    }
                                }
                                $roleNames = array_values(array_filter(array_map(function ($v) {
                                    return strtolower(trim($v));
                                }, $roleNames)));
                                $roleIds = [];
                                if (!empty($roleNames)) {
                                    $roleIds = Role::where(function ($q) use ($roleNames) {
                                        foreach ($roleNames as $name) {
                                            $q->orWhereRaw('LOWER(name) = ?', [$name]);
                                        }
                                    })->pluck('id')->all();
                                }
                                if (empty($roleIds)) {
                                    $fallback = Role::whereRaw('LOWER(name) = ?', ['cliente'])->first();
                                    if ($fallback) {
                                        $roleIds = [$fallback->id];
                                    }
                                }
                                if (!empty($roleIds)) {
                                    $user->roles()->sync($roleIds);
                                }
                            } catch (\Throwable $e) {
                            }
                            return true;
                        } catch (\Throwable $e) {
                        }
                    }
                }

                // Sem renovação: desativa usuário e revoga roles (não-admin)
                $user->update(['status' => 'disabled']);
                // ✅ Desativar todas as APIs ativas do usuário
                $this->deactivateUserApis($user);
                // ✅ Desativar agentes do usuário (exceto is_trial = 1 com status = enabled)
                $this->deactivateUserAgents($user);
                if (!($user->hasPermission('administrador'))) {
                    $user->roles()->sync([3]);
                }
            } catch (\Throwable $e) {
            }
            return false;
        }

        // Sem pagamento: tenta provisionar via logs de webhook (Hotmart/Ticto/Greenn)
        $log = WebHookLogs::where(['email' => $user->email, 'status' => 'pending'])
            ->orderBy('created_at', 'desc')->first();
        if ($log && $log->product) {
            $slug = $log->product == 'Toolzapp' ? 'silver' : $log->product;
            // Identifica plano por nome/tipo
            $plan = Plans::whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($slug) . '%'])
                ->orWhereRaw('LOWER(tipo) LIKE ?', ['%' . strtolower($slug) . '%'])->first();
            if ($plan) {
                // Define validade baseada no período informado pelo webhook
                $dias = $log->period ?? 30; // ajuste conforme sua tabela Plans

                // Cria o pagamento e estabelece relação com plano
                Payments::create([
                    'uuid' => Str::limit(Str::uuid(), 8, ''),
                    'user_id' => $user->uuid,
                    'spon_id' => $user->spon_id,
                    'billingType' => 'CREDIT_CARD',
                    'tipo' => 'plan_' . $plan->tipo,
                    'data_compra' => now()->format('Y-m-d'),
                    'validade' => now()->addDays($dias)->format('Y-m-d'),
                    'status' => 'creditado',
                ]);

                // Relaciona plano e papel
                $user->planRelation()->updateOrCreate(
                    ['user_id' => $user->uuid],
                    ['plan_id' => $plan->id]
                );

                // Aplica roles definidos no plano
                try {
                    $rolesRaw = $plan->roles ?? null;
                    $roleNames = [];
                    if (is_array($rolesRaw)) {
                        $roleNames = $rolesRaw;
                    } elseif (is_string($rolesRaw) && strlen(trim($rolesRaw)) > 0) {
                        $decoded = json_decode($rolesRaw, true);
                        if (is_array($decoded)) {
                            $roleNames = $decoded;
                        } else {
                            $roleNames = preg_split('/[\,;\|]+/', $rolesRaw) ?: [];
                        }
                    }
                    $roleNames = array_values(array_filter(array_map(function ($v) {
                        return strtolower(trim($v));
                    }, $roleNames)));
                    $roleIds = [];
                    if (!empty($roleNames)) {
                        $roleIds = Role::where(function ($q) use ($roleNames) {
                            foreach ($roleNames as $name) {
                                $q->orWhereRaw('LOWER(name) = ?', [$name]);
                            }
                        })->pluck('id')->all();
                    }
                    if (empty($roleIds)) {
                        $fallback = Role::whereRaw('LOWER(name) = ?', ['cliente'])->first();
                        if ($fallback) {
                            $roleIds = [$fallback->id];
                        }
                    }
                    if (!empty($roleIds)) {
                        $user->roles()->sync($roleIds);
                    }
                } catch (\Throwable $e) {
                }
                // Ativa usuário e conclui o log de provisionamento
                $user->update(['status' => 'active']);
                $log->update(['status' => 'enabled']);

                return true;
            }
        }

        // Último recurso: desativa e remove roles
        try {
            $user->update(['status' => 'disabled']);
            // ✅ Desativar todas as APIs ativas do usuário
            $this->deactivateUserApis($user);
            // ✅ Desativar agentes do usuário (exceto is_trial = 1 com status = enabled)
            $this->deactivateUserAgents($user);
            if (!($user->hasPermission('administrador'))) {
                $user->roles()->sync([3]);
            }
        } catch (\Throwable $e) {
            Log::error('checkPaymentUser: Erro ao desativar usuário no último recurso', [
                'user_id' => $user->uuid ?? null,
                'error' => $e->getMessage()
            ]);
        }
        return false;
    }

    /**
     * Resolve o plano de um pagamento usando a referência persistida no
     * checkout, o nome do produto ou o tipo legado do pagamento.
     */
    private function resolvePlanForPayment(Payments $payment, User $user): ?Plans
    {
        $reference = trim((string) ($payment->reference ?? ''));
        if (preg_match('/^plan[:_-](\d+)$/i', $reference, $matches)) {
            $plan = Plans::find((int) $matches[1]);
            if ($plan) {
                return $plan;
            }
        }

        $identifiers = array_filter([
            trim((string) ($payment->product_name ?? '')),
            trim((string) ($payment->tipo ?? '')),
        ]);

        $candidates = [];
        foreach ($identifiers as $identifier) {
            $lower = strtolower($identifier);
            $candidates[] = $lower;
            if (Str::startsWith($lower, 'plan_')) {
                $candidates[] = substr($lower, 5);
            }
        }

        if (!empty($candidates)) {
            $plan = Plans::where(function ($query) use ($candidates) {
                foreach (array_unique(array_filter($candidates)) as $candidate) {
                    $query->orWhereRaw('LOWER(tipo) = ?', [$candidate])
                        ->orWhereRaw('LOWER(name) = ?', [$candidate])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $candidate . '%']);
                }
            })->first();

            if ($plan) {
                return $plan;
            }
        }

        return $user->plan;
    }

    /**
     * ✅ Desativa todas as APIs ativas de um usuário
     * Usado quando o usuário é inativado ou já está inativo
     */
    private function deactivateUserApis(User $user): void
    {
        try {
            $activeApisCount = $user->userApis()
                ->where('is_active', true)
                ->count();

            if ($activeApisCount > 0) {
                $deactivatedCount = $user->userApis()
                    ->where('is_active', true)
                    ->update(['is_active' => false]);

                Log::info('checkPaymentUser: APIs desativadas para usuário inativo', [
                    'user_id' => $user->uuid,
                    'total_apis_ativas' => $activeApisCount,
                    'apis_desativadas' => $deactivatedCount
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('checkPaymentUser: Erro ao desativar APIs do usuário', [
                'user_id' => $user->uuid ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * ✅ Desativa agentes do usuário quando plano inativo/vencido
     * Mantém ativos apenas agentes com is_trial = 1 e status = 'enabled'
     */
    private function deactivateUserAgents(User $user): void
    {
        try {
            // Buscar todos os agentes do usuário (exceto rascunhos)
            $allAgents = Agents::where('user_id', $user->uuid)
                ->where('status', '!=', 'draft')
                ->get();

            if ($allAgents->isEmpty()) {
                return;
            }

            $deactivatedCount = 0;
            $keptActiveCount = 0;

            foreach ($allAgents as $agent) {
                // ✅ Verificar se é trial (1, true, '1', 'true')
                $isTrial = $agent->is_trial == 1
                    || $agent->is_trial === true
                    || $agent->is_trial === '1'
                    || $agent->is_trial === 'true';

                // ✅ Manter ativo apenas se is_trial = 1 E status = 'enabled'
                if ($isTrial && $agent->status === 'enabled') {
                    $keptActiveCount++;
                    continue;
                }

                // ✅ Desativar os demais agentes que estão ativos
                if ($agent->status === 'enabled') {
                    $agent->update(['status' => 'disabled']);
                    $deactivatedCount++;
                }
            }

            if ($deactivatedCount > 0 || $keptActiveCount > 0) {
                Log::info('checkPaymentUser: Agentes processados para usuário inativo', [
                    'user_id' => $user->uuid,
                    'total_agentes' => $allAgents->count(),
                    'agentes_desativados' => $deactivatedCount,
                    'agentes_mantidos_ativos' => $keptActiveCount
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('checkPaymentUser: Erro ao processar agentes do usuário', [
                'user_id' => $user->uuid ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public static function extractTextFromPdf($path)
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);
        return $pdf->getText();
    }

    public static function extractTextFromWord($path)
    {
        $phpWord = WordReader::load($path);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                }
            }
        }

        return $text;
    }

    public function formatted_phone($phone, $matches)
    {
        if (preg_match('/^\+(\d{1})(\d{3})(\d{3})(\d{2})(\d{2})$/', $phone, $matches)) {
            $result = '+' . $matches[1] . '(' . $matches[2] . ') ' . $matches[3] . '-' . $matches[4] . '-' . $matches[5];
            return $result;
        }
        return $phone;
    }

    public function formatCnpjCpf($value)
    {
        $CPF_LENGTH = 11;
        $cnpj_cpf = preg_replace("/\D/", '', $value);

        if (strlen($cnpj_cpf) === $CPF_LENGTH) {
            return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cnpj_cpf);
        }

        return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj_cpf);
    }

    public static function MoedaBr($dados)
    {
        return number_format($dados, 2, ',', '.');
    }

    public static function FormatNumber($value)
    {
        return number_format($value, 0, ',', '.');
    }

    public static function Paginate($items, $perPage = 10, $page = null, $options = [])
    {
        $options = ['path' => url()->current()];
        $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
        $items = $items instanceof Collection ? $items : Collection::make($items);
        return new LengthAwarePaginator($items->forPage($page, $perPage), $items->count(), $perPage, $page, $options);
    }

    /**
     * @param $filePath
     * @return string
     */
    public function createContentBase64(string $filePath)
    {
        // Obter o MIME type do arquivo
        $mimeType = mime_content_type($filePath);
        // Ler o conteúdo do arquivo e convertê-lo para base64
        $fileData = base64_encode(file_get_contents($filePath));
        // Construir a string content_base64
        return "data:{$mimeType};base64,{$fileData}";
    }

    public static function generateQrCodeBase64(?string $pixCode, int $size = 320): ?string
    {
        if (empty($pixCode)) {
            return null;
        }
        // Prefer API v6 (Builder) if available
        $hasBuilder = class_exists(\Endroid\QrCode\Builder\Builder::class)
            && class_exists(\Endroid\QrCode\Writer\PngWriter::class)
            && class_exists(\Endroid\QrCode\Writer\SvgWriter::class);

        if ($hasBuilder) {
            try {
                $builderClass = '\\Endroid\\QrCode\\Builder\\Builder';
                $pngWriterClass = '\\Endroid\\QrCode\\Writer\\PngWriter';
                $builder = call_user_func([$builderClass, 'create']);
                $builder = $builder->writer(new $pngWriterClass())
                    ->data($pixCode)
                    ->size($size)
                    ->margin(1);
                $pngResult = $builder->build();
                $pngData = method_exists($pngResult, 'getString') ? $pngResult->getString() : null;
                if (!empty($pngData)) {
                    return 'data:image/png;base64,' . base64_encode($pngData);
                }
            } catch (\Throwable $exception) {
                Log::warning('Falha ao gerar QR Code PNG (Builder)', [
                    'message' => $exception->getMessage(),
                ]);
            }

            try {
                $builderClass = '\\Endroid\\QrCode\\Builder\\Builder';
                $svgWriterClass = '\\Endroid\\QrCode\\Writer\\SvgWriter';
                $builder = call_user_func([$builderClass, 'create']);
                $builder = $builder->writer(new $svgWriterClass())
                    ->data($pixCode)
                    ->size($size)
                    ->margin(1);
                $svgResult = $builder->build();
                $svgData = method_exists($svgResult, 'getString') ? $svgResult->getString() : null;
                if (!empty($svgData)) {
                    return 'data:image/svg+xml;base64,' . base64_encode($svgData);
                }
            } catch (\Throwable $exception) {
                Log::error('Falha ao gerar QR Code SVG (Builder)', [
                    'message' => $exception->getMessage(),
                ]);
            }
        } else {
            // Fallback for older Endroid versions
            $hasLegacy = class_exists(\Endroid\QrCode\QrCode::class)
                && class_exists(\Endroid\QrCode\Writer\PngWriter::class)
                && class_exists(\Endroid\QrCode\Writer\SvgWriter::class);
            if ($hasLegacy) {
                try {
                    $qr = new \Endroid\QrCode\QrCode($pixCode);
                    if (method_exists($qr, 'setSize')) {
                        $qr->setSize($size);
                    }
                    if (method_exists($qr, 'setMargin')) {
                        $qr->setMargin(1);
                    }
                    $pngWriter = new \Endroid\QrCode\Writer\PngWriter();
                    $pngResult = $pngWriter->write($qr);
                    $pngData = method_exists($pngResult, 'getString') ? $pngResult->getString() : null;
                    if (!empty($pngData)) {
                        return 'data:image/png;base64,' . base64_encode($pngData);
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Falha ao gerar QR Code PNG (Legacy)', [
                        'message' => $exception->getMessage(),
                    ]);
                }

                try {
                    $qr = new \Endroid\QrCode\QrCode($pixCode);
                    if (method_exists($qr, 'setSize')) {
                        $qr->setSize($size);
                    }
                    if (method_exists($qr, 'setMargin')) {
                        $qr->setMargin(1);
                    }
                    $svgWriter = new \Endroid\QrCode\Writer\SvgWriter();
                    $svgResult = $svgWriter->write($qr);
                    $svgData = method_exists($svgResult, 'getString') ? $svgResult->getString() : null;
                    if (!empty($svgData)) {
                        return 'data:image/svg+xml;base64,' . base64_encode($svgData);
                    }
                } catch (\Throwable $exception) {
                    Log::error('Falha ao gerar QR Code SVG (Legacy)', [
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $fallbackSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . intval($size) . '" height="' . intval($size) . '"><rect width="100%" height="100%" fill="#ffffff"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="monospace" font-size="12" fill="#000000">' . htmlspecialchars(substr($pixCode, 0, 32), ENT_QUOTES) . '...</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($fallbackSvg);
    }

    public static function generatePix($user, $payment, $valorServico, $gateway = null)
    {
        $gw = $gateway ?: (config('payments.default_gateway') ?? 'asaas');
        $g = GatewayFactory::make($gw);
        return $g->generatePix($user, $payment, floatval($valorServico));
    }

    public static function checkPaymentStatus($paymentId, $userId)
    {
        // Verificar se o pagamento já foi confirmado no banco de dados
        $payment = Payments::where(['uuid' => $paymentId, 'user_id' => $userId])
            ->whereIn('status', ['pago', 'creditado'])
            ->first();

        if ($payment) {
            return [
                'success' => true,
                'status' => 'paid',
                'payment' => $payment
            ];
        }

        // Se não encontrou no banco, pode implementar verificação externa aqui se necessário
        // Por enquanto, retorna pending
        return [
            'success' => false,
            'status' => 'pending'
        ];
    }
}
