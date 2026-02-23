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

    protected ?string $lastProcessingError = null;

    protected ?string $lastRawResponse = null;

    /** @var object|null Cached OpenAI client instance */
    protected ?object $openAiClient = null;

    /** Cached system prompt string (invalidated when locale or additionalSystemPrompt changes) */
    protected ?string $cachedSystemPrompt = null;

    /** @var array<string,mixed>|null Cached JSON schema (immutable, built once per process) */
    protected static ?array $cachedJsonSchema = null;

    // Hot-path config values — read once in constructor to avoid repeated config() calls
    protected bool $cacheEnabled;

    protected int $cacheTtl;

    protected string $cachePrefix;

    protected string $model;

    protected int $maxOutputTokens;

    protected ?float $temperature;

    public function __construct()
    {
        $this->isOpenAiAvailable = $this->checkOpenAiAvailability();
        $this->locale = app()->getLocale();

        // Cache hot-path config values once
        $this->cacheEnabled = (bool) config('filament-natural-language-filter.cache.enabled', true);
        $this->cacheTtl = (int) config('filament-natural-language-filter.cache.ttl', 3600);
        $this->cachePrefix = (string) config('filament-natural-language-filter.cache.prefix', 'filament_nl_filter');
        $this->model = (string) config('filament-natural-language-filter.model', 'gpt-4o-mini');
        $this->maxOutputTokens = (int) config('filament-natural-language-filter.openai.max_output_tokens', 1024);
        $temperature = config('filament-natural-language-filter.openai.temperature');
        $this->temperature = $temperature !== null ? (float) $temperature : null;

        if ($this->isOpenAiAvailable) {
            try {
                $this->openAiClient = resolve('openai');
            } catch (Throwable) {
                $this->isOpenAiAvailable = false;
            }
        }
    }

    public function processQuery(string $query, array $availableColumns = [], array $availableRelations = []): array
    {
        if (! $this->isOpenAiAvailable) {
            Log::warning('OpenAI is not available, cannot process query: '.$query);

            return [];
        }

        $cacheKey = $this->getCacheKey($query, $availableColumns, $availableRelations);
        if ($this->cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::info('Natural Language Filter: Using cached result for query: '.$query);

                return $cached;
            }
        }

        $this->lastProcessingError = null;
        $this->lastRawResponse = null;

        try {
            $prompt = $this->buildPrompt($query, $availableColumns, $availableRelations);

            $params = [
                'model' => $this->model,
                'instructions' => $this->getSystemPrompt(),
                'input' => $prompt,
                'max_output_tokens' => $this->maxOutputTokens,
                'text' => [
                    'format' => $this->getJsonSchema(),
                ],
            ];

            if ($this->temperature !== null) {
                $params['temperature'] = $this->temperature;
            }

            $response = $this->openAiClient->responses()->create($params);

            if ($response->status !== 'completed') {
                // Check incomplete_details.reason (e.g. 'max_output_tokens') first
                $incompleteReason = null;
                if (isset($response->incompleteDetails) && is_object($response->incompleteDetails)) {
                    $incompleteReason = property_exists($response->incompleteDetails, 'reason')
                        ? $response->incompleteDetails->reason
                        : null;
                }

                $errorDetail = $incompleteReason
                    ?? (is_object($response->error ?? null)
                        ? (property_exists($response->error, 'message') ? $response->error->message : json_encode($response->error))
                        : (string) ($response->error ?? null));

                $humanReason = match ($errorDetail) {
                    'max_output_tokens' => 'La risposta è stata troncata: aumentare FILAMENT_NL_FILTER_MAX_TOKENS nel .env (attuale: '.config('filament-natural-language-filter.openai.max_output_tokens').' token).',
                    'content_filter' => 'La query è stata bloccata dai filtri di sicurezza di OpenAI.',
                    default => $errorDetail ?? $response->status,
                };

                $this->lastProcessingError = $humanReason;

                Log::warning('OpenAI Responses API returned non-completed status', [
                    'query' => $query,
                    'status' => $response->status,
                    'incomplete_reason' => $incompleteReason,
                    'error' => $response->error,
                    'human_reason' => $humanReason,
                ]);

                return [];
            }

            $content = trim((string) ($response->outputText ?? ''));
            $this->lastRawResponse = $content ?: null;

            if ($content === '') {
                $this->lastProcessingError = 'OpenAI ha restituito un output vuoto (status: '.$response->status.')';

                Log::warning('OpenAI returned empty output', [
                    'query' => $query,
                    'status' => $response->status,
                ]);

                return [];
            }

            $result = $this->parseResponse($content);

            if ($this->cacheEnabled && ! empty($result)) {
                Cache::put($cacheKey, $result, $this->cacheTtl);
            }

            Log::info('Natural Language Filter: Successfully processed query', [
                'query' => $query,
                'result_count' => count($result),
            ]);

            if (empty($result)) {
                $this->lastProcessingError = 'La risposta AI non conteneva filtri validi per la query fornita.';
            }

            return $result;
        } catch (Throwable $e) {
            $this->lastProcessingError = $e->getMessage();

            Log::error('Natural Language Filter Error: '.$e->getMessage(), [
                'query' => $query,
                'available_columns' => $availableColumns,
            ]);

            return [];
        }
    }

    public function getLastProcessingError(): ?string
    {
        return $this->lastProcessingError;
    }

    public function getLastRawResponse(): ?string
    {
        return $this->lastRawResponse;
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
        $this->cachedSystemPrompt = null;
    }

    public function setAdditionalSystemPrompt(string $text): void
    {
        $this->additionalSystemPrompt = $text;
        $this->cachedSystemPrompt = null;
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
        if ($this->cachedSystemPrompt !== null) {
            return $this->cachedSystemPrompt;
        }

        $operators = implode(', ', $this->getSupportedFilterTypes());

        $today = now()->toDateString();

        $prompt = <<<PROMPT
Convert natural language queries (any language) into structured database filters matching the provided JSON schema.

Operators: {$operators}

Rules:
- Multiple top-level filters are implicitly ANDed.
- Relative dates (today, yesterday, last year…) → ISO date (YYYY-MM-DD). Today is {$today}.
- CRITICAL — OR detection: When the user separates alternatives with "o" (Italian), "or" (English), "ou" (French/Portuguese), "oder" (German), "или" (Russian), you MUST emit a single boolean_filter with operator "or" wrapping the alternatives as conditions. NEVER emit separate top-level filters for alternatives joined by these words — that would AND them, which is the opposite of the user's intent.
- AND words ("e", "and", "et", "und") → separate top-level filters (implicit AND) or boolean_filter with operator "and".
- NOT words ("non", "not", "nicht") → boolean_filter with operator "not".
- Relationship queries ("with role admin", "con ruolo editor") → relationship_filter with has_relation operator.
- OR + AND in the same query: put the OR group as one top-level boolean_filter, each AND condition as separate top-level filters.
- If the query cannot be interpreted, return {"filters": []}.

Examples:
- "nomi che iniziano per dr o ing" → {"filters":[{"operator":"or","conditions":[{"column":"name","operator":"starts_with","value":"dr"},{"column":"name","operator":"starts_with","value":"ing"}]}]}
- "email contains gmail o yahoo" → {"filters":[{"operator":"or","conditions":[{"column":"email","operator":"contains","value":"gmail"},{"column":"email","operator":"contains","value":"yahoo"}]}]}
- "nome contiene mario e cognome contiene rossi" → {"filters":[{"column":"name","operator":"contains","value":"mario"},{"column":"surname","operator":"contains","value":"rossi"}]}
Locale: {$this->locale}
PROMPT;

        if ($this->additionalSystemPrompt !== null) {
            $prompt .= "\n\n".$this->additionalSystemPrompt;
        }

        $this->cachedSystemPrompt = $prompt;

        return $prompt;
    }

    protected function buildPrompt(string $query, array $availableColumns, array $availableRelations = []): string
    {
        $lines = ["Query: \"{$query}\""];

        if (! empty($availableColumns)) {
            $lines[] = 'Columns: '.implode(', ', $availableColumns);
        }

        if (! empty($availableRelations)) {
            $lines[] = 'Relations: '.implode(', ', $availableRelations);
        }

        return implode("\n", $lines);
    }

    /**
     * Build the strict JSON schema for structured output.
     *
     * Uses $defs + $ref for recursion so boolean_filter conditions can nest any filter type.
     * Cached as a static property: the schema is immutable across the entire request lifecycle.
     *
     * @return array<string, mixed>
     */
    protected function getJsonSchema(): array
    {
        if (static::$cachedJsonSchema !== null) {
            return static::$cachedJsonSchema;
        }

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
                            'operator' => [
                                'type' => 'string',
                                'description' => 'The filter operator.',
                                'enum' => ['equals', 'not_equals', 'contains', 'starts_with', 'ends_with', 'greater_than', 'less_than', 'between', 'in', 'not_in', 'is_null', 'is_not_null', 'date_equals', 'date_before', 'date_after', 'date_between'],
                            ],
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

        static::$cachedJsonSchema = $schema;

        return static::$cachedJsonSchema;
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
            } elseif (is_array($decoded) && array_key_exists('filter', $decoded) && is_array($decoded['filter'])) {
                $filters = [$decoded['filter']];
            } elseif (is_array($decoded)) {
                $filters = $decoded;
            } else {
                $filters = [];
            }

            $filters = array_map(fn (array $filter): array => $this->normalizeFilterOperators($filter), $filters);

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

    /**
     * Recursively normalize operator names in a filter to their canonical form.
     *
     * Maps common AI-returned aliases (e.g. "like", "!=", ">") to the supported
     * operator names (e.g. "contains", "not_equals", "greater_than").
     *
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    protected function normalizeFilterOperators(array $filter): array
    {
        static $operatorAliases = [
            'like' => 'contains',
            'ilike' => 'contains',
            'not_like' => 'not_equals',
            '=' => 'equals',
            '==' => 'equals',
            '!=' => 'not_equals',
            '<>' => 'not_equals',
            '>' => 'greater_than',
            '>=' => 'greater_than',
            '<' => 'less_than',
            '<=' => 'less_than',
            'gt' => 'greater_than',
            'gte' => 'greater_than',
            'lt' => 'less_than',
            'lte' => 'less_than',
            'is' => 'equals',
            'not' => 'not_equals',
            'null' => 'is_null',
            'not_null' => 'is_not_null',
            'notnull' => 'is_not_null',
        ];

        if (isset($filter['conditions']) && is_array($filter['conditions'])) {
            $filter['conditions'] = array_map(
                fn (array $c): array => $this->normalizeFilterOperators($c),
                $filter['conditions']
            );
        }

        if (isset($filter['operator']) && is_string($filter['operator'])) {
            $lower = strtolower(trim($filter['operator']));
            $filter['operator'] = $operatorAliases[$lower] ?? $lower;
        }

        return $filter;
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
        $key = md5($query.serialize($availableColumns).serialize($availableRelations).$this->locale.(string) $this->additionalSystemPrompt);

        return "{$this->cachePrefix}:{$key}";
    }
}
