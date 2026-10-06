<?php

namespace App\Gestionale;

use RuntimeException;

/** Raised when code tries to write a row of another agency, or to move a row between agencies. */
final class CrossAgencyWrite extends RuntimeException {}
