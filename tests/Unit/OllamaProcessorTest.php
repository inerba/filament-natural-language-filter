<?php

namespace EdrisaTuray\FilamentNaturalLanguageFilter\Tests\Unit;

use EdrisaTuray\FilamentNaturalLanguageFilter\Services\OllamaProcessor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Orchestra\Testbench\TestCase;

class OllamaProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        Config::set('filament-natural-language-filter.ollama', [
            'host' => 'http://localhost:11434',
            'model' => 'llama2',
            'temperature' => 0.1,
            'max_tokens' => 500,
            'timeout' => 30,
        ]);

        Config::set('filament-natural-language-filter.cache.enabled', false);
        Config::set('filament-natural-language-filter.validation.min_length', 3);
        Config::set('filament-natural-language-filter.validation.max_length', 500);
        Config::set('filament-natural-language-filter.supported_filters', [
            'equals', 'contains', 'greater_than', 'date_after',
        ]);
    }

    public function test_can_process_valid_query()
    {
        $processor = new OllamaProcessor;

        $this->assertTrue($processor->canProcess('users created after 2023'));
        $this->assertTrue($processor->canProcess('find users with email containing gmail'));
    }

    public function test_cannot_process_short_query()
    {
        $processor = new OllamaProcessor;

        $this->assertFalse($processor->canProcess('ab'));
        $this->assertFalse($processor->canProcess(''));
    }

    public function test_cannot_process_long_query()
    {
        $processor = new OllamaProcessor;

        $longQuery = str_repeat('a', 501);
        $this->assertFalse($processor->canProcess($longQuery));
    }

    public function test_get_supported_filter_types()
    {
        $processor = new OllamaProcessor;
        $types = $processor->getSupportedFilterTypes();

        $this->assertIsArray($types);
        $this->assertContains('equals', $types);
        $this->assertContains('contains', $types);
    }

    public function test_set_locale()
    {
        $processor = new OllamaProcessor;
        $processor->setLocale('es');

        // Should not throw any exceptions
        $this->assertTrue(true);
    }

    public function test_set_custom_column_mappings()
    {
        $processor = new OllamaProcessor;
        $processor->setCustomColumnMappings(['name' => 'full_name']);

        // Should not throw any exceptions
        $this->assertTrue(true);
    }

    public function test_get_custom_column_mappings()
    {
        $processor = new OllamaProcessor;
        $mappings = $processor->getCustomColumnMappings();

        $this->assertIsArray($mappings);
        $this->assertEmpty($mappings);
    }

    public function test_process_query_with_successful_response()
    {
        // Mock HTTP response
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'response' => '[{"column": "created_at", "operator": "date_after", "value": "2023-01-01"}]',
            ], 200),
        ]);

        $processor = new OllamaProcessor;
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('created_at', $result[0]['column']);
        $this->assertEquals('date_after', $result[0]['operator']);
        $this->assertEquals('2023-01-01', $result[0]['value']);
    }

    public function test_process_query_with_invalid_json_response()
    {
        // Mock HTTP response with invalid JSON
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'response' => 'invalid json response',
            ], 200),
        ]);

        $processor = new OllamaProcessor;
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_process_query_with_http_error()
    {
        // Mock HTTP error response
        Http::fake([
            'localhost:11434/api/generate' => Http::response([], 500),
        ]);

        $processor = new OllamaProcessor;
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_process_query_with_empty_response()
    {
        // Mock HTTP response with empty result
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'response' => '[]',
            ], 200),
        ]);

        $processor = new OllamaProcessor;
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
