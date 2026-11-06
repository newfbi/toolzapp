<?php

namespace App\Services;

use App\Helpers\PhoneHelper;
use App\Models\SmsDispatch;
use App\Models\SmsDispatchItem;
use App\Models\SmsLayout;
use App\Models\UserList;
use App\Services\Sms\SmsProviderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsDispatchService
{
    /** Limite operacional padrão de partes concatenadas por SMS. */
    public const DEFAULT_MAX_SMS_SEGMENTS = 5;
    public const MAX_ATTEMPTS_PER_BATCH = 2;

    /** @return array{dispatch:SmsDispatch,invalid_phones:int,invalid_messages:int,already_received:int,without_opt_in:int,eta:Carbon} */
    public function createDispatch(
        string $userId,
        string $layoutUuid,
        array $contactUuids,
        bool $includeAlreadySent = false,
    ): array {
        $layout = SmsLayout::forUser($userId)
            ->where('uuid', $layoutUuid)
            ->where('status', 'active')
            ->first();

        if (!$layout) {
            throw new \InvalidArgumentException('Tema SMS inválido ou inativo.');
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

        $withoutOptIn = $contacts->where('sms_opt_in', false)->count();
        $contacts = $contacts->filter(fn (UserList $contact): bool => (bool) $contact->sms_opt_in);
        if ($contacts->isEmpty()) {
            throw new \InvalidArgumentException('Nenhum contato elegível: marque o opt-in de SMS nos contatos autorizados.');
        }

        $alreadyReceived = 0;
        if (!$includeAlreadySent) {
            $receivedIds = SmsDispatchItem::where('layout_id', $layout->uuid)
                ->whereIn('status', ['accepted', 'delivered'])
                ->whereIn('user_list_id', $contacts->pluck('uuid'))
                ->distinct()
                ->pluck('user_list_id');
            $alreadyReceived = $receivedIds->count();
            $contacts = $contacts->reject(fn (UserList $contact): bool => $receivedIds->contains($contact->uuid));
        }

        if ($contacts->isEmpty()) {
            throw new \InvalidArgumentException(
                "Todos os {$alreadyReceived} contatos do escopo já receberam este tema SMS. Escolha outro tema ou marque o reenvio."
            );
        }

        $providerManager = app(SmsProviderManager::class);
        $providerKey = $providerManager->activeKey();
        $provider = $providerManager->driver($providerKey);
        if (!$provider->isConfigured()) {
            throw new \InvalidArgumentException("O provedor SMS ativo ({$provider->label()}) ainda não está configurado.");
        }

        $batchSize = max(1, (int) config('services.sms.batch_size', 500));
        $batchDelay = max(0, (int) config('services.sms.batch_delay_seconds', 15));
        $cursor = now()->addSeconds(5);
        $items = [];
        $invalidPhones = 0;
        $invalidMessages = 0;
        $seenPhones = [];

        foreach ($contacts as $contact) {
            $normalized = PhoneHelper::normalize((string) $contact->phone);
            $message = $layout->renderForLead($contact);

            $providerPhone = $provider->normalizeProviderPhone((string) $contact->phone);
            if (!$normalized['phone'] && !$providerPhone) {
                $invalidPhones++;
                continue;
            }
            if ($message === '' || $this->smsSegmentCount($message) > $this->maxSmsSegments()) {
                $invalidMessages++;
                continue;
            }
            $dedupePhone = $normalized['phone'] ?: $providerPhone;
            if (isset($seenPhones[$dedupePhone])) {
                // O provedor remove duplicidades no lote; fazemos o mesmo
                // antes de criar os itens para manter a contagem fiel.
                continue;
            }
            $seenPhones[$dedupePhone] = true;

            $batchNumber = intdiv(count($items), $batchSize);
            $scheduledAt = $cursor->copy()->addSeconds($batchNumber * $batchDelay);

            $items[] = [
                'uuid' => Str::limit(Str::uuid(), 8, ''),
                'dispatch_id' => null,
                'layout_id' => $layout->uuid,
                'user_list_id' => $contact->uuid,
                // Mantém o formato canônico local já usado pelo sistema e,
                // para internacionais, preserva o E.164 em dígitos.
                'phone' => $normalized['phone'] ?: $providerPhone,
                'message' => $message,
                'status' => 'pending',
                'attempts' => 0,
                'scheduled_at' => $scheduledAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($items)) {
            throw new \InvalidArgumentException('Nenhum contato possui telefone válido e mensagem SMS dentro do limite operacional de 5 partes.');
        }

        $dispatch = DB::transaction(function () use ($userId, $providerKey, $layout, $items): SmsDispatch {
            $dispatch = SmsDispatch::create([
                'user_id' => $userId,
                'provider' => $providerKey,
                'layout_id' => $layout->uuid,
                'total_items' => count($items),
                'status' => 'pending',
            ]);

            foreach (array_chunk($items, 200) as $chunk) {
                foreach ($chunk as &$row) {
                    $row['dispatch_id'] = $dispatch->id;
                }
                unset($row);
                SmsDispatchItem::insert($chunk);
            }

            return $dispatch;
        });

        $eta = $items[array_key_last($items)]['scheduled_at'];
        $eta = Carbon::parse($eta)->addSeconds(45);

        Log::info('Disparo SMS criado', [
            'dispatch_uuid' => $dispatch->uuid,
            'user_id' => $userId,
            'itens' => count($items),
            'telefones_invalidos' => $invalidPhones,
            'mensagens_acima_do_limite_de_partes' => $invalidMessages,
        ]);

        return [
            'dispatch' => $dispatch,
            'invalid_phones' => $invalidPhones,
            'invalid_messages' => $invalidMessages,
            'already_received' => $alreadyReceived,
            'without_opt_in' => $withoutOptIn,
            'eta' => $eta,
        ];
    }

    public function processDueItems(SmsDispatch $dispatch): void
    {
        $items = DB::transaction(function () use ($dispatch) {
            $items = $dispatch->items()
                ->where('status', 'pending')
                ->where('scheduled_at', '<=', now())
                ->orderBy('scheduled_at')
                ->limit(max(10, (int) config('services.sms.batch_size', 500)))
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                return $items;
            }

            $ids = $items->pluck('id');
            $dispatch->update([
                'status' => 'processing',
                'started_at' => $dispatch->started_at ?: now(),
                'last_progress_at' => now(),
            ]);
            $dispatch->items()->whereIn('id', $ids)->update([
                'status' => 'sending',
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

            return $items->fresh();
        });

        if ($items->isEmpty()) {
            $this->refreshCounters($dispatch);
            return;
        }

        try {
            $provider = app(SmsProviderManager::class)->driver($dispatch->provider ?: null);
            $recipients = $items->map(function (SmsDispatchItem $item) use ($provider): array {
                $phone = $provider->normalizeProviderPhone($item->phone);
                if (!$phone) {
                    throw new \RuntimeException("Telefone inválido no item {$item->uuid}.");
                }

                return ['phone' => $phone, 'message' => $item->message];
            })->all();

            $layout = $dispatch->layout;
            $result = $provider->sendBatch($recipients, $layout?->template_name);
            $batchId = $result['batch_id'] ?? null;

            if (!$batchId) {
                throw new \RuntimeException("O provedor {$provider->label()} não retornou batch_id; o resultado não pode ser reconciliado.");
            }

            $providerMessages = collect((array) ($result['messages'] ?? []))
                ->filter(fn ($message): bool => is_array($message))
                ->keyBy(fn (array $message): string => $this->localPhone(
                    (string) ($message['phone'] ?? $message['to'] ?? '')
                ) ?? '');

            foreach ($items as $item) {
                $providerMessage = $providerMessages->get($this->localPhone($item->phone));
                $itemStatus = (string) ($providerMessage['status'] ?? 'accepted');
                $failed = in_array($itemStatus, ['failed', 'rejected', 'undeliverable', 'expired'], true);

                $item->update([
                    'status' => $failed ? 'failed' : 'accepted',
                    'provider_batch_id' => (string) $batchId,
                    'provider_message_id' => $providerMessage['provider_message_id'] ?? null,
                    'provider_status' => mb_substr(
                        (string) ($providerMessage['provider_status'] ?? $result['status'] ?? 'accepted'),
                        0,
                        40,
                    ),
                    'sent_at' => $failed ? null : now(),
                    'error_message' => $failed
                        ? mb_substr((string) ($providerMessage['error'] ?? 'A Infobip recusou o destinatário.'), 0, 65000)
                        : null,
                    'updated_at' => now(),
                ]);
            }

            $dispatch->update([
                'provider_cost' => $result['cost'] ?? $dispatch->provider_cost,
                'last_progress_at' => now(),
                'error_message' => null,
            ]);

            Log::info('Lote SMS aceito pelo provedor', [
                'dispatch_uuid' => $dispatch->uuid,
                'batch_id' => $batchId,
                'quantity' => count($recipients),
                'parts' => $result['total_parts'] ?? null,
                'cost' => $result['cost'] ?? null,
                'provider' => $provider->key(),
            ]);
        } catch (\Throwable $e) {
            // Não repetir automaticamente uma chamada cujo resultado possa
            // ter sido aceito pelo provedor: isso evita SMS duplicado em timeout.
            $dispatch->items()->whereIn('id', $items->pluck('id'))->update([
                'status' => 'failed',
                'provider_status' => 'request_failed',
                'error_message' => mb_substr($e->getMessage(), 0, 65000),
                'updated_at' => now(),
            ]);
            $dispatch->update([
                'error_message' => mb_substr($e->getMessage(), 0, 65000),
                'last_progress_at' => now(),
            ]);
            Log::error('Falha no lote SMS', [
                'dispatch_uuid' => $dispatch->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        $this->refreshCounters($dispatch);
    }

    public function nextWakeAt(SmsDispatch $dispatch): ?Carbon
    {
        $next = $dispatch->items()
            ->where('status', 'pending')
            ->min('scheduled_at');

        return $next ? Carbon::parse($next) : null;
    }

    public function syncStatuses(SmsDispatch $dispatch): bool
    {
        $batchIds = $dispatch->items()
            ->whereNotNull('provider_batch_id')
            ->distinct()
            ->pluck('provider_batch_id');

        $provider = app(SmsProviderManager::class)->driver($dispatch->provider ?: null);
        foreach ($batchIds as $batchId) {
            try {
                $result = $provider->getBatchStatus((string) $batchId);
                foreach ($provider->extractEvents($result) as $event) {
                    $this->applyProviderEvent($dispatch, $event);
                }
            } catch (\Throwable $e) {
                Log::warning('Falha ao consultar status SMS', [
                    'dispatch_uuid' => $dispatch->uuid,
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->refreshCounters($dispatch);

        return $dispatch->items()->whereIn('status', ['accepted', 'sending'])->exists();
    }

    public function applyProviderEvent(SmsDispatch $dispatch, array $event): bool
    {
        $phone = $this->localPhone((string) ($event['phone'] ?? ''));
        if (!$phone) {
            return false;
        }

        $itemQuery = $dispatch->items()->where('phone', $phone);
        if (!empty($event['batch_id'])) {
            $itemQuery->where('provider_batch_id', (string) $event['batch_id']);
        }
        $item = $itemQuery->first();
        if (!$item) {
            return false;
        }

        $rawStatus = strtoupper((string) ($event['status'] ?? $event['type'] ?? ''));
        $status = match (true) {
            str_contains($rawStatus, 'ENTREG'), str_contains($rawStatus, 'DELIVER') => 'delivered',
            str_contains($rawStatus, 'REJECT'),
            str_contains($rawStatus, 'FALH'),
            str_contains($rawStatus, 'FAIL'),
            str_contains($rawStatus, 'UNDELIVER'),
            str_contains($rawStatus, 'EXPIRED'),
            str_contains($rawStatus, 'BLOCK') => 'failed',
            default => 'accepted',
        };

        $data = [
            'status' => $status,
            'provider_status' => mb_substr($rawStatus, 0, 40),
            'provider_message_id' => $event['provider_message_id'] ?? $item->provider_message_id,
            'updated_at' => now(),
        ];
        if ($status === 'delivered') {
            $data['delivered_at'] = $item->delivered_at ?: now();
        }
        if ($status === 'failed') {
            $data['error_message'] = $event['error'] ?? $event['message'] ?? 'A operadora não confirmou a entrega.';
        }

        $item->update($data);
        return true;
    }

    public function refreshDispatchCounters(SmsDispatch $dispatch): void
    {
        $this->refreshCounters($dispatch);
    }

    public function cancelDispatch(string $userId, string $dispatchUuid): bool
    {
        $dispatch = SmsDispatch::where('user_id', $userId)->where('uuid', $dispatchUuid)->first();
        if (!$dispatch || !in_array($dispatch->status, ['pending', 'processing'], true)) {
            return false;
        }

        $dispatch->update(['status' => 'cancelled', 'completed_at' => now()]);
        $dispatch->items()->whereIn('status', ['pending', 'sending'])->update([
            'status' => 'skipped',
            'error_message' => 'Disparo cancelado pelo usuário',
            'updated_at' => now(),
        ]);
        $this->refreshCounters($dispatch);
        return true;
    }

    private function refreshCounters(SmsDispatch $dispatch): void
    {
        $counts = $dispatch->items()
            ->selectRaw("SUM(status IN ('accepted','delivered')) as sent_count")
            ->selectRaw("SUM(status = 'delivered') as delivered_count")
            ->selectRaw("SUM(status = 'failed') as failed_count")
            ->selectRaw("SUM(status = 'skipped') as skipped_count")
            ->selectRaw("SUM(status IN ('pending','sending')) as pending_count")
            ->first();

        $data = [
            'sent_count' => (int) ($counts->sent_count ?? 0),
            'delivered_count' => (int) ($counts->delivered_count ?? 0),
            'failed_count' => (int) ($counts->failed_count ?? 0),
            'skipped_count' => (int) ($counts->skipped_count ?? 0),
            'last_progress_at' => now(),
        ];

        if ((int) ($counts->pending_count ?? 0) === 0 && $dispatch->status !== 'cancelled') {
            $data['status'] = $data['failed_count'] === (int) $dispatch->total_items ? 'failed' : 'completed';
            $data['completed_at'] = $dispatch->completed_at ?: now();
        } elseif ($dispatch->status === 'pending') {
            $data['status'] = 'processing';
        }

        $dispatch->update($data);
    }

    private function localPhone(string $phone): ?string
    {
        $normalized = PhoneHelper::normalize($phone);
        if ($normalized['phone']) {
            return $normalized['phone'];
        }

        $digits = preg_replace('/[^0-9]/', '', $phone) ?: '';
        $digits = ltrim($digits, '0');

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    private function maxSmsSegments(): int
    {
        return max(
            1,
            (int) config('services.infobip.max_segments', self::DEFAULT_MAX_SMS_SEGMENTS),
        );
    }

    /**
     * A Infobip usa 160/70 caracteres em uma parte e 153/67 em mensagens
     * concatenadas, conforme o conjunto GSM-7 ou Unicode.
     */
    private function smsSegmentCount(string $message): int
    {
        $gsm7 = '@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞ\\^{}[~]|€ !"#¤%&\'()*+,-./0123456789:;<=>?ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz' . "\n\r";
        $extended = '^{}\\[~]|€';
        $units = 0;
        $characters = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $character) {
            if (!str_contains($gsm7, $character)) {
                $length = mb_strlen($message);
                return max(1, (int) ceil($length / ($length <= 70 ? 70 : 67)));
            }
            $units += str_contains($extended, $character) ? 2 : 1;
        }

        return $units <= 160 ? 1 : (int) ceil($units / 153);
    }
}
