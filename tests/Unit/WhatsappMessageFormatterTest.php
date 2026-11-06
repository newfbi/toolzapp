<?php

use App\Services\WhatsappMessageFormatter;

it('normaliza artefatos de markdown e aspas das respostas da IA', function () {
    $formatter = new WhatsappMessageFormatter();

    $result = $formatter->format('"**Olá**,\n\n* Como posso ajudar?\\n\n\'Tudo bem?\'"');

    expect($result)->toBe("Olá,\n\n- Como posso ajudar?\n\nTudo bem?")
        ->and($result)->not->toContain('**')
        ->and($result)->not->toContain('"')
        ->and($result)->not->toContain("'");
});

it('preserva citações internas e converte links markdown', function () {
    $formatter = new WhatsappMessageFormatter();

    $result = $formatter->format('Ele disse: "Vou verificar". **Acesse:** [painel](https://toolzapp.com.br)');

    expect($result)->toBe('Ele disse: "Vou verificar". Acesse: painel (https://toolzapp.com.br)');
});
