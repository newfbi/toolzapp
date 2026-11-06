<?php

namespace App\Helpers;

use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileUploadHelper
{
    private static $fileUploadService = null;

    /**
     * Obter instância do FileUploadService
     */
    private static function getService(): FileUploadService
    {
        if (self::$fileUploadService === null) {
            self::$fileUploadService = new FileUploadService();
        }
        return self::$fileUploadService;
    }

    /**
     * Validar arquivo de imagem
     */
    public static function validateImage(UploadedFile $file): array
    {
        return self::getService()->validateFile($file, 'image');
    }

    /**
     * Validar arquivo de áudio
     */
    public static function validateAudio(UploadedFile $file): array
    {
        return self::getService()->validateFile($file, 'audio');
    }

    /**
     * Validar documento
     */
    public static function validateDocument(UploadedFile $file): array
    {
        return self::getService()->validateFile($file, 'document');
    }

    /**
     * Fazer upload de imagem
     */
    public static function uploadImage(UploadedFile $file, string $customPath = null): array
    {
        return self::getService()->uploadFile($file, 'image', $customPath);
    }

    /**
     * Fazer upload de áudio
     */
    public static function uploadAudio(UploadedFile $file, string $customPath = null): array
    {
        return self::getService()->uploadFile($file, 'audio', $customPath);
    }

    /**
     * Fazer upload de documento
     */
    public static function uploadDocument(UploadedFile $file, string $customPath = null): array
    {
        return self::getService()->uploadFile($file, 'document', $customPath);
    }

    /**
     * Fazer upload de arquivo baseado no tipo
     */
    public static function uploadFile(UploadedFile $file, string $type, string $customPath = null): array
    {
        return self::getService()->uploadFile($file, $type, $customPath);
    }

    /**
     * Fazer upload de múltiplos arquivos
     */
    public static function uploadMultipleFiles(array $files, string $type, string $customPath = null): array
    {
        return self::getService()->uploadMultipleFiles($files, $type, $customPath);
    }

    /**
     * Remover arquivo
     */
    public static function deleteFile(string $filePath): array
    {
        return self::getService()->deleteFile($filePath);
    }

    /**
     * Obter informações do arquivo
     */
    public static function getFileInfo(string $filePath): array
    {
        return self::getService()->getFileInfo($filePath);
    }

    /**
     * Limpar arquivos órfãos
     */
    public static function cleanupOrphanedFiles(int $daysOld = 7): array
    {
        return self::getService()->cleanupOrphanedFiles($daysOld);
    }

    /**
     * Obter configurações de upload
     */
    public static function getUploadConfig(string $type = null): array
    {
        return self::getService()->getUploadConfig($type);
    }

    /**
     * Validar arquivo base64 (para APIs)
     */
    public static function validateBase64File(string $base64Data, string $type, string $fileName = null): array
    {
        return self::getService()->validateBase64File($base64Data, $type, $fileName);
    }

    /**
     * Fazer upload de arquivo base64 (para APIs)
     */
    public static function uploadBase64File(string $base64Data, string $type, string $fileName, string $customPath = null): array
    {
        return self::getService()->uploadBase64File($base64Data, $type, $fileName, $customPath);
    }

    /**
     * Validar e fazer upload em uma única operação
     */
    public static function validateAndUpload(UploadedFile $file, string $type, string $customPath = null): array
    {
        $service = self::getService();

        // Validar primeiro
        $validation = $service->validateFile($file, $type);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error']
            ];
        }

        // Fazer upload
        return $service->uploadFile($file, $type, $customPath);
    }

    /**
     * Validar múltiplos arquivos
     */
    public static function validateMultipleFiles(array $files, string $type): array
    {
        $validFiles = [];
        $errors = [];

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile) {
                $errors[] = "Arquivo {$index}: Arquivo inválido";
                continue;
            }

            $validation = self::getService()->validateFile($file, $type);
            if ($validation['valid']) {
                $validFiles[] = $file;
            } else {
                $errors[] = "Arquivo {$index}: " . $validation['error'];
            }
        }

        return [
            'valid' => empty($errors),
            'valid_files' => $validFiles,
            'errors' => $errors,
            'total_files' => count($files),
            'valid_count' => count($validFiles),
            'error_count' => count($errors)
        ];
    }

    /**
     * Gerar nome único para arquivo
     */
    public static function generateUniqueFileName(string $originalName, string $extension = null): string
    {
        if (!$extension) {
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        }

        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $timestamp = time();
        $random = uniqid();

        return "{$baseName}_{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Verificar se arquivo existe
     */
    public static function fileExists(string $filePath): bool
    {
        return Storage::disk('public')->exists($filePath);
    }

    /**
     * Obter URL pública do arquivo
     */
    public static function getPublicUrl(string $filePath): string
    {
        return Storage::url($filePath);
    }

    /**
     * Obter caminho completo do arquivo
     */
    public static function getFullPath(string $filePath): string
    {
        return Storage::disk('public')->path($filePath);
    }

    /**
     * Verificar se arquivo é uma imagem válida
     */
    public static function isValidImage(UploadedFile $file): bool
    {
        $validation = self::validateImage($file);
        return $validation['valid'];
    }

    /**
     * Verificar se arquivo é um áudio válido
     */
    public static function isValidAudio(UploadedFile $file): bool
    {
        $validation = self::validateAudio($file);
        return $validation['valid'];
    }

    /**
     * Verificar se arquivo é um documento válido
     */
    public static function isValidDocument(UploadedFile $file): bool
    {
        $validation = self::validateDocument($file);
        return $validation['valid'];
    }

    /**
     * Obter tipo de arquivo baseado na extensão
     */
    public static function getFileTypeFromExtension(string $extension): ?string
    {
        $extension = strtolower($extension);

        $typeMap = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'audio' => ['mp3', 'wav', 'm4a', 'ogg'],
            'document' => ['pdf', 'doc', 'docx']
        ];

        foreach ($typeMap as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Obter tipo de arquivo baseado no MIME type
     */
    public static function getFileTypeFromMime(string $mimeType): ?string
    {
        $mimeType = strtolower(trim($mimeType));
        
        $mimeMap = [
            'image' => [
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
                'image/bmp', 'image/svg+xml', 'image/x-icon', 'image/tiff', 'image/tif',
                'image/x-png', 'image/pjpeg'
            ],
            'audio' => [
                'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/wave', 'audio/x-wav',
                'audio/mp4', 'audio/m4a', 'audio/ogg', 'audio/oga', 'audio/webm',
                'audio/aac', 'audio/flac', 'audio/x-flac', 'audio/wma', 'audio/x-ms-wma',
                'audio/opus', 'audio/vorbis'
            ],
            'document' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                'application/vnd.ms-word.document.macroEnabled.12', // .docm
                'application/vnd.ms-excel', // .xls
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
                'application/vnd.ms-powerpoint', // .ppt
                'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .pptx
                'text/plain', // .txt
                'text/rtf', // .rtf
                'application/rtf',
                'application/vnd.oasis.opendocument.text', // .odt
                'text/csv', // .csv
                'application/vnd.ms-excel.sheet.macroEnabled.12', // .xlsm
                'application/vnd.openxmlformats-officedocument.wordprocessingml.template' // .dotx
            ]
        ];

        // Verifica correspondência exata
        foreach ($mimeMap as $type => $mimes) {
            if (in_array($mimeType, $mimes)) {
                return $type;
            }
        }

        // Verifica correspondência parcial (ex: audio/*, image/*)
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'application/') || str_starts_with($mimeType, 'text/')) {
            // Pode ser documento, mas precisa verificar mais
            // Retorna null para permitir detecção por extensão ou conteúdo
            return null;
        }

        return null;
    }
}
