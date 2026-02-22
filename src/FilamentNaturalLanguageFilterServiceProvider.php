<?php

namespace Inerba\FilamentNaturalLanguageFilter;

use Illuminate\Support\ServiceProvider;
use Inerba\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use Inerba\FilamentNaturalLanguageFilter\Services\AzureOpenAIProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\CustomProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\LMStudioProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\NaturalLanguageProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\OllamaProcessor;
use Inerba\FilamentNaturalLanguageFilter\Services\ProcessorFactory;

class FilamentNaturalLanguageFilterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/filament-natural-language-filter.php',
            'filament-natural-language-filter'
        );

        $this->app->singleton(
            function ($app): NaturalLanguageProcessorInterface {
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
