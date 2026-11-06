<?php

namespace App\Http\Livewire\Admin;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use App\Models\{
    Agents,
    User,
    Chats,
    Messages,
    Settings,
    WebHookLogs
};
use Carbon\Carbon;

class Admin extends Component
{
    public $user;
    public $totalUsers;
    public $activeUsers;
    public $inactiveUsers;
    public $pindingLog;
    public $totalOfCredits;
    public $totalOfCost;
    public $creditsToday;
    public $totalCreditsMonth;
    public $creditsMonth;
    public $totalCreditsWeek;
    public $creditsWeek;
    public $totalAgents;
    public $totalChats;
    public $totalMessages;

    public $messages;
    public $labelMonth;
    public $messagesMonth;
    public $totalMessagesMonth;
    public $userConvertion;
    public $totalConversionMonth;

    public function mount()
    {
        view()->share('title', 'Administração');
        $settings = Settings::first();
        $this->user = Auth::user();
        $this->totalUsers = User::count();
        $this->activeUsers = User::where('status', 'active')->count();
        $this->inactiveUsers = User::where('status', 'disabled')->count();
        $this->pindingLog = WebHookLogs::where('status', 'pending')->count();
        $this->totalOfCredits = Messages::selectRaw('SUM(credit_usage) as total')->value('total') / 1000 ?? 0;
        $this->totalOfCost = ($this->totalOfCredits) * $settings->token_value;
        $this->creditsToday = Messages::selectRaw('SUM(credit_usage) as total')
            ->whereDate('created_at', Carbon::now()->toDateString())->value('total') / 1000 ?? 0;
        $this->loadChartData();
        $this->totalAgents = Agents::count();
        $this->totalChats = Chats::count();
        $this->totalMessages = Messages::count();
    }

    public function render()
    {
        return view('livewire.admin.admin');
    }

    public function loadChartData()
    {
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
        /* $weekDays = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
        $messagesPerWeek = Messages::selectRaw('DAYNAME(created_at) as day, COUNT(id) as total')
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();
        $this->totalMessages = array_sum($messagesPerWeek);
        $this->messages = array_map(fn($d) => $messagesPerWeek[$d] ?? 0, $weekDays); */

        // 🎯 3️⃣ Preencher todos os DIAS do MÊS (1 - 31)
        /*  $monthDays = range(1, 31);
        $messagesPerMonth = Chats::selectRaw('DAY(created_at) as day, COUNT(id) as total')
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();
        $this->totalMessagesMonth = array_sum($messagesPerMonth);
        $this->messagesMonth = array_map(fn($d) => $messagesPerMonth[$d] ?? 0, $monthDays);
        $this->labelMonth = collect($monthDays)->map(function ($d) {
            return 'Dia - ' . str_pad($d, 2, '0', STR_PAD_LEFT);
        })->toArray(); */

        // 🎯 4️⃣ Preencher todos os DIAS do MÊS (1 - 31) Chart
        /* $conversionPerMonth = Chats::selectRaw('MONTH(created_at) as month, COUNT(id) as total')
            ->where('conversion', 'true')
            ->whereYear('created_at', Carbon::now()->year)
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();
        $this->totalConversionMonth = array_sum($conversionPerMonth);
        $this->userConvertion = array_map(fn($m) => $conversionPerMonth[$m] ?? 0, $monthDays); */

        // 🎯 5️⃣ Preencher todos os DIAS do MÊS (1 - 31) Apex
        $monthDays = range(1, 31);
        $creditsPerMonth = Messages::selectRaw('DAY(created_at) as day, SUM(credit_usage) as total')
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();
        $this->totalCreditsMonth = array_sum($creditsPerMonth);
        $this->creditsMonth = array_map(fn($d) => $creditsPerMonth[$d] ?? 0, $monthDays);

        // 🎯 6️⃣ Preencher todos os DIAS da SEMANA (Seg - Dom) Apex
        $weekDays = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
        $creditsPerWeek = Messages::selectRaw('DAYNAME(created_at) as day, SUM(credit_usage) as total')
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();
        $this->totalCreditsWeek = array_sum($creditsPerWeek) / 1000 ?? 0;
        $this->creditsWeek = array_map(fn($d) => $creditsPerWeek[$d] ?? 0, $weekDays);
    }
}
