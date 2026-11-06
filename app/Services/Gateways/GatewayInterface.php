<?php

namespace App\Services\Gateways;

use Illuminate\Http\Request;
use App\Models\User;

interface GatewayInterface
{
    public function getName(): string;
    public function generatePix(User $user, $payment, float $amount): array;
    public function validateWebhook(Request $request): array;
}

