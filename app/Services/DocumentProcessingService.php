<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class DocumentProcessingService
{
    /**
     * Tipos de arquivo suportados
     */
    private const SUPPORTED_EXTENSIONS = ['pdf', 'doc', 'docx'];

    /**
     * Tamanho máximo de arquivo (10MB)
     */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Processa documento e extrai conteúdo
     */
    public function processDocument(UploadedFile $file): array
    {
        try {
            // Validar arquivo
            $validation = $this->validateDocument($file);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => $validation['error']
                ];
            }

            // Salvar arquivo temporariamente
            $filePath = $file->store('temp-documents', 'public');
            if (!$filePath) {
                return [
                    'success' => false,
                    'error' => 'Falha ao salvar arquivo temporário'
                ];
            }

            // Extrair conteúdo baseado na extensão
            $extension = strtolower($file->getClientOriginalExtension());
            $extractedContent = $this->extractContent($filePath, $extension);

            if (!$extractedContent['success']) {
                // Limpar arquivo temporário
                Storage::disk('public')->delete($filePath);

                return [
                    'success' => false,
                    'error' => $extractedContent['error']
                ];
            }

            // Salvar arquivo permanentemente
            $permanentPath = $file->store('documents', 'public');

            // Limpar arquivo temporário
            Storage::disk('public')->delete($filePath);

            return [
                'success' => true,
                'file_path' => $permanentPath,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'extension' => $extension,
                'extracted_content' => $extractedContent['content'],
                'content_length' => strlen($extractedContent['content']),
                'analysis' => $this->generateDocumentAnalysis($extractedContent['content'], $file->getClientOriginalName())
            ];
        } catch (Exception $e) {
            Log::error('DocumentProcessingService: Erro ao processar documento', [
                'file_name' => $file ? $file->getClientOriginalName() : 'N/A',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Erro interno ao processar documento: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Processa documento a partir de um caminho de arquivo existente
     */
    public function processDocumentFromPath(string $filePath): array
    {
        try {
            // Verificar se o arquivo existe
            if (!Storage::disk('public')->exists($filePath)) {
                return [
                    'success' => false,
                    'error' => 'Arquivo não encontrado'
                ];
            }

            // Obter informações do arquivo
            $fileName = basename($filePath);
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileSize = Storage::disk('public')->size($filePath);

            // Validar extensão
            if (!$this->isSupportedExtension($extension)) {
                return [
                    'success' => false,
                    'error' => 'Tipo de arquivo não suportado: ' . $extension
                ];
            }

            // Extrair conteúdo baseado na extensão
            $extractedContent = $this->extractContent($filePath, $extension);

            if (!$extractedContent['success']) {
                return [
                    'success' => false,
                    'error' => $extractedContent['error']
                ];
            }

            return [
                'success' => true,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'extension' => $extension,
                'extracted_content' => $extractedContent['content'],
                'content_length' => strlen($extractedContent['content']),
                'analysis' => $this->generateDocumentAnalysis($extractedContent['content'], $fileName)
            ];
        } catch (Exception $e) {
            Log::error('DocumentProcessingService: Erro ao processar documento do caminho', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Erro interno ao processar documento: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Valida documento enviado
     */
    private function validateDocument(UploadedFile $file): array
    {
        try {
            if (!$file || !$file->isValid()) {
                return [
                    'valid' => false,
                    'error' => 'Arquivo inválido ou corrompido'
                ];
            }

            // Verificar tamanho
            if ($file->getSize() > self::MAX_FILE_SIZE) {
                $sizeMB = round($file->getSize() / (1024 * 1024), 1);
                $maxSizeMB = round(self::MAX_FILE_SIZE / (1024 * 1024), 1);

                return [
                    'valid' => false,
                    'error' => "Arquivo muito grande ({$sizeMB}MB). Máximo permitido: {$maxSizeMB}MB"
                ];
            }

            // Verificar extensão
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, self::SUPPORTED_EXTENSIONS)) {
                $allowedExtensions = implode(', ', array_map('strtoupper', self::SUPPORTED_EXTENSIONS));
                return [
                    'valid' => false,
                    'error' => "Extensão '{$extension}' não suportada. Use apenas: {$allowedExtensions}"
                ];
            }

            return [
                'valid' => true,
                'extension' => $extension,
                'size' => $file->getSize()
            ];
        } catch (Exception $e) {
            Log::error('DocumentProcessingService: Erro na validação de documento', [
                'file_name' => $file ? $file->getClientOriginalName() : 'N/A',
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'error' => 'Erro na validação do documento'
            ];
        }
    }

    /**
     * Extrai conteúdo baseado na extensão do arquivo
     */
    private function extractContent(string $filePath, string $extension): array
    {
        try {
            $fullPath = Storage::disk('public')->path($filePath);

            switch ($extension) {
                case 'pdf':
                    return $this->extractPdfContent($fullPath);
                case 'docx':
                    return $this->extractDocxContent($fullPath);
                case 'doc':
                    return $this->extractDocContent($fullPath);
                default:
                    return [
                        'success' => false,
                        'error' => "Extensão '{$extension}' não suportada para extração"
                    ];
            }
        } catch (Exception $e) {
            Log::error('DocumentProcessingService: Erro ao extrair conteúdo', [
                'file_path' => $filePath,
                'extension' => $extension,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao extrair conteúdo do documento: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extrai conteúdo de arquivo PDF
     */
    private function extractPdfContent(string $filePath): array
    {
        try {
            // 1. Tentar primeiro com smalot/pdfparser (mais confiável)
            if (class_exists('\Smalot\PdfParser\Parser')) {
                try {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseFile($filePath);
                    $text = $pdf->getText();

                    if ($text && strlen(trim($text)) > 50) {
                        // Limpar e normalizar texto
                        $text = preg_replace('/\s+/', ' ', $text);
                        $text = trim($text);

                        Log::info('DocumentProcessingService: PDF extraído com smalot/pdfparser', [
                            'text_length' => strlen($text),
                            'preview' => substr($text, 0, 200) . '...'
                        ]);

                        return [
                            'success' => true,
                            'content' => $text
                        ];
                    }
                } catch (Exception $e) {
                    Log::warning('DocumentProcessingService: smalot/pdfparser falhou, tentando fallback', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 2. Fallback: usar comando do sistema
            $content = $this->extractPdfWithSystemCommand($filePath);
            if ($content && strlen(trim($content)) > 50) {
                return [
                    'success' => true,
                    'content' => trim($content)
                ];
            }

            // 3. Fallback: tentar extrair texto ASCII limpo
            $content = $this->extractPdfAsText($filePath);
            if ($content && strlen(trim($content)) > 50) {
                return [
                    'success' => true,
                    'content' => trim($content)
                ];
            }

            return [
                'success' => false,
                'error' => 'Não foi possível extrair texto do PDF'
            ];
        } catch (Exception $e) {
            Log::error('DocumentProcessingService: Erro ao extrair PDF', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao extrair conteúdo do PDF: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Fallback para extração de PDF usando comando do sistema
     */
    private function extractPdfWithSystemCommand(string $filePath): ?string
    {
        try {
            // Tentar usar pdftotext (poppler-utils)
            if (function_exists('shell_exec')) {
                $output = shell_exec("pdftotext '{$filePath}' - 2>/dev/null");
                if ($output && strlen(trim($output)) > 0) {
                    Log::info('DocumentProcessingService: PDF extraído com pdftotext');
                    return trim($output);
                }
            }

            // Tentar usar python com PyPDF2
            if (function_exists('shell_exec')) {
                $output = shell_exec("python3 -c \"import PyPDF2; print(PyPDF2.PdfReader('{$filePath}').pages[0].extract_text())\" 2>/dev/null");
                if ($output && strlen(trim($output)) > 0) {
                    Log::info('DocumentProcessingService: PDF extraído com Python PyPDF2');
                    return trim($output);
                }
            }

            return null;
        } catch (Exception $e) {
            Log::warning('DocumentProcessingService: Comandos do sistema falharam para PDF', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Fallback: extrair texto ASCII limpo do PDF
     */
    private function extractPdfAsText(string $filePath): ?string
    {
        try {
            $content = file_get_contents($filePath);
            if (!$content || strlen($content) < 100) {
                return null;
            }

            // Tentar extrair texto ASCII limpo
            $textContent = preg_replace('/[^\x20-\x7E\n\r\t]/', '', $content);

            // Limpar caracteres especiais e normalizar
            $textContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $textContent);
            $textContent = preg_replace('/\s+/', ' ', $textContent); // Normalizar espaços
            $textContent = trim($textContent);

            if (strlen($textContent) > 100) {
                // Verificar se o texto é legível (pelo menos 30% de caracteres válidos)
                $validChars = preg_match_all('/[a-zA-Z0-9\s]/', $textContent);
                $totalChars = strlen($textContent);

                if ($validChars / $totalChars > 0.3) {
                    Log::info('DocumentProcessingService: Texto PDF extraído com fallback ASCII', [
                        'total_chars' => $totalChars,
                        'valid_chars' => $validChars,
                        'ratio' => round($validChars / $totalChars, 2),
                        'preview' => substr($textContent, 0, 200) . '...'
                    ]);
                    return $textContent;
                }
            }

            return null;
        } catch (Exception $e) {
            Log::warning('DocumentProcessingService: Fallback ASCII falhou para PDF', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extrai conteúdo de arquivo DOCX
     */
    private function extractDocxContent(string $filePath): array
    {
        try {
            // DOCX é um arquivo ZIP com XML
            $zip = new \ZipArchive();
            if ($zip->open($filePath) !== true) {
                return [
                    'success' => false,
                    'error' => 'Não foi possível abrir arquivo DOCX'
                ];
            }

            $content = '';

            // Buscar o arquivo document.xml que contém o texto
            $xmlContent = $zip->getFromName('word/document.xml');
            if ($xmlContent) {
                // Remover tags XML e extrair texto
                $content = strip_tags($xmlContent);
                $content = preg_replace('/\s+/', ' ', $content); // Normalizar espaços
                $content = trim($content);
            }

            $zip->close();

            if (empty($content)) {
                return [
                    'success' => false,
                    'error' => 'Não foi possível extrair texto do arquivo DOCX'
                ];
            }

            return [
                'success' => true,
                'content' => $content
            ];
        } catch (Exception $e) {
            Log::error('DocumentProcessingService: Erro ao extrair DOCX', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao extrair conteúdo do DOCX: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extrai conteúdo de arquivo DOC
     */
    private function extractDocContent(string $filePath): array
    {
        try {
            // Para arquivos DOC antigos, tentar usar comando do sistema
            $content = $this->extractDocWithSystemCommand($filePath);
            if ($content) {
                return [
                    'success' => true,
                    'content' => $content
                ];
            }

            return [
                'success' => false,
                'error' => 'Não foi possível extrair texto do arquivo DOC. Formato pode não ser suportado.'
            ];
        } catch (Exception $e) {
            Log::error('DocumentProcessingService: Erro ao extrair DOC', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao extrair conteúdo do DOC: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Fallback para extração de DOC usando comando do sistema
     */
    private function extractDocWithSystemCommand(string $filePath): ?string
    {
        try {
            // Tentar usar antiword
            $output = shell_exec("antiword '{$filePath}' 2>/dev/null");
            if ($output && strlen(trim($output)) > 0) {
                return trim($output);
            }

            // Tentar usar catdoc
            $output = shell_exec("catdoc '{$filePath}' 2>/dev/null");
            if ($output && strlen(trim($output)) > 0) {
                return trim($output);
            }

            return null;
        } catch (Exception $e) {
            Log::warning('DocumentProcessingService: Comandos do sistema falharam para DOC', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Gera análise do documento extraído
     */
    private function generateDocumentAnalysis(string $content, string $fileName): string
    {
        try {
            $wordCount = str_word_count($content);
            $charCount = strlen($content);
            $lineCount = substr_count($content, "\n") + 1;

            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileType = strtoupper($extension);

            $analysis = "📄 **ANÁLISE DO DOCUMENTO {$fileType}**\n\n";
            $analysis .= "**📊 Estatísticas:**\n";
            $analysis .= "• Nome: {$fileName}\n";
            $analysis .= "• Tipo: {$fileType}\n";
            $analysis .= "• Palavras: {$wordCount}\n";
            $analysis .= "• Caracteres: {$charCount}\n";
            $analysis .= "• Linhas: {$lineCount}\n\n";

            // Resumo do conteúdo
            $summary = substr($content, 0, 500);
            if (strlen($content) > 500) {
                $summary .= "...";
            }

            $analysis .= "**📝 Resumo do Conteúdo:**\n{$summary}\n\n";
            $analysis .= "**🔍 Contexto para Análise:**\nO documento foi processado e seu conteúdo foi extraído para análise. O agente pode analisar o texto completo e fornecer insights relevantes.";

            return $analysis;
        } catch (Exception $e) {
            Log::error('DocumentProcessingService: Erro ao gerar análise', [
                'file_name' => $fileName,
                'error' => $e->getMessage()
            ]);

            return "📄 **Documento {$fileName}**\n\nO documento foi processado com sucesso, mas houve um erro ao gerar a análise detalhada.";
        }
    }

    /**
     * Verifica se a extensão é suportada
     */
    public function isSupportedExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::SUPPORTED_EXTENSIONS);
    }

    /**
     * Obtém extensões suportadas
     */
    public function getSupportedExtensions(): array
    {
        return self::SUPPORTED_EXTENSIONS;
    }

    /**
     * Obtém tamanho máximo permitido
     */
    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }
}
