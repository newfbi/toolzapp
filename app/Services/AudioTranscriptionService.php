<?php

namespace App\Services;

use App\Models\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI;
use GuzzleHttp\Client;

class AudioTranscriptionService
{
    /**
     * Transcreve áudio usando OpenAI Whisper API
     */
    public function transcribeAudio($audioPath, $disk = 'public'): ?string
    {
        try {

            $fullPath = storage_path('app/' . $disk . '/' . $audioPath);

            if (!file_exists($fullPath)) {
                Log::error('AudioTranscriptionService: Arquivo de áudio não encontrado para transcrição', [
                    'full_path' => $fullPath
                ]);
                return null;
            }

            // Validar arquivo
            if (!$this->validateAudioFile($fullPath)) {
                return null;
            }

            $client = $this->getOpenAiClient();
            if (!$client) {
                return null;
            }

            // Transcrever com o modelo Whisper
            $result = $client->audio()->transcribe([
                'file' => fopen($fullPath, 'r'),
                'model' => 'whisper-1',
                'response_format' => 'text',
                'language' => 'pt' // Português brasileiro
            ]);

            $transcription = trim((string) $result->text);

            if (empty($transcription)) {
                Log::warning('AudioTranscriptionService: Transcrição retornou texto vazio', [
                    'audio_path' => $audioPath
                ]);
                return null;
            }

            Log::info('AudioTranscriptionService: Transcrição de áudio concluída com sucesso', [
                'audio_path' => $audioPath,
                'trascription' => $transcription,
                'transcription_length' => strlen($transcription),
                'transcription_preview' => substr($transcription, 0, 100) . '...'
            ]);

            return $transcription;
        } catch (\Exception $e) {
            Log::error('AudioTranscriptionService: Erro ao transcrever áudio com OpenAI Whisper', [
                'error' => $e->getMessage(),
                'audio_path' => $audioPath,
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Valida arquivo de áudio para transcrição
     */
    private function validateAudioFile(string $fullPath): bool
    {
        try {
            $fileSize = filesize($fullPath);

            if ($fileSize <= 0) {
                Log::error('AudioTranscriptionService: Arquivo de áudio inválido para transcrição', [
                    'file_size' => $fileSize
                ]);
                return false;
            }

            // Verificar se o arquivo não é muito grande (limite da OpenAI: 25MB)
            $maxSize = 25 * 1024 * 1024; // 25MB
            if ($fileSize > $maxSize) {
                Log::warning('AudioTranscriptionService: Arquivo de áudio muito grande para transcrição', [
                    'file_size_mb' => round($fileSize / (1024 * 1024), 2),
                    'max_size_mb' => round($maxSize / (1024 * 1024), 2)
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('AudioTranscriptionService: Erro ao validar arquivo de áudio', [
                'error' => $e->getMessage(),
                'full_path' => $fullPath
            ]);
            return false;
        }
    }

    /**
     * Cria cliente OpenAI para transcrição
     */
    private function getOpenAiClient()
    {
        try {
            $settings = Settings::first();

            if (!$settings) {
                Log::error('AudioTranscriptionService: Configurações não encontradas');
                return null;
            }

            if (empty($settings->api_key)) {
                Log::error('AudioTranscriptionService: Chave da OpenAI não configurada');
                return null;
            }

            $httpClient = new Client([
                'timeout' => 120,
                'connect_timeout' => 30,
                'verify' => config('services.http.verify_ssl', true)
            ]);

            $client = OpenAI::factory()
                ->withApiKey($settings->api_key)
                ->withHttpClient($httpClient)
                ->make();

            return $client;
        } catch (\Exception $e) {
            Log::error('AudioTranscriptionService: Erro ao criar cliente OpenAI', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Analisa áudio e retorna contexto baseado em transcrição ou fallback
     */
    public function analyzeAudio($audioPath, $disk = 'public'): array
    {
        try {
            if (!Storage::disk($disk)->exists($audioPath)) {
                Log::error('AudioTranscriptionService: Arquivo de áudio não encontrado', [
                    'audio_path' => $audioPath,
                    'disk' => $disk
                ]);
                return [
                    'success' => false,
                    'error' => 'Arquivo não encontrado',
                    'context' => null
                ];
            }

            $fileSize = Storage::disk($disk)->size($audioPath);
            $fileSizeKB = round($fileSize / 1024, 2);

            // Tentar transcrição
            $transcription = $this->transcribeAudio($audioPath, $disk);

            if ($transcription) {
                // Transcrição bem-sucedida
                $context = $this->buildTranscriptionContext($transcription, $fileSizeKB);

                return [
                    'success' => true,
                    'transcription' => $transcription,
                    'context' => $context,
                    'file_size_kb' => $fileSizeKB
                ];
            } else {
                // Fallback para análise baseada em metadados
                $context = $this->buildFallbackAudioContext($audioPath, $fileSizeKB);

                return [
                    'success' => false,
                    'transcription' => null,
                    'context' => $context,
                    'file_size_kb' => $fileSizeKB
                ];
            }
        } catch (\Exception $e) {
            Log::error('AudioTranscriptionService: Erro ao analisar áudio', [
                'error' => $e->getMessage(),
                'audio_path' => $audioPath,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'context' => 'A usuária enviou um áudio. A análise não está disponível no momento.',
                'file_size_kb' => 0
            ];
        }
    }

    /**
     * Constrói contexto baseado na transcrição real do áudio
     */
    private function buildTranscriptionContext(string $transcription, float $fileSizeKB): string
    {
        $description = "A usuária enviou um áudio de {$fileSizeKB}KB.\n\n";

        $description .= "🎤 **TRANSCRIÇÃO DO ÁUDIO:**\n";
        $description .= "\"" . $transcription . "\"\n\n";

        $description .= "🎭 **ANÁLISE EMOCIONAL:**\n";
        $description .= "• Ela preferiu falar ao invés de escrever\n";
        $description .= "• O conteúdo foi transcrito com sucesso\n";
        $description .= "• Agora você pode responder diretamente ao que ela disse\n\n";

        $description .= "💬 **INSTRUÇÕES PARA O AGENTE:**\n";
        $description .= "• Responda diretamente ao conteúdo transcrito\n";
        $description .= "• Demonstre que entendeu o que ela disse\n";
        $description .= "• Ofereça conselhos ou apoio baseado no conteúdo\n";
        $description .= "• Mantenha o tom empático e acolhedor\n";
        $description .= "• Use a transcrição para criar uma conversa natural";

        return $description;
    }

    /**
     * Constrói contexto de fallback quando a transcrição falha
     */
    private function buildFallbackAudioContext(string $audioPath, float $fileSizeKB): string
    {
        $description = "A usuária enviou um áudio de {$fileSizeKB}KB.\n\n";

        $description .= "⚠️ **TRANSCRIÇÃO NÃO DISPONÍVEL:**\n";
        $description .= "• A transcrição automática não foi possível\n";
        $description .= "• Isso pode acontecer por problemas técnicos\n";
        $description .= "• Ou o áudio pode estar em formato não suportado\n\n";

        $description .= "🎭 **CONTEXTO EMOCIONAL:**\n";
        $description .= "• Ela preferiu falar ao invés de escrever\n";
        $description .= "• O assunto pode ser importante ou emocional para ela\n";
        $description .= "• Pode conter detalhes pessoais ou situações complexas\n\n";

        $description .= "💬 **INSTRUÇÕES PARA O AGENTE:**\n";
        $description .= "• Reconheça que ela escolheu falar (isso é significativo)\n";
        $description .= "• Pergunte sobre o conteúdo do áudio de forma empática\n";
        $description .= "• Ofereça apoio emocional se necessário\n";
        $description .= "• Mantenha o tom acolhedor e compreensivo\n";
        $description .= "• Sugira que ela repita por texto se necessário";

        return $description;
    }









    /**
     * Inicia gravação de áudio (controle de estado)
     */
    public function startRecording(): array
    {
        try {
            Log::info('AudioTranscriptionService: Gravação de áudio iniciada');

            return [
                'success' => true,
                'status' => 'recording',
                'start_time' => now(),
                'message' => 'Gravação iniciada com sucesso'
            ];
        } catch (\Exception $e) {
            Log::error('AudioTranscriptionService: Erro ao iniciar gravação', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'status' => 'error',
                'message' => 'Erro ao iniciar gravação: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Para gravação de áudio (controle de estado)
     */
    public function stopRecording($startTime): array
    {
        try {
            $duration = now()->diffInSeconds($startTime);

            Log::info('AudioTranscriptionService: Gravação de áudio parada', [
                'duration' => $duration
            ]);

            return [
                'success' => true,
                'status' => 'stopped',
                'duration' => $duration,
                'message' => 'Gravação parada com sucesso'
            ];
        } catch (\Exception $e) {
            Log::error('AudioTranscriptionService: Erro ao parar gravação', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'status' => 'error',
                'message' => 'Erro ao parar gravação: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Cancela gravação de áudio (controle de estado)
     */
    public function cancelRecording(): array
    {
        try {
            Log::info('AudioTranscriptionService: Gravação de áudio cancelada');

            return [
                'success' => true,
                'status' => 'cancelled',
                'message' => 'Gravação cancelada com sucesso'
            ];
        } catch (\Exception $e) {
            Log::error('AudioTranscriptionService: Erro ao cancelar gravação', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'status' => 'error',
                'message' => 'Erro ao cancelar gravação: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Salva áudio gravado e retorna o caminho do arquivo
     */
    public function saveRecordedAudio($audioBlob, $extension = 'webm'): ?array
    {
        try {
            // Gerar nome único para o arquivo
            $filename = 'recorded_audio_' . uniqid() . '_' . now()->format('Y-m-d_H-i-s') . '.' . $extension;
            $audioPath = 'audio/' . $filename;

            // Salvar o áudio gravado
            $success = Storage::disk('public')->put($audioPath, $audioBlob);

            if (!$success) {
                Log::error('AudioTranscriptionService: Falha ao salvar áudio gravado');
                return null;
            }

            Log::info('AudioTranscriptionService: Áudio gravado salvo com sucesso', [
                'audio_path' => $audioPath,
                'filename' => $filename
            ]);

            return [
                'success' => true,
                'audio_path' => $audioPath,
                'filename' => $filename,
                'full_path' => Storage::disk('public')->path($audioPath)
            ];
        } catch (\Exception $e) {
            Log::error('AudioTranscriptionService: Erro ao salvar áudio gravado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Processa áudio gravado: salva, transcreve e retorna contexto
     */
    public function processRecordedAudio($audioBlob, $extension = 'webm'): ?array
    {
        try {
            // 1. Salvar áudio gravado
            $saveResult = $this->saveRecordedAudio($audioBlob, $extension);

            if (!$saveResult || !$saveResult['success']) {
                Log::error('AudioTranscriptionService: Falha ao salvar áudio gravado');
                return null;
            }

            // 2. Transcrever áudio
            $transcription = $this->transcribeAudio($saveResult['audio_path'], 'public');

            // 3. Gerar contexto para análise
            $context = $transcription
                ? $this->buildTranscriptionContext($transcription, 0) // Tamanho será calculado depois
                : $this->buildFallbackAudioContext($saveResult['audio_path'], 0);

            Log::info('AudioTranscriptionService: Áudio gravado processado com sucesso', [
                'audio_path' => $saveResult['audio_path'],
                'has_transcription' => !empty($transcription),
                'context_length' => strlen($context)
            ]);

            return [
                'success' => true,
                'audio_path' => $saveResult['audio_path'],
                'transcription' => $transcription,
                'context' => $context,
                'filename' => $saveResult['filename']
            ];
        } catch (\Exception $e) {
            Log::error('AudioTranscriptionService: Erro ao processar áudio gravado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
}
