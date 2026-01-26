<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    */
    'provider' => env('FILAMENT_NL_FILTER_PROVIDER', 'openai'), // 'openai', 'azure', 'ollama', 'lmstudio', 'custom'

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    */
    'model' => env('FILAMENT_NL_FILTER_MODEL', 'gpt-3.5-turbo'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Configuration
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'max_tokens' => env('FILAMENT_NL_FILTER_MAX_TOKENS', 500),
        'temperature' => env('FILAMENT_NL_FILTER_TEMPERATURE', 0.1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Azure OpenAI API Configuration
    |--------------------------------------------------------------------------
    */
    'azure' => [
        'api_key' => env('AZURE_OPENAI_API_KEY'),
        'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
        'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-02-15-preview'),
        'deployment_name' => env('AZURE_OPENAI_DEPLOYMENT_NAME'),
        'max_tokens' => env('FILAMENT_NL_FILTER_MAX_TOKENS', 500),
        'temperature' => env('FILAMENT_NL_FILTER_TEMPERATURE', 0.1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration
    |--------------------------------------------------------------------------
    */
    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'llama2'),
        'max_tokens' => env('FILAMENT_NL_FILTER_MAX_TOKENS', 500),
        'temperature' => env('FILAMENT_NL_FILTER_TEMPERATURE', 0.1),
        'timeout' => env('OLLAMA_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | LM Studio Configuration
    |--------------------------------------------------------------------------
    */
    'lmstudio' => [
        'host' => env('LMSTUDIO_HOST', 'http://localhost:1234'),
        'model' => env('LMSTUDIO_MODEL', 'local-model'),
        'api_key' => env('LMSTUDIO_API_KEY'), // Optional
        'max_tokens' => env('FILAMENT_NL_FILTER_MAX_TOKENS', 500),
        'temperature' => env('FILAMENT_NL_FILTER_TEMPERATURE', 0.1),
        'timeout' => env('LMSTUDIO_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom AI Provider Configuration
    |--------------------------------------------------------------------------
    */
    'custom' => [
        'endpoint' => env('CUSTOM_AI_ENDPOINT'),
        'model' => env('CUSTOM_AI_MODEL'),
        'api_key' => env('CUSTOM_AI_API_KEY'), // Optional
        'api_format' => env('CUSTOM_AI_FORMAT', 'openai'), // 'openai', 'anthropic', 'custom'
        'auth_header' => env('CUSTOM_AI_AUTH_HEADER', 'Authorization'),
        'auth_prefix' => env('CUSTOM_AI_AUTH_PREFIX', 'Bearer '),
        'request_format' => [], // Custom request format for 'custom' api_format
        'response_path' => env('CUSTOM_AI_RESPONSE_PATH', 'choices.0.message.content'),
        'max_tokens' => env('FILAMENT_NL_FILTER_MAX_TOKENS', 500),
        'temperature' => env('FILAMENT_NL_FILTER_TEMPERATURE', 0.1),
        'timeout' => env('CUSTOM_AI_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('FILAMENT_NL_FILTER_CACHE_ENABLED', true),
        'ttl' => env('FILAMENT_NL_FILTER_CACHE_TTL', 3600),
        'prefix' => 'filament_nl_filter',
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'min_length' => 3,
        'max_length' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Language Support
    |--------------------------------------------------------------------------
    */
    'languages' => [
        'universal_support' => env('FILAMENT_NL_FILTER_UNIVERSAL_SUPPORT', true),
        'auto_detect_direction' => env('FILAMENT_NL_FILTER_AUTO_DETECT_DIRECTION', true),
        'preserve_original_values' => env('FILAMENT_NL_FILTER_PRESERVE_ORIGINAL_VALUES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Filter Types
    |--------------------------------------------------------------------------
    */
    'supported_filters' => [
        'equals',
        'not_equals',
        'contains',
        'starts_with',
        'ends_with',
        'greater_than',
        'less_than',
        'between',
        'in',
        'not_in',
        'is_null',
        'is_not_null',
        'date_equals',
        'date_before',
        'date_after',
        'date_between',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('FILAMENT_NL_FILTER_LOGGING', true),
        'channel' => env('FILAMENT_NL_FILTER_LOG_CHANNEL', 'default'),
        'level' => env('FILAMENT_NL_FILTER_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced Features Configuration
    |--------------------------------------------------------------------------
    */
    'features' => [
        /*
        |--------------------------------------------------------------------------
        | Relationship Filtering
        |--------------------------------------------------------------------------
        | Enable filtering across related models (e.g., "users with orders over $100")
        */
        'relationship_filtering' => [
            'enabled' => env('FILAMENT_NL_FILTER_RELATIONSHIP_FILTERING', true),
            'max_depth' => env('FILAMENT_NL_FILTER_MAX_RELATION_DEPTH', 2),
            'allowed_relations' => env('FILAMENT_NL_FILTER_ALLOWED_RELATIONS', ''),
        ],

        /*
        |--------------------------------------------------------------------------
        | Boolean Logic Support
        |--------------------------------------------------------------------------
        | Enable AND/OR operations in queries (e.g., "users named john OR email contains gmail")
        */
        'boolean_logic' => [
            'enabled' => env('FILAMENT_NL_FILTER_BOOLEAN_LOGIC', true),
            'max_conditions' => env('FILAMENT_NL_FILTER_MAX_CONDITIONS', 10),
        ],

        /*
        |--------------------------------------------------------------------------
        | Aggregation Queries
        |--------------------------------------------------------------------------
        | Enable aggregation operations (count, sum, average, etc.)
        */
        'aggregation_queries' => [
            'enabled' => env('FILAMENT_NL_FILTER_AGGREGATION_QUERIES', true),
            'allowed_operations' => ['count', 'sum', 'avg', 'min', 'max'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Query Suggestions
        |--------------------------------------------------------------------------
        | Enable AI-powered query suggestions and autocomplete
        */
        'query_suggestions' => [
            'enabled' => env('FILAMENT_NL_FILTER_QUERY_SUGGESTIONS', true),
            'max_suggestions' => env('FILAMENT_NL_FILTER_MAX_SUGGESTIONS', 5),
            'cache_suggestions' => env('FILAMENT_NL_FILTER_CACHE_SUGGESTIONS', true),
        ],
    ],
];
