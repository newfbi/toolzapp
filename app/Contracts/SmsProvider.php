<?php

namespace App\Contracts;

interface SmsProvider
{
    public function key(): string;

    public function label(): string;

    public function isConfigured(): bool;

    /** @param array<int, array{phone:string,message:string}> $recipients */
    public function sendBatch(array $recipients, ?string $templateName = null): array;

    public function getBatchStatus(string $batchId): array;

    public function normalizeProviderPhone(string $phone): ?string;

    /** @param array<string, mixed> $headers */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool;

    /** @return array<int, array<string, mixed>> */
    public function extractEvents(array $payload): array;
}
