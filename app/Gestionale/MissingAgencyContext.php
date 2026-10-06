<?php

namespace App\Gestionale;

use RuntimeException;

/** Raised when agency data is read or written without a current agency. Fails closed. */
final class MissingAgencyContext extends RuntimeException {}
