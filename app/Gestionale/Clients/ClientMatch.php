<?php

namespace App\Gestionale\Clients;

use App\Models\Contact;

/** One result of ClientMatcher: which keys coincide. */
final class ClientMatch
{
    public function __construct(
        public readonly Contact $contact,
        public readonly bool $phone,
        public readonly bool $email,
        public readonly bool $name,
    ) {}

    public function exact(): bool
    {
        return $this->phone && $this->email;
    }
}
