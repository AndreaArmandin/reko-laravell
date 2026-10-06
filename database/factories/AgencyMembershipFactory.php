<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgencyMembership>
 */
class AgencyMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'user_id' => User::factory(),
            'role' => 'crm',
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => 'admin']);
    }

    public function scout(): static
    {
        return $this->state(['role' => 'scout']);
    }

    public function crm(): static
    {
        return $this->state(['role' => 'crm']);
    }

    public function deactivated(): static
    {
        return $this->state(['deactivated_at' => now()]);
    }
}
