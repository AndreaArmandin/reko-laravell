<?php

namespace App\Trova;

use RuntimeException;
use Throwable;

/**
 * The search cannot answer. The message is meant for the person searching.
 *
 * retry = false: the criteria are wrong, the person must change them.
 * retry = true: the archive is not available right now, the same search may work later.
 */
final class SearchException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retry = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
