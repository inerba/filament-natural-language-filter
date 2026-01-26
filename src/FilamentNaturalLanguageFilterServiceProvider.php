<?php

namespace EdrisaTuray\FilamentNaturalLanguageFilter;

use EdrisaTuray\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\AzureOpenAIProcessor;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\CustomProcessor;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\LMStudioProcessor;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\NaturalLanguageProcessor;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\OllamaProcessor;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\ProcessorFactory;
use Illuminate\Support\ServiceProvider;

class FilamentNaturalLanguageFilterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/filament-natural-language-filter.php',
            'filament-natural-language-filter'
        );

        $this->app->singleton(
            NaturalLanguageProcessorInterface::class,
            function ($app) {
                return ProcessorFactory::create();
            }
        );

        // Individual processor bindings for direct injection
        $this->app->singleton(AzureOpenAIProcessor::class);
        $this->app->singleton(OllamaProcessor::class);
        $this->app->singleton(LMStudioProcessor::class);
        $this->app->singleton(CustomProcessor::class);
        $this->app->singleton(NaturalLanguageProcessor::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filament-natural-language-filter.php' => config_path('filament-natural-language-filter.php'),
            ], 'filament-natural-language-filter-config');
        }
    }
}
