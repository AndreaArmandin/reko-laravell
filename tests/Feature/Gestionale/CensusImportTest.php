<?php

use App\Gestionale\Census\CensusImporter;
use App\Gestionale\Census\CensusQuery;
use App\Gestionale\Census\CensusReader;
use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\AgencyUnitObservation;
use App\Models\CensusBatch;
use App\Models\Contact;
use App\Models\Municipality;
use App\Models\Ownership;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id, 'permissions' => ['sister.import', 'exports']]);
    $this->actingAs($this->admin->user);
    $province = DB::table('territorial_provinces')->insertGetId(['code' => '015', 'abbreviation' => 'MI', 'name' => 'Milano', 'created_at' => now(), 'updated_at' => now()]);
    Municipality::query()->create(['cadastral_code' => 'F205', 'name' => 'Milano', 'territorial_province_id' => $province]);
});

function census_context(array $extra = []): array
{
    return $extra + ['kind' => 'Fabbricati', 'province' => 'MI', 'municipality' => 'Milano', 'code' => 'F205', 'section' => '', 'situationDate' => ''];
}

function census_estate(string $sub = '1', string $address = 'VIA ROMA n. 5 Piano T', string $category = 'A/3', string $sheet = '309', string $parcel = '387'): string
{
    return implode("\t", [$sheet, $parcel, $sub, $address, '2', $category, '3', '4,5 vani', 'Euro: 523,10', '1234']);
}

function census_owner(string $name, string $cf, string $holding = 'Proprieta\' per 1/2'): string
{
    return implode("\t", [$name, $cf, $holding]);
}

function census_import(AgencyMembership $actor, string $text, array $extra = []): array
{
    return app(CensusImporter::class)->apply($actor, $extra + ['sourceText' => $text, 'context' => census_context(), 'confirmUpdates' => true, 'skipInvalid' => true]);
}

it('imports the estates of a pasted block without duplicates and leaves owners alone', function () {
    $text = census_estate('1')."\n".census_estate('2', 'VIA ROMA n. 5 Piano 1');

    $first = census_import($this->admin, $text);
    expect($first['unchanged'])->toBeFalse()->and($first['title'])->toBe('Importati 2 immobili')
        ->and(AgencyUnitObservation::query()->count())->toBe(2)
        ->and(DB::table('cadastral_units')->count())->toBe(2)
        ->and(DB::table('parcels')->count())->toBe(1);

    $unit = AgencyUnitObservation::query()->orderBy('id')->first();
    expect($unit->category)->toBe('A/3')->and($unit->address)->toBe('VIA ROMA n. 5')->and($unit->floor)->toBe('T')
        ->and($unit->census_zone)->toBe('2')->and($unit->consistency)->toBe('4,5 vani')->and((float) $unit->income)->toBe(523.1)
        ->and($unit->source)->toBe('sister-text')->and($unit->raw_text)->toContain('VIA ROMA');

    $again = census_import($this->admin, $text);
    expect($again['unchanged'])->toBeTrue()->and(AgencyUnitObservation::query()->count())->toBe(2)->and(CensusBatch::query()->count())->toBe(1);
});

it('links the pasted owners to the selected unit, normalizing the fiscal code and keeping right and share', function () {
    census_import($this->admin, census_estate('1'));
    $unit = AgencyUnitObservation::query()->firstOrFail();

    $text = census_owner('ROSSI MARIO', 'rssmra80a01 f205x')."\n".census_owner('BIANCHI ANNA', 'BNCNNA82B41F205Y');
    $preview = app(CensusImporter::class)->preview($this->admin, ['sourceText' => $text, 'targetUnitId' => $unit->cadastral_unit_id, 'context' => census_context()]);
    expect($preview['errors'])->toBe([])->and($preview['ownerPreview'])->toHaveCount(2)->and($preview['ownerPreview'][0]['pid'])->toBe('RSSMRA80A01F205X')
        ->and($preview['ownerPreview'][0]['outcome'])->toBe('Nuovo')->and($preview['warnings'])->toBe([]);

    $result = census_import($this->admin, $text, ['targetUnitId' => $unit->cadastral_unit_id]);
    expect($result['title'])->toBe('Collegati 2 proprietari a F.309 P.387 Sub.1');

    $rossi = Contact::query()->where('tax_code', 'RSSMRA80A01F205X')->firstOrFail();
    expect($rossi->origin)->toBe('sister')->and($rossi->display_name)->toBe('ROSSI MARIO')->and((int) $rossi->imported_by_user_id)->toBe($this->admin->user_id);
    $link = Ownership::query()->where('contact_id', $rossi->id)->firstOrFail();
    expect($link->valid_to)->toBeNull()->and($link->right_type)->toBe('Proprietà')->and($link->share_numerator)->toBe(1)->and($link->share_denominator)->toBe(2)
        ->and((int) $link->cadastral_unit_id)->toBe((int) $unit->cadastral_unit_id)->and($link->details['holding']['fraction'])->toBe('1/2');

    // Stesso testo: nessun duplicato, nessuna seconda importazione.
    expect(census_import($this->admin, $text, ['targetUnitId' => $unit->cadastral_unit_id])['unchanged'])->toBeTrue()
        ->and(Ownership::query()->count())->toBe(2)->and(Contact::query()->where('origin', 'sister')->count())->toBe(2);
});

it('keeps one owner per fiscal code on a unit when Sister lists distinct rights on separate rows', function () {
    census_import($this->admin, census_estate('1'));
    $unit = AgencyUnitObservation::query()->firstOrFail();
    $text = census_owner('ROSSI MARIO', 'RSSMRA80A01F205X', 'Nuda proprietà per 1/2')."\n"
        .census_owner('ROSSI MARIO', 'RSSMRA80A01F205X', 'Usufrutto per 1/2');

    $preview = app(CensusImporter::class)->preview($this->admin, [
        'sourceText' => $text,
        'targetUnitId' => $unit->cadastral_unit_id,
        'context' => census_context(),
    ]);

    expect($preview['errors'])->toBe([])->and($preview['owners'])->toHaveCount(1);
    census_import($this->admin, $text, ['targetUnitId' => $unit->cadastral_unit_id]);

    $owner = Contact::query()->where('tax_code', 'RSSMRA80A01F205X')->firstOrFail();
    $ownerships = Ownership::query()->where('contact_id', $owner->id)->where('cadastral_unit_id', $unit->cadastral_unit_id)->whereNull('valid_to')->get();
    expect($ownerships)->toHaveCount(1)
        ->and($ownerships->sole()->details['holding']['components'])->toHaveCount(2)
        ->and(Contact::query()->where('tax_code', 'RSSMRA80A01F205X')->count())->toBe(1);
});

it('warns when the shares of the right do not sum to one and refuses invalid right or share', function () {
    census_import($this->admin, census_estate('1'));
    $unit = AgencyUnitObservation::query()->firstOrFail();

    $preview = app(CensusImporter::class)->preview($this->admin, ['sourceText' => census_owner('ROSSI MARIO', 'RSSMRA80A01F205X'), 'targetUnitId' => $unit->cadastral_unit_id, 'context' => census_context()]);
    expect($preview['warnings'])->toHaveCount(1)->and($preview['warnings'][0])->toContain('sommano 0,5, non 1');

    $bad = app(CensusImporter::class)->preview($this->admin, ['sourceText' => census_owner('ROSSI MARIO', 'RSSMRA80A01F205X', 'Chissà 7/3'), 'targetUnitId' => $unit->cadastral_unit_id, 'context' => census_context()]);
    expect($bad['errors'][0])->toContain('diritto o quota non riconosciuti');
});

it('asks what to do with owners missing from the new paste and closes the holding into the history', function () {
    census_import($this->admin, census_estate('1'));
    $unit = AgencyUnitObservation::query()->firstOrFail();
    census_import($this->admin, census_owner('ROSSI MARIO', 'RSSMRA80A01F205X')."\n".census_owner('BIANCHI ANNA', 'BNCNNA82B41F205Y'), ['targetUnitId' => $unit->cadastral_unit_id]);

    $text = census_owner('ROSSI MARIO', 'RSSMRA80A01F205X');
    $preview = app(CensusImporter::class)->preview($this->admin, ['sourceText' => $text, 'targetUnitId' => $unit->cadastral_unit_id, 'context' => census_context()]);
    expect($preview['missingOwners'])->toHaveCount(1)->and($preview['missingOwners'][0]['pid'])->toBe('BNCNNA82B41F205Y');
    $key = $preview['missingOwners'][0]['key'];

    expect(fn () => census_import($this->admin, $text, ['targetUnitId' => $unit->cadastral_unit_id]))->toThrow(CommandRejected::class, 'scegli se mantenerlo o chiuderne l’intestazione');

    census_import($this->admin, $text, ['targetUnitId' => $unit->cadastral_unit_id, 'ownerDecisions' => [$key => 'close']]);
    $bianchi = Contact::query()->where('tax_code', 'BNCNNA82B41F205Y')->firstOrFail();
    $closed = Ownership::query()->where('contact_id', $bianchi->id)->firstOrFail();
    expect($closed->valid_to)->not->toBeNull()->and($closed->details['kind'])->toBe('Trasferimento')->and($closed->details['exact_date_unknown'])->toBeTrue()
        ->and(Contact::query()->where('tax_code', 'BNCNNA82B41F205Y')->exists())->toBeTrue();

    $entries = (new CensusReader($this->admin))->entries(['municipalityCode' => 'F205']);
    expect($entries)->toHaveCount(1)->and($entries[0]['owners'])->toHaveCount(1);
});

it('keeps conflicting rows out and requires the partial-import confirmation', function () {
    $text = census_estate('1')."\n".census_estate('3', 'VIA ROMA n. 5', '');
    expect(fn () => app(CensusImporter::class)->apply($this->admin, ['sourceText' => $text, 'context' => census_context(), 'confirmUpdates' => true]))
        ->toThrow(CommandRejected::class, 'conferma l’importazione delle sole righe valide');

    $preview = app(CensusImporter::class)->preview($this->admin, ['sourceText' => $text, 'context' => census_context()]);
    expect(collect($preview['rows'])->pluck('status')->all())->toBe(['Nuovo', 'Incompleto']);
});

it('discards suppressed and common non-censible rows', function () {
    $text = census_estate('1')."\n".census_estate('2')."\tSoppressa\n".implode("\t", ['309', '387', '9', 'BCNC bene comune non censibile', '', 'A/2', '', '', '', '']);
    $preview = app(CensusImporter::class)->preview($this->admin, ['sourceText' => $text, 'context' => census_context()]);
    expect(collect($preview['rows'])->pluck('status')->all())->toBe(['Nuovo', 'Escluso', 'Escluso']);
});

it('allows the import only to who holds the sister.import permission', function () {
    $crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    expect(fn () => census_import($crm, census_estate('1')))->toThrow(CommandRejected::class, 'Permesso “Importa da Sister” non assegnato.');
    expect(fn () => app(CensusImporter::class)->preview($crm, ['sourceText' => census_estate('1'), 'context' => census_context()]))
        ->toThrow(CommandRejected::class, 'Permesso “Importa da Sister” non assegnato.');
    expect(fn () => census_import(AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]), census_estate('1')))->toThrow(CommandRejected::class);
});

it('assigns the imported parcel to the scout who imports it and shows it only to him', function () {
    census_import($this->scout, census_estate('1'));
    $other = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);

    $own = (new CensusReader($this->scout))->entries(['municipalityCode' => 'F205']);
    expect($own)->toHaveCount(1)->and((new CensusReader($other))->entries(['municipalityCode' => 'F205']))->toHaveCount(0)
        ->and((new CensusReader($this->admin))->entries(['municipalityCode' => 'F205']))->toHaveCount(1)
        ->and((int) $own[0]['operator'])->toBe((int) $this->scout->user_id);

    // Un altro operatore non può importare nella particella assegnata a lui.
    $other->forceFill(['permissions' => ['sister.import']])->save();
    expect(fn () => census_import($other->refresh(), census_estate('2')))->toThrow(CommandRejected::class, 'Una particella esistente non è assegnata a questo operatore.');
});

it('filters, searches and orders the census like the original', function () {
    census_import($this->admin, census_estate('1')."\n".census_estate('2', 'VIA VERDI n. 12 Piano 2', 'A/2'));
    $first = AgencyUnitObservation::query()->orderBy('id')->first();
    census_import($this->admin, census_owner('ROSSI MARIO', 'RSSMRA80A01F205X', 'Proprieta\' per 1/1'), ['targetUnitId' => $first->cadastral_unit_id]);
    $q = new CensusQuery($this->admin);

    $all = $q->run(['view' => 'Immobili', 'filters' => ['municipality' => ['Milano'], 'municipalityCode' => ['F205']], 'order' => 'Da contattare']);
    expect($all['total'])->toBe(2);
    expect($q->run(['view' => 'Immobili', 'search' => 'verdi 12', 'filters' => ['municipalityCode' => ['F205']]])['total'])->toBe(1);
    expect($q->run(['view' => 'Immobili', 'search' => 'A/2', 'filters' => ['municipalityCode' => ['F205']]])['total'])->toBe(1);
    expect($q->run(['view' => 'Immobili', 'filters' => ['municipalityCode' => ['F205'], 'hasOwner' => ['Sì']]])['total'])->toBe(1);
    expect($q->run(['view' => 'Immobili', 'filters' => ['municipalityCode' => ['F205'], 'never' => ['Sì']]])['total'])->toBe(2);

    $people = $q->run(['view' => 'Proprietari', 'filters' => ['municipalityCode' => ['F205']]]);
    expect($people['total'])->toBe(1)->and($people['people'][0]['owner']['name'])->toBe('ROSSI MARIO')->and($people['people'][0]['count'])->toBe(1);
    expect($q->run(['view' => 'Proprietari', 'search' => 'rossi', 'filters' => ['municipalityCode' => ['F205']]])['total'])->toBe(1);
    expect($q->run(['view' => 'Proprietari', 'filters' => ['municipalityCode' => ['F205'], 'count' => ['Più di uno']]])['total'])->toBe(0);

    expect(fn () => (new CensusQuery(AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id])))->run([]))
        ->toThrow(CommandRejected::class, 'Sezione Proprietari non accessibile.');
});

it('returns only the requested page of default municipality results', function () {
    census_import($this->admin, census_estate('1', 'VIA ROMA n. 5', 'A/3', '309', '387')."\n".census_estate('1', 'VIA ROMA n. 6', 'A/3', '309', '388'));

    $page = (new CensusQuery($this->admin))->run([
        'view' => 'Immobili', 'filters' => ['municipalityCode' => ['F205']], 'page' => 2, 'size' => 1,
    ]);

    expect($page['total'])->toBe(2)
        ->and($page['page'])->toBe(2)
        ->and(count($page['items']))->toBe(1)
        ->and($page['items'][0]['context']['parcel'])->toBe('388');
});

it('shows the linked next contact and correction marker in cadastral activity history', function () {
    census_import($this->admin, census_estate('1'));
    $unit = AgencyUnitObservation::query()->firstOrFail();
    $activity = new Activity;
    $activity->forceFill([
        'agency_id' => $this->admin->agency_id,
        'user_id' => $this->admin->user_id,
        'created_by_user_id' => $this->admin->user_id,
        'assigned_to_user_id' => $this->admin->user_id,
        'kind' => 'Telefonata', 'subject' => 'Richiamo proprietario', 'status' => 'Completata', 'priority' => 'Normale',
        'scheduled_at' => now()->subDay(), 'completed_at' => now()->subDay(), 'completed_by_user_id' => $this->admin->user_id,
        'outcome' => 'Interessato', 'outcome_confirmed_at' => now()->subDay(), 'outcome_confirmed_by_user_id' => $this->admin->user_id,
        'contact_operation' => 'Vendita', 'visibility' => 'workflow', 'metadata' => (object) [], 'notes' => 'Richiamare dopo la visita',
    ])->save();
    DB::table('activity_units')->insert([
        'agency_id' => $this->admin->agency_id, 'activity_id' => $activity->id,
        'cadastral_unit_id' => $unit->cadastral_unit_id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $followup = new Activity;
    $followup->forceFill([
        'agency_id' => $this->admin->agency_id,
        'user_id' => $this->admin->user_id,
        'created_by_user_id' => $this->admin->user_id,
        'assigned_to_user_id' => $this->admin->user_id,
        'kind' => 'Telefonata', 'subject' => 'Prossimo contatto', 'status' => 'Da svolgere', 'priority' => 'Normale',
        'scheduled_at' => now()->addDays(2), 'visibility' => 'workflow', 'metadata' => (object) [],
    ])->save();
    $activity->forceFill(['next_activity_id' => $followup->id])->save();
    app(Audit::class)->record('activity.save', $activity, ['before' => []], 'Esito verificato e corretto');

    $entry = (new CensusReader($this->admin))->entries(['municipalityCode' => 'F205'])->first();
    $outcome = $entry['history'][0];

    expect($outcome['contactOperation'])->toBe('Vendita')
        ->and(Carbon\Carbon::parse($outcome['next'])->format('Y-m-d H:i:s'))->toBe($followup->fresh()->scheduled_at->format('Y-m-d H:i:s'))
        ->and($outcome['corrected'])->toBeTrue();

    $archive = Livewire::test('pages::gestionale.archive.index')->set('municipalityCode', 'F205')
        ->assertSee('Sentito per vendita')
        ->assertSee('Prossimo contatto:')
        ->assertSee('Corretto con motivazione');

    $archive->call('openOutcomeHistory', $unit->cadastral_unit_id)
        ->assertSee('Storico catastale e attività')
        ->assertSee('Richiamare dopo la visita')
        ->call('correctOutcomeFromHistory', $activity->id)
        ->assertDispatched('activity-form');
});
