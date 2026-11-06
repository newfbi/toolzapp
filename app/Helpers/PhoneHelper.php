<?php

namespace App\Helpers;

class PhoneHelper
{
    /**
     * Normaliza e valida telefone (compartilhado por importação, UI e fila de disparo).
     *
     * Retorna ['phone' => string|null, 'reason' => string|null]:
     * - phone=null com reason=null  -> nada informado (silencioso)
     * - phone=null com reason=texto -> inválido (motivo para o usuário)
     *
     * Regras:
     * - Remove não-dígitos e zeros à esquerda
     * - Remove o código do país (55) quando o restante é BR válido (forma canônica local)
     * - BR válido: fixo 10 dígitos (dígito 2-5) ou celular 11 dígitos (começa em 9), DDD >= 11
     * - Internacional aceito apenas com '+' explícito na origem
     */
    public static function normalize(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['phone' => null, 'reason' => null];
        }

        $digits = preg_replace('/[^0-9]/', '', $raw);
        if ($digits === '' || $digits === false) {
            return ['phone' => null, 'reason' => null];
        }

        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return ['phone' => null, 'reason' => 'só contém zeros'];
        }

        // Código do país 55 + BR válido (12-13 dígitos)
        if (strlen($digits) >= 12 && strlen($digits) <= 13 && str_starts_with($digits, '55')) {
            $local = substr($digits, 2);
            if (self::isBrazilianLocalValid($local)) {
                return ['phone' => $local, 'reason' => null];
            }
            return ['phone' => null, 'reason' => "após remover o código do país (55), o número ficou inválido: {$local}"];
        }

        if (self::isBrazilianLocalValid($digits)) {
            return ['phone' => $digits, 'reason' => null];
        }

        if (str_contains($raw, '+') && strlen($digits) >= 8 && strlen($digits) <= 15) {
            return ['phone' => $digits, 'reason' => null];
        }

        $len = strlen($digits);
        if ($len < 10) {
            return ['phone' => null, 'reason' => 'muito curto — informe o número com DDD'];
        }
        if ($len > 15) {
            return ['phone' => null, 'reason' => 'muito longo'];
        }

        return ['phone' => null, 'reason' => 'formato inválido para telefone brasileiro'];
    }

    /**
     * Valida número brasileiro LOCAL conforme numeração ANATEL
     */
    public static function isBrazilianLocalValid(string $digits): bool
    {
        $len = strlen($digits);
        if ($len !== 10 && $len !== 11) {
            return false;
        }

        $ddd = (int) substr($digits, 0, 2);
        $firstDigit = (int) $digits[2];

        if ($ddd < 11 || $ddd > 99) {
            return false;
        }

        return $len === 11
            ? $firstDigit === 9
            : ($firstDigit >= 2 && $firstDigit <= 5);
    }

    /**
     * Converte telefone canônico em JID do WhatsApp
     */
    public static function toWhatsAppJid(string $canonicalPhone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $canonicalPhone);

        if (self::isBrazilianLocalValid($digits)) {
            $digits = '55' . $digits;
        } elseif (!str_starts_with($digits, '55') && (strlen($digits) === 10 || strlen($digits) === 11)) {
            $digits = '55' . $digits;
        }

        return $digits . '@s.whatsapp.net';
    }
}
