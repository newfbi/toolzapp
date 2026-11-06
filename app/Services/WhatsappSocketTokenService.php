<?php

namespace App\Services;

use App\Models\User;

class WhatsappSocketTokenService
{
    private const TTL_SECONDS = 3600;

    public function issue(User $user): string
    {
        $payload = [
            'sub' => (string) $user->uuid,
            'admin' => $user->hasPermission('administrador'),
            'exp' => time() + self::TTL_SECONDS,
        ];

        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedPayload, $this->secret());

        return $encodedPayload . '.' . $signature;
    }

    private function secret(): string
    {
        $secret = (string) config('services.whatsapp.socket_secret');

        if ($secret === '') {
            throw new \RuntimeException('WHATSAPP_SOCKET_SECRET não está configurado.');
        }

        return $secret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
