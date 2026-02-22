<?php

namespace Inerba\FilamentNaturalLanguageFilter\Contracts;

interface NaturalLanguageProcessorInterface
{
    /**
     * Process natural language query and convert it to database filters
     */
    public function processQuery(string $query, array $availableColumns = [], array $availableRelations = []): array;

    /**
     * Validate if the query can be processed
     */
    public function canProcess(string $query): bool;

    /**
     * Get supported filter types
     */
    public function getSupportedFilterTypes(): array;

    /**
     * Set the locale for processing natural language queries
     */
    public function setLocale(string $locale): void;

    /**
     * Set custom column mappings for natural language to database column translation
     */
    public function setCustomColumnMappings(array $mappings): void;

    /**
     * Get current custom column mappings
     */
    public function getCustomColumnMappings(): array;
}
