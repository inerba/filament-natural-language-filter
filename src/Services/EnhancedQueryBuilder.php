<?php

namespace Inerba\FilamentNaturalLanguageFilter\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Inerba\FilamentNaturalLanguageFilter\Enums\FilterType;
use InvalidArgumentException;

/**
 * Enhanced Query Builder for Natural Language Filtering
 *
 * This service handles the construction of complex database queries from
 * natural language input, supporting relationships, boolean logic, and
 * aggregation operations.
 *
 * Features:
 * - Relationship filtering across related models
 * - Boolean logic (AND/OR operations)
 * - Aggregation queries (count, sum, average)
 * - Query optimization and validation
 */
class EnhancedQueryBuilder
{
    /**
     * The base query builder instance
     */
    protected Builder $query;

    /**
     * Available columns for filtering
     */
    protected array $availableColumns = [];

    /**
     * Available relationships for filtering
     */
    protected array $availableRelations = [];

    /**
     * Maximum depth for relationship traversal
     */
    protected int $maxRelationDepth = 2;

    /**
     * Maximum number of conditions in boolean logic
     */
    protected int $maxConditions = 10;

    /**
     * Constructor
     *
     * @param  Builder  $query  The base query builder
     * @param  array  $availableColumns  Available columns for filtering
     * @param  array  $availableRelations  Available relationships for filtering
     */
    public function __construct(Builder $query, array $availableColumns = [], array $availableRelations = [])
    {
        $this->query = $query;
        $this->availableColumns = $availableColumns;
        $this->availableRelations = $availableRelations;
        $this->maxRelationDepth = config('filament-natural-language-filter.features.relationship_filtering.max_depth', 2);
        $this->maxConditions = config('filament-natural-language-filter.features.boolean_logic.max_conditions', 10);
    }

    /**
     * Apply a single filter to the query
     *
     * @param  array  $filter  The filter configuration
     *
     * @throws InvalidArgumentException If filter is invalid
     */
    public function applyFilter(array $filter): void
    {
        $this->validateFilter($filter);

        $column = $filter['column'];
        $operator = $filter['operator'];
        $value = $filter['value'] ?? null;
        $relation = $filter['relation'] ?? null;

        // Handle relationship filtering
        if ($relation && $this->isRelationshipOperator($operator)) {
            $this->applyRelationshipFilter($relation, $column, $operator, $value);

            return;
        }

        // Handle aggregation operations
        if ($this->isAggregationOperator($operator)) {
            $this->applyAggregationFilter($column, $operator, $value);

            return;
        }

        // Handle boolean logic
        if ($this->isBooleanLogicOperator($operator)) {
            $this->applyBooleanLogicFilter($filter);

            return;
        }

        // Apply standard filter
        $this->applyStandardFilter($this->query, $column, $operator, $value);
    }

    /**
     * Apply relationship filter
     *
     * @param  string  $relation  The relationship name
     * @param  string  $column  The column to filter on
     * @param  string  $operator  The filter operator
     * @param  mixed  $value  The filter value
     */
    protected function applyRelationshipFilter(string $relation, string $column, string $operator, mixed $value): void
    {
        if (! config('filament-natural-language-filter.features.relationship_filtering.enabled', true)) {
            Log::warning('Relationship filtering is disabled');

            return;
        }

        // Validate relationship exists
        if (! $this->isValidRelationship($relation)) {
            Log::warning("Invalid relationship: {$relation}");

            return;
        }

        switch ($operator) {
            case FilterType::HAS_RELATION->value:
                $this->query->whereHas($relation, function (Builder $query) use ($column, $value) {
                    $this->applyStandardFilter($query, $column, 'equals', $value);
                });
                break;

            case FilterType::DOESNT_HAVE_RELATION->value:
                $this->query->whereDoesntHave($relation, function (Builder $query) use ($column, $value) {
                    $this->applyStandardFilter($query, $column, 'equals', $value);
                });
                break;

            case FilterType::RELATION_COUNT->value:
                $this->query->withCount($relation);
                if ($value !== null) {
                    $this->query->having("{$relation}_count", '>=', $value);
                }
                break;

            case FilterType::RELATION_SUM->value:
                $this->query->withSum($relation, $column);
                if ($value !== null) {
                    $this->query->having("{$relation}_{$column}_sum", '>=', $value);
                }
                break;

            case FilterType::RELATION_AVERAGE->value:
                $this->query->withAvg($relation, $column);
                if ($value !== null) {
                    $this->query->having("{$relation}_{$column}_avg", '>=', $value);
                }
                break;
        }
    }

    /**
     * Apply aggregation filter
     *
     * @param  string  $column  The column to aggregate
     * @param  string  $operator  The aggregation operator
     * @param  mixed  $value  The filter value
     */
    protected function applyAggregationFilter(string $column, string $operator, mixed $value): void
    {
        if (! config('filament-natural-language-filter.features.aggregation_queries.enabled', true)) {
            Log::warning('Aggregation queries are disabled');

            return;
        }

        // Validate column exists
        if (! $this->isValidColumn($column)) {
            Log::warning("Invalid column for aggregation: {$column}");

            return;
        }

        switch ($operator) {
            case FilterType::COUNT->value:
                $this->query->selectRaw("COUNT({$column}) as {$column}_count");
                if ($value !== null) {
                    $this->query->having("{$column}_count", '>=', $value);
                }
                break;

            case FilterType::SUM->value:
                $this->query->selectRaw("SUM({$column}) as {$column}_sum");
                if ($value !== null) {
                    $this->query->having("{$column}_sum", '>=', $value);
                }
                break;

            case FilterType::AVERAGE->value:
                $this->query->selectRaw("AVG({$column}) as {$column}_avg");
                if ($value !== null) {
                    $this->query->having("{$column}_avg", '>=', $value);
                }
                break;

            case FilterType::MIN->value:
                $this->query->selectRaw("MIN({$column}) as {$column}_min");
                if ($value !== null) {
                    $this->query->having("{$column}_min", '>=', $value);
                }
                break;

            case FilterType::MAX->value:
                $this->query->selectRaw("MAX({$column}) as {$column}_max");
                if ($value !== null) {
                    $this->query->having("{$column}_max", '>=', $value);
                }
                break;
        }
    }

    /**
     * Apply boolean logic filter
     *
     * @param  array  $filter  The filter configuration with boolean logic
     */
    protected function applyBooleanLogicFilter(array $filter): void
    {
        if (! config('filament-natural-language-filter.features.boolean_logic.enabled', true)) {
            Log::warning('Boolean logic is disabled');

            return;
        }

        $operator = $filter['operator'];
        $conditions = $filter['conditions'] ?? [];

        if (count($conditions) > $this->maxConditions) {
            Log::warning('Too many conditions: '.count($conditions)." (max: {$this->maxConditions})");

            return;
        }

        switch ($operator) {
            case FilterType::AND_OPERATOR->value:
                $this->query->where(function (Builder $query) use ($conditions) {
                    foreach ($conditions as $condition) {
                        $this->applyFilter($condition);
                    }
                });
                break;

            case FilterType::OR_OPERATOR->value:
                $this->query->where(function (Builder $query) use ($conditions) {
                    foreach ($conditions as $condition) {
                        $this->query->orWhere(function (Builder $subQuery) use ($condition) {
                            $this->applyFilter($condition);
                        });
                    }
                });
                break;

            case FilterType::NOT_OPERATOR->value:
                $this->query->where(function (Builder $query) use ($conditions) {
                    foreach ($conditions as $condition) {
                        $this->query->whereNot(function (Builder $subQuery) use ($condition) {
                            $this->applyFilter($condition);
                        });
                    }
                });
                break;
        }
    }

    /**
     * Apply standard filter to query
     *
     * @param  Builder  $query  The query builder
     * @param  string  $column  The column to filter on
     * @param  string  $operator  The filter operator
     * @param  mixed  $value  The filter value
     */
    protected function applyStandardFilter(Builder $query, string $column, string $operator, mixed $value): void
    {
        // Validate column exists
        if (! $this->isValidColumn($column)) {
            Log::warning("Invalid column: {$column}");

            return;
        }

        switch ($operator) {
            case FilterType::EQUALS->value:
                $query->where($column, '=', $value);
                break;

            case FilterType::NOT_EQUALS->value:
                $query->where($column, '!=', $value);
                break;

            case FilterType::CONTAINS->value:
                $query->where($column, 'LIKE', "%{$value}%");
                break;

            case FilterType::STARTS_WITH->value:
                $query->where($column, 'LIKE', "{$value}%");
                break;

            case FilterType::ENDS_WITH->value:
                $query->where($column, 'LIKE', "%{$value}");
                break;

            case FilterType::GREATER_THAN->value:
                $query->where($column, '>', $value);
                break;

            case FilterType::LESS_THAN->value:
                $query->where($column, '<', $value);
                break;

            case FilterType::BETWEEN->value:
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($column, $value);
                }
                break;

            case FilterType::IN->value:
                if (is_array($value)) {
                    $query->whereIn($column, $value);
                }
                break;

            case FilterType::NOT_IN->value:
                if (is_array($value)) {
                    $query->whereNotIn($column, $value);
                }
                break;

            case FilterType::IS_NULL->value:
                $query->whereNull($column);
                break;

            case FilterType::IS_NOT_NULL->value:
                $query->whereNotNull($column);
                break;

            case FilterType::DATE_EQUALS->value:
                $query->whereDate($column, '=', $value);
                break;

            case FilterType::DATE_BEFORE->value:
                $query->whereDate($column, '<', $value);
                break;

            case FilterType::DATE_AFTER->value:
                $query->whereDate($column, '>', $value);
                break;

            case FilterType::DATE_BETWEEN->value:
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($column, $value);
                }
                break;
        }
    }

    /**
     * Validate filter configuration
     *
     * @param  array  $filter  The filter to validate
     *
     * @throws InvalidArgumentException If filter is invalid
     */
    protected function validateFilter(array $filter): void
    {
        if (! isset($filter['operator'])) {
            throw new InvalidArgumentException('Filter must have an operator');
        }

        $operator = $filter['operator'];

        // Validate operator is supported
        if (! in_array($operator, array_merge(
            FilterType::getBasicTypes(),
            FilterType::getBooleanTypes(),
            FilterType::getAggregationTypes(),
            FilterType::getRelationshipTypes()
        ))) {
            throw new InvalidArgumentException("Unsupported operator: {$operator}");
        }

        // Validate required fields based on operator type
        if ($this->isRelationshipOperator($operator)) {
            if (! isset($filter['relation'])) {
                throw new InvalidArgumentException('Relationship filters must specify a relation');
            }
        }

        if ($this->isBooleanLogicOperator($operator)) {
            if (! isset($filter['conditions']) || ! is_array($filter['conditions'])) {
                throw new InvalidArgumentException('Boolean logic filters must specify conditions');
            }
        }
    }

    /**
     * Check if operator is for relationships
     *
     * @param  string  $operator  The operator to check
     */
    protected function isRelationshipOperator(string $operator): bool
    {
        return in_array($operator, FilterType::getRelationshipTypes());
    }

    /**
     * Check if operator is for aggregation
     *
     * @param  string  $operator  The operator to check
     */
    protected function isAggregationOperator(string $operator): bool
    {
        return in_array($operator, FilterType::getAggregationTypes());
    }

    /**
     * Check if operator is for boolean logic
     *
     * @param  string  $operator  The operator to check
     */
    protected function isBooleanLogicOperator(string $operator): bool
    {
        return in_array($operator, FilterType::getBooleanTypes());
    }

    /**
     * Check if column is valid for filtering
     *
     * @param  string  $column  The column to check
     */
    protected function isValidColumn(string $column): bool
    {
        return in_array($column, $this->availableColumns);
    }

    /**
     * Check if relationship is valid for filtering
     *
     * @param  string  $relation  The relationship to check
     */
    protected function isValidRelationship(string $relation): bool
    {
        return in_array($relation, $this->availableRelations);
    }

    /**
     * Get the query builder instance
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
