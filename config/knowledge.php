<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sistema Knowledge - Configurações de Performance e Otimizações
    |--------------------------------------------------------------------------
    |
    | Configurações para otimizar o sistema de memória e conhecimento
    | sem comprometer a funcionalidade ou causar lentidão adicional.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('KNOWLEDGE_CACHE_ENABLED', true),

        // TTL para diferentes tipos de cache (em segundos)
        'ttl' => [
            'episodes' => env('KNOWLEDGE_CACHE_EPISODES_TTL', 3600), // 1 hora
            'knowledge_base' => env('KNOWLEDGE_CACHE_KNOWLEDGE_TTL', 1800), // 30 min
            'context' => env('KNOWLEDGE_CACHE_CONTEXT_TTL', 900), // 15 min
        ],

        // Prefixo para chaves de cache
        'prefix' => env('KNOWLEDGE_CACHE_PREFIX', 'knowledge_memory_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Heavy Operations Control
    |--------------------------------------------------------------------------
    |
    | Controla quando executar operações pesadas (self-reflection, persona drift)
    | para melhorar performance sem perder funcionalidade.
    |
    */
    'heavy_operations' => [
        'enabled' => env('KNOWLEDGE_HEAVY_OPERATIONS_ENABLED', true),

        // Executa operações pesadas apenas para planos pagos
        'require_paid_plan' => env('KNOWLEDGE_HEAVY_OPS_PAID_ONLY', true),

        // Créditos mínimos necessários para operações pesadas
        'min_credits_required' => env('KNOWLEDGE_MIN_CREDITS_HEAVY_OPS', 1000),

        // Probabilidade de executar operações pesadas (0.0 a 1.0)
        'execution_probability' => env('KNOWLEDGE_HEAVY_OPS_PROBABILITY', 0.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-Reflection Settings
    |--------------------------------------------------------------------------
    */
    'self_reflection' => [
        'enabled' => env('KNOWLEDGE_SELF_REFLECTION_ENABLED', false), // Desabilitado por padrão para performance

        // Usa reflexão heurística (rápida) ao invés de AI quando possível
        'use_heuristic_fallback' => env('KNOWLEDGE_REFLECTION_HEURISTIC_FALLBACK', true),

        // Só executa reflexão para mensagens consideradas importantes
        'only_important_messages' => env('KNOWLEDGE_REFLECTION_IMPORTANT_ONLY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persona Drift Correction
    |--------------------------------------------------------------------------
    */
    'persona_drift' => [
        'enabled' => env('KNOWLEDGE_PERSONA_DRIFT_ENABLED', false), // Desabilitado por padrão

        // Intervalo de verificação (a cada X respostas)
        'check_interval' => env('KNOWLEDGE_PERSONA_DRIFT_INTERVAL', 10),

        // Threshold para considerar drift significativo
        'drift_threshold' => env('KNOWLEDGE_PERSONA_DRIFT_THRESHOLD', 0.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory Settings
    |--------------------------------------------------------------------------
    */
    'memory' => [
        // Limites de memória para evitar crescimento excessivo
        'max_episodes_per_chat' => env('KNOWLEDGE_MAX_EPISODES', 100),
        'max_knowledge_items' => env('KNOWLEDGE_MAX_KNOWLEDGE_ITEMS', 50),

        // Compressão automática
        'compression' => [
            'enabled' => env('KNOWLEDGE_COMPRESSION_ENABLED', true),
            'interval_messages' => env('KNOWLEDGE_COMPRESSION_INTERVAL', 50),
            'messages_to_compress' => env('KNOWLEDGE_COMPRESSION_BATCH_SIZE', 30),
        ],

        // Consolidação automática
        'consolidation' => [
            'enabled' => env('KNOWLEDGE_CONSOLIDATION_ENABLED', true),
            'interval_pairs' => env('KNOWLEDGE_CONSOLIDATION_INTERVAL', 15),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Search Settings
    |--------------------------------------------------------------------------
    */
    'semantic_search' => [
        'enabled' => env('KNOWLEDGE_SEMANTIC_SEARCH_ENABLED', true),

        // Thresholds para diferentes tipos de matching
        'thresholds' => [
            'phrase_match' => 1.0,    // Frases exatas têm peso máximo
            'intent_match' => 0.7,    // Intenções relacionadas
            'concept_match' => 0.4,   // Conceitos relacionados
            'keyword_match' => 0.1,   // Palavras-chave
            'minimum_score' => 0.2,   // Score mínimo para considerar relevante
        ],

        // Pesos para diferentes fatores de similaridade
        'weights' => [
            'phrase' => 0.4,
            'intent' => 0.25,
            'concept' => 0.2,
            'keyword' => 0.15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability & Monitoring
    |--------------------------------------------------------------------------
    */
    'observability' => [
        'enabled' => env('KNOWLEDGE_OBSERVABILITY_ENABLED', true),

        // Logging detalhado (pode impactar performance)
        'detailed_logging' => env('KNOWLEDGE_DETAILED_LOGGING', false),

        // Métricas de performance
        'performance_metrics' => env('KNOWLEDGE_PERFORMANCE_METRICS', true),

        // Alertas para anomalias
        'anomaly_detection' => env('KNOWLEDGE_ANOMALY_DETECTION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multimodal Processing
    |--------------------------------------------------------------------------
    */
    'multimodal' => [
        'enabled' => env('KNOWLEDGE_MULTIMODAL_ENABLED', true),

        // Processamento assíncrono para arquivos grandes
        'async_processing' => [
            'enabled' => env('KNOWLEDGE_ASYNC_PROCESSING_ENABLED', true),

            // Limites para processamento assíncrono
            'audio_max_size_mb' => env('KNOWLEDGE_AUDIO_MAX_SIZE_MB', 10),
            'document_max_size_mb' => env('KNOWLEDGE_DOCUMENT_MAX_SIZE_MB', 5),
            'document_max_pages' => env('KNOWLEDGE_DOCUMENT_MAX_PAGES', 50),
        ],

        // Limites de conteúdo
        'content_limits' => [
            'audio_transcription_max_length' => env('KNOWLEDGE_AUDIO_TRANSCRIPTION_MAX', 10000),
            'document_text_max_length' => env('KNOWLEDGE_DOCUMENT_TEXT_MAX', 50000),
            'context_truncation_length' => env('KNOWLEDGE_CONTEXT_TRUNCATION', 3000),
        ],

        // Cache multimodal
        'cache' => [
            'enabled' => env('KNOWLEDGE_MULTIMODAL_CACHE_ENABLED', true),
            'content_ttl' => env('KNOWLEDGE_MULTIMODAL_CACHE_CONTENT_TTL', 7200), // 2 horas
            'metadata_ttl' => env('KNOWLEDGE_MULTIMODAL_CACHE_METADATA_TTL', 3600), // 1 hora
        ],

        // Integração com Knowledge System
        'knowledge_integration' => [
            'enrich_memory' => env('KNOWLEDGE_MULTIMODAL_ENRICH_MEMORY', true),
            'max_memory_items' => env('KNOWLEDGE_MULTIMODAL_MAX_MEMORY_ITEMS', 20),
            'include_in_context' => env('KNOWLEDGE_MULTIMODAL_INCLUDE_IN_CONTEXT', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Tuning
    |--------------------------------------------------------------------------
    */
    'performance' => [
        // Timeout para operações pesadas (segundos)
        'operation_timeout' => env('KNOWLEDGE_OPERATION_TIMEOUT', 30),

        // Limite de memória por operação (MB)
        'memory_limit' => env('KNOWLEDGE_MEMORY_LIMIT', 128),

        // Pool de conexões para cache
        'cache_connection_pool' => env('KNOWLEDGE_CACHE_POOL_SIZE', 10),
    ],
];
