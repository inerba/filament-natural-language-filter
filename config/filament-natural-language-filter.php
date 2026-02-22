<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    */
    'model' => env('FILAMENT_NL_FILTER_MODEL', 'gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Configuration
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'max_output_tokens' => env('FILAMENT_NL_FILTER_MAX_TOKENS', 500),
        'temperature' => env('FILAMENT_NL_FILTER_TEMPERATURE'), // null = use model default (required for o1/o3/gpt-5 series)
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
        'relationship_filtering' => [
            'enabled' => env('FILAMENT_NL_FILTER_RELATIONSHIP_FILTERING', true),
            'max_depth' => env('FILAMENT_NL_FILTER_MAX_RELATION_DEPTH', 2),
        ],

        'boolean_logic' => [
            'enabled' => env('FILAMENT_NL_FILTER_BOOLEAN_LOGIC', true),
            'max_conditions' => env('FILAMENT_NL_FILTER_MAX_CONDITIONS', 10),
        ],

        'aggregation_queries' => [
            'enabled' => env('FILAMENT_NL_FILTER_AGGREGATION_QUERIES', true),
            'allowed_operations' => ['count', 'sum', 'avg', 'min', 'max'],
        ],
    ],
];
