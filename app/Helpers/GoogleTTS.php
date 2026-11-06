<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleTTS
{
    public static function synthesizeAudio(string $text, string $apiKey, string $gender = 'FEMALE', string $language = 'pt-BR', string $voice = 'pt-BR-Wavenet-A', string $audioFormat = 'MP3')
    {
        try {
            // Endpoint da API do Google Text-to-Speech
            $endpoint = 'https://texttospeech.googleapis.com/v1/text:synthesize';

            // Corpo da requisição
            $payload = [
                'input' => [
                    'text' => $text,
                ],
                'voice' => [
                    'languageCode' => $language,
                    'name' => $voice,
                    'ssmlGender' => strtoupper($gender),
                ],
                'audioConfig' => [
                    'audioEncoding' => $audioFormat,
                ],
            ];

            // Fazer a requisição à API
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($endpoint . '?key=' . $apiKey, $payload);

            // Verificar a resposta
            if ($response->successful()) {
                $audioContent = $response->json()['audioContent'];

                // Gera um nome único para o arquivo de áudio
                $fileName = 'audios/agent_' . uniqid() . '.mp3';
                $filePath = public_path('assets/' . $fileName);

                // Decodifica o áudio base64 e salva no disco
                file_put_contents($filePath, base64_decode($audioContent));

                // Retorna o caminho relativo do arquivo
                return $fileName;
            }

            // Tratar erros da API
            Log::error('Erro na API Google TTS: ' . $response->body());
            return null;
        } catch (\Exception $e) {
            Log::error('Erro no Google TTS: ' . $e->getMessage());
            return null;
        }
    }
}
