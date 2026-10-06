<?php

namespace App\Trova;

/**
 * Counters and the chunk being filled while a SISTER file is read.
 */
final class ImportProgress
{
    public int $read = 0;

    public int $rejected = 0;

    public int $repeated = 0;

    public int $inserted = 0;

    /** Rejected lines already written as issues: the rest is only counted. */
    public int $issues = 0;

    /** @var array<string, SisterRecord> units of the chunk, by legacy key */
    public array $batch = [];

    /** @var array<string, int> section|sheet|number → parcel id, kept across chunks */
    public array $parcelIds = [];
}
