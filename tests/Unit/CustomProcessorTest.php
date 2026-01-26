<?php

namespace EdrisaTuray\FilamentNaturalLanguageFilter\Tests\Unit;

use EdrisaTuray\FilamentNaturalLanguageFilter\Services\CustomProcessor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Orchestra\Testbench\TestCase;

class CustomProcessorTest extends TestCase
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
        Config::set('filament-natural-language-filter.custom', [
            'endpoint' => 'https://api.example.com/v1/chat/completions',
            'model' => 'custom-model',
            'api_key' => 'test-api-key',
            'api_format' => 'openai',
            'auth_header' => 'Authorization',
            'auth_prefix' => 'Bearer ',
            'request_format' => [],
            'response_path' => 'choices.0.message.content',
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
        $processor = new CustomProcessor;

        $this->assertTrue($processor->canProcess('users created after 2023'));
        $this->assertTrue($processor->canProcess('find users with email containing gmail'));
    }

    public function test_cannot_process_short_query()
    {
        $processor = new CustomProcessor;

        $this->assertFalse($processor->canProcess('ab'));
        $this->assertFalse($processor->canProcess(''));
    }

    public function test_process_query_with_openai_format()
    {
        // Ensure config is set for this test
        Config::set('filament-natural-language-filter.custom', [
            'endpoint' => 'https://api.example.com/v1/chat/completions',
            'model' => 'custom-model',
            'api_key' => 'test-api-key',
            'api_format' => 'openai',
            'auth_header' => 'Authorization',
            'auth_prefix' => 'Bearer ',
            'request_format' => [],
            'response_path' => 'choices.0.message.content',
            'temperature' => 0.1,
            'max_tokens' => 500,
            'timeout' => 30,
        ]);

        // Mock HTTP response for OpenAI format
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '[{"column": "name", "operator": "contains", "value": "john"}]',
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Create processor after setting config
        $processor = new CustomProcessor;

        // Ensure processor is available
        $this->assertTrue($processor->canProcess('users named john'));

        $result = $processor->processQuery('users named john', ['name', 'email']);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('name', $result[0]['column']);
        $this->assertEquals('contains', $result[0]['operator']);
        $this->assertEquals('john', $result[0]['value']);
    }

    public function test_process_query_with_anthropic_format()
    {
        // Set config for Anthropic format
        Config::set('filament-natural-language-filter.custom', [
            'endpoint' => 'https://api.anthropic.com/v1/messages',
            'model' => 'claude-3-sonnet',
            'api_key' => 'test-api-key',
            'api_format' => 'anthropic',
            'auth_header' => 'x-api-key',
            'auth_prefix' => '',
            'request_format' => [],
            'response_path' => 'content.0.text',
            'temperature' => 0.1,
            'max_tokens' => 500,
            'timeout' => 30,
        ]);

        // Mock HTTP response for Anthropic format
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'text' => '[{"column": "email", "operator": "contains", "value": "gmail"}]',
                    ],
                ],
            ], 200),
        ]);

        $processor = new CustomProcessor;
        $result = $processor->processQuery('users with gmail email', ['name', 'email']);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('email', $result[0]['column']);
        $this->assertEquals('contains', $result[0]['operator']);
        $this->assertEquals('gmail', $result[0]['value']);
    }

    public function test_process_query_with_custom_format()
    {
        // Set config for custom format
        Config::set('filament-natural-language-filter.custom', [
            'endpoint' => 'https://api.custom.com/generate',
            'model' => 'custom-model',
            'api_key' => 'test-api-key',
            'api_format' => 'custom',
            'auth_header' => 'X-API-Key',
            'auth_prefix' => '',
            'request_format' => [
                'prompt' => '{{prompt}}',
                'max_length' => '{{max_tokens}}',
                'temperature' => '{{temperature}}',
            ],
            'response_path' => 'result.text',
            'temperature' => 0.1,
            'max_tokens' => 500,
            'timeout' => 30,
        ]);

        // Mock HTTP response for custom format
        Http::fake([
            'api.custom.com/generate' => Http::response([
                'result' => [
                    'text' => '[{"column": "status", "operator": "equals", "value": "active"}]',
                ],
            ], 200),
        ]);

        $processor = new CustomProcessor;
        $result = $processor->processQuery('active users', ['name', 'status']);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('status', $result[0]['column']);
        $this->assertEquals('equals', $result[0]['operator']);
        $this->assertEquals('active', $result[0]['value']);
    }

    public function test_process_query_without_api_key()
    {
        // Set config without API key
        Config::set('filament-natural-language-filter.custom', [
            'endpoint' => 'https://api.example.com/v1/chat/completions',
            'model' => 'custom-model',
            'api_key' => null,
            'api_format' => 'openai',
            'auth_header' => 'Authorization',
            'auth_prefix' => 'Bearer ',
            'request_format' => [],
            'response_path' => 'choices.0.message.content',
            'temperature' => 0.1,
            'max_tokens' => 500,
            'timeout' => 30,
        ]);

        // Mock HTTP response
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '[{"column": "name", "operator": "contains", "value": "test"}]',
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Create processor after setting config
        $processor = new CustomProcessor;

        // Ensure processor is available
        $this->assertTrue($processor->canProcess('users with test in name'));

        $result = $processor->processQuery('users with test in name', ['name', 'email']);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('name', $result[0]['column']);
        $this->assertEquals('contains', $result[0]['operator']);
        $this->assertEquals('test', $result[0]['value']);
    }

    public function test_process_query_with_http_error()
    {
        // Mock HTTP error response
        Http::fake([
            'api.example.com/v1/chat/completions' => Http::response([], 500),
        ]);

        $processor = new CustomProcessor;
        $result = $processor->processQuery('users created after 2023', ['name', 'created_at']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_supported_filter_types()
    {
        $processor = new CustomProcessor;
        $types = $processor->getSupportedFilterTypes();

        $this->assertIsArray($types);
        $this->assertContains('equals', $types);
        $this->assertContains('contains', $types);
    }

    public function test_set_locale()
    {
        $processor = new CustomProcessor;
        $processor->setLocale('de');

        // Should not throw any exceptions
        $this->assertTrue(true);
    }
}
