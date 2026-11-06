<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use Exception;

class DocumentProcessor
{
    private PdfParser $pdfParser;

    public function __construct()
    {
        $this->pdfParser = new PdfParser();

        // Configurar PhpWord para melhor compatibilidade
        Settings::setOutputEscapingEnabled(true);
    }

    /**
     * Extrair texto de arquivo PDF
     */
    public function extractTextFromPdf(string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                throw new Exception("Arquivo PDF não encontrado: {$filePath}");
            }

            // Verificar se a biblioteca PdfParser está disponível
            if (!class_exists('\Smalot\PdfParser\Parser')) {
                throw new Exception("Biblioteca PdfParser não está disponível");
            }

            $pdf = $this->pdfParser->parseFile($filePath);
            if (!$pdf) {
                throw new Exception("Falha ao fazer parse do PDF");
            }

            $text = '';
            try {
                $text = $pdf->getText();
            } catch (Exception $e) {
                Log::warning('DocumentProcessor: Erro ao obter texto do PDF', [
                    'file' => $filePath,
                    'error' => $e->getMessage()
                ]);
                throw new Exception("Erro ao extrair texto do PDF: " . $e->getMessage());
            }

            if (!$text || empty(trim($text))) {
                throw new Exception("PDF não contém texto extraível");
            }

            $metadata = $this->getPdfMetadata($pdf);

            return [
                'success' => true,
                'text' => trim($text),
                'metadata' => $metadata,
                'word_count' => str_word_count($text),
                'char_count' => strlen($text)
            ];
        } catch (Exception $e) {
            Log::error('DocumentProcessor: Erro ao extrair texto do PDF', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'text' => '',
                'metadata' => [],
                'word_count' => 0,
                'char_count' => 0
            ];
        }
    }

    /**
     * Extrair texto de documento Word
     */
    public function extractTextFromWord(string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                throw new Exception("Arquivo Word não encontrado: {$filePath}");
            }

            // Verificar se a biblioteca PhpWord está disponível
            if (!class_exists('\PhpOffice\PhpWord\IOFactory')) {
                throw new Exception("Biblioteca PhpWord não está disponível");
            }

            $phpWord = IOFactory::load($filePath);
            if (!$phpWord) {
                throw new Exception("Falha ao carregar documento Word");
            }

            $text = '';

            try {
                $sections = $phpWord->getSections();
                if (is_array($sections) || $sections instanceof \Traversable) {
                    foreach ($sections as $section) {
                        if (method_exists($section, 'getElements')) {
                            $elements = $section->getElements();
                            if (is_array($elements) || $elements instanceof \Traversable) {
                                foreach ($elements as $element) {
                                    $text .= $this->extractTextFromElement($element) . "\n";
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                Log::warning('DocumentProcessor: Erro ao processar seções do Word', [
                    'file' => $filePath,
                    'error' => $e->getMessage()
                ]);
            }

            if (empty(trim($text))) {
                throw new Exception("Documento Word não contém texto extraível");
            }

            $metadata = $this->getWordMetadata($phpWord, $filePath);

            return [
                'success' => true,
                'text' => trim($text),
                'metadata' => $metadata,
                'word_count' => str_word_count($text),
                'char_count' => strlen($text)
            ];
        } catch (Exception $e) {
            Log::error('DocumentProcessor: Erro ao extrair texto do Word', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'text' => '',
                'metadata' => [],
                'word_count' => 0,
                'char_count' => 0
            ];
        }
    }

    /**
     * Validar documento antes do processamento
     */
    public function validateDocument(string $filePath, string $type): bool
    {
        try {
            if (!file_exists($filePath)) {
                Log::warning('DocumentProcessor: Arquivo não encontrado para validação', ['file' => $filePath]);
                return false;
            }

            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                Log::warning('DocumentProcessor: Não foi possível obter tamanho do arquivo', ['file' => $filePath]);
                return false;
            }

            if ($fileSize > 10 * 1024 * 1024) { // 10MB max
                Log::warning('DocumentProcessor: Arquivo muito grande', [
                    'file' => $filePath,
                    'size' => $fileSize,
                    'max_size' => 10 * 1024 * 1024
                ]);
                return false;
            }

            $mimeType = mime_content_type($filePath);
            if (!$mimeType) {
                Log::warning('DocumentProcessor: Não foi possível determinar tipo MIME', ['file' => $filePath]);
                return false;
            }

            $type = strtolower($type);
            $isValid = false;

            switch ($type) {
                case 'pdf':
                    $isValid = $mimeType === 'application/pdf';
                    break;
                case 'docx':
                case 'word':
                    $isValid = in_array($mimeType, [
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/msword',
                        'application/octet-stream' // Alguns sistemas podem retornar este tipo
                    ]);
                    break;
                default:
                    Log::warning('DocumentProcessor: Tipo de documento não suportado', ['type' => $type]);
                    return false;
            }

            if (!$isValid) {
                Log::warning('DocumentProcessor: Tipo MIME não corresponde ao tipo esperado', [
                    'file' => $filePath,
                    'expected_type' => $type,
                    'actual_mime' => $mimeType
                ]);
            }

            return $isValid;
        } catch (Exception $e) {
            Log::error('DocumentProcessor: Erro na validação do documento', [
                'file' => $filePath,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Obter metadados do documento
     */
    public function getDocumentMetadata(string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                Log::warning('DocumentProcessor: Arquivo não encontrado para metadados', ['file' => $filePath]);
                return [];
            }

            $fileInfo = pathinfo($filePath);
            $fileSize = filesize($filePath);
            $mimeType = mime_content_type($filePath);

            if ($fileSize === false) {
                Log::warning('DocumentProcessor: Não foi possível obter tamanho do arquivo', ['file' => $filePath]);
                $fileSize = 0;
            }

            $metadata = [
                'filename' => $fileInfo['basename'] ?? '',
                'extension' => $fileInfo['extension'] ?? '',
                'size' => $fileSize,
                'size_formatted' => $this->formatFileSize($fileSize),
                'mime_type' => $mimeType ?: 'application/octet-stream',
                'created_at' => '',
                'modified_at' => ''
            ];

            try {
                $metadata['created_at'] = date('Y-m-d H:i:s', filectime($filePath));
            } catch (Exception $e) {
                Log::warning('DocumentProcessor: Erro ao obter data de criação', ['error' => $e->getMessage()]);
            }

            try {
                $metadata['modified_at'] = date('Y-m-d H:i:s', filemtime($filePath));
            } catch (Exception $e) {
                Log::warning('DocumentProcessor: Erro ao obter data de modificação', ['error' => $e->getMessage()]);
            }

            // Adicionar metadados específicos por tipo
            if ($mimeType === 'application/pdf') {
                try {
                    $pdfMetadata = $this->extractPdfSpecificMetadata($filePath);
                    $metadata = array_merge($metadata, $pdfMetadata);
                } catch (Exception $e) {
                    Log::warning('DocumentProcessor: Erro ao extrair metadados específicos do PDF', ['error' => $e->getMessage()]);
                }
            }

            return $metadata;
        } catch (Exception $e) {
            Log::error('DocumentProcessor: Erro ao obter metadados', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extrair texto de elemento do Word
     */
    private function extractTextFromElement($element): string
    {
        $text = '';

        try {
            // Verificar se o elemento é válido
            if (!$element) {
                return '';
            }

            // Text simples
            if (method_exists($element, 'getText')) {
                try {
                    $elementText = $element->getText();
                    if (is_string($elementText)) {
                        $text .= $elementText;
                    }
                } catch (Exception $e) {
                    Log::warning('DocumentProcessor: Erro ao obter texto do elemento', [
                        'element_type' => get_class($element),
                        'error' => $e->getMessage()
                    ]);
                }
            }
            // Elementos com filhos (TextRun, Title, etc.)
            elseif (method_exists($element, 'getElements')) {
                try {
                    $childElements = $element->getElements();
                    if (is_array($childElements) || $childElements instanceof \Traversable) {
                        foreach ($childElements as $childElement) {
                            $text .= $this->extractTextFromElement($childElement);
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('DocumentProcessor: Erro ao processar elementos filhos', [
                        'element_type' => get_class($element),
                        'error' => $e->getMessage()
                    ]);
                }
            }
            // Elementos de tabela
            elseif (method_exists($element, 'getRows')) {
                try {
                    $rows = $element->getRows();
                    if (is_array($rows) || $rows instanceof \Traversable) {
                        foreach ($rows as $row) {
                            if (method_exists($row, 'getCells')) {
                                $cells = $row->getCells();
                                if (is_array($cells) || $cells instanceof \Traversable) {
                                    foreach ($cells as $cell) {
                                        $text .= $this->extractTextFromElement($cell) . ' ';
                                    }
                                }
                            }
                            $text .= "\n";
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('DocumentProcessor: Erro ao processar tabela', [
                        'element_type' => get_class($element),
                        'error' => $e->getMessage()
                    ]);
                }
            }
            // Elementos de lista
            elseif (method_exists($element, 'getListItems')) {
                try {
                    $listItems = $element->getListItems();
                    if (is_array($listItems) || $listItems instanceof \Traversable) {
                        foreach ($listItems as $listItem) {
                            $text .= $this->extractTextFromElement($listItem) . "\n";
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('DocumentProcessor: Erro ao processar lista', [
                        'element_type' => get_class($element),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $text;
        } catch (Exception $e) {
            Log::warning('DocumentProcessor: Erro ao extrair texto de elemento', [
                'element_type' => $element ? get_class($element) : 'null',
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Obter metadados específicos do PDF
     */
    private function getPdfMetadata($pdf): array
    {
        try {
            if (!$pdf) {
                return ['pages' => 0];
            }

            $metadata = [
                'pages' => 0,
                'title' => '',
                'author' => '',
                'subject' => '',
                'creator' => '',
                'creation_date' => '',
                'modification_date' => ''
            ];

            try {
                $details = $pdf->getDetails();
                if ($details && is_array($details)) {
                    $metadata['title'] = $details['Title'] ?? '';
                    $metadata['author'] = $details['Author'] ?? '';
                    $metadata['subject'] = $details['Subject'] ?? '';
                    $metadata['creator'] = $details['Creator'] ?? '';
                    $metadata['creation_date'] = $details['CreationDate'] ?? '';
                    $metadata['modification_date'] = $details['ModDate'] ?? '';
                }
            } catch (Exception $e) {
                Log::warning('DocumentProcessor: Erro ao obter detalhes do PDF', ['error' => $e->getMessage()]);
            }

            try {
                $pages = $pdf->getPages();
                if (is_array($pages) || $pages instanceof \Traversable) {
                    $metadata['pages'] = count($pages);
                } else {
                    $metadata['pages'] = 0;
                }
            } catch (Exception $e) {
                Log::warning('DocumentProcessor: Erro ao obter páginas do PDF', ['error' => $e->getMessage()]);
            }

            return $metadata;
        } catch (Exception $e) {
            Log::error('DocumentProcessor: Erro ao obter metadados do PDF', [
                'error' => $e->getMessage()
            ]);
            return ['pages' => 0];
        }
    }

    /**
     * Obter metadados específicos do Word
     */
    private function getWordMetadata($phpWord, string $filePath): array
    {
        try {
            if (!$phpWord) {
                return ['sections' => 0];
            }

            $metadata = [
                'sections' => 0,
                'title' => '',
                'author' => '',
                'subject' => '',
                'description' => '',
                'created' => '',
                'modified' => ''
            ];

            try {
                $sections = $phpWord->getSections();
                if (is_array($sections) || $sections instanceof \Traversable) {
                    $metadata['sections'] = count($sections);
                }
            } catch (Exception $e) {
                Log::warning('DocumentProcessor: Erro ao obter seções do Word', ['error' => $e->getMessage()]);
            }

            // Extrair propriedades de forma segura
            try {
                $properties = $phpWord->getDocInfo();
                if ($properties) {
                    try {
                        $metadata['title'] = $properties->getTitle() ?? '';
                    } catch (Exception $e) {
                        Log::warning('DocumentProcessor: Erro ao obter título do Word', ['error' => $e->getMessage()]);
                    }

                    try {
                        $metadata['author'] = $properties->getCreator() ?? '';
                    } catch (Exception $e) {
                        Log::warning('DocumentProcessor: Erro ao obter autor do Word', ['error' => $e->getMessage()]);
                    }

                    try {
                        $metadata['subject'] = $properties->getSubject() ?? '';
                    } catch (Exception $e) {
                        Log::warning('DocumentProcessor: Erro ao obter assunto do Word', ['error' => $e->getMessage()]);
                    }

                    try {
                        $metadata['description'] = $properties->getDescription() ?? '';
                    } catch (Exception $e) {
                        Log::warning('DocumentProcessor: Erro ao obter descrição do Word', ['error' => $e->getMessage()]);
                    }

                    try {
                        $metadata['created'] = $this->formatWordDate($properties->getCreated());
                    } catch (Exception $e) {
                        Log::warning('DocumentProcessor: Erro ao obter data de criação do Word', ['error' => $e->getMessage()]);
                    }

                    try {
                        $metadata['modified'] = $this->formatWordDate($properties->getModified());
                    } catch (Exception $e) {
                        Log::warning('DocumentProcessor: Erro ao obter data de modificação do Word', ['error' => $e->getMessage()]);
                    }
                }
            } catch (Exception $e) {
                Log::warning('DocumentProcessor: Erro ao obter propriedades do Word', ['error' => $e->getMessage()]);
            }

            return $metadata;
        } catch (Exception $e) {
            Log::error('DocumentProcessor: Erro ao obter metadados do Word', [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);
            return ['sections' => 0];
        }
    }

    /**
     * Formatar data do Word de forma segura
     */
    private function formatWordDate($dateValue): string
    {
        try {
            if (!$dateValue) {
                return '';
            }

            // Se for um objeto DateTime, formatar normalmente
            if ($dateValue instanceof \DateTime) {
                return $dateValue->format('Y-m-d H:i:s');
            }

            // Se for um timestamp (inteiro), converter para DateTime
            if (is_numeric($dateValue)) {
                $dateTime = new \DateTime();
                $dateTime->setTimestamp((int)$dateValue);
                return $dateTime->format('Y-m-d H:i:s');
            }

            // Se for uma string de data, tentar converter
            if (is_string($dateValue) && !empty(trim($dateValue))) {
                try {
                    $dateTime = new \DateTime(trim($dateValue));
                    return $dateTime->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    Log::warning('DocumentProcessor: Erro ao converter string de data', [
                        'date_string' => $dateValue,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return '';
        } catch (Exception $e) {
            Log::warning('DocumentProcessor: Erro ao formatar data do Word', [
                'date_value' => $dateValue,
                'type' => gettype($dateValue),
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Extrair metadados específicos do PDF
     */
    private function extractPdfSpecificMetadata(string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                Log::warning('DocumentProcessor: Arquivo PDF não encontrado para extração de metadados', ['file' => $filePath]);
                return [];
            }

            // Verificar se a biblioteca PdfParser está disponível
            if (!class_exists('\Smalot\PdfParser\Parser')) {
                Log::warning('DocumentProcessor: Biblioteca PdfParser não está disponível');
                return [];
            }

            $pdf = $this->pdfParser->parseFile($filePath);
            if (!$pdf) {
                Log::warning('DocumentProcessor: Falha ao fazer parse do PDF', ['file' => $filePath]);
                return [];
            }

            return $this->getPdfMetadata($pdf);
        } catch (Exception $e) {
            Log::error('DocumentProcessor: Erro ao extrair metadados específicos do PDF', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return [];
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
            Log::warning('DocumentProcessor: Erro ao formatar tamanho do arquivo', [
                'bytes' => $bytes,
                'error' => $e->getMessage()
            ]);
            return '0 B';
        }
    }
}
