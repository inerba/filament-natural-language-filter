<?php

namespace Inerba\FilamentNaturalLanguageFilter\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Inerba\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use Inerba\FilamentNaturalLanguageFilter\FilamentNaturalLanguageFilterServiceProvider;
use Inerba\FilamentNaturalLanguageFilter\Services\NaturalLanguageProcessor;
use Mockery;
use Orchestra\Testbench\TestCase;

class NaturalLanguageFilterTest extends TestCase
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
        Config::set('filament-natural-language-filter.openai.api_key', 'test-key');
        Config::set('filament-natural-language-filter.validation.min_length', 3);
        Config::set('filament-natural-language-filter.validation.max_length', 500);
        Config::set('filament-natural-language-filter.supported_filters', [
            'equals', 'contains', 'greater_than', 'date_after',
        ]);
    }

    public function test_service_provider_registers_interface_binding(): void
    {
        $this->assertTrue($this->app->bound(NaturalLanguageProcessorInterface::class));
    }

    public function test_service_provider_resolves_to_natural_language_processor(): void
    {
        $processor = $this->app->make(NaturalLanguageProcessorInterface::class);
        $this->assertInstanceOf(NaturalLanguageProcessor::class, $processor);
    }

    public function test_processor_processes_query_with_mocked_responses_api(): void
    {
        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->andReturn((object) [
                'status' => 'completed',
                'outputText' => '{"filters":[{"column":"created_at","operator":"date_after","value":"2023-01-01"}]}',
                'error' => null,
            ]);

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = $this->app->make(NaturalLanguageProcessorInterface::class);
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('created_at', $result[0]['column']);
        $this->assertEquals('date_after', $result[0]['operator']);
        $this->assertEquals('2023-01-01', $result[0]['value']);
    }

    public function test_processor_returns_empty_array_on_incomplete_status(): void
    {
        $responsesMock = Mockery::mock();
        $responsesMock
            ->shouldReceive('create')
            ->once()
            ->andReturn((object) [
                'status' => 'failed',
                'outputText' => '',
                'error' => 'content_filter',
            ]);

        $openAiMock = Mockery::mock();
        $openAiMock->shouldReceive('responses')->once()->andReturn($responsesMock);

        $this->app->instance('openai', $openAiMock);

        $processor = $this->app->make(NaturalLanguageProcessorInterface::class);
        $result = $processor->processQuery('test query', ['name']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_processor_validation(): void
    {
        $processor = $this->app->make(NaturalLanguageProcessorInterface::class);

        $this->assertTrue($processor->canProcess('users created after 2023'));
        $this->assertTrue($processor->canProcess('find users with email containing gmail'));

        $this->assertFalse($processor->canProcess('ab')); // Too short
        $this->assertFalse($processor->canProcess('')); // Empty
        $this->assertFalse($processor->canProcess(str_repeat('a', 501))); // Too long
    }

    public function test_processor_supported_filter_types(): void
    {
        $processor = $this->app->make(NaturalLanguageProcessorInterface::class);
        $types = $processor->getSupportedFilterTypes();

        $this->assertIsArray($types);
        $this->assertContains('equals', $types);
        $this->assertContains('contains', $types);
        $this->assertContains('greater_than', $types);
        $this->assertContains('date_after', $types);
    }

    public function test_processor_locale_and_mappings(): void
    {
        $processor = $this->app->make(NaturalLanguageProcessorInterface::class);

        $processor->setLocale('it');
        $processor->setCustomColumnMappings(['nome' => 'name']);

        $this->assertSame(['nome' => 'name'], $processor->getCustomColumnMappings());
    }
}
