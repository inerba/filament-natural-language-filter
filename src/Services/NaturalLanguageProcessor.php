<?php

namespace Inerba\FilamentNaturalLanguageFilter\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inerba\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

class NaturalLanguageProcessor implements NaturalLanguageProcessorInterface
{
    protected array $supportedFilterTypes = [
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
        'has_relation',
        'doesnt_have_relation',
        'relation_count',
        'or',
        'and',
        'not',
    ];

    protected bool $isOpenAiAvailable;

    protected string $locale;

    protected ?string $additionalSystemPrompt = null;

    protected array $customColumnMappings = [];

    public function __construct()
    {
        $this->isOpenAiAvailable = $this->checkOpenAiAvailability();
        $this->locale = app()->getLocale();
    }

    public function processQuery(string $query, array $availableColumns = [], array $availableRelations = []): array
    {
        if (! $this->isOpenAiAvailable) {
            Log::warning('OpenAI is not available, cannot process query: '.$query);

            return [];
        }

        $cacheKey = $this->getCacheKey($query, $availableColumns, $availableRelations);
        if (config('filament-natural-language-filter.cache.enabled', true)) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::info('Natural Language Filter: Using cached result for query: '.$query);

                return $cached;
            }
        }

        try {
            $prompt = $this->buildPrompt($query, $availableColumns, $availableRelations);

            $openAI = resolve('openai');

            $maxTokens = config('filament-natural-language-filter.openai.max_output_tokens', 500);

            $params = [
                'model' => config('filament-natural-language-filter.model', 'gpt-4o-mini'),
                'instructions' => $this->getSystemPrompt(),
                'input' => $prompt,
                'max_output_tokens' => $maxTokens,
                'text' => [
                    'format' => $this->getJsonSchema(),
                ],
            ];

            $temperature = config('filament-natural-language-filter.openai.temperature');
            if ($temperature !== null) {
                $params['temperature'] = $temperature;
            }

            $response = $openAI->responses()->create($params);

            if ($response->status !== 'completed') {
                Log::warning('OpenAI Responses API returned non-completed status', [
                    'query' => $query,
                    'status' => $response->status,
                    'error' => $response->error,
                ]);

                return [];
            }

            $content = trim((string) ($response->outputText ?? ''));

            if ($content === '') {
                Log::warning('OpenAI returned empty output', [
                    'query' => $query,
                    'status' => $response->status,
                ]);

                return [];
            }

            $result = $this->parseResponse($content);

            if (config('filament-natural-language-filter.cache.enabled', true) && ! empty($result)) {
                $ttl = config('filament-natural-language-filter.cache.ttl', 3600);
                Cache::put($cacheKey, $result, $ttl);
            }

            Log::info('Natural Language Filter: Successfully processed query', [
                'query' => $query,
                'result_count' => count($result),
            ]);

            return $result;
        } catch (Throwable $e) {
            Log::error('Natural Language Filter Error: '.$e->getMessage(), [
                'query' => $query,
                'available_columns' => $availableColumns,
            ]);

            return [];
        }
    }

    public function canProcess(string $query): bool
    {
        $query = trim($query);
        $minLength = config('filament-natural-language-filter.validation.min_length', 3);
        $maxLength = config('filament-natural-language-filter.validation.max_length', 500);

        $length = mb_strlen($query, 'UTF-8');

        return ! empty($query) && $length >= $minLength && $length <= $maxLength;
    }

    public function getSupportedFilterTypes(): array
    {
        return config('filament-natural-language-filter.supported_filters', $this->supportedFilterTypes);
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function setAdditionalSystemPrompt(string $text): void
    {
        $this->additionalSystemPrompt = $text;
    }

    public function setCustomColumnMappings(array $mappings): void
    {
        $this->customColumnMappings = $mappings;
    }

    public function getCustomColumnMappings(): array
    {
        return $this->customColumnMappings;
    }

    protected function checkOpenAiAvailability(): bool
    {
        try {
            $hasOpenAiClass = class_exists(OpenAI::class);
            $hasApiKey = ! empty(config('filament-natural-language-filter.openai.api_key')) || ! empty(config('openai.api_key'));
            $isBound = app()->bound('openai');

            $isAvailable = $hasOpenAiClass && $hasApiKey && $isBound;

            if (! $isAvailable) {
                Log::warning('OpenAI not available', [
                    'has_openai_class' => $hasOpenAiClass,
                    'has_api_key' => $hasApiKey,
                    'is_bound' => $isBound,
                ]);
            }

            return $isAvailable;
        } catch (Exception $e) {
            Log::warning('OpenAI availability check failed: '.$e->getMessage());

            return false;
        }
    }

    protected function getSystemPrompt(): string
    {
        $supportedOperators = implode(', ', $this->getSupportedFilterTypes());

        return "You are a database query assistant that converts natural language queries into structured filter arrays.

IMPORTANT RULES:
1. Return ONLY valid JSON matching the required schema
2. Each filter must have these keys: 'column', 'operator', 'value' (and 'relation' for relationship filters)
3. Use only these operators: {$supportedOperators}
4. For date operations, convert relative dates (yesterday, last week, etc.) to actual dates
5. Be flexible with column name matching (e.g., 'name' could match 'full_name', 'user_name', etc.)
6. Understand queries in ANY language and convert them appropriately
7. If the query is unclear or cannot be processed, return: {\"filters\": []}
8. Output MUST match this top-level shape: {\"filters\": [ ... ]}

RELATIONSHIP FILTERING:
- When a query references relationships (e.g., 'role', 'ruolo', 'category', 'orders'):
  * Use a relationship_filter with 'relation', 'column', 'operator', 'value' fields
  * Use 'has_relation' operator to filter by relationship attributes
  * The 'column' field should be the relationship's column (e.g., 'name')
  * Example: [{\"relation\": \"roles\", \"column\": \"name\", \"operator\": \"has_relation\", \"value\": \"admin\"}]

BOOLEAN LOGIC (CRITICAL):
- When query contains 'OR', 'o', 'ou', 'oder', '\xe6\x88\x96', '\xd8\xa3\xd9\x88' (or equivalent in any language):
  * Use a boolean_filter: {\"operator\": \"or\", \"conditions\": [array of filters]}
  * Each condition inside is a separate filter that will be OR'd together
  * Example: 'name contains ing OR name is nicola' - [{\"operator\": \"or\", \"conditions\": [{\"column\": \"name\", \"operator\": \"contains\", \"value\": \"ing\"}, {\"column\": \"name\", \"operator\": \"contains\", \"value\": \"nicola\"}]}]

- When query contains 'AND', 'e', 'et', 'und', '\xe5\x92\x8c', '\xd9\x88' (and equivalent):
  * Use a boolean_filter: {\"operator\": \"and\", \"conditions\": [array of filters]}
  * Example: 'name contains ing AND email ends with .com' - [{\"operator\": \"and\", \"conditions\": [{\"column\": \"name\", \"operator\": \"contains\", \"value\": \"ing\"}, {\"column\": \"email\", \"operator\": \"ends_with\", \"value\": \".com\"}]}]

- When query contains 'NOT', 'non', 'ne pas', 'nicht', '\xe4\xb8\x8d', '\xd9\x84\xd9\x8a\xd8\xb3':
  * Use a boolean_filter: {\"operator\": \"not\", \"conditions\": [array of filters]}
  * Example: 'NOT name is john' - [{\"operator\": \"not\", \"conditions\": [{\"column\": \"name\", \"operator\": \"equals\", \"value\": \"john\"}]}]

COMBINING OR WITH AND (CRITICAL):
- Top-level array items are ALWAYS combined with AND automatically.
- When a query has OR conditions alongside AND conditions, use SEPARATE top-level filters:
  * OR group -> one top-level boolean_filter with {\"operator\": \"or\", \"conditions\": [...]}
  * Each AND condition -> its own separate top-level standard_filter
- NEVER wrap everything in a flat AND filter when OR conditions are present.
- Example: 'name is antonio OR carlo OR maria AND role is guest' - [{\"operator\": \"or\", \"conditions\": [{\"column\": \"name\", \"operator\": \"contains\", \"value\": \"antonio\"}, {\"column\": \"name\", \"operator\": \"contains\", \"value\": \"carlo\"}, {\"column\": \"name\", \"operator\": \"contains\", \"value\": \"maria\"}]}, {\"relation\": \"roles\", \"column\": \"name\", \"operator\": \"has_relation\", \"value\": \"guest\"}]

EXAMPLES (Multiple Languages):
- English: 'users created after 2023' - [{\"column\": \"created_at\", \"operator\": \"date_after\", \"value\": \"2023-01-01\"}]
- English: 'users with role admin' - [{\"relation\": \"roles\", \"column\": \"name\", \"operator\": \"has_relation\", \"value\": \"admin\"}]
- Italian: 'utenti con ruolo editor' - [{\"relation\": \"roles\", \"column\": \"name\", \"operator\": \"has_relation\", \"value\": \"editor\"}]
- Italian: 'utenti che nel nome hanno ing o si chiamano nicola' - [{\"operator\": \"or\", \"conditions\": [{\"column\": \"name\", \"operator\": \"contains\", \"value\": \"ing\"}, {\"column\": \"name\", \"operator\": \"contains\", \"value\": \"nicola\"}]}]

LANGUAGE HANDLING:
- Automatically detect and understand the input language
- Map language-specific keywords to operators (contains, equals, between, etc.)
- Preserve original values (names, text) in their original language
- Handle mixed-language queries naturally
- CRITICAL: Recognize OR/AND keywords in all languages

Current locale: {$this->locale}".(($this->additionalSystemPrompt !== null) ? "\n\nADDITIONAL INSTRUCTIONS:\n{$this->additionalSystemPrompt}" : '');
    }

    protected function buildPrompt(string $query, array $availableColumns, array $availableRelations = []): string
    {
        $prompt = "Convert this natural language query to database filters: \"{$query}\"";

        if (! empty($availableColumns)) {
            $prompt .= "\n\nAvailable database columns: ".implode(', ', $availableColumns);
            $prompt .= "\nPlease use only these column names in your response.";
        }

        if (! empty($availableRelations)) {
            $prompt .= "\n\nAvailable relationships: ".implode(', ', $availableRelations);
            $prompt .= "\n\nWhen filtering by relationships (e.g., 'users with role admin', 'utenti con ruolo editor'):";
            $prompt .= "\n- Use 'relation' field to specify the relationship name (e.g., 'roles')";
            $prompt .= "\n- Use 'column' field to specify the relationship column (e.g., 'name')";
            $prompt .= "\n- Use 'operator' field with 'has_relation' to check relationship existence with a value";
            $prompt .= "\n- Example: [{\"relation\": \"roles\", \"column\": \"name\", \"operator\": \"has_relation\", \"value\": \"admin\"}]";
            $prompt .= "\n- For queries like 'role admin' or 'ruolo editor', map to the appropriate relationship (roles) automatically";
        }

        $prompt .= "\n\nNote: The query may be in any language. Please understand the intent and map keywords to the appropriate operators automatically.";
        $prompt .= "\n\nReturn an object with this exact top-level key: {\"filters\": [...]}";

        return $prompt;
    }

    /**
     * Build the strict JSON schema for structured output.
     *
     * Uses $defs + $ref for recursion so boolean_filter conditions can nest any filter type.
     *
     * @return array<string, mixed>
     */
    protected function getJsonSchema(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'natural_language_filters',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'filters' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/$defs/filter_item'],
                    ],
                ],
                'required' => ['filters'],
                'additionalProperties' => false,
                '$defs' => [
                    'filter_item' => [
                        'anyOf' => [
                            ['$ref' => '#/$defs/standard_filter'],
                            ['$ref' => '#/$defs/relationship_filter'],
                            ['$ref' => '#/$defs/boolean_filter'],
                        ],
                    ],
                    'standard_filter' => [
                        'type' => 'object',
                        'description' => 'A filter on a direct table column using an operator and value.',
                        'properties' => [
                            'column' => ['type' => 'string', 'description' => 'The database column name.'],
                            'operator' => ['type' => 'string', 'description' => 'The filter operator (e.g. equals, contains, date_after, between, in).'],
                            'value' => [
                                'description' => 'The filter value. Use an array of two elements for between/date_between, an array for in/not_in, null for is_null/is_not_null, otherwise a string or number.',
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'number'],
                                    ['type' => 'null'],
                                    ['type' => 'array', 'items' => ['anyOf' => [['type' => 'string'], ['type' => 'number']]]],
                                ],
                            ],
                        ],
                        'required' => ['column', 'operator', 'value'],
                        'additionalProperties' => false,
                    ],
                    'relationship_filter' => [
                        'type' => 'object',
                        'description' => 'A filter that traverses a relationship (e.g. whereHas).',
                        'properties' => [
                            'relation' => ['type' => 'string', 'description' => 'The Eloquent relationship name.'],
                            'column' => ['type' => 'string', 'description' => 'The column on the related model.'],
                            'operator' => ['type' => 'string', 'description' => 'Must be one of: has_relation, doesnt_have_relation, relation_count.'],
                            'value' => [
                                'description' => 'The value to match on the related model column.',
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'number'],
                                    ['type' => 'null'],
                                ],
                            ],
                        ],
                        'required' => ['relation', 'column', 'operator', 'value'],
                        'additionalProperties' => false,
                    ],
                    'boolean_filter' => [
                        'type' => 'object',
                        'description' => 'A logical grouping of filters using OR, AND, or NOT.',
                        'properties' => [
                            'operator' => ['type' => 'string', 'enum' => ['or', 'and', 'not'], 'description' => 'The boolean operator.'],
                            'conditions' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/$defs/filter_item'],
                                'description' => 'The nested filters to combine.',
                            ],
                        ],
                        'required' => ['operator', 'conditions'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    protected function parseResponse(string $response): array
    {
        try {
            $decoded = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Failed to parse structured output as JSON', [
                    'response' => $response,
                    'json_error' => json_last_error_msg(),
                ]);

                return [];
            }

            if (is_array($decoded) && array_key_exists('filters', $decoded) && is_array($decoded['filters'])) {
                $filters = $decoded['filters'];
            } elseif (is_array($decoded)) {
                $filters = $decoded;
            } else {
                $filters = [];
            }

            $validatedFilters = [];
            foreach ($filters as $filter) {
                if ($this->validateFilter($filter)) {
                    $validatedFilters[] = $filter;
                } else {
                    Log::warning('Invalid filter from AI response', ['filter' => $filter]);
                }
            }

            return $validatedFilters;
        } catch (Exception $e) {
            Log::error('Error parsing AI response: '.$e->getMessage(), [
                'response' => $response,
            ]);

            return [];
        }
    }

    protected function validateFilter(array $filter): bool
    {
        // Boolean logic filters require: operator, conditions
        if (isset($filter['operator'], $filter['conditions']) && ! isset($filter['column'])) {
            $booleanOperators = ['or', 'and', 'not'];
            if (! in_array($filter['operator'], $booleanOperators)) {
                return false;
            }

            if (! is_array($filter['conditions']) || empty($filter['conditions'])) {
                return false;
            }

            foreach ($filter['conditions'] as $condition) {
                if (! is_array($condition) || ! $this->validateFilter($condition)) {
                    return false;
                }
            }

            return true;
        }

        // Relationship filters require: relation, column, operator, value
        if (isset($filter['relation'])) {
            if (! isset($filter['column'], $filter['operator'], $filter['value'])) {
                return false;
            }

            $relationOperators = ['has_relation', 'doesnt_have_relation', 'relation_count'];
            if (! in_array($filter['operator'], $relationOperators)) {
                return false;
            }

            return true;
        }

        // Standard filters require: column, operator, value
        if (! isset($filter['column'], $filter['operator'], $filter['value'])) {
            return false;
        }

        if (! in_array($filter['operator'], $this->getSupportedFilterTypes())) {
            return false;
        }

        if (in_array($filter['operator'], ['between', 'date_between'])) {
            return is_array($filter['value']) && count($filter['value']) === 2;
        }

        if (in_array($filter['operator'], ['in', 'not_in'])) {
            return is_array($filter['value']);
        }

        return true;
    }

    protected function getCacheKey(string $query, array $availableColumns, array $availableRelations = []): string
    {
        $prefix = config('filament-natural-language-filter.cache.prefix', 'filament_nl_filter');
        $key = md5($query.serialize($availableColumns).serialize($availableRelations).$this->locale.(string) $this->additionalSystemPrompt);

        return "{$prefix}:{$key}";
    }
}
