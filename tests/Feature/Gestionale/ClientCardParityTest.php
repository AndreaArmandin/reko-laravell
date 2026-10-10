<?php

use App\Gestionale\Actions\Clients\SaveClient;
use App\Models\AgencyMembership;
use Livewire\Livewire;

it('keeps the client card aligned with the original detail layout', function () {
    $crm = AgencyMembership::factory()->crm()->create();
    $this->actingAs($crm->user);
    $client = app(SaveClient::class)->handle($crm, ['name' => 'Cliente scheda prova', 'phone' => '3335550149']);

    Livewire::test('pages::gestionale.clients.show', ['contact' => $client])
        ->assertSee('Numero di telefono')
        ->assertSee('Creatore del lead')
        ->assertSee('Che cosa cerca')
        ->assertSee('Nuova richiesta')
        ->assertDontSee('Operatore assegnato')
        ->assertDontSee('Documenti e stato')
        ->assertDontSee('Dettagli')
        ->assertDontSee('Canale preferito');
});

it('opens the client create and edit form as the original wide dialog', function () {
    $crm = AgencyMembership::factory()->crm()->create();
    $this->actingAs($crm->user);
    $client = app(SaveClient::class)->handle($crm, ['name' => 'Cliente finestra prova', 'phone' => '3335550150']);

    Livewire::test('gestionale.client-form-modal')
        ->call('showForm')
        ->assertSet('open', true)
        ->assertSee('Nuovo cliente')
        ->assertSee('Bastano il nome e almeno un recapito. Puoi completare gli altri dettagli in seguito. Per le prove usa dati di fantasia.')
        ->assertSee('proto-dialog-wide');

    Livewire::test('gestionale.client-form-modal')
        ->call('showForm', $client->id)
        ->assertSet('open', true)
        ->assertSee('Modifica cliente')
        ->assertSet('name', 'Cliente finestra prova');
});
