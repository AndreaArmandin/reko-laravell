<?php

namespace App\Trova;

/**
 * One page of parcels matching a catalogue search.
 */
final readonly class CatalogSearchResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public int $total,
        public int $matchedUnits,
        public int $page,
        public int $pageSize,
        public ?string $measure,
        public array $rows,
    ) {}

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->pageSize));
    }
}
