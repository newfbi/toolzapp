<?php

namespace App\Services\Gateways;

class GatewayFactory
{
    public static function make(string $name): GatewayInterface
    {
        $key = strtolower($name);
        if ($key === 'asaas') {
            return new AsaasGateway();
        }
        if ($key === 'ondapay') {
            return new OndaPayGateway();
        }
        return new AsaasGateway();
    }
}

