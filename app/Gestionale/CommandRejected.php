<?php

namespace App\Gestionale;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Port of the gestionale CRMError(message, status): a command refused with the exact Italian
 * message and HTTP status of the old engine (400 invalid data, 403 role/record, 409 conflict,
 * 428 missing revision). Nothing is written: Actions throw before or inside their transaction.
 * $field lets forms show the message next to the related input.
 */
class CommandRejected extends HttpException
{
    public function __construct(string $message, int $status = 400, public readonly ?string $field = null)
    {
        parent::__construct($status, $message);
    }

    public function status(): int
    {
        return $this->getStatusCode();
    }
}
