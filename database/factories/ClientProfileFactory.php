<?php

namespace Database\Factories;

use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientProfile>
 */
class ClientProfileFactory extends Factory
{
    /**
     * The referent defaults to a new crm member of the contact's agency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'agency_id' => fn (array $attributes) => ContactChannelFactory::agencyOf($attributes['contact_id']),
            'agent_user_id' => fn (array $attributes) => AgencyMembership::factory()->crm()
                ->create(['agency_id' => $attributes['agency_id']])->user_id,
            'status' => 'Nuovo',
            'preferred_channel' => 'Telefono',
        ];
    }

    public function agent(AgencyMembership $membership): static
    {
        return $this->state(['agent_user_id' => $membership->user_id]);
    }
}
