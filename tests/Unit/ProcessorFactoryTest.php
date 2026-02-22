<?php

namespace Inerba\FilamentNaturalLanguageFilter\Tests\Unit;

use Inerba\FilamentNaturalLanguageFilter\Services\AzureOpenAIProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\CustomProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\LMStudioProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\NaturalLanguageProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\OllamaProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\ProcessorFactory;
use Mockery;
use Orchestra\Testbench\TestCase;

class ProcessorFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_available_providers()
    {
        $providers = ProcessorFactory::getAvailableProviders();

        $this->assertIsArray($providers);
        $this->assertContains('openai', $providers);
        $this->assertContains('azure', $providers);
        $this->assertContains('ollama', $providers);
        $this->assertContains('lmstudio', $providers);
        $this->assertContains('custom', $providers);
    }

    public function test_is_provider_supported()
    {
        $this->assertTrue(ProcessorFactory::isProviderSupported('openai'));
        $this->assertTrue(ProcessorFactory::isProviderSupported('azure'));
        $this->assertTrue(ProcessorFactory::isProviderSupported('ollama'));
        $this->assertTrue(ProcessorFactory::isProviderSupported('lmstudio'));
        $this->assertTrue(ProcessorFactory::isProviderSupported('custom'));
        $this->assertFalse(ProcessorFactory::isProviderSupported('invalid'));
    }

    public function test_create_with_openai_provider()
    {
        $processor = ProcessorFactory::createWithProvider('openai');
        $this->assertInstanceOf(NaturalLanguageProcessor::class, $processor);
    }

    public function test_create_with_azure_provider()
    {
        $processor = ProcessorFactory::createWithProvider('azure');
        $this->assertInstanceOf(AzureOpenAIProcessor::class, $processor);
    }

    public function test_create_with_ollama_provider()
    {
        $processor = ProcessorFactory::createWithProvider('ollama');
        $this->assertInstanceOf(OllamaProcessor::class, $processor);
    }

    public function test_create_with_lmstudio_provider()
    {
        $processor = ProcessorFactory::createWithProvider('lmstudio');
        $this->assertInstanceOf(LMStudioProcessor::class, $processor);
    }

    public function test_create_with_custom_provider()
    {
        $processor = ProcessorFactory::createWithProvider('custom');
        $this->assertInstanceOf(CustomProcessor::class, $processor);
    }

    public function test_create_with_invalid_provider_defaults_to_openai()
    {
        $processor = ProcessorFactory::createWithProvider('invalid');
        $this->assertInstanceOf(NaturalLanguageProcessor::class, $processor);
    }

    public function test_get_provider_status()
    {
        $status = ProcessorFactory::getProviderStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKey('openai', $status);
        $this->assertArrayHasKey('azure', $status);
        $this->assertArrayHasKey('ollama', $status);
        $this->assertArrayHasKey('lmstudio', $status);
        $this->assertArrayHasKey('custom', $status);

        foreach ($status as $provider => $info) {
            $this->assertArrayHasKey('available', $info);
            $this->assertArrayHasKey('class', $info);
            $this->assertIsBool($info['available']);
        }
    }

    public function test_get_best_available_provider()
    {
        $bestProvider = ProcessorFactory::getBestAvailableProvider();

        // Should return a string or null
        $this->assertTrue(is_string($bestProvider) || is_null($bestProvider));

        if ($bestProvider) {
            $this->assertTrue(ProcessorFactory::isProviderSupported($bestProvider));
        }
    }
}
