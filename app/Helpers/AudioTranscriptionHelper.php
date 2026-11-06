<?php

namespace App\Helpers;

use App\Services\AudioTranscriptionService;

class AudioTranscriptionHelper
{
    /**
     * Transcreve áudio usando OpenAI Whisper
     */
    public static function transcribe($audioPath, $disk = 'public'): ?string
    {
        $service = new AudioTranscriptionService();
        return $service->transcribeAudio($audioPath, $disk);
    }

    /**
     * Analisa áudio e retorna contexto completo
     */
    public static function analyze($audioPath, $disk = 'public'): array
    {
        $service = new AudioTranscriptionService();
        return $service->analyzeAudio($audioPath, $disk);
    }

    /**
     * Transcreve áudio a partir de dados base64
     */
    public static function transcribeFromBase64(string $base64Audio, string $extension = 'webm'): ?string
    {
        $service = new AudioTranscriptionService();
        return $service->transcribeFromBase64($base64Audio, $extension);
    }

    /**
     * Verifica se a transcrição está disponível
     */
    public static function isAvailable(): bool
    {
        try {
            $settings = \App\Models\Settings::first();
            return $settings && !empty($settings->api_key);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obtém informações sobre o serviço
     */
    public static function getServiceInfo(): array
    {
        return [
            'available' => self::isAvailable(),
            'service' => 'OpenAI Whisper API',
            'supported_formats' => ['webm', 'mp3', 'wav', 'm4a', 'ogg', 'aac'],
            'max_file_size' => '25MB',
            'language_support' => 'Português brasileiro (pt)',
            'features' => [
                'Real-time transcription',
                'Emotional context analysis',
                'Fallback analysis',
                'Base64 support for APIs'
            ]
        ];
    }
}
