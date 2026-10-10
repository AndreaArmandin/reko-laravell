<?php

use App\Models\Document;
use App\Models\Property;
use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);
    $this->property = app(CurrentAgency::class)->runAs($this->admin->agency, fn () => Property::query()->create([
        'agent_user_id' => $this->crm->user_id,
        'title' => 'Bilocale di prova',
        'code' => 'P-100',
        'status' => 'Attivo',
        'city' => 'Cuneo',
        'address' => 'Via Roma',
        'features' => ['operation' => 'Acquisto', 'typology' => 'Appartamento', 'price' => 145000, 'area' => 72, 'tags' => ['balcone']],
        'publication' => ['status' => 'Non pubblicato'],
    ]));
});

it('renders the original detail sections and hides compatible requests from the scouting profile', function () {
    $this->actingAs($this->admin->user)->get(route('gestionale.properties.show', $this->property))
        ->assertOk()->assertSee('Scheda immobile')->assertSee('Clienti compatibili')->assertSee('Cronologia attività immobile')
        ->assertSee('Collegamenti all’archivio')->assertSee('Documenti e stato')->assertSee('P-100');

    $this->actingAs($this->scout->user)->get(route('gestionale.properties.show', $this->property))->assertForbidden();

    $this->actingAs($this->admin->user);
    Livewire::test('pages::gestionale.properties.show', ['property' => $this->property])
        ->call('selectTab', 'Clienti compatibili')->assertSet('activeTab', 'Clienti compatibili')
        ->call('selectTab', 'Scheda immobile');
});

it('records a document note without creating an uploaded attachment', function () {
    $this->actingAs($this->crm->user);

    Livewire::test('pages::gestionale.properties.show', ['property' => $this->property])
        ->call('openDocumentForm')
        ->set('documentTitle', 'Planimetria da richiedere')
        ->set('documentKind', 'Planimetria')
        ->set('documentStatus', 'Da richiedere')
        ->set('documentContent', 'Chiedere copia aggiornata al proprietario.')
        ->set('documentInternal', true)
        ->call('saveDocument')->assertHasNoErrors()->assertSet('documentDialog', null);

    $document = Document::withoutGlobalScopes()->where('documentable_id', $this->property->id)->firstOrFail();
    expect($document->title)->toBe('Planimetria da richiedere')
        ->and($document->storage_path)->toBeNull()
        ->and($document->metadata['content'])->toBe('Chiedere copia aggiornata al proprietario.')
        ->and($document->metadata['internal'])->toBeTrue();

    Livewire::test('pages::gestionale.properties.show', ['property' => $this->property])
        ->call('openDocumentPreview', $document->id)->assertSet('documentDialog', 'preview')->assertSee('Chiedere copia aggiornata al proprietario');
});
