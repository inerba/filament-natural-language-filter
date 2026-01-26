<?php

namespace EdrisaTuray\FilamentNaturalLanguageFilter\Filters;

use EdrisaTuray\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\EnhancedQueryBuilder;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\ProcessorFactory;
use EdrisaTuray\FilamentNaturalLanguageFilter\Services\QuerySuggestionsService;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class NaturalLanguageFilter extends BaseFilter
{
    protected array $availableColumns = [];

    protected array $availableRelations = [];

    protected array $customColumnMappings = [];

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

    public function searchMode(string $mode): static
    {
        if (! in_array($mode, ['live', 'submit'])) {
            throw new \InvalidArgumentException('Search mode must be either "live" or "submit"');
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
            ? 'e.g., show users named john | اعرض المستخدمين باسم أحمد | mostrar usuarios llamados juan'
            : 'e.g., show users named john created after 2023';

        $textInput = TextInput::make('query')
            ->label('Natural Language Filter')
            ->placeholder($placeholder)
            ->extraInputAttributes([
                'autocomplete' => 'off',
                'spellcheck' => 'false',
                ...$autoDetectDirection ? ['dir' => 'auto', 'lang' => 'auto'] : [],
            ]);

        if ($this->searchMode === 'live') {
            $helperText = $universalSupport
                ? 'Type your query in any language - search happens automatically | اكتب استعلامك بأي لغة | escriba su consulta en cualquier idioma'
                : 'Type your query - search happens automatically as you type';

            $textInput
                ->live()
                ->afterStateUpdated(function ($state) {
                    // Trigger filter update immediately when state changes
                    $this->getTable()?->resetPage();
                })
                ->debounce(800)
                ->helperText($helperText);
        } else {
            $helperText = $universalSupport
                ? 'Enter your query in any language and press Enter | أدخل استعلامك بأي لغة واضغط Enter | ingrese su consulta en cualquier idioma y presione Enter'
                : 'Enter your query in natural language and press Enter to apply';

            $textInput
                ->live(false) // Explicitly disable live mode for submit
                ->helperText($helperText);
        }

        $this->form([$textInput]);
    }

    protected function getProcessor(): ?NaturalLanguageProcessorInterface
    {
        if ($this->processor === null) {
            try {
                $this->processor = app(NaturalLanguageProcessorInterface::class);
            } catch (\Exception $e) {
                Log::error('Failed to resolve NaturalLanguageProcessorInterface: '.$e->getMessage());
                try {
                    // Fallback to factory with default provider
                    $this->processor = ProcessorFactory::create();
                } catch (\Exception $fallbackException) {
                    Log::error('Failed to create fallback processor: '.$fallbackException->getMessage());
                    $this->processor = null;
                }
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
            return $query;
        }

        $queryText = trim($data['query']);

        try {
            $processor = $this->getProcessor();

            if (! $processor) {
                return $query;
            }

            if (! $processor->canProcess($queryText)) {
                return $query;
            }

            // Process the query with AI to get filter array
            $filters = $processor->processQuery($queryText, $this->getAvailableColumns());

            Log::info('NaturalLanguageFilter - AI Processing Result', [
                'user_query' => $queryText,
                'available_columns' => $this->getAvailableColumns(),
                'available_relations' => $this->getAvailableRelations(),
                'ai_filters' => $filters,
            ]);

            if (empty($filters)) {
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
                } catch (\Exception $filterException) {
                    Log::warning('Failed to apply filter: '.$filterException->getMessage(), [
                        'filter' => $filter,
                    ]);
                }
            }

            return $enhancedBuilder->getQuery();
        } catch (\Exception $e) {
            Log::error('Natural Language Filter Error: '.$e->getMessage(), [
                'query' => $queryText,
                'available_columns' => $this->getAvailableColumns(),
                'available_relations' => $this->getAvailableRelations(),
            ]);
        }

        return $query;
    }

    protected function applyFilter(Builder $query, array $filter): void
    {
        $column = $filter['column'];
        $operator = $filter['operator'];
        $value = $filter['value'];

        switch ($operator) {
            case 'equals':
                $query->where($column, '=', $value);
                break;
            case 'not_equals':
                $query->where($column, '!=', $value);
                break;
            case 'contains':
                $query->where($column, 'LIKE', "%{$value}%");
                break;
            case 'starts_with':
                $query->where($column, 'LIKE', "{$value}%");
                break;
            case 'ends_with':
                $query->where($column, 'LIKE', "%{$value}");
                break;
            case 'greater_than':
                $query->where($column, '>', $value);
                break;
            case 'less_than':
                $query->where($column, '<', $value);
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($column, $value);
                }
                break;
            case 'in':
                if (is_array($value)) {
                    $query->whereIn($column, $value);
                }
                break;
            case 'not_in':
                if (is_array($value)) {
                    $query->whereNotIn($column, $value);
                }
                break;
            case 'is_null':
                $query->whereNull($column);
                break;
            case 'is_not_null':
                $query->whereNotNull($column);
                break;
            case 'date_equals':
                $query->whereDate($column, '=', $value);
                break;
            case 'date_before':
                $query->whereDate($column, '<', $value);
                break;
            case 'date_after':
                $query->whereDate($column, '>', $value);
                break;
            case 'date_between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($column, $value);
                }
                break;
        }
    }
}
