<?php

namespace EdrisaTuray\FilamentNaturalLanguageFilter\Examples;

use EdrisaTuray\FilamentNaturalLanguageFilter\Services\OllamaProcessor;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\ProcessorFactory;

/**
 * Example usage of different AI providers
 */
class ProviderExamples
{
    /**
     * Example: Using Ollama for local AI processing
     */
    public function ollamaExample()
    {
        // Configure Ollama in your .env:
        // FILAMENT_NL_FILTER_PROVIDER=ollama
        // OLLAMA_HOST=http://localhost:11434
        // OLLAMA_MODEL=llama2

        $processor = ProcessorFactory::createWithProvider('ollama');

        $query = 'show users created after 2023';
        $columns = ['name', 'email', 'created_at'];

        $filters = $processor->processQuery($query, $columns);

        // Result: [['column' => 'created_at', 'operator' => 'date_after', 'value' => '2023-01-01']]
        return $filters;
    }

    /**
     * Example: Using LM Studio for local AI processing
     */
    public function lmStudioExample()
    {
        // Configure LM Studio in your .env:
        // FILAMENT_NL_FILTER_PROVIDER=lmstudio
        // LMSTUDIO_HOST=http://localhost:1234
        // LMSTUDIO_MODEL=local-model

        $processor = ProcessorFactory::createWithProvider('lmstudio');

        $query = 'find users with email containing gmail';
        $columns = ['name', 'email', 'created_at'];

        $filters = $processor->processQuery($query, $columns);

        // Result: [['column' => 'email', 'operator' => 'contains', 'value' => 'gmail']]
        return $filters;
    }

    /**
     * Example: Using a custom OpenAI-compatible API
     */
    public function customProviderExample()
    {
        // Configure custom provider in your .env:
        // FILAMENT_NL_FILTER_PROVIDER=custom
        // CUSTOM_AI_ENDPOINT=https://your-api.com/v1/chat/completions
        // CUSTOM_AI_MODEL=your-model
        // CUSTOM_AI_API_KEY=your-key

        $processor = ProcessorFactory::createWithProvider('custom');

        $query = 'users with orders over $100';
        $columns = ['name', 'email', 'order_total'];

        $filters = $processor->processQuery($query, $columns);

        // Result: [['column' => 'order_total', 'operator' => 'greater_than', 'value' => '100']]
        return $filters;
    }

    /**
     * Example: Checking provider availability
     */
    public function checkProviderStatus()
    {
        $status = ProcessorFactory::getProviderStatus();

        foreach ($status as $provider => $info) {
            if ($info['available']) {
                echo "✅ {$provider} is available\n";
            } else {
                echo "❌ {$provider} is not available: {$info['error']}\n";
            }
        }

        // Get the best available provider
        $bestProvider = ProcessorFactory::getBestAvailableProvider();
        echo "Best available provider: {$bestProvider}\n";
    }

    /**
     * Example: Using dependency injection with specific processor
     */
    public function dependencyInjectionExample(OllamaProcessor $ollamaProcessor)
    {
        $query = 'show active users';
        $columns = ['name', 'email', 'status'];

        return $ollamaProcessor->processQuery($query, $columns);
    }

    /**
     * Example: Multi-language queries with Ollama
     */
    public function multiLanguageExample()
    {
        $processor = ProcessorFactory::createWithProvider('ollama');
        $columns = ['name', 'email', 'created_at'];

        $queries = [
            'English' => 'users created after 2023',
            'Spanish' => 'usuarios creados después de 2023',
            'French' => 'utilisateurs créés après 2023',
            'Arabic' => 'المستخدمون المنشأون بعد 2023',
        ];

        $results = [];
        foreach ($queries as $language => $query) {
            $results[$language] = $processor->processQuery($query, $columns);
        }

        return $results;
    }

    /**
     * Example: Filament table integration
     */
    public function filamentTableExample()
    {
        // In your Filament resource
        return [
            Tables\Filters\Filter::make('natural_language')
                ->form([
                    Forms\Components\TextInput::make('query')
                        ->label('Natural Language Search')
                        ->placeholder('e.g., users created after 2023')
                        ->live()
                        ->afterStateUpdated(function ($state, $set) {
                            if (strlen($state) >= 3) {
                                $processor = ProcessorFactory::create();
                                $filters = $processor->processQuery($state, ['name', 'email', 'created_at']);

                                // Apply filters to your table
                                $this->applyFilters($filters);
                            }
                        }),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    if (empty($data['query'])) {
                        return $query;
                    }

                    $processor = ProcessorFactory::create();
                    $filters = $processor->processQuery($data['query'], ['name', 'email', 'created_at']);

                    foreach ($filters as $filter) {
                        $this->applyFilter($query, $filter);
                    }

                    return $query;
                }),
        ];
    }

    /**
     * Example: Error handling and fallbacks
     */
    public function errorHandlingExample()
    {
        try {
            $processor = ProcessorFactory::createWithProvider('ollama');
            $filters = $processor->processQuery('test query', ['name']);

            if (empty($filters)) {
                // Fallback to another provider
                $fallbackProcessor = ProcessorFactory::createWithProvider('openai');
                $filters = $fallbackProcessor->processQuery('test query', ['name']);
            }

            return $filters;
        } catch (\Exception $e) {
            // Log error and return empty filters
            \Log::error('AI processing failed: '.$e->getMessage());

            return [];
        }
    }
}
