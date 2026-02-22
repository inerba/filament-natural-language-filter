<?php

namespace Inerba\FilamentNaturalLanguageFilter;

use Illuminate\Support\ServiceProvider;
use Inerba\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use Inerba\FilamentNaturalLanguageFilter\Services\NaturalLanguageProcessor;

class FilamentNaturalLanguageFilterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/filament-natural-language-filter.php',
            'filament-natural-language-filter'
        );

        $this->app->singleton(NaturalLanguageProcessorInterface::class, NaturalLanguageProcessor::class);
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
