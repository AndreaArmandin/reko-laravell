<?php

use App\Gestionale\Actions\Clients\SaveClient;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use Livewire\Livewire;

beforeEach(function () {
    $this->crm = AgencyMembership::factory()->crm()->create();
});

function card_client(AgencyMembership $agent, string $status, string $createdAt): ClientProfile
{
    $contact = Contact::factory()->create(['agency_id' => $agent->agency_id]);

    return ClientProfile::factory()->agent($agent)->create(['contact_id' => $contact->id, 'status' => $status, 'created_at' => $createdAt]);
}

it('flags old clients to call back except concluded, suspended and not interested (test-crm-interface-2000)', function () {
    foreach (ClientProfile::STATUSES as $status) {
        $client = card_client($this->crm, $status, now()->subDays(45)->toDateTimeString());
        expect($client->needsCallback(30))->toBe(! in_array($status, ['Concluso', 'Sospeso', 'Non interessato'], true), $status);
    }

    expect(ClientProfile::query()->withoutGlobalScopes()->needsCallback(30)->count())->toBe(6);
});

it('flags a new client created three weeks ago, not one created now (test-giro8)', function () {
    $old = card_client($this->crm, 'Nuovo', now()->subWeeks(3)->toDateTimeString());
    $new = card_client($this->crm, 'Nuovo', now()->toDateTimeString());

    expect($old->needsCallback())->toBeTrue()
        ->and($new->needsCallback())->toBeFalse()
        ->and(card_client($this->crm, 'Da contattare', now()->toDateTimeString())->needsCallback())->toBeTrue();
});

it('accepts 500 Italian mobile numbers and links them with tel: and an encoded mailto:', function () {
    for ($i = 0; $i < 500; $i++) {
        SaveClient::validateContact('Cliente '.$i, '+39 333 '.(1000000 + $i), '', true, false, true);
    }

    $this->actingAs($this->crm->user);
    $client = app(SaveClient::class)->handle($this->crm, ['name' => 'Mario Rossi', 'phone' => '+39 333 1000000', 'email' => 'mario+casa@example.it']);

    Livewire::test('pages::gestionale.clients.show', ['contact' => $client])
        ->assertSeeHtml('href="tel:+393331000000"')
        ->assertSeeHtml('href="mailto:'.rawurlencode('mario+casa@example.it').'"');
});
