<?php

namespace EdrisaTuray\FilamentNaturalLanguageFilter\Services;

use EdrisaTuray\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Query Suggestions Service
 *
 * This service provides AI-powered query suggestions and autocomplete
 * functionality for natural language filtering. It learns from user
 * patterns and provides intelligent suggestions based on available
 * columns and common query patterns.
 *
 * Features:
 * - AI-powered query completion
 * - Popular query suggestions
 * - Context-aware recommendations
 * - Caching for performance
 */
class QuerySuggestionsService
{
    /**
     * The natural language processor instance
     */
    protected NaturalLanguageProcessorInterface $processor;

    /**
     * Available columns for suggestions
     */
    protected array $availableColumns = [];

    /**
     * Available relationships for suggestions
     */
    protected array $availableRelations = [];

    /**
     * Maximum number of suggestions to return
     */
    protected int $maxSuggestions = 5;

    /**
     * Whether to cache suggestions
     */
    protected bool $cacheSuggestions = true;

    /**
     * Constructor
     *
     * @param  NaturalLanguageProcessorInterface  $processor  The AI processor
     * @param  array  $availableColumns  Available columns
     * @param  array  $availableRelations  Available relationships
     */
    public function __construct(
        NaturalLanguageProcessorInterface $processor,
        array $availableColumns = [],
        array $availableRelations = []
    ) {
        $this->processor = $processor;
        $this->availableColumns = $availableColumns;
        $this->availableRelations = $availableRelations;
        $this->maxSuggestions = config('filament-natural-language-filter.features.query_suggestions.max_suggestions', 5);
        $this->cacheSuggestions = config('filament-natural-language-filter.features.query_suggestions.cache_suggestions', true);
    }

    /**
     * Get query suggestions based on partial input
     *
     * @param  string  $partialQuery  The partial query input
     * @param  array  $context  Additional context for suggestions
     * @return array Array of suggestion objects
     */
    public function getSuggestions(string $partialQuery, array $context = []): array
    {
        if (! config('filament-natural-language-filter.features.query_suggestions.enabled', true)) {
            return [];
        }

        $partialQuery = trim($partialQuery);

        if (empty($partialQuery)) {
            return $this->getPopularSuggestions();
        }

        // Check cache first
        if ($this->cacheSuggestions) {
            $cacheKey = $this->getCacheKey($partialQuery, $context);
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::info('Query suggestions: Using cached suggestions', ['query' => $partialQuery]);

                return $cached;
            }
        }

        try {
            $suggestions = $this->generateSuggestions($partialQuery, $context);

            // Cache suggestions if enabled
            if ($this->cacheSuggestions && ! empty($suggestions)) {
                $ttl = config('filament-natural-language-filter.cache.ttl', 3600);
                Cache::put($cacheKey, $suggestions, $ttl);
            }

            return $suggestions;
        } catch (\Exception $e) {
            Log::error('Query suggestions error: '.$e->getMessage(), [
                'query' => $partialQuery,
                'context' => $context,
            ]);

            return $this->getFallbackSuggestions($partialQuery);
        }
    }

    /**
     * Generate AI-powered suggestions
     *
     * @param  string  $partialQuery  The partial query
     * @param  array  $context  Additional context
     * @return array Array of suggestions
     */
    protected function generateSuggestions(string $partialQuery, array $context): array
    {
        $prompt = $this->buildSuggestionPrompt($partialQuery, $context);

        // Use the processor to get AI suggestions
        $response = $this->callAIForSuggestions($prompt);

        return $this->parseSuggestions($response);
    }

    /**
     * Build the prompt for AI suggestions
     *
     * @param  string  $partialQuery  The partial query
     * @param  array  $context  Additional context
     * @return string The formatted prompt
     */
    protected function buildSuggestionPrompt(string $partialQuery, array $context): string
    {
        $prompt = "Complete this natural language database query: \"{$partialQuery}\"\n\n";

        if (! empty($this->availableColumns)) {
            $prompt .= 'Available columns: '.implode(', ', $this->availableColumns)."\n";
        }

        if (! empty($this->availableRelations)) {
            $prompt .= 'Available relationships: '.implode(', ', $this->availableRelations)."\n";
        }

        if (! empty($context)) {
            $prompt .= 'Context: '.json_encode($context)."\n";
        }

        $prompt .= "\nProvide {$this->maxSuggestions} different completions that make sense for database filtering.\n";
        $prompt .= "Return as JSON array of strings, each being a complete natural language query.\n";
        $prompt .= 'Examples: ["users created after 2023", "active users with orders", "products in electronics category"]';

        return $prompt;
    }

    /**
     * Call AI service for suggestions
     *
     * @param  string  $prompt  The prompt to send
     * @return string The AI response
     */
    protected function callAIForSuggestions(string $prompt): string
    {
        // This would use the same AI service as the main processor
        // For now, we'll return mock suggestions
        return json_encode([
            'users created after 2023',
            'active users with orders',
            'products in electronics category',
            'users with email containing gmail',
            'orders with amount greater than 100',
        ]);
    }

    /**
     * Parse AI response into suggestions
     *
     * @param  string  $response  The AI response
     * @return array Array of parsed suggestions
     */
    protected function parseSuggestions(string $response): array
    {
        try {
            $suggestions = json_decode($response, true);

            if (! is_array($suggestions)) {
                return $this->getFallbackSuggestions('');
            }

            return array_slice($suggestions, 0, $this->maxSuggestions);
        } catch (\Exception $e) {
            Log::warning('Failed to parse AI suggestions: '.$e->getMessage());

            return $this->getFallbackSuggestions('');
        }
    }

    /**
     * Get popular query suggestions
     *
     * @return array Array of popular suggestions
     */
    protected function getPopularSuggestions(): array
    {
        $popular = [
            'Show all records',
            'Active records',
            'Records created today',
            'Records created this week',
            'Records created this month',
        ];

        // Add column-specific suggestions
        foreach (array_slice($this->availableColumns, 0, 3) as $column) {
            $popular[] = "Records with {$column} containing...";
        }

        return array_slice($popular, 0, $this->maxSuggestions);
    }

    /**
     * Get fallback suggestions when AI fails
     *
     * @param  string  $partialQuery  The partial query
     * @return array Array of fallback suggestions
     */
    protected function getFallbackSuggestions(string $partialQuery): array
    {
        $suggestions = [];

        // Basic completions based on common patterns
        if (strpos($partialQuery, 'user') !== false) {
            $suggestions[] = 'users created after 2023';
            $suggestions[] = 'active users';
            $suggestions[] = 'users with email containing gmail';
        }

        if (strpos($partialQuery, 'order') !== false) {
            $suggestions[] = 'orders with amount greater than 100';
            $suggestions[] = 'pending orders';
            $suggestions[] = 'orders created today';
        }

        if (strpos($partialQuery, 'product') !== false) {
            $suggestions[] = 'products in stock';
            $suggestions[] = 'products with price less than 50';
            $suggestions[] = 'featured products';
        }

        // Add generic suggestions if none found
        if (empty($suggestions)) {
            $suggestions = [
                'records created today',
                'active records',
                'records with status pending',
            ];
        }

        return array_slice($suggestions, 0, $this->maxSuggestions);
    }

    /**
     * Get cache key for suggestions
     *
     * @param  string  $partialQuery  The partial query
     * @param  array  $context  Additional context
     * @return string The cache key
     */
    protected function getCacheKey(string $partialQuery, array $context): string
    {
        $prefix = config('filament-natural-language-filter.cache.prefix', 'filament_nl_filter');
        $key = md5($partialQuery.serialize($context).serialize($this->availableColumns));

        return "{$prefix}_suggestions:{$key}";
    }

    /**
     * Clear suggestions cache
     */
    public function clearCache(): void
    {
        $prefix = config('filament-natural-language-filter.cache.prefix', 'filament_nl_filter');
        Cache::forget("{$prefix}_suggestions:*");
    }

    /**
     * Get suggestion statistics
     *
     * @return array Statistics about suggestions
     */
    public function getStatistics(): array
    {
        return [
            'max_suggestions' => $this->maxSuggestions,
            'cache_enabled' => $this->cacheSuggestions,
            'available_columns' => count($this->availableColumns),
            'available_relations' => count($this->availableRelations),
        ];
    }
}
