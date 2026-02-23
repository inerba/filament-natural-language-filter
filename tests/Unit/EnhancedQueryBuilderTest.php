<?php

namespace Inerba\FilamentNaturalLanguageFilter\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Inerba\FilamentNaturalLanguageFilter\Services\EnhancedQueryBuilder;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EnhancedQueryBuilder::class)]
class EnhancedQueryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filament-natural-language-filter.features.boolean_logic.enabled', true);
        Config::set('filament-natural-language-filter.features.boolean_logic.max_conditions', 10);
        Config::set('filament-natural-language-filter.features.relationship_filtering.enabled', true);
        Config::set('filament-natural-language-filter.features.relationship_filtering.max_depth', 2);
        Config::set('filament-natural-language-filter.features.aggregation_queries.enabled', true);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    private function makeQuery(array $columns = ['name', 'email']): Builder
    {
        // Create an anonymous model to get a real Builder instance
        $model = new class extends Model
        {
            protected $table = 'users';
        };

        return $model->newQuery();
    }

    public function test_or_boolean_filter_produces_or_where_clauses(): void
    {
        $query = $this->makeQuery();
        $builder = new EnhancedQueryBuilder($query, ['name', 'email']);

        $builder->applyFilter([
            'operator' => 'or',
            'conditions' => [
                ['column' => 'name', 'operator' => 'starts_with', 'value' => 'dr'],
                ['column' => 'name', 'operator' => 'starts_with', 'value' => 'ing'],
            ],
        ]);

        $sql = $builder->getQuery()->toSql();
        $bindings = $builder->getQuery()->getBindings();

        $this->assertStringContainsString('or', strtolower($sql));
        $this->assertStringContainsString('like', strtolower($sql));
        $this->assertContains('dr%', $bindings);
        $this->assertContains('ing%', $bindings);
    }

    public function test_separate_standard_filters_produce_and_clauses(): void
    {
        $query = $this->makeQuery();
        $builder = new EnhancedQueryBuilder($query, ['name', 'email']);

        $builder->applyFilter(['column' => 'name', 'operator' => 'starts_with', 'value' => 'dr']);
        $builder->applyFilter(['column' => 'name', 'operator' => 'starts_with', 'value' => 'ing']);

        $sql = $builder->getQuery()->toSql();
        $bindings = $builder->getQuery()->getBindings();

        // Two separate filters should be ANDed (no "or" in the SQL)
        $this->assertStringNotContainsString(' or ', strtolower($sql));
        $this->assertContains('dr%', $bindings);
        $this->assertContains('ing%', $bindings);
    }

    public function test_or_with_contains_operator(): void
    {
        $query = $this->makeQuery();
        $builder = new EnhancedQueryBuilder($query, ['name', 'email']);

        $builder->applyFilter([
            'operator' => 'or',
            'conditions' => [
                ['column' => 'email', 'operator' => 'contains', 'value' => 'gmail'],
                ['column' => 'email', 'operator' => 'contains', 'value' => 'yahoo'],
            ],
        ]);

        $sql = $builder->getQuery()->toSql();
        $bindings = $builder->getQuery()->getBindings();

        $this->assertStringContainsString('or', strtolower($sql));
        $this->assertContains('%gmail%', $bindings);
        $this->assertContains('%yahoo%', $bindings);
    }

    public function test_and_boolean_filter_produces_and_clauses(): void
    {
        $query = $this->makeQuery();
        $builder = new EnhancedQueryBuilder($query, ['name', 'email']);

        $builder->applyFilter([
            'operator' => 'and',
            'conditions' => [
                ['column' => 'name', 'operator' => 'contains', 'value' => 'mario'],
                ['column' => 'email', 'operator' => 'contains', 'value' => 'gmail'],
            ],
        ]);

        $sql = $builder->getQuery()->toSql();
        $bindings = $builder->getQuery()->getBindings();

        $this->assertStringNotContainsString(' or ', strtolower($sql));
        $this->assertContains('%mario%', $bindings);
        $this->assertContains('%gmail%', $bindings);
    }

    public function test_validate_filter_throws_for_aggregate_with_missing_relation(): void
    {
        $query = $this->makeQuery();
        $builder = new EnhancedQueryBuilder($query, ['name'], ['orders']);

        $this->expectException(\InvalidArgumentException::class);

        $builder->applyFilter([
            'aggregate' => 'count',
            // 'relation' intentionally missing
        ]);
    }

    public function test_validate_filter_throws_for_aggregate_with_invalid_aggregate(): void
    {
        $query = $this->makeQuery();
        $builder = new EnhancedQueryBuilder($query, ['name'], ['orders']);

        $this->expectException(\InvalidArgumentException::class);

        $builder->applyFilter([
            'relation'  => 'orders',
            'aggregate' => 'invalid_agg',
        ]);
    }

    public function test_validate_filter_throws_for_sum_aggregate_without_column(): void
    {
        $query = $this->makeQuery();
        $builder = new EnhancedQueryBuilder($query, ['name'], ['orders']);

        $this->expectException(\InvalidArgumentException::class);

        $builder->applyFilter([
            'relation'   => 'orders',
            'aggregate'  => 'sum',
            'column'     => null,
            'comparison' => '>=',
            'value'      => 100,
            'order'      => null,
        ]);
    }

    public function test_aggregate_filter_invalid_relation_is_silently_ignored(): void
    {
        $query = $this->makeQuery();
        // 'orders' is NOT in availableRelations → should be ignored, not throw
        $builder = new EnhancedQueryBuilder($query, ['name'], ['customers']);

        $sqlBefore = $builder->getQuery()->toSql();

        $builder->applyFilter([
            'relation'   => 'orders',
            'aggregate'  => 'count',
            'column'     => null,
            'comparison' => '>=',
            'value'      => 5,
            'order'      => null,
        ]);

        $this->assertSame($sqlBefore, $builder->getQuery()->toSql());
    }
}
