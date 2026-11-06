<?php

namespace App\Jobs;

use App\Models\SmsDispatch;
use App\Services\SmsDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSmsDispatchStatus implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;
    public int $uniqueFor = 300;

    public function __construct(public string $dispatchUuid)
    {
    }

    public function uniqueId(): string
    {
        return $this->dispatchUuid;
    }

    public function handle(SmsDispatchService $service): void
    {
        $dispatch = SmsDispatch::where('uuid', $this->dispatchUuid)->first();
        if (!$dispatch || $dispatch->status === 'cancelled') {
            return;
        }

        $hasPendingProviderStatus = $service->syncStatuses($dispatch);
        $dispatch->refresh();

        // A Infobip mantém os relatórios de SMS por até 48h. O polling é
        // encerrado depois desse período para não deixar uma campanha antiga
        // consumindo fila indefinidamente.
        $pollHours = max(1, (int) config('services.sms.status_poll_hours', 48));
        if ($hasPendingProviderStatus && $dispatch->created_at?->gt(now()->subHours($pollHours))) {
            self::dispatch($dispatch->uuid)->delay(now()->addSeconds(60));
        }
    }
}
