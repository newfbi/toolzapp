<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Http\Controllers\Knowledge\Repositories\StreamRepository;
use App\Http\Controllers\Knowledge\Services\AIProviderFactory;
use App\Models\MemoryStream;
use Illuminate\Support\Facades\Log;

/**
 * Memory Compression Service - Compressão de Histórico.
 *
 * Responsabilidade: A cada X turns (ex: 50), gerar um resumo semântico
 * e substituir mensagens antigas por um bloco comprimido.
 *
 * Isso economiza tokens e mantém contexto relevante sem perder informações importantes.
 */
class MemoryCompressionService
{
    private $repository;

    const COMPRESSION_INTERVAL = 50; // Comprime a cada 50 mensagens
    const MESSAGES_TO_COMPRESS = 30; // Comprime 30 mensagens antigas
    const MESSAGES_TO_KEEP = 20; // Mantém 20 mensagens recentes

    public function __construct(StreamRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Obtém o provedor de IA correto baseado no agente
     */
    private function getAIProvider($agent)
    {
        return AIProviderFactory::create($agent);
    }

    /**
     * Verifica se precisa comprimir e executa se necessário.
     *
     * @param MemoryStream $session
     * @return bool True se compressão foi executada
     */
    public function compressIfNeeded(MemoryStream $session): bool
    {
        $conversationLog = $session->conversation_log ?? [];
        if (is_string($conversationLog)) {
            $decoded = json_decode($conversationLog, true);
            $conversationLog = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        $history = $conversationLog['history'] ?? [];
        $totalMessages = count($history);

        // Verifica se atingiu o intervalo de compressão
        if ($totalMessages < self::COMPRESSION_INTERVAL) {
            return false;
        }

        // Verifica se já foi comprimido recentemente
        $lastCompression = $session->last_compression_count ?? 0;
        $messagesSinceLastCompression = $totalMessages - $lastCompression;

        if ($messagesSinceLastCompression < self::COMPRESSION_INTERVAL) {
            return false;
        }

        // Executa compressão
        return $this->compress($session);
    }

    /**
     * Comprime o histórico de mensagens antigas.
     *
     * @param MemoryStream $session
     * @return bool True se compressão foi bem-sucedida
     */
    public function compress(MemoryStream $session): bool
    {
        try {
            $conversationLog = $session->conversation_log ?? [];
            if (is_string($conversationLog)) {
                $decoded = json_decode($conversationLog, true);
                $conversationLog = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }

            $history = $conversationLog['history'] ?? [];
            $totalMessages = count($history);

            if ($totalMessages < self::MESSAGES_TO_COMPRESS) {
                return false; // Não há mensagens suficientes para comprimir
            }

            // Separa mensagens antigas (para comprimir) e recentes (para manter)
            $messagesToCompress = array_slice($history, 0, -self::MESSAGES_TO_KEEP);
            $messagesToKeep = array_slice($history, -self::MESSAGES_TO_KEEP);

            // Carrega agente do chat
            $chat = $session->chat;
            if (!$chat) {
                Log::warning('Chat não encontrado para compressão', ['session_id' => $session->id]);
                return false;
            }

            $agent = $chat->agent;
            if (!$agent) {
                Log::warning('Agente não encontrado para compressão', ['chat_id' => $chat->uuid]);
                return false;
            }

            // Gera resumo semântico das mensagens antigas
            $compressedBlock = $this->generateCompressedBlock($messagesToCompress, $agent);

            // Reconstrói histórico com bloco comprimido + mensagens recentes
            $compressedHistory = [];

            // Adiciona bloco comprimido
            if (!empty($compressedBlock)) {
                $compressedHistory[] = [
                    'role' => 'system',
                    'content' => $compressedBlock,
                    'timestamp' => now()->toIso8601String(),
                    'meta' => [
                        'compressed' => true,
                        'original_count' => count($messagesToCompress),
                        'compressed_at' => now()->toIso8601String()
                    ]
                ];
            }

            // Adiciona mensagens recentes
            $compressedHistory = array_merge($compressedHistory, $messagesToKeep);

            // Atualiza sessão
            $conversationLog['history'] = $compressedHistory;
            $session->conversation_log = $conversationLog;
            $session->last_compression_count = $totalMessages;
            $this->repository->save($session);

            return true;
        } catch (\Exception $e) {
            Log::error('Erro ao comprimir histórico: ' . $e->getMessage(), [
                'chat_id' => $session->chat_id
            ]);
            return false;
        }
    }

    /**
     * Gera um bloco comprimido a partir de mensagens antigas.
     */
    private function generateCompressedBlock(array $messages, $agent): string
    {
        try {
            // Monta texto das mensagens antigas
            $conversationText = '';
            foreach ($messages as $entry) {
                $role = $entry['role'] === 'agent' ? 'Assistente' : 'Usuário';
                $content = $entry['content'] ?? '';
                $conversationText .= "[{$role}]: {$content}\n\n";
            }

            $systemPrompt = "Você é um compressor de conversas. Sua tarefa é criar um resumo semântico conciso que preserve as informações importantes de uma conversa antiga.\n\n";
            $systemPrompt .= "INSTRUÇÕES:\n";
            $systemPrompt .= "- Crie um resumo que preserve contexto, intenções e informações importantes\n";
            $systemPrompt .= "- Seja conciso mas completo\n";
            $systemPrompt .= "- Mantenha informações sobre preferências, necessidades e contexto do usuário\n";
            $systemPrompt .= "- Retorne APENAS o resumo, sem formatação adicional\n";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                [
                    'role' => 'user',
                    'content' => "Comprima esta conversa antiga em um resumo semântico:\n\n{$conversationText}\n\nResumo:"
                ]
            ];

            $aiProvider = $this->getAIProvider($agent);
            $response = $aiProvider->getCompletion($messages, $agent, [
                'temperature' => 0.3,
                'max_completion_tokens' => 500
            ]);

            $compressed = trim($response['message'] ?? '');

            // Adiciona prefixo para identificar como bloco comprimido
            return "RESUMO DE CONVERSA ANTERIOR: {$compressed}";
        } catch (\Exception $e) {
            Log::error('Erro ao gerar bloco comprimido: ' . $e->getMessage());
            return '';
        }
    }
}
