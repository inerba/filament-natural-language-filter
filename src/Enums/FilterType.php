<?php

namespace EdrisaTuray\FilamentNaturalLanguageFilter\Enums;

/**
 * Enumeration of supported filter types for natural language processing
 *
 * This enum defines all the filter operations that can be performed
 * on database columns, including basic comparisons, date operations,
 * aggregation functions, and relationship filtering.
 */
enum FilterType: string
{
    // Basic comparison operators
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case CONTAINS = 'contains';
    case STARTS_WITH = 'starts_with';
    case ENDS_WITH = 'ends_with';
    case GREATER_THAN = 'greater_than';
    case LESS_THAN = 'less_than';
    case BETWEEN = 'between';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case IS_NULL = 'is_null';
    case IS_NOT_NULL = 'is_not_null';

    // Date-specific operators
    case DATE_EQUALS = 'date_equals';
    case DATE_BEFORE = 'date_before';
    case DATE_AFTER = 'date_after';
    case DATE_BETWEEN = 'date_between';

    // Boolean logic operators
    case AND_OPERATOR = 'and';
    case OR_OPERATOR = 'or';
    case NOT_OPERATOR = 'not';

    // Aggregation operators
    case COUNT = 'count';
    case SUM = 'sum';
    case AVERAGE = 'avg';
    case MIN = 'min';
    case MAX = 'max';

    // Relationship operators
    case HAS_RELATION = 'has_relation';
    case DOESNT_HAVE_RELATION = 'doesnt_have_relation';
    case RELATION_COUNT = 'relation_count';
    case RELATION_SUM = 'relation_sum';
    case RELATION_AVERAGE = 'relation_avg';

    /**
     * Get all basic filter types (excluding boolean logic and aggregation)
     *
     * @return array<string>
     */
    public static function getBasicTypes(): array
    {
        return [
            self::EQUALS->value,
            self::NOT_EQUALS->value,
            self::CONTAINS->value,
            self::STARTS_WITH->value,
            self::ENDS_WITH->value,
            self::GREATER_THAN->value,
            self::LESS_THAN->value,
            self::BETWEEN->value,
            self::IN->value,
            self::NOT_IN->value,
            self::IS_NULL->value,
            self::IS_NOT_NULL->value,
            self::DATE_EQUALS->value,
            self::DATE_BEFORE->value,
            self::DATE_AFTER->value,
            self::DATE_BETWEEN->value,
        ];
    }

    /**
     * Get boolean logic operators
     *
     * @return array<string>
     */
    public static function getBooleanTypes(): array
    {
        return [
            self::AND_OPERATOR->value,
            self::OR_OPERATOR->value,
            self::NOT_OPERATOR->value,
        ];
    }

    /**
     * Get aggregation operators
     *
     * @return array<string>
     */
    public static function getAggregationTypes(): array
    {
        return [
            self::COUNT->value,
            self::SUM->value,
            self::AVERAGE->value,
            self::MIN->value,
            self::MAX->value,
        ];
    }

    /**
     * Get relationship operators
     *
     * @return array<string>
     */
    public static function getRelationshipTypes(): array
    {
        return [
            self::HAS_RELATION->value,
            self::DOESNT_HAVE_RELATION->value,
            self::RELATION_COUNT->value,
            self::RELATION_SUM->value,
            self::RELATION_AVERAGE->value,
        ];
    }

    /**
     * Check if this filter type requires a value
     */
    public function requiresValue(): bool
    {
        return ! in_array($this, [
            self::IS_NULL,
            self::IS_NOT_NULL,
            self::HAS_RELATION,
            self::DOESNT_HAVE_RELATION,
        ]);
    }

    /**
     * Check if this filter type supports multiple values
     */
    public function supportsMultipleValues(): bool
    {
        return in_array($this, [
            self::BETWEEN,
            self::IN,
            self::NOT_IN,
            self::DATE_BETWEEN,
        ]);
    }

    /**
     * Check if this is an aggregation operator
     */
    public function isAggregation(): bool
    {
        return in_array($this, self::getAggregationTypes());
    }

    /**
     * Check if this is a relationship operator
     */
    public function isRelationship(): bool
    {
        return in_array($this, self::getRelationshipTypes());
    }

    /**
     * Check if this is a boolean logic operator
     */
    public function isBooleanLogic(): bool
    {
        return in_array($this, self::getBooleanTypes());
    }
}
