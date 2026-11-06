<?php

namespace App\Services;

/**
 * Formata respostas de IA para o canal WhatsApp.
 *
 * O WhatsApp não deve receber artefatos de serialização ou Markdown cru
 * produzido pelo modelo, como aspas envolvendo toda a resposta, backticks,
 * marcadores de negrito e sequências escapadas (\\n, \\" etc.).
 */
class WhatsappMessageFormatter
{
    /**
     * Retorna uma mensagem limpa, legível e segura para envio pelo WhatsApp.
     */
    public function format(?string $message): string
    {
        if ($message === null) {
            return '';
        }

        $message = (string) $message;

        // Corrige respostas que chegaram como string JSON escapada.
        $message = str_replace(["\\r\\n", "\\n", "\\r", "\\t"], ["\n", "\n", "\n", "\t"], $message);
        $message = preg_replace('/\\\\([\\\\\'"*`_])/', '$1', $message) ?? $message;

        // Normaliza caracteres invisíveis e finais de linha sem remover
        // quebras de linha úteis ao usuário.
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $message) ?? $message;
        $message = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $message) ?? $message;
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove formatos que não são renderizados de forma consistente no
        // WhatsApp e transforma HTML básico em texto simples.
        $message = preg_replace('/<br\s*\/?\s*>/i', "\n", $message) ?? $message;
        $message = strip_tags($message);
        $message = preg_replace('/```[^\n]*\n?/u', '', $message) ?? $message;
        $message = str_replace('`', '', $message);

        // Converte links Markdown em texto e remove marcadores de formatação.
        $message = preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/u', '$1 ($2)', $message) ?? $message;
        $message = preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $message) ?? $message;
        $message = preg_replace('/^[ \t]*>[ \t]?/mu', '', $message) ?? $message;
        $message = preg_replace('/^[ \t]*[•*][ \t]+/mu', '- ', $message) ?? $message;
        $message = str_replace(['**', '__', '~~'], '', $message);
        $message = preg_replace('/(?<!\w)\*([^*\n]+)\*(?!\w)/u', '$1', $message) ?? $message;
        $message = preg_replace('/(?<!\w)_([^_\n]+)_(?!\w)/u', '$1', $message) ?? $message;

        // A IA às vezes serializa toda a resposta como "texto" ou 'texto'.
        // Remove apenas aspas que envolvem o conteúdo inteiro/uma linha, sem
        // destruir apóstrofos ou citações internas legítimas.
        $message = $this->unwrapWrappingQuotes($message);

        $lines = [];
        foreach (explode("\n", $message) as $line) {
            $rawLine = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line);
            $line = $this->unwrapWrappingQuotes($rawLine);

            // Evita que artefatos isolados como "" ou '' sejam enviados.
            if ($rawLine !== '' && $line === '') {
                continue;
            }

            if ($line === '') {
                if (!empty($lines) && end($lines) !== '') {
                    $lines[] = '';
                }
                continue;
            }

            $lines[] = $line;
        }

        $message = implode("\n", $lines);
        $message = preg_replace("/\n{3,}/", "\n\n", $message) ?? $message;

        return trim($message);
    }

    /**
     * Remove pares de aspas somente quando estão nas extremidades do texto.
     */
    private function unwrapWrappingQuotes(string $value): string
    {
        $pairs = [
            '"' => '"',
            "'" => "'",
            '“' => '”',
            '‘' => '’',
            '«' => '»',
        ];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $value = trim($value);
            if ($value === '' || !function_exists('mb_substr')) {
                return $value;
            }

            $first = mb_substr($value, 0, 1);
            $last = mb_substr($value, -1);
            if (!isset($pairs[$first]) || $pairs[$first] !== $last) {
                break;
            }

            $length = mb_strlen($value);
            $value = trim(mb_substr($value, 1, $length - 2));
        }

        return $value;
    }
}
