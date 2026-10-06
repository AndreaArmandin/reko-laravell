<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactChannel>
 */
class ContactChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'agency_id' => fn (array $attributes) => self::agencyOf($attributes['contact_id']),
            'kind' => 'phone',
            'value' => '+39 3'.fake()->numerify('## ### ####'),
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => ['kind' => 'email', 'value' => fake()->unique()->safeEmail()]);
    }

    public static function agencyOf(mixed $contact): int
    {
        return $contact instanceof Contact
            ? $contact->agency_id
            : Contact::withoutGlobalScopes()->findOrFail($contact)->agency_id;
    }
}
