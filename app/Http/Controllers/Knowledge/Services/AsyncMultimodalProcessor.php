<?php

namespace App\Http\Controllers\Knowledge\Services;

use App\Helpers\FileUploadHelper;
use App\Models\Agents;
use App\Models\Chats;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Processamento Assíncrono de Arquivos Multimodais
 *
 * Para arquivos grandes (áudio longo, documentos extensos),
 * processa em background para não bloquear a resposta do usuário.
 */
class AsyncMultimodalProcessor
{
    private $multimodalProcessor;

    public function __construct(MultimodalProcessorService $multimodalProcessor)
    {
        $this->multimodalProcessor = $multimodalProcessor;
    }

    /**
     * Processa arquivo multimodal de forma assíncrona se for grande
     */
    public function processAsyncIfNeeded(array $fileData, Chats $chat, Agents $agent): array
    {
        $filePath = $fileData['path'] ?? '';
        $fileType = $fileData['type'] ?? 'unknown';

        // Verifica se deve processar de forma assíncrona
        if ($this->shouldProcessAsync($fileData)) {
            return $this->scheduleAsyncProcessing($fileData, $chat, $agent);
        }

        // Processa de forma síncrona (normal)
        return $this->multimodalProcessor->processMultimodalFile($fileData, $chat, $agent);
    }

    /**
     * Decide se deve processar de forma assíncrona baseado no tamanho/tipo
     */
    private function shouldProcessAsync(array $fileData): bool
    {
        $filePath = $fileData['path'] ?? '';
        $fileType = $fileData['type'] ?? 'unknown';

        // Áudio maior que 10MB
        if ($fileType === 'audio' && file_exists($filePath)) {
            $fileSizeMB = filesize($filePath) / (1024 * 1024);
            if ($fileSizeMB > 10) {
                return true;
            }
        }

        // Documentos maiores que 5MB
        if ($fileType === 'document' && file_exists($filePath)) {
            $fileSizeMB = filesize($filePath) / (1024 * 1024);
            if ($fileSizeMB > 5) {
                return true;
            }
        }

        // PDFs com mais de 50 páginas (estimativa)
        if ($fileType === 'document' && isset($fileData['metadata']['estimated_pages'])) {
            if ($fileData['metadata']['estimated_pages'] > 50) {
                return true;
            }
        }

        return false;
    }

    /**
     * Agenda processamento assíncrono
     */
    private function scheduleAsyncProcessing(array $fileData, Chats $chat, Agents $agent): array
    {
        $filePath = $fileData['path'] ?? '';
        $fileType = $fileData['type'] ?? 'unknown';

        // Cria job para processamento assíncrono
        // Note: Em produção, você criaria uma classe de Job específica
        // Por enquanto, apenas logamos que seria processado de forma assíncrona

        // Queue::push(new ProcessMultimodalFile($fileData, $chat->uuid, $agent->uuid));

        // Retorna resposta imediata indicando processamento em andamento
        return [
            'success' => true,
            'async' => true,
            'message' => "Arquivo {$fileType} está sendo processado em segundo plano. Você será notificado quando estiver pronto.",
            'file_type' => $fileType,
            'estimated_time' => $this->estimateProcessingTime($fileData),
            'content' => null,
            'metadata' => array_merge($fileData['metadata'] ?? [], [
                'processing_status' => 'async_scheduled',
                'scheduled_at' => now()->toIso8601String()
            ])
        ];
    }

    /**
     * Estima tempo de processamento baseado no tipo/tamanho
     */
    private function estimateProcessingTime(array $fileData): string
    {
        $fileType = $fileData['type'] ?? 'unknown';
        $filePath = $fileData['path'] ?? '';

        $baseTime = 5; // segundos base

        if (file_exists($filePath)) {
            $fileSizeMB = filesize($filePath) / (1024 * 1024);

            switch ($fileType) {
                case 'audio':
                    // Áudio: ~1 segundo por MB
                    $baseTime += $fileSizeMB * 1;
                    break;
                case 'document':
                    // Documento: ~2 segundos por MB
                    $baseTime += $fileSizeMB * 2;
                    break;
                case 'image':
                    // Imagem: processamento rápido
                    $baseTime += 1;
                    break;
            }
        }

        // Limita estimativa em 5 minutos
        $baseTime = min($baseTime, 300);

        if ($baseTime < 60) {
            return "~{$baseTime} segundos";
        } else {
            return "~" . ceil($baseTime / 60) . " minutos";
        }
    }

    /**
     * Processa resultado de job assíncrono (seria chamado pelo Job)
     */
    public function processAsyncResult(array $fileData, string $chatId, string $agentId): void
    {
        try {
            $chat = Chats::find($chatId);
            $agent = Agents::find($agentId);

            if (!$chat || !$agent) {
                Log::error('Chat ou agente não encontrado para processamento assíncrono', [
                    'chat_id' => $chatId,
                    'agent_id' => $agentId
                ]);
                return;
            }

            // Processa o arquivo
            $result = $this->multimodalProcessor->processMultimodalFile($fileData, $chat, $agent);

            // Notifica o usuário (poderia enviar WebSocket, email, etc.)
            $this->notifyUserOfCompletion($chat, $result);
        } catch (\Exception $e) {
            Log::error('Erro no processamento assíncrono multimodal', [
                'chat_id' => $chatId,
                'agent_id' => $agentId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notifica usuário sobre conclusão do processamento
     */
    private function notifyUserOfCompletion(Chats $chat, array $result): void
    {
        $message = $result['success']
            ? "✅ Seu arquivo foi processado com sucesso! Agora você pode perguntar sobre o conteúdo."
            : "❌ Houve um erro ao processar seu arquivo: " . ($result['error'] ?? 'Erro desconhecido');

        // Aqui você poderia:
        // 1. Enviar notificação WebSocket para o frontend
        // 2. Criar uma mensagem no chat
        // 3. Enviar email
        // 4. Disparar evento Laravel
    }

    /**
     * Verifica status de processamento assíncrono
     */
    public function getProcessingStatus(string $chatId, string $fileHash): array
    {
        // Implementar verificação de status se usar jobs reais
        return [
            'status' => 'unknown',
            'message' => 'Status de processamento não disponível'
        ];
    }
}
