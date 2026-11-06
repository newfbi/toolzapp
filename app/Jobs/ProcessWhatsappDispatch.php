<?php

namespace App\Jobs;

use App\Models\WhatsappDispatch;
use App\Services\WhatsappDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processa um ciclo do disparo e se re-enfileira enquanto houver itens
 * pendentes. O encadeamento usa um NOVO job agendado para o horário do
 * próximo item (release() esgotaria as tentativas em campanhas longas
 * com silêncio noturno).
 */
class ProcessWhatsappDispatch implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** Segundos de processamento máximo por ciclo */
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
            (new \Illuminate\Queue\Middleware\WithoutOverlapping('whatsapp-dispatch:' . $this->dispatchUuid))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(WhatsappDispatchService $service): void
    {
        $dispatch = WhatsappDispatch::where('uuid', $this->dispatchUuid)->first();

        if (!$dispatch || in_array($dispatch->status, ['completed', 'cancelled', 'failed'])) {
            return;
        }

        $service->processDueItems($dispatch);

        $dispatch->refresh();

        // A service considera pacing, silêncio noturno e quotas da instância.
        // Assim, o job não fica acordando em loop quando a quota está cheia.
        $nextWakeAt = $service->nextWakeAt($dispatch);

        if ($nextWakeAt) {
            $delaySeconds = max(
                45,
                min(now()->diffInSeconds($nextWakeAt), 86400)
            );

            self::dispatch($this->dispatchUuid)
                ->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Job de disparo falhou', [
            'dispatch_uuid' => $this->dispatchUuid,
            'error' => $e->getMessage(),
        ]);
    }
}
