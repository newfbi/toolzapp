<?php

namespace App\Jobs;

use App\Models\SmsDispatch;
use App\Services\SmsDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSmsDispatch implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 240;
    public int $uniqueFor = 86400;

    public function __construct(public string $dispatchUuid)
    {
    }

    public function uniqueId(): string
    {
        return $this->dispatchUuid;
    }

    public function middleware(): array
    {
        return [
            (new \Illuminate\Queue\Middleware\WithoutOverlapping('sms-dispatch:' . $this->dispatchUuid))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(SmsDispatchService $service): void
    {
        $dispatch = SmsDispatch::where('uuid', $this->dispatchUuid)->first();
        if (!$dispatch || in_array($dispatch->status, ['completed', 'cancelled', 'failed'], true)) {
            return;
        }

        $service->processDueItems($dispatch);
        $dispatch->refresh();

        // A entrega pode chegar pelo webhook ou pela consulta de fallback.
        SyncSmsDispatchStatus::dispatch($dispatch->uuid)->delay(now()->addSeconds(60));

        $nextWakeAt = $service->nextWakeAt($dispatch);
        if ($nextWakeAt) {
            $delaySeconds = max(5, min(now()->diffInSeconds($nextWakeAt), 86400));
            self::dispatch($this->dispatchUuid)->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Job de disparo SMS falhou', [
            'dispatch_uuid' => $this->dispatchUuid,
            'error' => $e->getMessage(),
        ]);
    }
}
