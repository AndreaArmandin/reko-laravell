<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $given = fake()->firstName();
        $family = fake()->lastName();

        return [
            'agency_id' => Agency::factory(),
            'display_name' => "{$given} {$family}",
            'given_name' => $given,
            'family_name' => $family,
            'origin' => 'manual',
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => [
            'tax_code' => strtoupper(fake()->unique()->bothify('??????##?##?###?')),
            'origin' => 'sister',
        ]);
    }
}
