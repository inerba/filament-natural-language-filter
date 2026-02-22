<?php

namespace Inerba\FilamentNaturalLanguageFilter\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Inerba\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use Inerba\FilamentNaturalLanguageFilter\FilamentNaturalLanguageFilterServiceProvider;
use Inerba\FilamentNaturalLanguageFilter\Filters\NaturalLanguageFilter;
use InvalidArgumentException;
use Mockery;
use Orchestra\Testbench\TestCase;

class NaturalLanguageFilterIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            FilamentNaturalLanguageFilterServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filament-natural-language-filter.cache.enabled', false);
        Config::set('filament-natural-language-filter.validation.min_length', 3);
        Config::set('filament-natural-language-filter.validation.max_length', 500);
        Config::set('filament-natural-language-filter.supported_filters', [
            'equals', 'contains', 'greater_than', 'date_after',
        ]);
    }

    /** @return Mockery\MockInterface&NaturalLanguageProcessorInterface */
    private function mockProcessor(): Mockery\MockInterface
    {
        $mock = Mockery::mock(NaturalLanguageProcessorInterface::class);
        $this->app->instance(NaturalLanguageProcessorInterface::class, $mock);

        return $mock;
    }

    public function test_natural_language_filter_creation()
    {
        $filter = NaturalLanguageFilter::make('test_filter');

        $this->assertInstanceOf(NaturalLanguageFilter::class, $filter);
    }

    public function test_natural_language_filter_with_available_columns()
    {
        $filter = NaturalLanguageFilter::make('test_filter')
            ->availableColumns(['name', 'email', 'created_at']);

        $this->assertEquals(['name', 'email', 'created_at'], $filter->getAvailableColumns());
    }

    public function test_natural_language_filter_with_available_relations()
    {
        $filter = NaturalLanguageFilter::make('test_filter')
            ->availableRelations(['orders', 'profile']);

        $this->assertEquals(['orders', 'profile'], $filter->getAvailableRelations());
    }

    public function test_natural_language_filter_with_column_mappings()
    {
        $mappings = ['name' => 'full_name', 'email' => 'email_address'];
        $filter = NaturalLanguageFilter::make('test_filter')
            ->columnMappings($mappings);

        $this->assertEquals($mappings, $filter->getCustomColumnMappings());
    }

    public function test_natural_language_filter_search_modes()
    {
        $filter = NaturalLanguageFilter::make('test_filter');

        // Test live search mode
        $filter->liveSearch();
        $this->assertEquals('live', $filter->getSearchMode());

        // Test submit search mode
        $filter->submitSearch();
        $this->assertEquals('submit', $filter->getSearchMode());

        // Test direct mode setting
        $filter->searchMode('live');
        $this->assertEquals('live', $filter->getSearchMode());
    }

    public function test_natural_language_filter_invalid_search_mode()
    {
        $this->expectException(InvalidArgumentException::class);

        $filter = NaturalLanguageFilter::make('test_filter');
        $filter->searchMode('invalid_mode');
    }

    public function test_natural_language_filter_is_active()
    {
        $filter = NaturalLanguageFilter::make('test_filter');

        // Should be active with valid query
        $this->assertTrue($filter->isActive(['query' => 'users created after 2023']));

        // Should not be active with short query
        $this->assertFalse($filter->isActive(['query' => 'ab']));

        // Should not be active with empty query
        $this->assertFalse($filter->isActive(['query' => '']));

        // Should not be active with no query
        $this->assertFalse($filter->isActive([]));
    }

    public function test_natural_language_filter_apply_with_mock_response()
    {
        $processor = $this->mockProcessor();
        $processor->shouldReceive('canProcess')->andReturn(true);
        $processor->shouldReceive('setLocale')->andReturn();
        $processor->shouldReceive('setCustomColumnMappings')->andReturn();
        $processor->shouldReceive('processQuery')
            ->once()
            ->andReturn([['column' => 'created_at', 'operator' => 'date_after', 'value' => '2023-01-01']]);

        $filter = NaturalLanguageFilter::make('test_filter')
            ->availableColumns(['name', 'email', 'created_at']);

        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereDate')->with('created_at', '>', '2023-01-01')->andReturnSelf();

        $result = $filter->apply($query, ['query' => 'users created after 2023']);

        $this->assertInstanceOf(Builder::class, $result);
    }

    public function test_natural_language_filter_logs_final_sql_query()
    {
        $processor = $this->mockProcessor();
        $processor->shouldReceive('canProcess')->andReturn(true);
        $processor->shouldReceive('setLocale')->andReturn();
        $processor->shouldReceive('setCustomColumnMappings')->andReturn();
        $processor->shouldReceive('processQuery')
            ->once()
            ->andReturn([['column' => 'created_at', 'operator' => 'date_after', 'value' => '2023-01-01']]);

        Log::spy();

        $filter = NaturalLanguageFilter::make('test_filter')
            ->availableColumns(['name', 'email', 'created_at']);

        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereDate')->with('created_at', '>', '2023-01-01')->andReturnSelf();
        $query->shouldReceive('toSql')->once()->andReturn('select * from users where created_at > ?');
        $query->shouldReceive('getBindings')->once()->andReturn(['2023-01-01']);

        $filter->apply($query, ['query' => 'users created after 2023']);

        Log::shouldHaveReceived('info')
            ->with('NaturalLanguageFilter - Final SQL Query', Mockery::on(function (array $context): bool {
                return ($context['user_query'] ?? null) === 'users created after 2023'
                    && ($context['sql'] ?? null) === 'select * from users where created_at > ?'
                    && ($context['bindings'] ?? null) === ['2023-01-01']
                    && ($context['filters_count'] ?? null) === 1;
            }))
            ->once();
    }

    public function test_natural_language_filter_apply_with_empty_query()
    {
        $filter = NaturalLanguageFilter::make('test_filter');

        // Mock query builder
        $query = Mockery::mock(Builder::class);

        $result = $filter->apply($query, ['query' => '']);

        $this->assertInstanceOf(Builder::class, $result);
    }

    public function test_natural_language_filter_apply_with_short_query()
    {
        $filter = NaturalLanguageFilter::make('test_filter');

        // Mock query builder
        $query = Mockery::mock(Builder::class);

        $result = $filter->apply($query, ['query' => 'ab']);

        $this->assertInstanceOf(Builder::class, $result);
    }

    public function test_natural_language_filter_apply_with_http_error()
    {
        $processor = $this->mockProcessor();
        $processor->shouldReceive('canProcess')->andReturn(true);
        $processor->shouldReceive('setLocale')->andReturn();
        $processor->shouldReceive('setCustomColumnMappings')->andReturn();
        $processor->shouldReceive('processQuery')->once()->andReturn([]);

        $filter = NaturalLanguageFilter::make('test_filter')
            ->availableColumns(['name', 'email', 'created_at']);

        $query = Mockery::mock(Builder::class);

        $result = $filter->apply($query, ['query' => 'users created after 2023']);

        $this->assertInstanceOf(Builder::class, $result);
    }

    public function test_natural_language_filter_apply_with_invalid_response()
    {
        $processor = $this->mockProcessor();
        $processor->shouldReceive('canProcess')->andReturn(true);
        $processor->shouldReceive('setLocale')->andReturn();
        $processor->shouldReceive('setCustomColumnMappings')->andReturn();
        $processor->shouldReceive('processQuery')->once()->andReturn([]);

        $filter = NaturalLanguageFilter::make('test_filter')
            ->availableColumns(['name', 'email', 'created_at']);

        $query = Mockery::mock(Builder::class);

        $result = $filter->apply($query, ['query' => 'users created after 2023']);

        $this->assertInstanceOf(Builder::class, $result);
    }

    public function test_natural_language_filter_query_suggestions()
    {
        $filter = NaturalLanguageFilter::make('test_filter')
            ->availableColumns(['name', 'email', 'created_at'])
            ->availableRelations(['orders']);

        $suggestions = $filter->getQuerySuggestions('users');

        $this->assertIsArray($suggestions);
    }

    public function test_natural_language_filter_apply_does_not_throw_on_empty_result()
    {
        $processor = $this->mockProcessor();
        $processor->shouldReceive('canProcess')->andReturn(true);
        $processor->shouldReceive('setLocale')->andReturn();
        $processor->shouldReceive('setCustomColumnMappings')->andReturn();
        $processor->shouldReceive('processQuery')->once()->andReturn([]);

        $filter = NaturalLanguageFilter::make('test_filter');

        $query = Mockery::mock(Builder::class);

        $result = $filter->apply($query, ['query' => 'users created after 2023']);

        $this->assertInstanceOf(Builder::class, $result);
    }
}
