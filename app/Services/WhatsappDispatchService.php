<?php

namespace App\Services;

use App\Helpers\PhoneHelper;
use App\Models\Chats;
use App\Models\Messages;
use App\Models\UserList;
use App\Models\WhatsappDispatch;
use App\Models\WhatsappDispatchItem;
use App\Models\WhatsappInstance;
use App\Models\WhatsappLayout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orquestra disparos assistidos de mensagens para leads com proteção
 * contra spam/banimento.
 *
 * Este NÃO é um sistema de disparo em massa: os limites abaixo são
 * deliberadamente conservadores para alertas de marketing e avisos.
 *
 * Regras de conformidade aplicadas em camadas:
 * 1. Agendamento previsível: intervalo mínimo fixo entre mensagens
 * 2. Janela de descanso: nada é enviado durante QUIET_START_HOUR–QUIET_END_HOUR
 *    (America/Sao_Paulo)
 * 3. Limites globais POR INSTÂNCIA (somando todas as campanhas):
 *    - máx. HOURLY_LIMIT mensagens/hora
 *    - máx. DAILY_LIMIT mensagens/dia
 * 4. Instância precisa estar realmente conectada (checado antes de CADA envio)
 * 5. Retry limitado por item (MAX_ATTEMPTS) re-agendado pelo mesmo pacing
 * 6. Watchdog: disparo sem progresso há mais de DISPATCH_STALL_HOURS é encerrado
 */
class WhatsappDispatchService
{
    /** Intervalo fixo mínimo entre mensagens da mesma fila (segundos) */
    public const MIN_DELAY_SECONDS = 60;

    /** Máximo de envios por hora por instância (todas as campanhas somadas) */
    public const HOURLY_LIMIT = 15;

    /** Faixa operacional desejada por dia, sem obrigação de atingir o mínimo */
    public const DAILY_TARGET_MIN = 120;

    /** Máximo de envios por dia por instância */
    public const DAILY_LIMIT = 150;

    /** Início da janela de descanso (hora) */
    public const QUIET_START_HOUR = 0;

    /** Fim da janela de descanso (hora) */
    public const QUIET_END_HOUR = 8;

    /** Fuso para as regras de horário */
    public const TIMEZONE = 'America/Sao_Paulo';

    /** Tentativas por item antes de marcar como falhado */
    public const MAX_ATTEMPTS_PER_ITEM = 2;

    /** Horas sem progresso até o watchdog encerrar o disparo */
    public const DISPATCH_STALL_HOURS = 72;

    /** Itens processados por ciclo do job */
    public const ITEMS_PER_RUN = 8;

    /**
     * Cria o disparo: valida layout/instância/contatos, filtra telefones
     * inválidos, pula quem JÁ RECEBEU este layout (dedupe por layout×contato,
     * a menos que $includeAlreadySent) e agenda com espaçamento fixo.
     *
     * @return array{dispatch: WhatsappDispatch, invalid_phones: int, already_received: int, without_opt_in: int, eta: Carbon}
     */
    public function createDispatch(string $userId, string $layoutUuid, string $agentId, array $contactUuids, bool $includeAlreadySent = false): array
    {
        $layout = WhatsappLayout::forUser($userId)
            ->where('uuid', $layoutUuid)
            ->where('status', 'active')
            ->first();
        if (!$layout) {
            throw new \InvalidArgumentException('Layout inválido ou inativo.');
        }

        // A instância emissora precisa pertencer ao usuário. O estado real é
        // validado no bridge porque o banco pode ficar transitariamente atrás
        // durante uma reconexão do Baileys.
        $instance = WhatsappInstance::where('agent_id', $agentId)
            ->where('user_id', $userId)
            ->first();
        if (!$instance) {
            throw new \InvalidArgumentException('A instância selecionada não pertence ao usuário.');
        }

        if (!$this->isInstanceConnected($agentId)) {
            if ($instance->status === 'connected') {
                $instance->update([
                    'status' => 'disconnected',
                    'error_message' => 'Conexão perdida. Reconecte a instância antes de disparar.',
                    'last_activity' => now(),
                ]);
            }

            throw new \InvalidArgumentException('A instância selecionada não está conectada.');
        }

        if ($instance->status !== 'connected' || $instance->error_message !== null) {
            $instance->update([
                'status' => 'connected',
                'error_message' => null,
                'connected_at' => $instance->connected_at ?: now(),
                'last_activity' => now(),
            ]);
        }

        if (empty($contactUuids)) {
            throw new \InvalidArgumentException('Nenhum contato selecionado.');
        }

        $contacts = UserList::forUser($userId)
            ->where('status', 'active')
            ->whereIn('uuid', $contactUuids)
            ->get();
        if ($contacts->isEmpty()) {
            throw new \InvalidArgumentException('Contatos não encontrados.');
        }

        $withoutOptIn = $contacts->where('whatsapp_opt_in', false)->count();
        $contacts = $contacts->filter(fn (UserList $contact) => (bool) $contact->whatsapp_opt_in);

        if ($contacts->isEmpty()) {
            throw new \InvalidArgumentException(
                'Nenhum contato elegível: marque o campo opt_in como verdadeiro para os contatos que solicitaram receber mensagens.'
            );
        }

        // DEDUPE: quem já recebeu ESTE layout fica fora (layouts novos continuam liberados)
        $alreadyReceived = 0;
        if (!$includeAlreadySent) {
            $alreadyReceived = WhatsappDispatchItem::where('layout_id', $layout->uuid)
                ->where('status', 'sent')
                ->whereIn('user_list_id', $contacts->pluck('uuid'))
                ->distinct('user_list_id')
                ->count('user_list_id');

            if ($alreadyReceived > 0) {
                $receivedIds = WhatsappDispatchItem::where('layout_id', $layout->uuid)
                    ->where('status', 'sent')
                    ->whereIn('user_list_id', $contacts->pluck('uuid'))
                    ->distinct()
                    ->pluck('user_list_id');
                $contacts = $contacts->reject(fn ($c) => $receivedIds->contains($c->uuid));
            }
        }
        if ($contacts->isEmpty()) {
            throw new \InvalidArgumentException(
                "Todos os {$alreadyReceived} contatos do escopo já receberam esta mensagem. Escolha outro layout ou marque a opção de reenvio."
            );
        }

        // Monta os itens com espaçamento fixo e previsível.
        $items = [];
        $invalidPhones = 0;
        $cursor = now()->addSeconds(15); // pequena folga inicial

        foreach ($contacts as $contact) {
            $normalized = PhoneHelper::normalize((string) $contact->phone);
            if (!$normalized['phone']) {
                $invalidPhones++;
                continue;
            }

            $cursor = $this->nextAllowedSlot($cursor);

            $items[] = [
                'uuid' => Str::limit(Str::uuid(), 8, ''),
                'dispatch_id' => null,
                'layout_id' => $layout->uuid,
                'user_list_id' => $contact->uuid,
                'phone' => $normalized['phone'],
                'status' => 'pending',
                'attempts' => 0,
                'scheduled_at' => $cursor->copy(),
            ];

            // Próxima mensagem: não gerar rajadas nem simular comportamento humano.
            $cursor = $cursor->addSeconds(self::MIN_DELAY_SECONDS);
        }

        if (empty($items)) {
            throw new \InvalidArgumentException('Nenhum dos contatos selecionados possui telefone válido.');
        }

        $dispatch = DB::transaction(function () use ($userId, $layout, $agentId, $items) {
            $dispatch = WhatsappDispatch::create([
                'user_id' => $userId,
                'layout_id' => $layout->uuid,
                'agent_id' => $agentId,
                'total_items' => count($items),
                'status' => 'pending',
            ]);

            foreach (array_chunk($items, 200) as $chunk) {
                foreach ($chunk as &$row) {
                    $row['dispatch_id'] = $dispatch->id;
                    $row['created_at'] = now();
                    $row['updated_at'] = now();
                }
                unset($row);
                WhatsappDispatchItem::insert($chunk);
            }

            return $dispatch;
        });

        $eta = $this->estimateCompletionAt($agentId, count($items), $cursor);

        Log::info('Disparo criado', [
            'dispatch_uuid' => $dispatch->uuid,
            'user_id' => $userId,
            'agent_id' => $agentId,
            'itens' => count($items),
            'telefones_invalidos' => $invalidPhones,
            'eta' => $eta->toDateTimeString(),
        ]);

        return [
            'dispatch' => $dispatch,
            'invalid_phones' => $invalidPhones,
            'already_received' => $alreadyReceived,
            'without_opt_in' => $withoutOptIn,
            'eta' => $eta,
        ];
    }

    /**
     * Empurra o horário para fora da janela de descanso configurada.
     *
     * @param \Carbon\Carbon|\Illuminate\Support\Carbon $cursor
     */
    public function nextAllowedSlot($cursor)
    {
        $local = $cursor->copy()->setTimezone(self::TIMEZONE);
        if ($this->isQuietPeriod($local)) {
            $quietStart = self::QUIET_START_HOUR * 60;
            $quietEnd = self::QUIET_END_HOUR * 60;
            $currentMinutes = ((int) $local->format('G') * 60) + (int) $local->format('i');

            if ($quietStart < $quietEnd || $currentMinutes < $quietStart) {
                $local->setTime(self::QUIET_END_HOUR, 0, 0);
            } else {
                $local->addDay()->setTime(self::QUIET_END_HOUR, 0, 0);
            }
        }

        return $local->setTimezone(config('app.timezone', 'UTC'));
    }

    private function isQuietPeriod(Carbon $local): bool
    {
        $minutes = ((int) $local->format('G') * 60) + (int) $local->format('i');
        $quietStart = self::QUIET_START_HOUR * 60;
        $quietEnd = self::QUIET_END_HOUR * 60;

        if ($quietStart < $quietEnd) {
            return $minutes >= $quietStart && $minutes < $quietEnd;
        }

        return $minutes >= $quietStart || $minutes < $quietEnd;
    }

    private function minutesUntilQuietStart(Carbon $local): int
    {
        $quietStart = self::QUIET_START_HOUR * 60;
        $quietEnd = self::QUIET_END_HOUR * 60;
        $currentMinutes = ((int) $local->format('G') * 60) + (int) $local->format('i');

        if ($quietStart < $quietEnd) {
            $target = $local->copy()->startOfDay()->addDay()->setTime(self::QUIET_START_HOUR, 0, 0);
        } elseif ($currentMinutes < $quietStart) {
            $target = $local->copy()->setTime(self::QUIET_START_HOUR, 0, 0);
        } else {
            $target = $local->copy()->addDay()->setTime(self::QUIET_START_HOUR, 0, 0);
        }

        return max(0, (int) $local->diffInMinutes($target));
    }

    /**
     * Enviados pela instância na última hora (todas as campanhas)
     */
    public function hourlyCount(string $agentId): int
    {
        return WhatsappDispatchItem::whereHas('dispatch', fn ($q) => $q->where('agent_id', $agentId))
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->subHour())
            ->count();
    }

    /**
     * Enviados pela instância hoje (todas as campanhas)
     */
    public function dailyCount(string $agentId): int
    {
        return WhatsappDispatchItem::whereHas('dispatch', fn ($q) => $q->where('agent_id', $agentId))
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * Estima o término considerando a quota diária e a janela de envio.
     * É uma previsão conservadora para a interface; o worker continua sendo
     * a fonte da verdade e respeita também a quota horária em tempo real.
     */
    private function estimateCompletionAt(string $agentId, int $itemCount, Carbon $pacingEnd): Carbon
    {
        $localNow = now()->setTimezone(self::TIMEZONE);
        $dailyUsed = $this->dailyCount($agentId);
        $remaining = $itemCount;

        $availableToday = 0;
        $hour = (int) $localNow->format('G');
        if (!$this->isQuietPeriod($localNow)) {
            $minutesUntilQuiet = $this->minutesUntilQuietStart($localNow);
            $windowCapacity = (int) floor(($minutesUntilQuiet / 60) * self::HOURLY_LIMIT);
            $availableToday = max(0, min(self::DAILY_LIMIT - $dailyUsed, $windowCapacity));
        }

        $remaining -= $availableToday;
        if ($remaining <= 0) {
            return $pacingEnd;
        }

        $daysAhead = 1;
        while ($remaining > self::DAILY_LIMIT) {
            $remaining -= self::DAILY_LIMIT;
            $daysAhead++;
        }

        $etaLocal = $localNow->copy()
            ->startOfDay()
            ->addDays($daysAhead)
            ->setTime(self::QUIET_END_HOUR, 0, 0)
            ->addHours((int) ceil($remaining / self::HOURLY_LIMIT));
        $eta = $etaLocal->setTimezone(config('app.timezone', 'UTC'));

        return $eta->greaterThan($pacingEnd) ? $eta : $pacingEnd;
    }

    /**
     * Próxima janela em que o job deve acordar.
     *
     * Quando uma quota foi atingida, evita ciclos de 45s sem possibilidade
     * de envio e agenda o próximo ciclo para a liberação real da quota.
     */
    public function nextWakeAt(WhatsappDispatch $dispatch): ?Carbon
    {
        $nextScheduledAt = $dispatch->items()
            ->where('status', 'pending')
            ->min('scheduled_at');

        if (!$nextScheduledAt) {
            return null;
        }

        $next = Carbon::parse($nextScheduledAt);
        $now = now();
        $allowedAt = $this->nextAllowedSlot($now);

        if ($next->lt($allowedAt)) {
            $next = $allowedAt;
        }

        $latestSentAt = WhatsappDispatchItem::whereHas(
            'dispatch',
            fn ($query) => $query->where('agent_id', $dispatch->agent_id)
        )
            ->where('status', 'sent')
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->value('sent_at');

        if ($latestSentAt) {
            $pacingRelease = Carbon::parse($latestSentAt)->addSeconds(self::MIN_DELAY_SECONDS);
            if ($next->lt($pacingRelease)) {
                $next = $pacingRelease;
            }
        }

        if ($this->hourlyCount($dispatch->agent_id) >= self::HOURLY_LIMIT) {
            $oldestRecentSentAt = WhatsappDispatchItem::whereHas(
                'dispatch',
                fn ($query) => $query->where('agent_id', $dispatch->agent_id)
            )
                ->where('status', 'sent')
                ->where('sent_at', '>=', $now->copy()->subHour())
                ->orderBy('sent_at')
                ->value('sent_at');

            if ($oldestRecentSentAt) {
                $hourlyRelease = $this->nextAllowedSlot(
                    Carbon::parse($oldestRecentSentAt)->addHour()->addSecond()
                );
                if ($next->lt($hourlyRelease)) {
                    $next = $hourlyRelease;
                }
            }
        }

        if ($this->dailyCount($dispatch->agent_id) >= self::DAILY_LIMIT) {
            $dailyRelease = $this->nextAllowedSlot($now->copy()->startOfDay()->addDay());
            if ($next->lt($dailyRelease)) {
                $next = $dailyRelease;
            }
        }

        return $this->nextAllowedSlot($next);
    }

    /**
     * Verifica conexão real da instância no servidor Node (Baileys)
     */
    public function isInstanceConnected(string $agentId): bool
    {
        $instance = WhatsappInstance::where('agent_id', $agentId)->first();
        if ($instance && app(WhatsappProviderService::class)->isZapi($instance)) {
            return app(WhatsappProviderService::class)->isConnected($instance);
        }

        try {
            $base = rtrim((string) config('services.whatsapp.internal_url'), '/');
            $request = Http::timeout(6);
            $token = config('services.whatsapp.http_token');
            if ($token) {
                $request = $request->withHeaders(['X-Dispatch-Token' => $token]);
            }
            $response = $request->get("{$base}/instance-status/{$agentId}");

            return $response->ok() && $response->json('connected') === true;
        } catch (\Throwable $e) {
            Log::warning('Disparo: falha ao checar instância', ['agent_id' => $agentId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Processa um ciclo do disparo: envia os itens devidos respeitando
     * limites por instância e conexão. Chamado pelo job encadeado.
     */
    public function processDueItems(WhatsappDispatch $dispatch): void
    {
        $dispatch->refresh();

        if (!in_array($dispatch->status, ['pending', 'processing'])) {
            return; // completed/cancelled/failed
        }

        // Watchdog baseado no último progresso, não no início da campanha.
        // Uma fila pode atravessar a quota diária e permanecer pendente sem
        // estar travada.
        $lastProgressAt = $dispatch->last_progress_at ?: $dispatch->started_at;
        if ($lastProgressAt && $lastProgressAt->diffInHours(now()) > self::DISPATCH_STALL_HOURS) {
            $dispatch->update(['status' => 'failed', 'completed_at' => now()]);
            Log::warning('Disparo encerrado pelo watchdog', ['dispatch_uuid' => $dispatch->uuid]);

            return;
        }

        // A fila pode ter sido enfileirada antes de uma alteração da janela
        // de descanso. Revalida aqui, antes de reivindicar qualquer item,
        // para impedir que um job antigo envie fora do horário permitido.
        $allowedAt = $this->nextAllowedSlot(now());
        if ($allowedAt->isFuture()) {
            Log::info('Disparo aguardando janela permitida', [
                'dispatch_uuid' => $dispatch->uuid,
                'next_allowed_at' => $allowedAt->toDateTimeString(),
            ]);

            return;
        }

        if ($dispatch->status === 'pending') {
            $dispatch->update([
                'status' => 'processing',
                'started_at' => now(),
                'last_progress_at' => now(),
            ]);
        }

        // Recupera itens que ficaram em processamento após uma queda do worker.
        $dispatch->items()
            ->where('status', 'processing')
            ->whereNotNull('processing_at')
            ->where('processing_at', '<', now()->subMinutes(15))
            ->update([
                'status' => 'pending',
                'processing_at' => null,
                'claim_token' => null,
            ]);

        $layout = WhatsappLayout::where('uuid', $dispatch->layout_id)->first();
        if (!$layout) {
            $this->failDispatch($dispatch, 'Layout removido antes da conclusão do disparo.');

            return;
        }

        $claimToken = (string) Str::uuid();
        $dueItems = DB::transaction(function () use ($dispatch, $claimToken) {
            $query = $dispatch->items()
                ->where('status', 'pending')
                ->where('scheduled_at', '<=', now())
                ->orderBy('scheduled_at')
                ->limit(self::ITEMS_PER_RUN);

            $items = DB::getDriverName() === 'mysql'
                ? $query->lock('FOR UPDATE SKIP LOCKED')->get()
                : $query->lockForUpdate()->get();

            if ($items->isNotEmpty()) {
                $dispatch->items()
                    ->whereIn('id', $items->pluck('id'))
                    ->update([
                        'status' => 'processing',
                        'processing_at' => now(),
                        'claim_token' => $claimToken,
                    ]);
            }

            return $items;
        });

        try {
            $deliveryAttempted = false;
            foreach ($dueItems as $item) {
                // Camada 3: limites globais por instância (param o ciclo, não falham o item)
                if ($this->hourlyCount($dispatch->agent_id) >= self::HOURLY_LIMIT) {
                    break;
                }
                if ($this->dailyCount($dispatch->agent_id) >= self::DAILY_LIMIT) {
                    break;
                }

                // O agendamento continua sendo seguro mesmo se o worker ficar
                // atrasado e vários itens se tornarem devidos de uma vez.
                $lastSentAt = WhatsappDispatchItem::whereHas(
                    'dispatch',
                    fn ($query) => $query->where('agent_id', $dispatch->agent_id)
                )
                    ->where('status', 'sent')
                    ->whereNotNull('sent_at')
                    ->orderByDesc('sent_at')
                    ->value('sent_at');
                if ($lastSentAt && Carbon::parse($lastSentAt)->addSeconds(self::MIN_DELAY_SECONDS)->isFuture()) {
                    break;
                }

                // Camada 4: instância precisa estar conectada AGORA
                if (!$this->isInstanceConnected($dispatch->agent_id)) {
                    break;
                }

                $contact = UserList::where('uuid', $item->user_list_id)->first();
                if (!$contact) {
                    $item->update([
                        'status' => 'failed',
                        'processing_at' => null,
                        'claim_token' => null,
                        'error_message' => 'Contato removido da lista',
                    ]);
                    continue;
                }

                // A pausa do chat controla apenas a automação do Agente. Ela
                // não é um opt-out de marketing: um contato pode continuar
                // recebendo novos layouts enquanto permanecer autorizado na
                // lista. O opt-in é revalidado também no momento do envio,
                // para cobrir uma saída registrada depois do agendamento.
                if (!(bool) $contact->whatsapp_opt_in) {
                    $item->update([
                        'status' => 'skipped',
                        'processing_at' => null,
                        'claim_token' => null,
                        'error_message' => 'Contato sem autorização para receber mensagens de marketing',
                    ]);
                    continue;
                }

                $sent = $this->sendItem($dispatch, $layout, $item, $contact);
                $deliveryAttempted = true;

                if (!$sent && $item->attempts < self::MAX_ATTEMPTS_PER_ITEM) {
                    // Falha transiente: pausa o ciclo e tenta no próximo com novo slot
                    break;
                }

                if ($deliveryAttempted) {
                    break;
                }
            }
        } finally {
            // Itens não consumidos neste ciclo voltam a ficar elegíveis.
            $dispatch->items()
                ->where('claim_token', $claimToken)
                ->where('status', 'processing')
                ->update([
                    'status' => 'pending',
                    'processing_at' => null,
                    'claim_token' => null,
                ]);
        }

        $this->refreshCounters($dispatch);
    }

    /**
     * Envia um item via servidor Node e registra o resultado.
     * Em falha com tentativas restantes, re-agenda pelo mesmo pacing.
     */
    private function sendItem(WhatsappDispatch $dispatch, WhatsappLayout $layout, WhatsappDispatchItem $item, UserList $contact): bool
    {
        $text = $layout->renderForLead($contact);

        $payload = [
            'agent_id' => $dispatch->agent_id,
            'to' => PhoneHelper::toWhatsAppJid((string) $item->phone),
            'text' => $text,
        ];

        if ($layout->hasImage()) {
            if ($layout->isLocalMedia()) {
                $payload['media_path'] = $layout->getMediaAbsolutePath();
            } else {
                $payload['media_url'] = $layout->media_source;
            }
        }

        $deliveryUncertain = false;
        $instance = WhatsappInstance::where('agent_id', $dispatch->agent_id)
            ->where('user_id', $dispatch->user_id)
            ->first();

        if ($instance && app(WhatsappProviderService::class)->isZapi($instance)) {
            $provider = app(WhatsappProviderService::class);
            $zapiResult = $layout->hasImage()
                ? $provider->sendImage(
                    $instance,
                    (string) $item->phone,
                    $layout->getImageDisplayUrl() ?: $layout->media_source,
                    $text
                )
                : $provider->sendText($instance, (string) $item->phone, $text);

            $success = ($zapiResult['success'] ?? false) === true;
            $whatsappMessageId = $zapiResult['message_id'] ?? null;
            $deliveryStatus = $zapiResult['delivery_status'] ?? ($whatsappMessageId ? 'accepted' : 'unknown');
            $deliveryUncertain = ($zapiResult['delivery_uncertain'] ?? false) === true;
            $error = $zapiResult['error'] ?? 'Falha no envio pela Z-API';
        } else {
            try {
            $base = rtrim((string) config('services.whatsapp.internal_url'), '/');
            $request = Http::timeout(45);

            $token = config('services.whatsapp.http_token');
            if ($token) {
                $request = $request->withHeaders(['X-Dispatch-Token' => $token]);
            }

            $response = $request->post("{$base}/send-direct", $payload);
            $body = $response->json() ?? [];
            $success = ($body['success'] ?? false) === true;
            $whatsappMessageId = $body['message_id'] ?? null;
            $deliveryStatus = $body['delivery_status'] ?? ($whatsappMessageId ? 'accepted' : 'unknown');
            $deliveryUncertain = ($body['delivery_uncertain'] ?? false) === true;
            $error = $body['error'] ?? ("HTTP {$response->status()}");
        } catch (\Throwable $e) {
            $success = false;
            $whatsappMessageId = null;
            $deliveryStatus = 'unknown';
            $error = $e->getMessage();
            // Uma falha de transporte não permite saber se o Node recebeu e
            // enviou o item. Repetir automaticamente poderia duplicar a mensagem.
            $deliveryUncertain = true;
            }
        }

        $item->attempts++;

        if ($success) {
            $item->update([
                'status' => 'sent',
                'whatsapp_message_id' => $whatsappMessageId,
                'delivery_status' => $deliveryStatus,
                'sent_at' => now(),
                'processing_at' => null,
                'claim_token' => null,
                'error_message' => null,
            ]);

            $this->recordDispatchInChatHistory(
                $dispatch,
                $layout,
                $item,
                $contact,
                $text,
                $whatsappMessageId,
                $deliveryStatus
            );

            return true;
        }

        if ($deliveryUncertain) {
            $item->update([
                'status' => 'failed',
                'processing_at' => null,
                'claim_token' => null,
                'error_message' => mb_substr(
                    'Resultado inconclusivo; reenvio automático bloqueado para evitar duplicidade: ' . $error,
                    0,
                    500
                ),
            ]);

            Log::warning('Disparo: resultado inconclusivo; item não será reenviado automaticamente', [
                'item_uuid' => $item->uuid,
                'error' => $error,
            ]);

            return false;
        }

        // Falha: agenda nova tentativa pelo mesmo pacing ou marca como falhado
        if ($item->attempts >= self::MAX_ATTEMPTS_PER_ITEM) {
            $item->update([
                'status' => 'failed',
                'processing_at' => null,
                'claim_token' => null,
                'error_message' => mb_substr($error, 0, 500),
            ]);

            return false;
        }

        $item->update([
            'attempts' => $item->attempts,
            'status' => 'pending',
            'processing_at' => null,
            'claim_token' => null,
            'scheduled_at' => $this->nextAllowedSlot(now()->addMinutes(3)),
            'error_message' => mb_substr($error, 0, 500),
        ]);

        Log::warning('Disparo: item falhou, será reenviado', [
            'item_uuid' => $item->uuid,
            'attempt' => $item->attempts,
            'error' => $error,
        ]);

        return false;
    }

    /**
     * Registra o chat e a mensagem de disparo no histórico para que
     * agentes e parceiros possam visualizar e responder no painel de Chats.
     */
    private function recordDispatchInChatHistory(
        WhatsappDispatch $dispatch,
        WhatsappLayout $layout,
        WhatsappDispatchItem $item,
        UserList $contact,
        string $text,
        ?string $whatsappMessageId = null,
        ?string $deliveryStatus = null
    ): void {
        try {
            $canonicalPhone = (string) $item->phone;
            $whatsappJid = PhoneHelper::toWhatsAppJid($canonicalPhone);
            $cleanDigits = preg_replace('/[^\d]/', '', $canonicalPhone);

            // Buscar se já existe um chat para este lead com este agente ou usuário
            $chat = Chats::where('user_id', $dispatch->user_id)
                ->where('agent_id', $dispatch->agent_id)
                ->where(function ($q) use ($canonicalPhone, $whatsappJid, $cleanDigits) {
                    $q->where('phone', $canonicalPhone)
                        ->orWhere('phone', $whatsappJid)
                        ->orWhere('phone', 'like', "%{$cleanDigits}%");
                })
                ->first();

            $leadName = $contact->name ?: "Lead: " . $canonicalPhone;

            if (!$chat) {
                $chat = Chats::create([
                    'uuid' => Str::limit((string) Str::uuid(), 8, ''),
                    'user_id' => $dispatch->user_id,
                    'agent_id' => $dispatch->agent_id,
                    'phone' => $whatsappJid,
                    'name' => $leadName,
                    'lead_interval' => 6,
                    'expertise' => 'assistente',
                    'status' => 'active',
                    'is_pause' => 'true', // Pausado por padrão para captação de leads via lista
                    'time' => now()->format('H:i'),
                    'last_message' => Str::limit($text, 100),
                ]);
            } else {
                $chat->update([
                    'name' => $chat->name ?: $leadName,
                    'phone' => $chat->phone ?: $whatsappJid,
                    // O disparo de marketing não concede permissão para
                    // diálogo automático, mesmo que já exista um chat ativo.
                    'is_pause' => 'true',
                    'last_message' => Str::limit($text, 100),
                    'time' => now()->format('H:i'),
                ]);
            }

            // Registrar mensagem enviada no histórico
            Messages::create([
                'uuid' => (string) Str::uuid(),
                'chat_id' => $chat->uuid,
                'agent_id' => $dispatch->agent_id,
                'name' => $layout->name ?: 'Disparo',
                'content' => $text,
                'direction' => 'output',
                'phone' => $whatsappJid,
                'whatsapp_message_id' => $whatsappMessageId,
                'delivery_status' => $deliveryStatus ?: ($whatsappMessageId ? 'accepted' : 'unknown'),
                'credit_usage' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erro ao registrar histórico de chat para disparo:', [
                'dispatch_id' => $dispatch->id,
                'item_id' => $item->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Recalcula contadores a partir dos itens (fonte da verdade) e
     * finaliza o disparo quando não sobra pendente.
     */
    private function refreshCounters(WhatsappDispatch $dispatch): void
    {
        $counts = $dispatch->items()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($counts['pending'] ?? 0);
        $processing = (int) ($counts['processing'] ?? 0);

        $data = [
            'sent_count' => (int) ($counts['sent'] ?? 0),
            'failed_count' => (int) ($counts['failed'] ?? 0),
            'skipped_count' => (int) ($counts['skipped'] ?? 0),
        ];

        $previousDone = (int) $dispatch->sent_count
            + (int) $dispatch->failed_count
            + (int) $dispatch->skipped_count;
        $currentDone = $data['sent_count'] + $data['failed_count'] + $data['skipped_count'];
        if ($currentDone > $previousDone) {
            $data['last_progress_at'] = now();
        }

        if ($dispatch->status === 'cancelled') {
            // Cancelamento é estado terminal — nunca sobrescrever
            $dispatch->update($data);

            return;
        }

        if ($pending === 0 && $processing === 0) {
            $data['status'] = in_array($dispatch->status, ['failed']) ? 'failed' : 'completed';
            $data['completed_at'] = now();

            Log::info('Disparo concluído', [
                'dispatch_uuid' => $dispatch->uuid,
                'sent' => $data['sent_count'],
                'failed' => $data['failed_count'],
            ]);
        } elseif ($dispatch->status === 'pending') {
            $data['status'] = 'processing';
        }

        $dispatch->update($data);
    }

    /**
     * Encerra todos os itens pendentes como falhados
     */
    public function failDispatch(WhatsappDispatch $dispatch, string $reason): void
    {
        $dispatch->items()->where('status', 'pending')->update([
            'status' => 'failed',
            'error_message' => $reason,
        ]);
        $this->refreshCounters($dispatch);
    }

    /** Recalcula contadores quando um webhook confirma falha/entrega posterior. */
    public function refreshDispatchCounters(WhatsappDispatch $dispatch): void
    {
        $this->refreshCounters($dispatch);
    }

    /**
     * Cancela um disparo ainda em andamento (itens pendentes viram skipped)
     */
    public function cancelDispatch(string $userId, string $dispatchUuid): bool
    {
        $dispatch = WhatsappDispatch::where('user_id', $userId)->where('uuid', $dispatchUuid)->first();

        if (!$dispatch || !in_array($dispatch->status, ['pending', 'processing'])) {
            return false;
        }

        $dispatch->update(['status' => 'cancelled', 'completed_at' => now()]);
        $dispatch->items()->where('status', 'pending')->update([
            'status' => 'skipped',
            'error_message' => 'Disparo cancelado pelo usuário',
        ]);
        $this->refreshCounters($dispatch);

        return true;
    }
}
