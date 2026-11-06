<?php

namespace App\Services\Sms;

use App\Contracts\SmsProvider;
use App\Models\Settings;

class SmsProviderManager
{
    /** @return array<string, array{label:string, driver:class-string<SmsProvider>}> */
    public function definitions(): array
    {
        return (array) config('services.sms.providers', []);
    }

    public function activeKey(): string
    {
        $configured = trim((string) Settings::query()->value('sms_provider'));
        $fallback = (string) config('services.sms.default_provider', 'comprarsms');
        $key = $configured !== '' ? $configured : $fallback;

        return array_key_exists($key, $this->definitions()) ? $key : $fallback;
    }

    public function driver(?string $key = null): SmsProvider
    {
        $key ??= $this->activeKey();
        $definition = $this->definitions()[$key] ?? null;

        if (!$definition || empty($definition['driver'])) {
            throw new SmsProviderException("Provedor SMS '{$key}' não está disponível.");
        }

        $driver = app($definition['driver']);
        if (!$driver instanceof SmsProvider) {
            throw new SmsProviderException("O adaptador SMS '{$key}' não implementa o contrato esperado.");
        }

        return $driver;
    }

    /** @return array<string, array{key:string,label:string,configured:bool}> */
    public function available(): array
    {
        $providers = [];
        foreach ($this->definitions() as $key => $definition) {
            $driver = $this->driver($key);
            $providers[$key] = [
                'key' => $key,
                'label' => $driver->label(),
                'configured' => $driver->isConfigured(),
            ];
        }

        return $providers;
    }
}
