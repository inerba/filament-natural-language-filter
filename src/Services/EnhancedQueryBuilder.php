<?php

namespace Inerba\FilamentNaturalLanguageFilter\Services;

use Filament\Support\Contracts\HasLabel;
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
     * Map of column name → enum FQCN for automatic label-to-value resolution
     *
     * @var array<string, class-string<\BackedEnum>>
     */
    protected array $enumColumns = [];

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
    /**
     * @param  array<string, class-string<\BackedEnum>>  $enumColumns
     */
    public function __construct(Builder $query, array $availableColumns = [], array $availableRelations = [], array $enumColumns = [])
    {
        $this->query = $query;
        $this->availableColumns = $availableColumns;
        $this->availableRelations = $availableRelations;
        $this->enumColumns = $enumColumns;
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

        // Handle aggregate_filter (no 'operator' key — must be detected before $operator extraction)
        if (isset($filter['aggregate'], $filter['relation'])) {
            $this->applyRelationAggregateFilter($filter);

            return;
        }

        $operator = $filter['operator'];

        // Handle boolean logic first (these filters have no 'column' key)
        if ($this->isBooleanLogicOperator($operator)) {
            $this->applyBooleanLogicFilter($filter);

            return;
        }

        $column = $filter['column'];
        $value = $filter['value'] ?? null;
        $relation = $filter['relation'] ?? null;

        // Handle relationship filtering
        if ($relation && $this->isRelationshipOperator($operator)) {
            $this->applyRelationshipFilter($relation, $column, $operator, $value);

            return;
        }

        // Handle dot-notation columns (e.g. 'customer.surname') → whereHas chain
        if (str_contains($column, '.')) {
            $this->applyDotNotationFilter($column, $operator, $value);

            return;
        }

        // Handle aggregation operations
        if ($this->isAggregationOperator($operator)) {
            $this->applyAggregationFilter($column, $operator, $value);

            return;
        }

        // Apply standard filter
        $this->applyStandardFilter($this->query, $column, $operator, $value);
    }

    /**
     * Apply a filter for a dot-notation column (e.g. 'customer.surname').
     *
     * Converts the path into a whereHas chain so that every standard operator
     * (contains, starts_with, equals, …) works correctly across relations.
     * Eloquent natively supports dot-notation in whereHas for nested relations
     * (e.g. 'customer.address' → WHERE EXISTS on customers JOIN addresses).
     *
     * @param  string  $dotColumn  Full dot-notation column, e.g. 'customer.surname'
     * @param  string  $operator  Standard filter operator
     * @param  mixed  $value  Filter value
     */
    protected function applyDotNotationFilter(string $dotColumn, string $operator, mixed $value): void
    {
        // Security: the full dotted path must be explicitly whitelisted
        if (! in_array($dotColumn, $this->availableColumns)) {
            Log::warning("Invalid dot-notation column: {$dotColumn}");

            return;
        }

        $lastDot = strrpos($dotColumn, '.');
        $relation = substr($dotColumn, 0, $lastDot); // e.g. 'customer' or 'customer.address'
        $column = substr($dotColumn, $lastDot + 1);  // leaf column, e.g. 'surname'

        $this->query->whereHas($relation, function (Builder $subQuery) use ($column, $operator, $value): void {
            // Validation is skipped here because the full dotted path was already validated above
            $this->applyStandardFilter($subQuery, $column, $operator, $value, skipValidation: true);
        });
    }

    /**
     * Apply a relation-based aggregate filter (aggregate_filter schema type).
     *
     * Supports two modes (independently or combined):
     * - Threshold: filters records where the relation aggregate satisfies a comparison (e.g. COUNT >= 5)
     * - Sort: orders records by the relation aggregate (e.g. ORDER BY SUM(minutes) DESC)
     *
     * For count-only thresholds without ordering, `has()` is used instead of `withCount` + `having`
     * to ensure cross-database safety (works on SQLite, MySQL, PostgreSQL).
     *
     * @param  array{relation: string, aggregate: string, column: string|null, comparison: string|null, value: float|null, order: string|null}  $filter
     */
    protected function applyRelationAggregateFilter(array $filter): void
    {
        $relation   = $filter['relation'];
        $aggregate  = $filter['aggregate'];
        $column     = $filter['column'] ?? null;
        $comparison = $filter['comparison'] ?? null;
        $value      = $filter['value'] ?? null;
        $order      = $filter['order'] ?? null;

        if (! $this->isValidRelationship($relation)) {
            Log::warning("aggregate_filter: invalid relation '{$relation}'");

            return;
        }

        // Count-only threshold without ordering: use has() for cross-DB safety
        if ($aggregate === 'count' && $comparison !== null && $value !== null && $order === null) {
            $this->query->has($relation, $comparison, (int) $value);

            return;
        }

        // For all other cases, apply the aggregate selector + optional HAVING + optional ORDER
        $alias = $aggregate === 'count'
            ? "{$relation}_count"
            : "{$relation}_{$column}_{$aggregate}";

        match ($aggregate) {
            'count' => $this->query->withCount($relation),
            'sum'   => $this->query->withSum($relation, $column),
            'avg'   => $this->query->withAvg($relation, $column),
            'min'   => $this->query->withMin($relation, $column),
            'max'   => $this->query->withMax($relation, $column),
        };

        if ($comparison !== null && $value !== null) {
            $this->query->having($alias, $comparison, $value);
        }

        if ($order !== null) {
            $this->query->orderBy($alias, $order);
        }
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
                $this->query->where(function (Builder $subQuery) use ($conditions) {
                    foreach ($conditions as $condition) {
                        $subQuery->where(function (Builder $innerQuery) use ($condition) {
                            $builder = new self($innerQuery, $this->availableColumns, $this->availableRelations, $this->enumColumns);
                            $builder->applyFilter($condition);
                        });
                    }
                });
                break;

            case FilterType::OR_OPERATOR->value:
                $this->query->where(function (Builder $subQuery) use ($conditions) {
                    foreach ($conditions as $index => $condition) {
                        if ($index === 0) {
                            $builder = new self($subQuery, $this->availableColumns, $this->availableRelations, $this->enumColumns);
                            $builder->applyFilter($condition);
                        } else {
                            $subQuery->orWhere(function (Builder $innerQuery) use ($condition) {
                                $builder = new self($innerQuery, $this->availableColumns, $this->availableRelations, $this->enumColumns);
                                $builder->applyFilter($condition);
                            });
                        }
                    }
                });
                break;

            case FilterType::NOT_OPERATOR->value:
                $this->query->where(function (Builder $subQuery) use ($conditions) {
                    foreach ($conditions as $condition) {
                        $subQuery->whereNot(function (Builder $innerQuery) use ($condition) {
                            $builder = new self($innerQuery, $this->availableColumns, $this->availableRelations, $this->enumColumns);
                            $builder->applyFilter($condition);
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
    protected function applyStandardFilter(Builder $query, string $column, string $operator, mixed $value, bool $skipValidation = false): void
    {
        // Validate column exists (skip when called from applyDotNotationFilter, which validates the full dotted path)
        if (! $skipValidation && ! $this->isValidColumn($column)) {
            Log::warning("Invalid column: {$column}");

            return;
        }

        // Resolve enum labels to raw values automatically
        if (
            isset($this->enumColumns[$column])
            && is_string($value)
            && in_array($operator, ['equals', 'not_equals', 'contains', 'starts_with', 'ends_with', 'in', 'not_in'])
        ) {
            $resolved = $this->resolveEnumValue($this->enumColumns[$column], $value, $operator);

            if ($resolved !== null) {
                [$operator, $value] = $resolved;
            }
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
     * Resolve a human-readable text value to matching enum raw values.
     *
     * Finds all enum cases whose label contains / equals the given text (case-insensitive)
     * and rewrites the operator to `equals` (single match) or `in` (multiple matches).
     *
     * Returns [$newOperator, $newValue] or null if no match is found.
     *
     * @param  class-string<\BackedEnum>  $enumClass
     * @return array{0: string, 1: mixed}|null
     */
    protected function resolveEnumValue(string $enumClass, string $text, string $operator): ?array
    {
        if (! is_a($enumClass, \BackedEnum::class, true)) {
            return null;
        }

        $needle = mb_strtolower(trim($text), 'UTF-8');

        $matches = [];

        foreach ($enumClass::cases() as $case) {
            $label = $case instanceof HasLabel
                ? mb_strtolower((string) $case->getLabel(), 'UTF-8')
                : mb_strtolower($case->value, 'UTF-8');

            $matched = match (true) {
                in_array($operator, ['equals', 'not_equals']) => $label === $needle,
                $operator === 'starts_with'                   => str_starts_with($label, $needle),
                $operator === 'ends_with'                     => str_ends_with($label, $needle),
                default                                       => str_contains($label, $needle), // contains / in / not_in
            };

            if ($matched) {
                $matches[] = $case->value;
            }
        }

        if (empty($matches)) {
            return null;
        }

        if (count($matches) === 1) {
            return [
                in_array($operator, ['not_equals', 'not_in']) ? 'not_equals' : 'equals',
                $matches[0],
            ];
        }

        return [
            in_array($operator, ['not_equals', 'not_in']) ? 'not_in' : 'in',
            $matches,
        ];
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
        // aggregate_filter: identified by 'aggregate' key (no 'operator' key)
        if (isset($filter['aggregate'])) {
            $allowed = FilterType::getAggregationTypes();

            if (! isset($filter['relation']) || ! in_array($filter['aggregate'], $allowed)) {
                throw new InvalidArgumentException("aggregate_filter requires a valid 'relation' and 'aggregate' field");
            }

            if ($filter['aggregate'] !== 'count' && empty($filter['column'])) {
                throw new InvalidArgumentException("aggregate_filter with aggregate '{$filter['aggregate']}' requires a 'column'");
            }

            return;
        }

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
