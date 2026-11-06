<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class FileUploadService
{
    /**
     * Configurações padrão para uploads
     */
    private const UPLOAD_CONFIG = [
        'image' => [
            'max_size' => 10 * 1024 * 1024, // 10MB
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'storage_path' => 'chat-images'
        ],
        'audio' => [
            'max_size' => 20 * 1024 * 1024, // 20MB
            'allowed_extensions' => ['mp3', 'wav', 'm4a', 'ogg'],
            'allowed_mimes' => ['audio/mpeg', 'audio/wav', 'audio/mp4', 'audio/ogg'],
            'storage_path' => 'chat-audios'
        ],
        'document' => [
            'max_size' => 10 * 1024 * 1024, // 10MB
            'allowed_extensions' => ['pdf', 'doc', 'docx'],
            'allowed_mimes' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ],
            'storage_path' => 'chat-files'
        ]
    ];

    /**
     * Validar arquivo de upload
     */
    public function validateFile(UploadedFile $file, string $type): array
    {
        try {
            if (!$file || !$file->isValid()) {
                return [
                    'valid' => false,
                    'error' => 'Arquivo inválido ou corrompido'
                ];
            }

            $config = self::UPLOAD_CONFIG[$type] ?? null;
            if (!$config) {
                return [
                    'valid' => false,
                    'error' => "Tipo de arquivo não suportado: {$type}"
                ];
            }

            // Validar tamanho
            if ($file->getSize() > $config['max_size']) {
                $sizeMB = round($file->getSize() / (1024 * 1024), 1);
                $maxSizeMB = round($config['max_size'] / (1024 * 1024), 1);

                return [
                    'valid' => false,
                    'error' => "Arquivo muito grande ({$sizeMB}MB). Máximo permitido: {$maxSizeMB}MB"
                ];
            }

            // Validar extensão
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, $config['allowed_extensions'])) {
                $allowedExtensions = implode(', ', array_map('strtoupper', $config['allowed_extensions']));
                return [
                    'valid' => false,
                    'error' => "Extensão '{$extension}' não suportada. Use apenas: {$allowedExtensions}"
                ];
            }

            // Validar tipo MIME
            $mimeType = $file->getMimeType();
            if (!in_array($mimeType, $config['allowed_mimes'])) {
                return [
                    'valid' => false,
                    'error' => "Tipo de arquivo não reconhecido. Certifique-se de que é um arquivo válido."
                ];
            }

            // Validações específicas por tipo
            $specificValidation = $this->validateFileType($file, $type);
            if (!$specificValidation['valid']) {
                return $specificValidation;
            }

            return [
                'valid' => true,
                'type' => $type,
                'extension' => $extension,
                'size' => $file->getSize(),
                'mime_type' => $mimeType,
                'original_name' => $file->getClientOriginalName()
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService: Erro na validação de arquivo', [
                'file_name' => $file ? $file->getClientOriginalName() : 'N/A',
                'type' => $type,
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'error' => 'Erro interno na validação do arquivo'
            ];
        }
    }

    /**
     * Validações específicas por tipo de arquivo
     */
    private function validateFileType(UploadedFile $file, string $type): array
    {
        try {
            switch ($type) {
                case 'image':
                    return $this->validateImage($file);
                case 'audio':
                    return $this->validateAudio($file);
                case 'document':
                    return $this->validateDocument($file);
                default:
                    return ['valid' => false, 'error' => 'Tipo de arquivo não suportado'];
            }
        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'Erro na validação específica: ' . $e->getMessage()];
        }
    }

    /**
     * Validar arquivo de imagem
     */
    private function validateImage(UploadedFile $file): array
    {
        try {
            // Verificar se é uma imagem válida
            $imageInfo = getimagesize($file->getRealPath());
            if (!$imageInfo) {
                return ['valid' => false, 'error' => 'Arquivo enviado não é uma imagem válida'];
            }

            // Verificar dimensões mínimas (opcional)
            if ($imageInfo[0] < 10 || $imageInfo[1] < 10) {
                return ['valid' => false, 'error' => 'Imagem muito pequena. Dimensões mínimas: 10x10 pixels'];
            }

            return ['valid' => true];
        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'Erro ao validar imagem: ' . $e->getMessage()];
        }
    }

    /**
     * Validar arquivo de áudio
     */
    private function validateAudio(UploadedFile $file): array
    {
        try {
            // Verificar se o arquivo pode ser lido
            $audioData = file_get_contents($file->getRealPath());
            if ($audioData === false) {
                return ['valid' => false, 'error' => 'Arquivo de áudio não pode ser lido'];
            }

            // Verificar se tem conteúdo mínimo
            if (strlen($audioData) < 100) {
                return ['valid' => false, 'error' => 'Arquivo de áudio parece estar vazio ou corrompido'];
            }

            return [
                'valid' => true,
                'extension' => $file->getClientOriginalExtension(),
                'mime_type' => $file->getMimeType()
            ];
        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'Erro ao validar áudio: ' . $e->getMessage()];
        }
    }

    /**
     * Validar documento
     */
    private function validateDocument(UploadedFile $file): array
    {
        try {
            // Verificar se o arquivo pode ser lido
            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                return ['valid' => false, 'error' => 'Documento não pode ser lido'];
            }

            // Verificar se tem conteúdo mínimo
            if (strlen($content) < 50) {
                return ['valid' => false, 'error' => 'Documento parece estar vazio ou corrompido'];
            }

            return ['valid' => true];
        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'Erro ao validar documento: ' . $e->getMessage()];
        }
    }

    /**
     * Fazer upload de arquivo
     */
    public function uploadFile(UploadedFile $file, string $type, string $customPath = null): array
    {
        try {

            // Validar arquivo primeiro
            if ($type == 'audio') {
                $validation = $this->validateAudio($file);
            } else {
                $validation = $this->validateFile($file, $type);
            }

            if (!$validation['valid']) {
                Log::error("Erro ao validar arquivo", [
                    'validation' => $validation['error'],
                    'file_info' => [
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'extension' => $file->getClientOriginalExtension(),
                        'isValid' => $file->isValid(),
                        'error' => $file->getError()
                    ]
                ]);
                return [
                    'success' => false,
                    'error' => $validation['error']
                ];
            }

            $config = self::UPLOAD_CONFIG[$type];
            $storagePath = $customPath ?: $config['storage_path'];

            // Verificar se o diretório existe e tem permissões
            $fullStoragePath = Storage::disk('public')->path($storagePath);
            if (!is_dir($fullStoragePath)) {
                // Criar diretório se não existir
                if (!mkdir($fullStoragePath, 0755, true)) {
                    Log::error("FileUploadService: Falha ao criar diretório", [
                        'path' => $fullStoragePath,
                        'type' => $type
                    ]);
                    return [
                        'success' => false,
                        'error' => 'Falha ao criar diretório de storage'
                    ];
                }
            }

            // Verificar permissões de escrita
            if (!is_writable($fullStoragePath)) {
                Log::error("FileUploadService: Diretório sem permissão de escrita", [
                    'path' => $fullStoragePath,
                    'permissions' => substr(sprintf('%o', fileperms($fullStoragePath)), -4)
                ]);
                return [
                    'success' => false,
                    'error' => 'Diretório de storage sem permissão de escrita'
                ];
            }

            // Fazer upload
            $filePath = $file->store($storagePath, 'public');
            if (!$filePath) {
                Log::error("FileUploadService: Falha no store()", [
                    'storage_path' => $storagePath,
                    'file_name' => $file->getClientOriginalName(),
                    'type' => $type
                ]);
                return [
                    'success' => false,
                    'error' => 'Falha ao salvar arquivo no servidor'
                ];
            }

            // Gerar URL pública
            $publicUrl = Storage::url($filePath);

            return [
                'success' => true,
                'file_path' => $filePath,
                'public_url' => $publicUrl,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'file_type' => $type,
                'extension' => $validation['extension'],
                'mime_type' => $validation['mime_type']
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService: Erro no upload de arquivo', [
                'file_name' => $file ? $file->getClientOriginalName() : 'N/A',
                'type' => $type,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro interno no upload: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Fazer upload de múltiplos arquivos
     */
    public function uploadMultipleFiles(array $files, string $type, string $customPath = null): array
    {
        $results = [];
        $errors = [];

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile) {
                $errors[] = "Arquivo {$index}: Arquivo inválido";
                continue;
            }

            $result = $this->uploadFile($file, $type, $customPath);
            if ($result['success']) {
                $results[] = $result;
            } else {
                $errors[] = "Arquivo {$index}: " . $result['error'];
            }
        }

        return [
            'success' => empty($errors),
            'uploaded_files' => $results,
            'errors' => $errors,
            'total_files' => count($files),
            'successful_uploads' => count($results),
            'failed_uploads' => count($errors)
        ];
    }

    /**
     * Remover arquivo
     */
    public function deleteFile(string $filePath): array
    {
        try {
            if (Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);

                return [
                    'success' => true,
                    'message' => 'Arquivo removido com sucesso'
                ];
            }

            return [
                'success' => false,
                'error' => 'Arquivo não encontrado'
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService: Erro ao remover arquivo', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao remover arquivo: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obter informações do arquivo
     */
    public function getFileInfo(string $filePath): array
    {
        try {
            if (!Storage::disk('public')->exists($filePath)) {
                return [
                    'success' => false,
                    'error' => 'Arquivo não encontrado'
                ];
            }

            $fullPath = Storage::disk('public')->path($filePath);
            $fileInfo = pathinfo($fullPath);
            $fileSize = filesize($fullPath);
            $mimeType = mime_content_type($fullPath);

            return [
                'success' => true,
                'file_path' => $filePath,
                'file_name' => $fileInfo['basename'],
                'extension' => $fileInfo['extension'] ?? '',
                'size' => $fileSize,
                'size_formatted' => $this->formatFileSize($fileSize),
                'mime_type' => $mimeType,
                'created_at' => date('Y-m-d H:i:s', filectime($fullPath)),
                'modified_at' => date('Y-m-d H:i:s', filemtime($fullPath)),
                'public_url' => Storage::url($filePath)
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService: Erro ao obter informações do arquivo', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao obter informações: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Formatar tamanho do arquivo
     */
    private function formatFileSize(int $bytes): string
    {
        try {
            if ($bytes < 0) {
                $bytes = 0;
            }

            $units = ['B', 'KB', 'MB', 'GB'];
            $pow = 0;

            if ($bytes > 0) {
                $pow = floor(log($bytes) / log(1024));
            }

            $pow = min($pow, count($units) - 1);
            $bytes = $bytes / pow(1024, $pow);

            return round($bytes, 2) . ' ' . $units[$pow];
        } catch (Exception $e) {
            return '0 B';
        }
    }

    /**
     * Limpar arquivos órfãos
     */
    public function cleanupOrphanedFiles(int $daysOld = 7): array
    {
        try {
            $directories = array_values(array_column(self::UPLOAD_CONFIG, 'storage_path'));
            $cutoffTime = now()->subDays($daysOld);
            $cleanedCount = 0;
            $errors = [];

            foreach ($directories as $directory) {
                $fullPath = Storage::disk('public')->path($directory);

                if (!is_dir($fullPath)) {
                    continue;
                }

                $files = glob($fullPath . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $fileTime = filemtime($file);

                        if ($fileTime < $cutoffTime->timestamp) {
                            $relativePath = $directory . '/' . basename($file);

                            try {
                                Storage::disk('public')->delete($relativePath);
                                $cleanedCount++;
                            } catch (Exception $e) {
                                $errors[] = "Erro ao remover {$relativePath}: " . $e->getMessage();
                            }
                        }
                    }
                }
            }

            return [
                'success' => true,
                'cleaned_count' => $cleanedCount,
                'errors' => $errors,
                'cutoff_date' => $cutoffTime->format('Y-m-d H:i:s')
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService: Erro na limpeza de arquivos órfãos', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro na limpeza: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obter configurações de upload
     */
    public function getUploadConfig(string $type): array
    {
        if ($type) {
            return self::UPLOAD_CONFIG[$type] ?? [];
        }

        return self::UPLOAD_CONFIG;
    }

    /**
     * Validar arquivo a partir de dados base64 (para APIs)
     */
    public function validateBase64File(string $base64Data, string $type, string $fileName): array
    {
        try {
            // Decodificar base64
            $fileData = base64_decode($base64Data, true);
            if ($fileData === false) {
                return [
                    'valid' => false,
                    'error' => 'Dados base64 inválidos'
                ];
            }

            $config = self::UPLOAD_CONFIG[$type] ?? null;
            if (!$config) {
                return [
                    'valid' => false,
                    'error' => "Tipo de arquivo não suportado: {$type}"
                ];
            }

            // Validar tamanho
            if (strlen($fileData) > $config['max_size']) {
                $sizeMB = round(strlen($fileData) / (1024 * 1024), 2);
                $maxSizeMB = round($config['max_size'] / (1024 * 1024), 2);

                return [
                    'valid' => false,
                    'error' => "Arquivo muito grande ({$sizeMB}MB). Máximo permitido: {$maxSizeMB}MB"
                ];
            }

            // Validar extensão se fileName for fornecido
            if ($fileName) {
                $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($extension, $config['allowed_extensions'])) {
                    $allowedExtensions = implode(', ', array_map('strtoupper', $config['allowed_extensions']));
                    return [
                        'valid' => false,
                        'error' => "Extensão '{$extension}' não suportada. Use apenas: {$allowedExtensions}"
                    ];
                }
            }

            return [
                'valid' => true,
                'file_data' => $fileData,
                'size' => strlen($fileData),
                'type' => $type
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService: Erro na validação de arquivo base64', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'error' => 'Erro na validação: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Fazer upload de arquivo base64 (para APIs)
     */
    public function uploadBase64File(string $base64Data, string $type, string $fileName, string $customPath = null): array
    {
        try {
            // Validar arquivo base64
            $validation = $this->validateBase64File($base64Data, $type, $fileName);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => $validation['error']
                ];
            }

            $config = self::UPLOAD_CONFIG[$type];
            $storagePath = $customPath ?: $config['storage_path'];

            // Gerar nome único para o arquivo
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $uniqueName = uniqid() . '_' . time() . '.' . $extension;
            $fullPath = $storagePath . '/' . $uniqueName;

            // Salvar arquivo
            if (!Storage::disk('public')->put($fullPath, $validation['file_data'])) {
                return [
                    'success' => false,
                    'error' => 'Falha ao salvar arquivo no servidor'
                ];
            }

            // Gerar URL pública
            $publicUrl = Storage::url($fullPath);

            return [
                'success' => true,
                'file_path' => $fullPath,
                'public_url' => $publicUrl,
                'file_name' => $fileName,
                'unique_name' => $uniqueName,
                'file_size' => $validation['size'],
                'file_type' => $type,
                'extension' => $extension
            ];
        } catch (Exception $e) {
            Log::error('FileUploadService: Erro no upload de arquivo base64', [
                'type' => $type,
                'file_name' => $fileName,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro no upload: ' . $e->getMessage()
            ];
        }
    }
}
