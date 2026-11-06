<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class DocumentAnalyzer
{
    /**
     * Preparar conteúdo do documento para análise do agente
     */
    public function prepareDocumentForAgent(array $extractedContent, string $userQuery = null): string
    {
        if (!$extractedContent['success']) {
            return "Erro ao processar documento: " . ($extractedContent['error'] ?? 'Erro desconhecido');
        }

        $text = $extractedContent['text'];
        $metadata = $extractedContent['metadata'];

        // Construir contexto para o agente
        $context = "=== DOCUMENTO ANALISADO ===\n";

        // Adicionar informações do documento
        if (!empty($metadata['filename'])) {
            $context .= "Arquivo: " . $metadata['filename'] . "\n";
        }

        if (!empty($metadata['pages'])) {
            $context .= "Páginas: " . $metadata['pages'] . "\n";
        } elseif (!empty($metadata['sections'])) {
            $context .= "Seções: " . $metadata['sections'] . "\n";
        }

        if (!empty($extractedContent['word_count'])) {
            $context .= "Palavras: " . number_format($extractedContent['word_count']) . "\n";
        }

        $context .= "\n=== CONTEÚDO DO DOCUMENTO ===\n";

        // Limitar tamanho do texto se muito longo (máximo ~8000 caracteres para não sobrecarregar o agente)
        if (strlen($text) > 8000) {
            $text = $this->truncateText($text, 8000);
            $context .= $text . "\n\n[DOCUMENTO TRUNCADO - MOSTRANDO INÍCIO DO CONTEÚDO]\n";
        } else {
            $context .= $text . "\n";
        }

        // Adicionar pergunta específica do usuário se fornecida
        if (!empty($userQuery)) {
            $context .= "\n=== PERGUNTA DO USUÁRIO ===\n";
            $context .= $userQuery . "\n";
            $context .= "\nPor favor, responda à pergunta baseando-se no conteúdo do documento acima.";
        } else {
            $context .= "\nPor favor, analise este documento e forneça um resumo ou insights relevantes.";
        }

        return $context;
    }

    /**
     * Formatar preview do documento para a interface
     */
    public function formatDocumentPreview(array $extractedContent): string
    {
        if (!$extractedContent['success']) {
            return "❌ Erro: " . ($extractedContent['error'] ?? 'Não foi possível processar o documento');
        }

        $text = $extractedContent['text'];
        $metadata = $extractedContent['metadata'];

        $preview = "📄 **Documento processado com sucesso**\n\n";

        // Informações básicas
        if (!empty($metadata['filename'])) {
            $preview .= "**Arquivo:** " . $metadata['filename'] . "\n";
        }

        if (!empty($metadata['size_formatted'])) {
            $preview .= "**Tamanho:** " . $metadata['size_formatted'] . "\n";
        }

        if (!empty($metadata['pages'])) {
            $preview .= "**Páginas:** " . $metadata['pages'] . "\n";
        } elseif (!empty($metadata['sections'])) {
            $preview .= "**Seções:** " . $metadata['sections'] . "\n";
        }

        if (!empty($extractedContent['word_count'])) {
            $preview .= "**Palavras:** " . number_format($extractedContent['word_count']) . "\n";
        }

        // Preview do conteúdo (primeiros 300 caracteres)
        $preview .= "\n**Preview do conteúdo:**\n";
        $preview .= "```\n";
        $preview .= $this->truncateText($text, 300);
        if (strlen($text) > 300) {
            $preview .= "\n... (conteúdo continua)";
        }
        $preview .= "\n```";

        return $preview;
    }

    /**
     * Gerar resumo automático do documento
     */
    public function summarizeDocument(array $extractedContent): array
    {
        if (!$extractedContent['success']) {
            return [
                'success' => false,
                'error' => $extractedContent['error'] ?? 'Erro ao processar documento'
            ];
        }

        $text = $extractedContent['text'];
        $metadata = $extractedContent['metadata'];

        // Estatísticas básicas
        $wordCount = $extractedContent['word_count'];
        $charCount = $extractedContent['char_count'];
        $sentences = $this->countSentences($text);
        $paragraphs = $this->countParagraphs($text);

        // Extrair primeiras frases como resumo
        $summary = $this->extractFirstSentences($text, 3);

        // Palavras-chave mais frequentes
        $keywords = $this->extractKeywords($text, 10);

        return [
            'success' => true,
            'summary' => $summary,
            'statistics' => [
                'words' => $wordCount,
                'characters' => $charCount,
                'sentences' => $sentences,
                'paragraphs' => $paragraphs
            ],
            'keywords' => $keywords,
            'metadata' => $metadata
        ];
    }

    /**
     * Truncar texto mantendo palavras completas
     */
    private function truncateText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = substr($text, 0, $maxLength);
        $lastSpace = strrpos($truncated, ' ');

        if ($lastSpace !== false) {
            $truncated = substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
    }

    /**
     * Contar sentenças no texto
     */
    private function countSentences(string $text): int
    {
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return count($sentences);
    }

    /**
     * Contar parágrafos no texto
     */
    private function countParagraphs(string $text): int
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return count($paragraphs);
    }

    /**
     * Extrair primeiras sentenças como resumo
     */
    private function extractFirstSentences(string $text, int $count = 3): string
    {
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $firstSentences = array_slice($sentences, 0, $count);

        return implode('. ', array_map('trim', $firstSentences)) . '.';
    }

    /**
     * Extrair palavras-chave mais frequentes
     */
    private function extractKeywords(string $text, int $limit = 10): array
    {
        // Remover pontuação e converter para minúsculas
        $cleanText = strtolower(preg_replace('/[^\w\s]/', ' ', $text));

        // Dividir em palavras
        $words = preg_split('/\s+/', $cleanText, -1, PREG_SPLIT_NO_EMPTY);

        // Filtrar palavras muito curtas e palavras comuns
        $stopWords = [
            'a',
            'o',
            'e',
            'de',
            'do',
            'da',
            'em',
            'um',
            'uma',
            'para',
            'com',
            'por',
            'que',
            'se',
            'na',
            'no',
            'as',
            'os',
            'das',
            'dos',
            'ao',
            'aos',
            'à',
            'às',
            'pelo',
            'pela',
            'pelos',
            'pelas',
            'este',
            'esta',
            'estes',
            'estas',
            'esse',
            'essa',
            'esses',
            'essas',
            'aquele',
            'aquela',
            'aqueles',
            'aquelas',
            'seu',
            'sua',
            'seus',
            'suas',
            'nosso',
            'nossa',
            'nossos',
            'nossas',
            'the',
            'and',
            'or',
            'but',
            'in',
            'on',
            'at',
            'to',
            'for',
            'of',
            'with',
            'by',
            'is',
            'are',
            'was',
            'were',
            'be',
            'been',
            'have',
            'has',
            'had'
        ];

        $filteredWords = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 3 && !in_array($word, $stopWords);
        });

        // Contar frequência
        $wordCount = array_count_values($filteredWords);

        // Ordenar por frequência
        arsort($wordCount);

        // Retornar top palavras
        return array_slice(array_keys($wordCount), 0, $limit);
    }
}
