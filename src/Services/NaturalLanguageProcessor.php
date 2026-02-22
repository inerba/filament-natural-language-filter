<?php

namespace Inerba\FilamentNaturalLanguageFilter\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inerba\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use OpenAI\Laravel\Facades\OpenAI;

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
    ];

    protected bool $isOpenAiAvailable;

    protected string $locale;

    public function __construct()
    {
        $this->isOpenAiAvailable = $this->checkOpenAiAvailability();
        $this->locale = app()->getLocale();
    }

    public function processQuery(string $query, array $availableColumns = []): array
    {
        if (! $this->isOpenAiAvailable) {
            Log::warning('OpenAI is not available, cannot process query: '.$query);

            return [];
        }

        $cacheKey = $this->getCacheKey($query, $availableColumns);
        if (config('filament-natural-language-filter.cache.enabled', true)) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::info('Natural Language Filter: Using cached result for query: '.$query);

                return $cached;
            }
        }

        try {
            $prompt = $this->buildPrompt($query, $availableColumns);

            $openAI = resolve('openai');
            $response = $openAI->chat()->create([
                'model' => config('filament-natural-language-filter.model', 'gpt-3.5-turbo'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => config('filament-natural-language-filter.openai.temperature', 0.1),
                'max_tokens' => config('filament-natural-language-filter.openai.max_tokens', 500),
            ]);

            $content = $response->choices[0]->message->content;
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
        } catch (Exception $e) {
            Log::error('Natural Language Filter Error: '.$e->getMessage(), [
                'query' => $query,
                'available_columns' => $availableColumns,
            ]);

            return [];
        }
    }

    public function canProcess(string $query): bool
    {
        if (! $this->isOpenAiAvailable) {
            return false;
        }

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

    public function setCustomColumnMappings(array $mappings): void
    {
        // Interface compliance - not used with AI processing
    }

    public function getCustomColumnMappings(): array
    {
        return [];
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
1. Return ONLY valid JSON array format
2. Each filter must have exactly these keys: 'column', 'operator', 'value'
3. Use only these operators: {$supportedOperators}
4. For date operations, convert relative dates (yesterday, last week, etc.) to actual dates
5. Be flexible with column name matching (e.g., 'name' could match 'full_name', 'user_name', etc.)
6. Understand queries in ANY language and convert them appropriately
7. If the query is unclear or cannot be processed, return an empty array: []

RESPONSE FORMAT:
[{\"column\": \"column_name\", \"operator\": \"operator_type\", \"value\": \"filter_value\"}]

EXAMPLES (Multiple Languages):
- English: 'users created after 2023' → [{\"column\": \"created_at\", \"operator\": \"date_after\", \"value\": \"2023-01-01\"}]
- Arabic: 'الاسم يحتوي على أحمد' → [{\"column\": \"name\", \"operator\": \"contains\", \"value\": \"أحمد\"}]
- Spanish: 'usuarios con nombre juan' → [{\"column\": \"name\", \"operator\": \"contains\", \"value\": \"juan\"}]
- French: 'nom contient marie' → [{\"column\": \"name\", \"operator\": \"contains\", \"value\": \"marie\"}]
- German: 'benutzer erstellt nach 2023' → [{\"column\": \"created_at\", \"operator\": \"date_after\", \"value\": \"2023-01-01\"}]
- Chinese: '姓名包含张三' → [{\"column\": \"name\", \"operator\": \"contains\", \"value\": \"张三\"}]

LANGUAGE HANDLING:
- Automatically detect and understand the input language
- Map language-specific keywords to operators (contains, equals, between, etc.)
- Preserve original values (names, text) in their original language
- Handle mixed-language queries naturally

Current locale: {$this->locale}";
    }

    protected function buildPrompt(string $query, array $availableColumns): string
    {
        $prompt = "Convert this natural language query to database filters: \"{$query}\"";

        if (! empty($availableColumns)) {
            $prompt .= "\n\nAvailable database columns: ".implode(', ', $availableColumns);
            $prompt .= "\nPlease use only these column names in your response.";
        }

        $prompt .= "\n\nNote: The query may be in any language. Please understand the intent and map keywords to the appropriate operators automatically.";
        $prompt .= "\n\nReturn only the JSON array, no additional text or explanation.";

        return $prompt;
    }

    protected function parseResponse(string $response): array
    {
        try {
            $response = trim($response);

            $response = preg_replace('/^```(?:json)?\s*/', '', $response);
            $response = preg_replace('/\s*```$/', '', $response);

            if (preg_match('/\[.*\]/s', $response, $matches)) {
                $response = $matches[0];
            }

            $filters = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Failed to parse AI response as JSON', [
                    'response' => $response,
                    'json_error' => json_last_error_msg(),
                ]);

                return [];
            }

            if (! is_array($filters)) {
                Log::warning('AI response is not an array', ['response' => $response]);

                return [];
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

    protected function getCacheKey(string $query, array $availableColumns): string
    {
        $prefix = config('filament-natural-language-filter.cache.prefix', 'filament_nl_filter');
        $key = md5($query.serialize($availableColumns).$this->locale);

        return "{$prefix}:{$key}";
    }
}
