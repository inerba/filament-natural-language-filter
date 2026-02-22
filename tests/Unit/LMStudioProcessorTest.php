<?php

namespace Inerba\FilamentNaturalLanguageFilter\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inerba\FilamentNaturalLanguageFilter\Services\LMStudioProcessor;
use Mockery;
use Orchestra\Testbench\TestCase;

class LMStudioProcessorTest extends TestCase
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
        Config::set('filament-natural-language-filter.lmstudio', [
            'host' => 'http://localhost:1234',
            'model' => 'local-model',
            'api_key' => null,
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
        $processor = new LMStudioProcessor;

        $this->assertTrue($processor->canProcess('users created after 2023'));
        $this->assertTrue($processor->canProcess('find users with email containing gmail'));
    }

    public function test_cannot_process_short_query()
    {
        $processor = new LMStudioProcessor;

        $this->assertFalse($processor->canProcess('ab'));
        $this->assertFalse($processor->canProcess(''));
    }

    public function test_process_query_with_successful_response()
    {
        // Mock HTTP response
        Http::fake([
            'localhost:1234/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '[{"column": "email", "operator": "contains", "value": "gmail"}]',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $processor = new LMStudioProcessor;
        $result = $processor->processQuery('users with gmail email', ['name', 'email']);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('email', $result[0]['column']);
        $this->assertEquals('contains', $result[0]['operator']);
        $this->assertEquals('gmail', $result[0]['value']);
    }

    public function test_process_query_with_api_key()
    {
        // Set config with API key
        Config::set('filament-natural-language-filter.lmstudio', [
            'host' => 'http://localhost:1234',
            'model' => 'local-model',
            'api_key' => 'test-api-key',
            'temperature' => 0.1,
            'max_tokens' => 500,
            'timeout' => 30,
        ]);

        // Mock HTTP response
        Http::fake([
            'localhost:1234/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '[{"column": "name", "operator": "contains", "value": "john"}]',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $processor = new LMStudioProcessor;
        $result = $processor->processQuery('users named john', ['name', 'email']);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('name', $result[0]['column']);
    }

    public function test_process_query_with_invalid_response()
    {
        // Mock HTTP response with invalid structure
        Http::fake([
            'localhost:1234/v1/chat/completions' => Http::response([
                'invalid' => 'response',
            ], 200),
        ]);

        $processor = new LMStudioProcessor;
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_process_query_with_http_error()
    {
        // Mock HTTP error response
        Http::fake([
            'localhost:1234/v1/chat/completions' => Http::response([], 500),
        ]);

        $processor = new LMStudioProcessor;
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_supported_filter_types()
    {
        $processor = new LMStudioProcessor;
        $types = $processor->getSupportedFilterTypes();

        $this->assertIsArray($types);
        $this->assertContains('equals', $types);
        $this->assertContains('contains', $types);
    }

    public function test_set_locale()
    {
        $processor = new LMStudioProcessor;
        $processor->setLocale('fr');

        // Should not throw any exceptions
        $this->assertTrue(true);
    }
}
