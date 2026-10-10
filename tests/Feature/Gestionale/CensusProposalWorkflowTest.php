<?php

use App\Gestionale\Census\CensusImporter;
use App\Models\AgencyMembership;
use App\Models\CensusProposal;
use App\Models\Municipality;
use Livewire\Livewire;

it('lets an operator submit a census correction and lets the administrator review it from Today', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $scout = AgencyMembership::factory()->scout()->create(['agency_id' => $admin->agency_id]);
    $this->actingAs($admin->user);
    $provinceId = DB::table('territorial_provinces')->insertGetId([
        'code' => '015', 'abbreviation' => 'MI', 'name' => 'Milano', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Municipality::query()->create(['cadastral_code' => 'F205', 'name' => 'Milano', 'territorial_province_id' => $provinceId]);
    $import = app(CensusImporter::class)->apply($admin, [
        'sourceText' => implode("\t", ['309', '387', '1', 'VIA ROMA n. 5 Piano T', '2', 'A/3', '3', '4,5 vani', 'Euro: 523,10', '1234']),
        'context' => ['kind' => 'Fabbricati', 'province' => 'MI', 'municipality' => 'Milano', 'code' => 'F205', 'section' => '', 'situationDate' => ''],
        'confirmUpdates' => true,
        'skipInvalid' => true,
    ]);
    $unitId = $import['unitIds'][0];
    $parcelId = (int) DB::table('cadastral_units')->where('id', $unitId)->value('parcel_id');
    \App\Gestionale\Census\CensusScope::assignParcel((int) $admin->agency_id, $parcelId, (int) $scout->user_id);

    $this->actingAs($scout->user);
    Livewire::test('pages::gestionale.archive.index')
        ->set('municipalityCode', 'F205')
        ->call('openProposal', $unitId)
        ->set('proposalNote', 'Verificare il numero civico con la visura aggiornata.')
        ->call('submitProposal')
        ->assertSet('proposalOpen', false)
        ->assertSee('Proposta inviata all’Amministratore.');

    $proposal = CensusProposal::query()->where('agency_id', $admin->agency_id)->sole();
    expect($proposal->status)->toBe('Da verificare')
        ->and($proposal->cadastral_unit_id)->toBe($unitId)
        ->and($proposal->notes)->toBe('Verificare il numero civico con la visura aggiornata.');

    $this->actingAs($admin->user);
    Livewire::test('pages::gestionale.home')
        ->set('filtro', 'approvals')
        ->assertSee('Da approvare · 1')
        ->assertSee('Verificare il numero civico con la visura aggiornata.')
        ->call('openProposalReview', $proposal->id, 'reject')
        ->call('reviewProposal')
        ->assertSee('Indica il motivo del rifiuto')
        ->set('proposalReviewNote', 'La visura conferma l’indirizzo già registrato.')
        ->call('reviewProposal')
        ->assertSet('reviewProposalId', null);

    $proposal->refresh();
    expect($proposal->status)->toBe('Rifiutata')
        ->and($proposal->review['actorId'])->toBe($admin->user_id)
        ->and($proposal->review['reason'])->toBe('La visura conferma l’indirizzo già registrato.');
});

it('prevents non administrators from reviewing census proposals', function () {
    $admin = AgencyMembership::factory()->admin()->create();
    $scout = AgencyMembership::factory()->scout()->create(['agency_id' => $admin->agency_id]);
    $this->actingAs($admin->user);
    $proposal = CensusProposal::query()->create([
        'agency_id' => $admin->agency_id,
        'proposed_by_user_id' => $admin->user_id,
        'status' => 'Da verificare',
        'payload' => ['name' => 'Proprietario di prova'],
        'notes' => 'Confermare il codice fiscale.',
    ]);

    $this->actingAs($scout->user);
    expect(fn () => app(\App\Gestionale\Actions\Census\ReviewCensusProposal::class)->handle($scout, $proposal->id, ['decision' => 'approve']))
        ->toThrow(\App\Gestionale\CommandRejected::class, 'La revisione delle proposte è riservata all’Amministratore.');
});
