<?php

namespace EdrisaTuray\FilamentNaturalLanguageFilter\Tests\Feature;

use EdrisaTuray\FilamentNaturalLanguageFilter\FilamentNaturalLanguageFilterServiceProvider;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\CustomProcessor;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\LMStudioProcessor;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\OllamaProcessor;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\ProcessorFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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

        // Set up test configuration
        Config::set('filament-natural-language-filter.provider', 'ollama');
        Config::set('filament-natural-language-filter.cache.enabled', false);
        Config::set('filament-natural-language-filter.validation.min_length', 3);
        Config::set('filament-natural-language-filter.validation.max_length', 500);
        Config::set('filament-natural-language-filter.supported_filters', [
            'equals', 'contains', 'greater_than', 'date_after',
        ]);

        // Mock Ollama config
        Config::set('filament-natural-language-filter.ollama', [
            'host' => 'http://localhost:11434',
            'model' => 'llama2',
            'temperature' => 0.1,
            'max_tokens' => 500,
            'timeout' => 30,
        ]);
    }

    public function test_service_provider_registers_correctly()
    {
        $this->assertTrue($this->app->bound('EdrisaTuray\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface'));
    }

    public function test_processor_factory_creates_correct_processor()
    {
        $processor = ProcessorFactory::create();
        $this->assertInstanceOf(OllamaProcessor::class, $processor);
    }

    public function test_processor_factory_with_different_providers()
    {
        // Test Ollama
        $ollamaProcessor = ProcessorFactory::createWithProvider('ollama');
        $this->assertInstanceOf(OllamaProcessor::class, $ollamaProcessor);

        // Test LM Studio
        $lmstudioProcessor = ProcessorFactory::createWithProvider('lmstudio');
        $this->assertInstanceOf(LMStudioProcessor::class, $lmstudioProcessor);

        // Test Custom
        $customProcessor = ProcessorFactory::createWithProvider('custom');
        $this->assertInstanceOf(CustomProcessor::class, $customProcessor);
    }

    public function test_processor_can_process_query_with_mock_response()
    {
        // Mock HTTP response
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'response' => '[{"column": "created_at", "operator": "date_after", "value": "2023-01-01"}]',
            ], 200),
        ]);

        $processor = ProcessorFactory::create();
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('created_at', $result[0]['column']);
        $this->assertEquals('date_after', $result[0]['operator']);
        $this->assertEquals('2023-01-01', $result[0]['value']);
    }

    public function test_processor_handles_multiple_filters()
    {
        // Mock HTTP response with multiple filters
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'response' => '[{"column": "name", "operator": "contains", "value": "john"}, {"column": "email", "operator": "contains", "value": "gmail"}]',
            ], 200),
        ]);

        $processor = ProcessorFactory::create();
        $result = $processor->processQuery('users named john with gmail email', ['name', 'email']);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        $this->assertEquals('name', $result[0]['column']);
        $this->assertEquals('contains', $result[0]['operator']);
        $this->assertEquals('john', $result[0]['value']);

        $this->assertEquals('email', $result[1]['column']);
        $this->assertEquals('contains', $result[1]['operator']);
        $this->assertEquals('gmail', $result[1]['value']);
    }

    public function test_processor_handles_empty_response()
    {
        // Mock HTTP response with empty result
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'response' => '[]',
            ], 200),
        ]);

        $processor = ProcessorFactory::create();
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_processor_handles_invalid_json_response()
    {
        // Mock HTTP response with invalid JSON
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'response' => 'invalid json response',
            ], 200),
        ]);

        $processor = ProcessorFactory::create();
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_processor_handles_http_error()
    {
        // Mock HTTP error response
        Http::fake([
            'localhost:11434/api/generate' => Http::response([], 500),
        ]);

        $processor = ProcessorFactory::create();
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_processor_validation()
    {
        $processor = ProcessorFactory::create();

        // Valid queries
        $this->assertTrue($processor->canProcess('users created after 2023'));
        $this->assertTrue($processor->canProcess('find users with email containing gmail'));

        // Invalid queries
        $this->assertFalse($processor->canProcess('ab')); // Too short
        $this->assertFalse($processor->canProcess('')); // Empty
        $this->assertFalse($processor->canProcess(str_repeat('a', 501))); // Too long
    }

    public function test_processor_supported_filter_types()
    {
        $processor = ProcessorFactory::create();
        $types = $processor->getSupportedFilterTypes();

        $this->assertIsArray($types);
        $this->assertContains('equals', $types);
        $this->assertContains('contains', $types);
        $this->assertContains('greater_than', $types);
        $this->assertContains('date_after', $types);
    }

    public function test_processor_locale_support()
    {
        $processor = ProcessorFactory::create();

        // Should not throw exceptions when setting locale
        $processor->setLocale('es');
        $processor->setLocale('fr');
        $processor->setLocale('de');

        $this->assertTrue(true);
    }

    public function test_processor_custom_column_mappings()
    {
        $processor = ProcessorFactory::create();

        // Should not throw exceptions when setting mappings
        $processor->setCustomColumnMappings(['name' => 'full_name', 'email' => 'email_address']);

        $mappings = $processor->getCustomColumnMappings();
        $this->assertIsArray($mappings);
    }
}
