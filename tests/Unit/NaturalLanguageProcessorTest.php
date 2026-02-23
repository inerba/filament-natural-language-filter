<?php

namespace Inerba\FilamentNaturalLanguageFilter\Tests\Unit;

use Exception;
use Illuminate\Support\Facades\Config;
use Inerba\FilamentNaturalLanguageFilter\Services\NaturalLanguageProcessor;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(NaturalLanguageProcessor::class)]
class NaturalLanguageProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filament-natural-language-filter.cache.enabled', false);
        Config::set('filament-natural-language-filter.openai.api_key', 'test-key');
        Config::set('filament-natural-language-filter.model', 'gpt-4o-mini');
        Config::set('filament-natural-language-filter.supported_filters', [
            'equals',
            'contains',
            'greater_than',
            'date_after',
            'or',
            'and',
            'not',
            'has_relation',
            'doesnt_have_relation',
            'relation_count',
        ]);
    }

    public function test_it_calls_responses_api_with_structured_output_schema(): void
    {
        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $params): bool {
                return ($params['text']['format']['type'] ?? null) === 'json_schema'
                    && ($params['text']['format']['strict'] ?? null) === true
                    && ($params['text']['format']['name'] ?? null) === 'natural_language_filters'
                    && isset($params['instructions'])
                    && isset($params['input'])
                    && isset($params['max_output_tokens']);
            }))
            ->andReturn($this->makeResponsesApiResponse('{"filters":[{"column":"name","operator":"contains","value":"john"}]}'));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('users named john', ['name', 'email']);

        $this->assertCount(1, $result);
        $this->assertSame('name', $result[0]['column']);
        $this->assertSame('contains', $result[0]['operator']);
        $this->assertSame('john', $result[0]['value']);
    }

    public function test_it_returns_empty_array_when_status_is_not_completed(): void
    {
        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->andReturn($this->makeResponsesApiResponse('', 'incomplete'));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('unclear input xyz', ['name']);

        $this->assertSame([], $result);
    }

    public function test_it_returns_empty_array_on_api_exception(): void
    {
        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->andThrow(new Exception('API error'));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('test query', ['name']);

        $this->assertSame([], $result);
    }

    public function test_it_handles_relationship_filters(): void
    {
        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->andReturn($this->makeResponsesApiResponse('{"filters":[{"relation":"roles","column":"name","operator":"has_relation","value":"admin"}]}'));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('users with role admin', ['name', 'email'], ['roles']);

        $this->assertCount(1, $result);
        $this->assertSame('roles', $result[0]['relation']);
        $this->assertSame('has_relation', $result[0]['operator']);
    }

    public function test_it_handles_boolean_logic_filters(): void
    {
        $json = '{"filters":[{"operator":"or","conditions":[{"column":"name","operator":"contains","value":"john"},{"column":"name","operator":"contains","value":"jane"}]}]}';

        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->andReturn($this->makeResponsesApiResponse($json));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('name is john or jane', ['name']);

        $this->assertCount(1, $result);
        $this->assertSame('or', $result[0]['operator']);
        $this->assertCount(2, $result[0]['conditions']);
    }

    public function test_system_prompt_includes_or_detection_instructions(): void
    {
        $processor = new NaturalLanguageProcessor;

        $reflection = new \ReflectionMethod($processor, 'getSystemPrompt');
        $prompt = $reflection->invoke($processor);

        $this->assertStringContainsString('boolean_filter', $prompt);
        $this->assertStringContainsString('"or"', $prompt);
        $this->assertStringContainsString('CRITICAL', $prompt);
        $this->assertStringContainsString('"o"', $prompt);
    }

    public function test_supported_filters_include_boolean_operators(): void
    {
        $processor = new NaturalLanguageProcessor;
        $types = $processor->getSupportedFilterTypes();

        $this->assertContains('or', $types);
        $this->assertContains('and', $types);
        $this->assertContains('not', $types);
    }

    public function test_it_normalizes_like_operator_to_contains(): void
    {
        $json = '{"filters":[{"operator":"or","conditions":[{"column":"name","operator":"like","value":"mario"},{"column":"name","operator":"like","value":"luigi"}]}]}';

        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->andReturn($this->makeResponsesApiResponse($json));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('utenti che nel nome hanno mario o luigi', ['name']);

        $this->assertCount(1, $result);
        $this->assertSame('or', $result[0]['operator']);
        $this->assertCount(2, $result[0]['conditions']);
        $this->assertSame('contains', $result[0]['conditions'][0]['operator']);
        $this->assertSame('contains', $result[0]['conditions'][1]['operator']);
    }

    public function test_it_normalizes_common_operator_aliases(): void
    {
        $json = '{"filters":[{"column":"age","operator":">=","value":"18"},{"column":"name","operator":"like","value":"test"}]}';

        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->andReturn($this->makeResponsesApiResponse($json));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('users over 18 with name like test', ['age', 'name']);

        $this->assertCount(2, $result);
        $this->assertSame('greater_than', $result[0]['operator']);
        $this->assertSame('contains', $result[1]['operator']);
    }

    public function test_it_handles_singular_filter_key(): void
    {
        $json = '{"filter":{"operator":"or","conditions":[{"column":"name","operator":"contains","value":"mario"},{"column":"name","operator":"contains","value":"luigi"}]}}';

        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->andReturn($this->makeResponsesApiResponse($json));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('utenti mario o luigi', ['name']);

        $this->assertCount(1, $result);
        $this->assertSame('or', $result[0]['operator']);
        $this->assertCount(2, $result[0]['conditions']);
    }

    public function test_json_schema_includes_operator_enum(): void
    {
        $processor = new NaturalLanguageProcessor;

        // Reset static cache so our test gets a fresh schema
        $cacheProperty = new \ReflectionProperty($processor, 'cachedJsonSchema');
        $cacheProperty->setValue(null, null);

        $reflection = new \ReflectionMethod($processor, 'getJsonSchema');
        $schema = $reflection->invoke($processor);

        $standardFilter = $schema['schema']['$defs']['standard_filter'] ?? [];
        $operatorField = $standardFilter['properties']['operator'] ?? [];

        $this->assertArrayHasKey('enum', $operatorField);
        $this->assertContains('contains', $operatorField['enum']);
        $this->assertContains('equals', $operatorField['enum']);
    }

    public function test_json_schema_includes_aggregate_filter_definition(): void
    {
        $processor = new NaturalLanguageProcessor;

        $cacheProperty = new \ReflectionProperty($processor, 'cachedJsonSchema');
        $cacheProperty->setValue(null, null);

        $reflection = new \ReflectionMethod($processor, 'getJsonSchema');
        $schema = $reflection->invoke($processor);

        $this->assertArrayHasKey('aggregate_filter', $schema['schema']['$defs']);

        $aggregateDef = $schema['schema']['$defs']['aggregate_filter'];
        $this->assertArrayHasKey('aggregate', $aggregateDef['properties']);
        $this->assertContains('count', $aggregateDef['properties']['aggregate']['enum']);
        $this->assertContains('sum', $aggregateDef['properties']['aggregate']['enum']);

        // aggregate_filter must be included in filter_item.anyOf
        $refs = array_column($schema['schema']['$defs']['filter_item']['anyOf'], '$ref');
        $this->assertContains('#/$defs/aggregate_filter', $refs);
    }

    public function test_it_handles_aggregate_filter_count_threshold(): void
    {
        $json = '{"filters":[{"relation":"orders","aggregate":"count","column":null,"comparison":">=","value":5,"order":null}]}';

        $responsesMock = Mockery::mock();
        $responsesMock->shouldReceive('create')->once()->andReturn($this->makeResponsesApiResponse($json));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);
        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('records with more than 5 orders', ['name'], ['orders']);

        $this->assertCount(1, $result);
        $this->assertSame('orders', $result[0]['relation']);
        $this->assertSame('count', $result[0]['aggregate']);
        $this->assertSame('>=', $result[0]['comparison']);
        $this->assertSame(5, $result[0]['value']);
        $this->assertNull($result[0]['order']);
    }

    public function test_it_handles_aggregate_filter_sum_with_order(): void
    {
        $json = '{"filters":[{"relation":"workLogs","aggregate":"sum","column":"minutes","comparison":null,"value":null,"order":"desc"}]}';

        $responsesMock = Mockery::mock();
        $responsesMock->shouldReceive('create')->once()->andReturn($this->makeResponsesApiResponse($json));

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);
        $this->app->instance('openai', $openAiMock);

        $processor = new NaturalLanguageProcessor;
        $result = $processor->processQuery('top clients by hours', ['name'], ['workLogs']);

        $this->assertCount(1, $result);
        $this->assertSame('workLogs', $result[0]['relation']);
        $this->assertSame('sum', $result[0]['aggregate']);
        $this->assertSame('minutes', $result[0]['column']);
        $this->assertNull($result[0]['comparison']);
        $this->assertSame('desc', $result[0]['order']);
    }

    public function test_validate_filter_rejects_aggregate_with_missing_relation(): void
    {
        $processor = new NaturalLanguageProcessor;

        $reflection = new \ReflectionMethod($processor, 'validateFilter');

        $this->assertFalse($reflection->invoke($processor, [
            'aggregate' => 'count',
            // 'relation' intentionally missing
        ]));
    }

    public function test_validate_filter_rejects_aggregate_with_invalid_aggregate(): void
    {
        $processor = new NaturalLanguageProcessor;

        $reflection = new \ReflectionMethod($processor, 'validateFilter');

        $this->assertFalse($reflection->invoke($processor, [
            'relation'  => 'orders',
            'aggregate' => 'invalid_op',
        ]));
    }

    public function test_validate_filter_rejects_sum_aggregate_without_column(): void
    {
        $processor = new NaturalLanguageProcessor;

        $reflection = new \ReflectionMethod($processor, 'validateFilter');

        $this->assertFalse($reflection->invoke($processor, [
            'relation'  => 'orders',
            'aggregate' => 'sum',
            'column'    => null,
        ]));
    }

    public function test_system_prompt_includes_aggregation_instructions(): void
    {
        $processor = new NaturalLanguageProcessor;

        $reflection = new \ReflectionMethod($processor, 'getSystemPrompt');
        $prompt = $reflection->invoke($processor);

        $this->assertStringContainsString('aggregate_filter', $prompt);
        $this->assertStringContainsString('count', $prompt);
        $this->assertStringContainsString('sum', $prompt);
    }

    private function makeResponsesApiResponse(string $outputText, string $status = 'completed'): object
    {
        return (object) [
            'status' => $status,
            'outputText' => $outputText,
            'error' => null,
        ];
    }
}
