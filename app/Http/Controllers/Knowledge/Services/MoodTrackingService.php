<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Http\Controllers\Knowledge\Repositories\StreamRepository;
use App\Models\Chats;
use Illuminate\Support\Facades\Log;

/**
 * Mood Tracking Service - Rastreamento de Humor/Emoções.
 *
 * Responsabilidade: Rastrear variação de humor e emoções do usuário ao longo
 * do tempo, armazenando tags emocionais e ajustando o tom da resposta de acordo.
 *
 * Tags emocionais: hopeful, curious, stressed, motivated, lost, confident, neutral
 */
class MoodTrackingService
{
    private $repository;

    public function __construct(StreamRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Rastreia e atualiza o humor/emoção do usuário.
     *
     * @param Chats $chat
     * @param string $userMessage Mensagem do usuário
     * @return string Emoção detectada
     */
    public function trackMood(Chats $chat, string $userMessage): string
    {
        $emotion = $this->detectEmotion($userMessage);

        $session = $this->repository->getOrCreateStream($chat);

        $knowledge = $session->knowledge_base ?? [];
        if (is_string($knowledge)) {
            $decoded = json_decode($knowledge, true);
            $knowledge = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        // Inicializa mood tracking se não existir
        if (!isset($knowledge['mood_tracking'])) {
            $knowledge['mood_tracking'] = [
                'current_mood' => 'neutral',
                'mood_history' => [],
                'mood_patterns' => []
            ];
        }

        // Atualiza humor atual
        $knowledge['mood_tracking']['current_mood'] = $emotion;

        // Adiciona ao histórico
        $knowledge['mood_tracking']['mood_history'][] = [
            'emotion' => $emotion,
            'timestamp' => now()->toIso8601String(),
            'message_preview' => substr($userMessage, 0, 100)
        ];

        // Mantém apenas últimos 50 registros de humor
        if (count($knowledge['mood_tracking']['mood_history']) > 50) {
            $knowledge['mood_tracking']['mood_history'] = array_slice(
                $knowledge['mood_tracking']['mood_history'],
                -50
            );
        }

        // Analisa padrões de humor
        $knowledge['mood_tracking']['mood_patterns'] = $this->analyzeMoodPatterns(
            $knowledge['mood_tracking']['mood_history']
        );

        $session->knowledge_base = $knowledge;
        $this->repository->save($session);

        return $emotion;
    }

    /**
     * Obtém o humor atual do usuário.
     *
     * @param Chats $chat
     * @return string Emoção atual
     */
    public function getCurrentMood(Chats $chat): string
    {
        $session = $this->repository->getLastStream($chat);

        if (!$session) {
            return 'neutral';
        }

        $knowledge = $session->knowledge_base ?? [];
        if (is_string($knowledge)) {
            $decoded = json_decode($knowledge, true);
            $knowledge = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        return $knowledge['mood_tracking']['current_mood'] ?? 'neutral';
    }

    /**
     * Obtém histórico de humor.
     *
     * @param Chats $chat
     * @param int $limit Número de registros a retornar
     * @return array Histórico de humor
     */
    public function getMoodHistory(Chats $chat, int $limit = 10): array
    {
        $session = $this->repository->getLastStream($chat);

        if (!$session) {
            return [];
        }

        $knowledge = $session->knowledge_base ?? [];
        if (is_string($knowledge)) {
            $decoded = json_decode($knowledge, true);
            $knowledge = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        $history = $knowledge['mood_tracking']['mood_history'] ?? [];

        return array_slice($history, -$limit);
    }

    /**
     * Detecta emoção na mensagem do usuário.
     */
    private function detectEmotion(string $message): string
    {
        $messageLower = strtolower($message);

        $emotionKeywords = [
            'hopeful' => ['esperança', 'espero', 'esperança', 'otimista', 'otimismo', 'confiante', 'confiança'],
            'curious' => ['curioso', 'curiosidade', 'interessante', 'quero saber', 'gostaria de saber', 'como funciona'],
            'stressed' => ['estressado', 'preocupado', 'ansioso', 'nervoso', 'problema', 'dificuldade', 'complicado'],
            'motivated' => ['motivado', 'animado', 'empolgado', 'entusiasmado', 'vamos', 'vou fazer', 'quero aprender'],
            'lost' => ['perdido', 'confuso', 'não entendo', 'não sei', 'ajuda', 'preciso de ajuda', 'não consigo'],
            'confident' => ['confiante', 'certeza', 'tenho certeza', 'sei que', 'entendo', 'compreendo'],
            'frustrated' => ['frustrado', 'irritado', 'chateado', 'não funciona', 'erro', 'bug', 'falha'],
            'grateful' => ['obrigado', 'obrigada', 'grato', 'gratidão', 'agradeço', 'valeu']
        ];

        $scores = [];
        foreach ($emotionKeywords as $emotion => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (stripos($messageLower, $keyword) !== false) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$emotion] = $score;
            }
        }

        if (empty($scores)) {
            return 'neutral';
        }

        // Retorna emoção com maior score
        arsort($scores);
        return array_key_first($scores);
    }

    /**
     * Analisa padrões de humor ao longo do tempo.
     */
    private function analyzeMoodPatterns(array $history): array
    {
        if (empty($history)) {
            return [];
        }

        $emotionCounts = [];
        foreach ($history as $entry) {
            $emotion = $entry['emotion'] ?? 'neutral';
            $emotionCounts[$emotion] = ($emotionCounts[$emotion] ?? 0) + 1;
        }

        $total = count($history);
        $patterns = [];

        foreach ($emotionCounts as $emotion => $count) {
            $percentage = ($count / $total) * 100;
            $patterns[$emotion] = [
                'count' => $count,
                'percentage' => round($percentage, 2),
                'dominant' => $percentage > 30 // Considera dominante se > 30%
            ];
        }

        return $patterns;
    }

    /**
     * Ajusta o tom da resposta baseado no humor atual.
     * Implementação real que adapta linguagem conforme emoção detectada.
     *
     * @param string $response Resposta original
     * @param string $currentMood Humor atual
     * @return string Resposta ajustada
     */
    public function adjustToneByMood(string $response, string $currentMood): string
    {
        // Se humor é neutro, não ajusta
        if ($currentMood === 'neutral') {
            return $response;
        }

        $lowerResponse = strtolower($response);
        $adjusted = $response;

        // Ajustes baseados em humor
        switch ($currentMood) {
            case 'stressed':
            case 'frustrated':
                // Tom mais empático e acolhedor
                /* if (stripos($lowerResponse, 'desculpe') === false &&
                    stripos($lowerResponse, 'entendo') === false &&
                    stripos($lowerResponse, 'compreendo') === false) {
                    // Adiciona prefixo empático se não tiver
                    $adjusted = "Entendo que isso pode ser frustrante. " . $adjusted;
                } */
                // Substitui linguagem muito técnica por mais acessível
                $adjusted = preg_replace('/\b(erro|falha|problema)\b/i', 'situação', $adjusted);
                break;

            case 'lost':
            case 'curious':
                // Tom mais explicativo e didático
                /* if (stripos($lowerResponse, 'vou explicar') === false &&
                    stripos($lowerResponse, 'deixa eu te ajudar') === false) {
                    // Adiciona tom mais didático
                    $adjusted = "Deixa eu te ajudar a entender melhor. " . $adjusted;
                } */
                break;

            case 'motivated':
            case 'hopeful':
                // Tom mais encorajador e positivo
                /* if (stripos($lowerResponse, 'ótimo') === false &&
                    stripos($lowerResponse, 'excelente') === false &&
                    stripos($lowerResponse, 'perfeito') === false) {
                    // Adiciona reforço positivo
                    $adjusted = "Ótimo! " . $adjusted;
                } */
                break;

            case 'confident':
                // Tom mais direto e assertivo (sem mudanças excessivas)
                // Remove hesitações desnecessárias
                $adjusted = preg_replace('/\b(acho que|talvez|provavelmente)\b/i', '', $adjusted);
                $adjusted = preg_replace('/\s+/', ' ', $adjusted);
                $adjusted = trim($adjusted);
                break;

            case 'grateful':
                // Tom mais caloroso e receptivo
                /* if (stripos($lowerResponse, 'fico feliz') === false &&
                    stripos($lowerResponse, 'fico contente') === false) {
                    $adjusted = "Fico feliz em ajudar! " . $adjusted;
                } */
                break;
        }

        // Limpa espaços duplos sem destruir parágrafos e listas que serão
        // enviados pelo WhatsApp.
        $adjusted = preg_replace('/[ \t]+/', ' ', $adjusted);
        $adjusted = preg_replace('/\n[ \t]+/', "\n", $adjusted);
        $adjusted = preg_replace("/\n{3,}/", "\n\n", $adjusted);
        $adjusted = trim($adjusted);

        return $adjusted;
    }
}
