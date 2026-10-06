<?php

namespace App\Gestionale;

use RuntimeException;

/** Same idempotency key reused with a different payload (HTTP 409 in the gestionale). */
final class IdempotencyConflict extends RuntimeException {}
