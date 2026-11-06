<?php

namespace App\Http\Controllers\WebHook;

/**
 * Compatibilidade com a URL antiga da ComprarSMS.
 * A implementação real agora fica no controlador agnóstico de provedor.
 */
class ComprarSmsWebhookController extends SmsWebhookController
{
}
