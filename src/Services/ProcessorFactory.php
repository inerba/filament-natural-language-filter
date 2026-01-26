<?php

namespace EdrisaTuray\FilamentNaturalLanguageFilter\Services;

use EdrisaTuray\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;

class ProcessorFactory
{
    public static function create(): NaturalLanguageProcessorInterface
    {
        $provider = config('filament-natural-language-filter.provider', 'openai');

        return self::createWithProvider($provider);
    }

    public static function createWithProvider(string $provider): NaturalLanguageProcessorInterface
    {
        switch ($provider) {
            case 'azure':
                return new AzureOpenAIProcessor;

            case 'ollama':
                return new OllamaProcessor;

            case 'lmstudio':
                return new LMStudioProcessor;

            case 'custom':
                return new CustomProcessor;

            case 'openai':
            default:
                return new NaturalLanguageProcessor;
        }
    }

    public static function getAvailableProviders(): array
    {
        return ['openai', 'azure', 'ollama', 'lmstudio', 'custom'];
    }

    public static function isProviderSupported(string $provider): bool
    {
        return in_array($provider, self::getAvailableProviders());
    }

    /**
     * Get provider configuration status
     *
     * @return array Array of provider names and their availability status
     */
    public static function getProviderStatus(): array
    {
        $providers = self::getAvailableProviders();
        $status = [];

        foreach ($providers as $provider) {
            try {
                $processor = self::createWithProvider($provider);
                $status[$provider] = [
                    'available' => $processor->canProcess('test query'),
                    'class' => get_class($processor),
                ];
            } catch (\Exception $e) {
                $status[$provider] = [
                    'available' => false,
                    'error' => $e->getMessage(),
                    'class' => null,
                ];
            }
        }

        return $status;
    }

    /**
     * Get the best available provider
     *
     * @return string|null The name of the best available provider or null if none available
     */
    public static function getBestAvailableProvider(): ?string
    {
        $status = self::getProviderStatus();

        // Priority order for providers
        $priority = ['azure', 'openai', 'ollama', 'lmstudio', 'custom'];

        foreach ($priority as $provider) {
            if (isset($status[$provider]) && $status[$provider]['available']) {
                return $provider;
            }
        }

        return null;
    }
}
