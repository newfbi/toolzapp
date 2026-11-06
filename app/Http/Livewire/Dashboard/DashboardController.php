<?php

namespace App\Http\Livewire\Dashboard;

use App\Models\Agents;
use App\Models\Chats;
use App\Models\Messages;
use App\Models\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Livewire\Component;

class DashboardController extends Component
{
    public $user;
    public $messages;
    public $totalMessages;
    public $labelMonth;
    public $messagesMonth;
    public $totalMessagesMonth;
    public $userConvertion;
    public $totalConversionMonth;
    public $totalOfCredits;
    public $totalOfCost;
    public $creditsToday;
    public $totalCreditsMonth;
    public $creditsMonth;
    public $totalCreditsWeek;
    public $creditsWeek;
    public $totalAgents;
    public $totalChats;
    public $totalConversions;

    public function mount()
    {
        View::share('title', 'Painel de Controle');
        $settings = Settings::first();
        $this->user = Auth::User();
        // Calcula total de créditos usando a mesma lógica dos outros cálculos
        // Filtra explicitamente pelos agentes do usuário para garantir consistência
        $this->totalOfCredits = Messages::selectRaw('SUM(credit_usage) as total')
            ->whereHas('agent', function ($q) {
                $q->where('user_id', $this->user->uuid);
            })
            ->value('total') / 1000 ?? 0;

        // Calcula custo total
        // IMPORTANTE: A OpenAI cobra por 1000 tokens (não por token individual)
        // token_value deve ser o custo por 1000 tokens (ex: 0.002 = $0.002 por 1000 tokens)
        // Cálculo correto: (total_tokens / 1000) * custo_por_1000_tokens
        $tokenValue = $settings->token_value ?? 0;
        $this->totalOfCost = ($this->totalOfCredits) * $tokenValue;
        $this->creditsToday = Messages::selectRaw('SUM(credit_usage) as total')
            ->whereHas('agent', function ($q) {
                $q->where('user_id', $this->user->uuid);
            })
            ->whereDate('created_at', Carbon::now()->toDateString())
            ->value('total') / 1000 ?? 0;

        // Calcular dados dos agentes
        $this->totalAgents = Agents::where('user_id', $this->user->uuid)->count();
        $this->totalChats = Chats::whereHas('agent', function ($q) {
            $q->where('user_id', $this->user->uuid);
        })->count();
        $this->totalMessages = Messages::whereHas('agent', function ($q) {
            $q->where('user_id', $this->user->uuid);
        })->count();

        // Calcular total de conversões
        $this->totalConversions = Chats::whereHas('agent', function ($q) {
            $q->where('user_id', $this->user->uuid);
        })->where(function ($query) {
            $query->where('conversion', 'true')
                ->orWhere('conversion', true)
                ->orWhere('conversion', 1);
        })->count();

        $this->loadChartData();
    }

    public function render()
    {
        return view('livewire.dashboard.index');
    }

    public function loadChartData()
    {
        $driver = DB::connection()->getDriverName();

        /* // 🎯 1️⃣ Preencher todas as HORAS do dia (0h - 23h)
        $hours = range(0, 23);
        $salesPerHour = Payments::whereIn('status', ['pago', 'creditado'])->selectRaw('HOUR(created_at) as hour, SUM(valor) as total')
            ->whereDate('created_at', Carbon::now())
            ->groupBy('hour')
            ->pluck('total', 'hour') // Retorna array associativo [hora => total]
            ->toArray();
        // Preenche com 0 onde não houver vendas
        $this->totalSaleDay = array_map(fn($h) => $salesPerHour[$h] ?? 0, $hours); */

        // 🎯 2️⃣ Preencher todos os DIAS da SEMANA (Seg - Dom)
        $weekDays = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
        $weekDayExpression = $driver === 'sqlite'
            ? "CAST(strftime('%w', created_at) AS INTEGER)"
            : 'DAYNAME(created_at)';
        $messagesPerWeek = Messages::selectRaw("{$weekDayExpression} as day, COUNT(id) as total")
            ->whereHas('agent', function ($q) {
                $q->where('user_id', $this->user->uuid);
            })
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();

        if ($driver === 'sqlite') {
            $sqliteWeekDays = [
                0 => 'Sunday',
                1 => 'Monday',
                2 => 'Tuesday',
                3 => 'Wednesday',
                4 => 'Thursday',
                5 => 'Friday',
                6 => 'Saturday',
            ];
            $messagesPerWeek = collect($messagesPerWeek)
                ->mapWithKeys(fn ($total, $day) => [$sqliteWeekDays[(int) $day] ?? $day => $total])
                ->all();
        }

        $this->messages = array_map(fn($d) => $messagesPerWeek[$d] ?? 0, $weekDays);

        // 🎯 3️⃣ Preencher todos os DIAS do MÊS (1 - 31)
        $monthDays = range(1, 31);
        $monthDayExpression = $driver === 'sqlite'
            ? "CAST(strftime('%d', created_at) AS INTEGER)"
            : 'DAY(created_at)';
        $messagesPerMonth = Chats::selectRaw("{$monthDayExpression} as day, COUNT(id) as total")
            ->whereHas('agent', function ($q) {
                $q->where('user_id', $this->user->uuid);
            })
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();
        $this->totalMessagesMonth = array_sum($messagesPerMonth);
        $this->messagesMonth = array_map(fn($d) => $messagesPerMonth[$d] ?? 0, $monthDays);
        $this->labelMonth = collect($monthDays)->map(function ($d) {
            return 'Dia - ' . str_pad($d, 2, '0', STR_PAD_LEFT);
        })->toArray();

        // 🎯 4️⃣ Preencher todos os MESES do ANO (1 - 12) Chart
        $months = range(1, 12);
        $monthExpression = $driver === 'sqlite'
            ? "CAST(strftime('%m', created_at) AS INTEGER)"
            : 'MONTH(created_at)';
        $conversionPerMonth = Chats::selectRaw("{$monthExpression} as month, COUNT(id) as total")
            ->whereHas('agent', function ($q) {
                $q->where('user_id', $this->user->uuid);
            })
            ->where(function ($query) {
                $query->where('conversion', 'true')
                    ->orWhere('conversion', true)
                    ->orWhere('conversion', 1);
            })
            ->whereYear('created_at', Carbon::now()->year)
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();
        $this->totalConversionMonth = array_sum($conversionPerMonth);
        $this->userConvertion = array_map(fn($m) => $conversionPerMonth[$m] ?? 0, $months);

        // 🎯 5️⃣ Preencher todos os DIAS do MÊS (1 - 31) Apex
        $creditsPerMonth = Messages::selectRaw("{$monthDayExpression} as day, SUM(credit_usage) as total")
            ->whereHas('agent', function ($q) {
                $q->where('user_id', $this->user->uuid);
            })
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();
        $this->totalCreditsMonth = array_sum($creditsPerMonth);
        $this->creditsMonth = array_map(fn($d) => $creditsPerMonth[$d] ?? 0, $monthDays);

        // 🎯 6️⃣ Preencher todos os DIAS da SEMANA (Seg - Dom) Apex
        $creditsPerWeek = Messages::selectRaw("{$weekDayExpression} as day, SUM(credit_usage) as total")
            ->whereHas('agent', function ($q) {
                $q->where('user_id', $this->user->uuid);
            })
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();

        if ($driver === 'sqlite') {
            $creditsPerWeek = collect($creditsPerWeek)
                ->mapWithKeys(fn ($total, $day) => [$sqliteWeekDays[(int) $day] ?? $day => $total])
                ->all();
        }

        $this->totalCreditsWeek = array_sum($creditsPerWeek) / 1000 ?? 0;
        $this->creditsWeek = array_map(fn($d) => $creditsPerWeek[$d] ?? 0, $weekDays);
    }
}
