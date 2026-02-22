<?php

namespace Inerba\FilamentNaturalLanguageFilter\Filters;

use Exception;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Inerba\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use Inerba\FilamentNaturalLanguageFilter\Services\EnhancedQueryBuilder;
use Inerba\FilamentNaturalLanguageFilter\Services\QuerySuggestionsService;
use InvalidArgumentException;
use Throwable;

class NaturalLanguageFilter extends BaseFilter
{
    protected array $availableColumns = [];

    protected array $availableRelations = [];

    protected array $customColumnMappings = [];

    protected ?string $systemPromptAddition = null;

    protected ?NaturalLanguageProcessorInterface $processor = null;

    protected ?QuerySuggestionsService $suggestionsService = null;

    protected string $searchMode = 'submit';

    public static function make(?string $name = 'natural_language'): static
    {
        return parent::make($name);
    }

    /**
     * Set available columns for filtering
     *
     * @param  array  $columns  Array of column names that can be filtered
     */
    public function availableColumns(array $columns): static
    {
        $this->availableColumns = $columns;

        return $this;
    }

    /**
     * Set available relationships for filtering
     *
     * @param  array  $relations  Array of relationship names that can be filtered
     */
    public function availableRelations(array $relations): static
    {
        $this->availableRelations = $relations;

        return $this;
    }

    public function columnMappings(array $mappings): static
    {
        $this->customColumnMappings = $mappings;

        return $this;
    }

    /**
     * Append custom instructions to the AI system prompt
     *
     * @param  string  $text  Additional instructions to include in the system prompt
     */
    public function systemPromptAddition(string $text): static
    {
        $this->systemPromptAddition = $text;

        return $this;
    }

    public function searchMode(string $mode): static
    {
        if (! in_array($mode, ['live', 'submit'])) {
            throw new InvalidArgumentException('Search mode must be either "live" or "submit"');
        }

        $this->searchMode = $mode;

        return $this;
    }

    public function liveSearch(): static
    {
        return $this->searchMode('live');
    }

    public function submitSearch(): static
    {
        return $this->searchMode('submit');
    }

    public function getSearchMode(): string
    {
        return $this->searchMode;
    }

    public function getAvailableColumns(): array
    {
        return $this->availableColumns;
    }

    public function getCustomColumnMappings(): array
    {
        return $this->customColumnMappings;
    }

    /**
     * Get available relationships
     */
    public function getAvailableRelations(): array
    {
        return $this->availableRelations;
    }

    /**
     * Get query suggestions for the current input
     *
     * @param  string  $partialQuery  The partial query input
     * @return array Array of suggestion strings
     */
    public function getQuerySuggestions(string $partialQuery): array
    {
        if (! $this->suggestionsService) {
            $processor = $this->getProcessor();
            if (! $processor) {
                return [];
            }

            $this->suggestionsService = new QuerySuggestionsService(
                $processor,
                $this->getAvailableColumns(),
                $this->getAvailableRelations()
            );
        }

        return $this->suggestionsService->getSuggestions($partialQuery);
    }

    public function isActive(array $data = []): bool
    {
        return ! empty($data['query']) && strlen(trim($data['query'])) >= 3;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $universalSupport = config('filament-natural-language-filter.languages.universal_support', true);
        $autoDetectDirection = config('filament-natural-language-filter.languages.auto_detect_direction', true);

        $placeholder = $universalSupport
            ? 'e.g., trovami tutti gli utenti chiamati samantah'
            : 'e.g., show users named john created after 2023';

        $textInput = TextInput::make('query')
            ->label('Filtro con linguaggio naturale')
            ->placeholder($placeholder)
            ->extraInputAttributes([
                'autocomplete' => 'off',
                'spellcheck' => 'false',
                ...$autoDetectDirection ? ['dir' => 'auto', 'lang' => 'auto'] : [],
            ]);

        if ($this->searchMode === 'live') {
            $helperText = $universalSupport
                ? 'Digita la tua query in qualsiasi lingua - la ricerca avviene automaticamente | اكتب استعلامك بأي لغة | escriba su consulta en cualquier idioma'
                : 'Type your query - search happens automatically as you type';

            $textInput
                ->live(true)
                ->afterStateUpdated(function ($state) {
                    // Trigger filter update immediately when state changes
                    $this->getTable()?->resetPage();
                })
                ->debounce(800)
                ->helperText($helperText);
        } else {
            $helperText = $universalSupport
                ? 'Inserisci la tua query e premi Invio'
                : 'Enter your query in natural language and press Enter to apply';

            $textInput
                ->live(true, null, false) // Explicitly disable live mode for submit
                ->helperText($helperText);
        }

        $this->schema([$textInput]);

        $this->indicateUsing(function (array $data): array {
            $query = trim($data['query'] ?? '');

            if (mb_strlen($query, 'UTF-8') < 3) {
                return [];
            }

            return [
                Indicator::make(mb_strlen($query) > 40 ? mb_substr($query, 0, 40).'…' : $query)
                    ->label(__('Filtro AI')),
            ];
        });
    }

    protected function getProcessor(): ?NaturalLanguageProcessorInterface
    {
        if ($this->processor === null) {
            try {
                $this->processor = resolve(NaturalLanguageProcessorInterface::class);
            } catch (Exception $e) {
                Log::error('Failed to resolve NaturalLanguageProcessorInterface: '.$e->getMessage());
            }
        }

        return $this->processor;
    }

    /**
     * Apply the natural language filter to the query
     *
     * This method processes the natural language query and applies
     * the resulting filters to the database query builder.
     *
     * @param  Builder  $query  The database query builder
     * @param  array  $data  The filter data containing the natural language query
     * @return Builder The modified query builder
     */
    public function apply(Builder $query, array $data = []): Builder
    {
        if (empty($data['query']) || strlen(trim($data['query'])) < 3) {
            session()->forget('nlf_last_query_'.md5(request()->url().'_'.$this->getName()));

            return $query;
        }

        $queryText = trim($data['query']);
        $isQueryNew = $this->isQueryNew($queryText);

        try {
            $processor = $this->getProcessor();

            if (! $processor) {
                if ($isQueryNew) {
                    Notification::make()
                        ->title(__('AI non disponibile'))
                        ->body(__('Impossibile elaborare il filtro: il processore AI non è configurato.'))
                        ->warning()
                        ->send();
                }

                return $query;
            }

            if (! $processor->canProcess($queryText)) {
                if ($isQueryNew) {
                    Notification::make()
                        ->title(__('Query non valida'))
                        ->body(__('La query è troppo corta o troppo lunga.'))
                        ->warning()
                        ->send();
                }

                return $query;
            }

            // Process the query with AI to get filter array
            if ($this->systemPromptAddition !== null) {
                $processor->setAdditionalSystemPrompt($this->systemPromptAddition);
            }

            $filters = $processor->processQuery($queryText, $this->getAvailableColumns(), $this->getAvailableRelations());

            Log::info('NaturalLanguageFilter - AI Processing Result', [
                'user_query' => $queryText,
                'available_columns' => $this->getAvailableColumns(),
                'available_relations' => $this->getAvailableRelations(),
                'ai_filters' => $filters,
            ]);

            if (empty($filters)) {
                if ($isQueryNew) {
                    Notification::make()
                        ->title(__('Nessun filtro trovato'))
                        ->body(__("L'AI non è riuscita a interpretare la query come filtro."))
                        ->warning()
                        ->send();
                }

                return $query;
            }

            // Use enhanced query builder for complex filtering
            $enhancedBuilder = new EnhancedQueryBuilder(
                $query,
                $this->getAvailableColumns(),
                $this->getAvailableRelations()
            );

            // Apply each filter using the enhanced builder
            foreach ($filters as $filter) {
                if (! isset($filter['operator'])) {
                    continue;
                }

                try {
                    $enhancedBuilder->applyFilter($filter);
                } catch (Exception $filterException) {
                    Log::warning('Failed to apply filter: '.$filterException->getMessage(), [
                        'filter' => $filter,
                    ]);
                }
            }

            $finalQuery = $enhancedBuilder->getQuery();

            $this->logFinalSqlQuery($finalQuery, $queryText, $filters);

            if ($isQueryNew) {
                Notification::make()
                    ->title(__('Filtro applicato'))
                    ->body(trans_choice(':count filtro attivo|:count filtri attivi', count($filters), ['count' => count($filters)]))
                    ->success()
                    ->send();
            }

            return $finalQuery;
        } catch (Exception $e) {
            Log::error('Natural Language Filter Error: '.$e->getMessage(), [
                'query' => $queryText,
                'available_columns' => $this->getAvailableColumns(),
                'available_relations' => $this->getAvailableRelations(),
            ]);

            if ($isQueryNew) {
                Notification::make()
                    ->title(__('Errore nel filtro AI'))
                    ->body($e->getMessage())
                    ->danger()
                    ->send();
            }
        }

        return $query;
    }

    protected function isQueryNew(string $queryText): bool
    {
        $sessionKey = 'nlf_last_query_'.md5(request()->url().'_'.$this->getName());
        $isNew = session($sessionKey) !== $queryText;

        if ($isNew) {
            session([$sessionKey => $queryText]);
        }

        return $isNew;
    }

    protected function logFinalSqlQuery(Builder $query, string $queryText, array $filters): void
    {
        try {
            Log::info('NaturalLanguageFilter - Final SQL Query', [
                'user_query' => $queryText,
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
                'filters_count' => count($filters),
            ]);
        } catch (Throwable $exception) {
            Log::warning('NaturalLanguageFilter - Unable to log final SQL query: '.$exception->getMessage(), [
                'user_query' => $queryText,
            ]);
        }
    }
}
