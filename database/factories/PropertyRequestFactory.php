<?php

namespace Database\Factories;

use App\Models\ClientProfile;
use App\Models\PropertyRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyRequest>
 */
class PropertyRequestFactory extends Factory
{
    /**
     * Creates a client (contact + profile) and inherits its agency and referent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => fn () => ClientProfile::factory()->create()->contact_id,
            'agency_id' => fn (array $a) => ClientProfile::query()->withoutGlobalScopes()->where('contact_id', $a['contact_id'])->value('agency_id'),
            'agent_user_id' => fn (array $a) => ClientProfile::query()->withoutGlobalScopes()->where('contact_id', $a['contact_id'])->value('agent_user_id'),
            'title' => 'Richiesta di prova',
            'status' => 'Nuova',
        ];
    }

    public function forClient(ClientProfile $profile): static
    {
        return $this->state(['contact_id' => $profile->contact_id, 'agency_id' => $profile->agency_id, 'agent_user_id' => $profile->agent_user_id]);
    }
}
