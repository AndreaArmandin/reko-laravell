<?php

use App\Events\Gestionale\PropertyRequestCompleted;
use App\Events\Gestionale\PropertyRequestCriteriaChanged;
use App\Gestionale\Actions\Clients\SaveClient;
use App\Gestionale\Actions\Requests\AnswerPropertyRequestQuestion;
use App\Gestionale\Actions\Requests\CreatePropertyRequest;
use App\Gestionale\Actions\Requests\FinishPropertyRequest;
use App\Gestionale\Actions\Requests\SavePropertyRequest;
use App\Gestionale\Actions\Requests\StepPropertyRequest;
use App\Gestionale\Actions\SetRecordLifecycle;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Gestionale\IdempotencyConflict;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\AgencyMembership;
use App\Models\AuditEvent;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\PropertyRequest;
use App\Models\Property;
use App\Models\PropertyMatch;
use App\Models\Activity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->crm2 = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);
});

it('saves map picks into the zone answer instead of leaving the default point', function () {
    $this->actingAs($this->crm->user);

    Livewire::test('pages::gestionale.requests.create')
        ->dispatch('map-point-picked', key: 'pick-details.nearPoint', lat: 45.12345, lng: 9.54321)
        ->assertSet('details.nearPoint.lat', '45.12345')
        ->assertSet('details.nearPoint.lng', '9.54321')
        ->assertSet('details.nearPoint.radius', '2');
});

it('uses the original match-state workflow and records a confirmed proposal in the agenda', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Cliente abbinamenti', 'phone' => '3335550198']]);
    $request = rq_answer($this->crm, $request, 'operation', 'Acquisto');
    $this->actingAs($this->admin->user);
    $property = Property::query()->create([
        'agency_id' => $this->admin->agency_id, 'agent_user_id' => $this->crm->user_id,
        'title' => 'Appartamento abbinato', 'status' => 'Attivo', 'city' => 'Milano',
    ]);
    $match = PropertyMatch::query()->create([
        'agency_id' => $this->admin->agency_id, 'property_id' => $property->id,
        'property_request_id' => $request->id, 'score' => 88, 'status' => 'Nuovo abbinamento',
        'result' => ['score' => 88, 'confidence' => 'Media', 'compared' => 1, 'answered' => 2, 'conditional' => true, 'version' => 1,
            'comparisons' => [['id' => 'budget', 'label' => 'Qual è il budget?', 'classification' => 'Indispensabile', 'status' => 'missing', 'expected' => '100000', 'actual' => '150000']],
            'reasons' => ['Requisito indispensabile non soddisfatto: Qual è il budget?']],
    ]);
    $lowerProperty = Property::query()->create([
        'agency_id' => $this->admin->agency_id, 'agent_user_id' => $this->crm->user_id,
        'title' => 'Immobile sotto soglia', 'status' => 'Attivo', 'city' => 'Milano',
    ]);
    PropertyMatch::query()->create([
        'agency_id' => $this->admin->agency_id, 'property_id' => $lowerProperty->id,
        'property_request_id' => $request->id, 'score' => 55, 'status' => 'Nuovo abbinamento',
    ]);

    $page = Livewire::test('pages::gestionale.requests.show', ['propertyRequest' => $request])
        ->assertSet('activeTab', 'Profilazione')
        ->call('selectTab', 'Abbinamenti')
        ->assertSet('activeTab', 'Abbinamenti')
        ->assertSee('Apri confronto')
        ->set('matchMinimum', '80')
        ->assertSee('Appartamento abbinato')
        ->assertDontSee('Immobile sotto soglia')
        ->set('matchStatus', 'Da valutare')
        ->assertSee('Nessun abbinamento con questi filtri')
        ->set('matchStatus', 'Tutti')
        ->call('openMatchEvidence', $match->id)
        ->assertSee('Cosa non coincide')
        ->assertSee('Perché questo punteggio?')
        ->assertSee('Richiesta:')
        ->assertSee('Valuta abbinamento')
        ->assertSee('Salva stato')
        ->assertSet('matchStateForm.state', 'Nuovo abbinamento')
        ->assertSee('Da valutare')
        ->set('matchStateForm.state', 'Proposto al cliente')
        ->call('saveMatchState')
        ->assertSet('matchStateError', 'Controlla il contenuto e conferma la registrazione della proposta.');

    $page->set('matchStateForm.confirmed', true)
        ->set('matchStateForm.note', 'Proposta verificata in chiamata')
        ->call('saveMatchState')
        ->assertSet('matchStateId', null)
        ->assertSet('matchEvidenceId', null);

    expect($match->fresh()->status)->toBe('Proposto al cliente')
        ->and($request->fresh()->status)->toBe('Immobili proposti')
        ->and(Activity::query()->where('kind', 'Proposta di immobile')->where('property_id', $property->id)->exists())->toBeTrue();
});

it('keeps the matches tab unavailable until the request has an operation', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Cliente scheda', 'phone' => '3335550197']]);
    $this->actingAs($this->admin->user);

    Livewire::test('pages::gestionale.requests.show', ['propertyRequest' => $request])
        ->assertSet('activeTab', 'Profilazione')
        ->call('selectTab', 'Abbinamenti')
        ->assertSet('activeTab', 'Profilazione')
        ->call('selectTab', 'Cronologia')
        ->assertSet('activeTab', 'Cronologia')
        ->assertSee('Cronologia');
});

function rq_counts(): array
{
    return [Contact::query()->withoutGlobalScopes()->count(), ClientProfile::query()->withoutGlobalScopes()->count(),
        PropertyRequest::query()->withoutGlobalScopes()->count(), AuditEvent::query()->count()];
}

function rq_rejected(Closure $command, int $status, string $message): void
{
    try {
        $command();
    } catch (CommandRejected $e) {
        expect($e->status())->toBe($status)->and($e->getMessage())->toContain($message);

        return;
    }
    test()->fail('CommandRejected not thrown: '.$message);
}

function rq_create(AgencyMembership $actor, array $input): PropertyRequest
{
    test()->actingAs($actor->user);

    return app(CreatePropertyRequest::class)->handle($actor, $input);
}

function rq_answer(AgencyMembership $actor, PropertyRequest $request, string $id, mixed $value, array $extra = []): PropertyRequest
{
    test()->actingAs($actor->user);
    $request = $request->fresh();

    return app(AnswerPropertyRequestQuestion::class)->handle($actor, $request,
        ['question_id' => $id, 'value' => $value, 'expected_updated_at' => Commands::revision($request), ...$extra]);
}

function rq_step(AgencyMembership $actor, PropertyRequest $request, string $step): PropertyRequest
{
    test()->actingAs($actor->user);

    return app(StepPropertyRequest::class)->handle($actor, $request->fresh(), $step, Commands::revision($request->fresh()));
}

function rq_finish(AgencyMembership $actor, PropertyRequest $request): PropertyRequest
{
    test()->actingAs($actor->user);

    return app(FinishPropertyRequest::class)->handle($actor, $request->fresh(), Commands::revision($request->fresh()));
}

function rq_new_client(AgencyMembership $actor, array $input): Contact
{
    test()->actingAs($actor->user);

    return app(SaveClient::class)->handle($actor, $input);
}

it('creates a new client and its request atomically, two audit entries (typed new client)', function () {
    $before = rq_counts();
    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'Giulia', 'phone' => '3331112222', 'email' => 'giulia@']]), 400, 'email');
    expect(rq_counts())->toBe($before);

    $request = rq_create($this->crm, ['client' => ['name' => 'Giulia', 'phone' => '3331112222', 'email' => 'giulia@example.it']]);

    expect(rq_counts())->toBe([$before[0] + 1, $before[1] + 1, $before[2] + 1, $before[3] + 2])
        ->and($request->status)->toBe('Nuova')->and($request->title_auto)->toBeTrue()
        ->and($request->title)->toBe('Richiesta di Giulia')->and($request->step_id)->toBe('operation')
        ->and($request->agent_user_id)->toBe($this->crm->user_id)
        ->and(AuditEvent::query()->orderBy('id')->pluck('action')->all())->toBe(['client.save', 'request.save']);

    $request = rq_answer($this->crm, $request, 'operation', 'Acquisto');
    $request = rq_answer($this->crm, $request, 'typology', ['Attico']);
    expect($request->title)->toBe('Attico da acquistare')->and($request->status)->toBe('Da completare');

    $before = rq_counts();
    rq_rejected(fn () => rq_create($this->crm, ['contact_id' => $request->contact_id]), 409, 'già una richiesta');
    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'Altro nome', 'email' => 'giulia@example.it']]), 409, 'Cellulare o email già presenti');
    expect(rq_counts())->toBe($before);
});

it('reuses the client when phone and email match, and blocks a second request for the same identity', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Marco', 'phone' => '+39 333 2223333', 'email' => 'marco@example.it']]);
    $before = rq_counts();

    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'Marco', 'phone' => '0039 333 222 3333', 'email' => 'MARCO@example.it']]), 409, 'già una richiesta');
    rq_rejected(fn () => app(SavePropertyRequest::class)->handle($this->crm, ['contact_id' => $request->contact_id]), 409, 'Questo cliente ha già una richiesta');
    expect(rq_counts())->toBe($before);

    // Answering an existing request creates nothing and touches no other request.
    $other = rq_create($this->crm, ['client' => ['name' => 'Altro', 'phone' => '3339990000']]);
    rq_answer($this->crm, $request, 'operation', 'Locazione');
    expect(PropertyRequest::query()->count())->toBe(2)->and($other->fresh()->criteria)->toBe([]);
});

it('reuses an existing client without a request', function () {
    $client = rq_new_client($this->crm, ['name' => 'Elisa', 'phone' => '3334445555', 'email' => 'elisa@example.it']);
    $before = rq_counts();

    $request = rq_create($this->crm, ['client' => ['name' => 'Elisa', 'phone' => '3334445555', 'email' => 'elisa@example.it']]);

    expect($request->contact_id)->toBe($client->id)->and(Contact::query()->count())->toBe($before[0])
        ->and(PropertyRequest::query()->count())->toBe($before[2] + 1);
});

it('requires verified contacts for homonyms', function () {
    rq_new_client($this->crm, ['name' => 'Paolo Neri', 'phone' => '3335556666', 'email' => 'paolo@example.it']);

    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'paolo  neri', 'phone' => '3330001111', 'email' => 'p2@example.it']]), 409, 'Omonimia');
    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'Paolo Neri', 'phone' => '3330001111'], 'confirm_homonym' => true]), 409, 'Omonimia');

    $request = rq_create($this->crm, ['client' => ['name' => 'Paolo Neri', 'phone' => '3330001111', 'email' => 'p2@example.it'], 'confirm_homonym' => true]);
    expect(Contact::query()->where('name_key', 'paolo neri')->count())->toBe(2)->and($request->exists)->toBeTrue();
});

it('requires verification for partial and conflicting contacts', function () {
    $a = rq_new_client($this->crm, ['name' => 'Cliente A', 'phone' => '3336667777', 'email' => 'a@example.it']);
    rq_new_client($this->crm, ['name' => 'Cliente B', 'phone' => '3338889999', 'email' => 'b@example.it']);

    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'Nuovo', 'phone' => '3336667777', 'confirm_duplicate' => true]]), 409, 'verifica');
    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'Nuovo', 'phone' => '3336667777', 'email' => 'b@example.it']]), 409, 'verifica');
    // Exact match on a client of another crm: 403, without revealing it.
    rq_rejected(fn () => rq_create($this->crm2, ['client' => ['name' => 'Chiunque', 'phone' => '3336667777', 'email' => 'a@example.it']]), 403, 'Record non accessibile con questo ruolo.');

    // A confirmed duplicate card (same phone + email) is the same identity: one request only.
    $copy = rq_new_client($this->crm, ['name' => 'Cliente A bis', 'phone' => '3336667777', 'email' => 'a@example.it', 'confirm_duplicate' => true]);
    rq_create($this->crm, ['contact_id' => $a->id]);
    rq_rejected(fn () => rq_create($this->crm, ['contact_id' => $copy->id]), 409, 'già una richiesta');

    // Two cards with the same contacts and a third typed request → "più schede".
    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'Terzo', 'phone' => '3336667777', 'email' => 'a@example.it']]), 409, 'Gli stessi recapiti sono presenti in più schede');
});

it('ignores legacy priority fields on request.save and accepts any valid status', function () {
    $request = rq_create($this->admin, ['client' => ['name' => 'Prio', 'phone' => '3331230000']]);
    $saved = app(SavePropertyRequest::class)->handle($this->admin, ['priority' => 'Urgente', 'next_action' => 'Chiama', 'due_date' => '2026-12-01',
        'status' => 'In trattativa', 'expected_updated_at' => Commands::revision($request)], $request);

    expect($saved->priority)->toBe('Normale')->and($saved->next_action)->toBeNull()->and($saved->due_date)->toBeNull()
        ->and($saved->status)->toBe('In trattativa');
    rq_rejected(fn () => app(SavePropertyRequest::class)->handle($this->admin, ['status' => 'Chiusa', 'expected_updated_at' => Commands::revision($saved)], $saved), 400, 'Stato richiesta non valido.');
    rq_rejected(fn () => app(SavePropertyRequest::class)->handle($this->admin, ['quick' => [], 'expected_updated_at' => Commands::revision($saved)], $saved), 400, 'La richiesta rapida crea una bozza nuova');
});

it('moves a request to another client of the referent and follows the new referent', function () {
    $request = rq_create($this->admin, ['client' => ['name' => 'Primo', 'phone' => '3331230001']]);
    $other = rq_new_client($this->admin, ['name' => 'Secondo', 'phone' => '3331230002', 'agent_user_id' => $this->crm->user_id]);

    $saved = app(SavePropertyRequest::class)->handle($this->admin, ['contact_id' => $other->id, 'expected_updated_at' => Commands::revision($request)], $request);
    expect($saved->contact_id)->toBe($other->id)->and($saved->agent_user_id)->toBe($this->crm->user_id);
});

it('makes purchase or rent mandatory before anything else, nothing written', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Obbligo', 'phone' => '3331230003']]);
    $before = rq_counts();

    rq_rejected(fn () => rq_answer($this->crm, $request, 'operation', null), 400, 'obbligatoria');
    rq_rejected(fn () => rq_answer($this->crm, $request, 'notes', 'ciao'), 400, 'Scegli prima Acquisto o Locazione');
    rq_rejected(fn () => rq_step($this->crm, $request, 'review'), 400, 'obbligatoria');
    rq_rejected(fn () => rq_finish($this->crm, $request), 400, 'obbligatoria');
    expect(rq_counts())->toBe($before)->and(rq_step($this->crm, $request, 'operation')->step_id)->toBe('operation');
});

it('saves ranges and multiple typologies, rejects inverted ranges', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Range', 'phone' => '3331230004']]);
    rq_answer($this->crm, $request, 'operation', 'Acquisto');
    rq_answer($this->crm, $request, 'budget', ['min' => 200000, 'max' => 350000]);
    rq_answer($this->crm, $request, 'bedrooms', ['min' => 2, 'max' => 3]);
    $request = rq_answer($this->crm, $request, 'typology', ['Appartamento', 'Attico']);

    expect($request->criteria)->toMatchArray(['budget' => ['min' => 200000, 'max' => 350000], 'bedrooms' => ['min' => 2, 'max' => 3], 'typology' => ['Appartamento', 'Attico']]);
    rq_rejected(fn () => rq_answer($this->crm, $request, 'budget', ['min' => 5, 'max' => 1]), 400, 'minimo');
    rq_rejected(fn () => rq_answer($this->crm, $request, 'area', ['min' => 120, 'max' => 90]), 400, 'minimo');
    rq_rejected(fn () => rq_answer($this->crm, $request, 'operation', 'Acquisto', ['classification' => 'Fondamentale']), 400, 'Classificazione non valida.');
});

it('discards stale amounts and typologies when contract or purpose change', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Cambio', 'phone' => '3331230005']]);
    rq_answer($this->crm, $request, 'operation', 'Acquisto');
    rq_answer($this->crm, $request, 'purpose', 'Abitazione principale');
    rq_answer($this->crm, $request, 'budget', ['max' => 300000]);
    rq_answer($this->crm, $request, 'typology', ['Villa']);
    rq_answer($this->crm, $request, 'bedrooms', ['min' => 3]);

    $request = rq_answer($this->crm, $request, 'operation', 'Locazione');
    expect($request->criteria)->not->toHaveKey('budget');

    $request = rq_answer($this->crm, $request, 'purpose', 'Attività commerciale');
    $visible = array_column(Questionnaire::forAgency(null)->visible($request->criteria), 'id');
    expect($request->criteria)->not->toHaveKeys(['bedrooms', 'typology'])
        ->and($visible)->toContain('activity')->not->toContain('mortgage');
});

it('keeps a compatible quick typology on the first purpose answer only', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Rapida', 'phone' => '3331230006'],
        'quick' => ['operation' => 'Acquisto', 'typology' => 'Appartamento', 'zone' => 'Centro', 'budgetMax' => 250000]]);
    expect($request->status)->toBe('Da completare')->and($request->step_id)->toBe('purpose')->and($request->title)->toBe('Appartamento da acquistare')
        ->and($request->criteria['notes'])->toBe('Zona richiesta: Centro (da precisare nella profilazione).');

    $request = rq_answer($this->crm, $request, 'purpose', 'Investimento');
    expect($request->criteria['typology'])->toBe(['Appartamento']);
    $request = rq_answer($this->crm, $request, 'purpose', 'Abitazione principale');
    expect($request->criteria)->not->toHaveKey('typology');
});

it('rejects quick details that need the full profile and never leaves a new client behind', function () {
    $before = rq_counts();
    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'Bozza', 'phone' => '3331230007'],
        'quick' => ['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 0]]), 400, 'budget valido');
    rq_rejected(fn () => rq_create($this->crm, ['client' => ['name' => 'Bozza', 'phone' => '3331230007'],
        'quick' => ['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 1, 'details' => ['purpose' => 'ignota']]]), 400, 'Seleziona una risposta disponibile.');
    expect(rq_counts())->toBe($before);

    $request = rq_create($this->crm, ['client' => ['name' => 'Bar', 'phone' => '3331230008'],
        'quick' => ['operation' => 'Locazione', 'typology' => 'Negozio', 'zone' => 'Porto', 'budgetMax' => 1500,
            'details' => ['purpose' => 'Attività commerciale', 'activity' => 'Bar con dehors']]]);
    expect($request->criteria['activity'])->toBe('Bar con dehors')
        ->and($request->contact->clientProfile->consent_practice)->toBeFalse();
    rq_rejected(fn () => rq_create($this->crm, ['contact_id' => $request->contact_id,
        'quick' => ['operation' => 'Locazione', 'typology' => 'Negozio', 'zone' => 'Porto', 'budgetMax' => 1500]]), 409, 'già una richiesta');
});

it('keeps manual answers editable without changing completeness, and archived answers survive', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Terrazza', 'phone' => '3331230009']]);
    $request = rq_answer($this->crm, $request, 'operation', 'Acquisto');
    $q = Questionnaire::forAgency(null);
    $before = $q->completeness($request->criteria);

    $request = rq_answer($this->crm, $request, 'terrace', true, ['classification' => 'Indispensabile', 'next_step' => 'review']);
    expect($request->step_id)->toBe('review')->and($request->classifications)->toBe(['terrace' => 'Indispensabile'])
        ->and($q->completeness($request->criteria))->toBe($before);

    $request->forceFill(['criteria' => [...$request->criteria, 'capital' => 50000]])->save();
    $request = rq_answer($this->crm, $request, 'area', ['min' => 70]);
    expect($request->criteria['capital'])->toBe(50000);
});

it('rejects answers to questions not pertinent to the path (mortgage estimate)', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Mutuo', 'phone' => '3331230010']]);
    rq_answer($this->crm, $request, 'operation', 'Acquisto');
    rq_answer($this->crm, $request, 'mortgage', true);
    rq_answer($this->crm, $request, 'mortgageState', 'Non ancora');
    rq_rejected(fn () => rq_answer($this->crm, $request, 'mortgageEstimate', ['amount' => 1]), 400, 'pertinente');

    rq_answer($this->crm, $request, 'mortgageState', 'Sì, ho già un’indicazione dell’importo');
    rq_rejected(fn () => rq_answer($this->crm, $request, 'mortgageEstimate', ['percent' => 101]), 400, 'percentuale');
    $request = rq_answer($this->crm, $request, 'mortgageEstimate', ['amount' => 300000, 'percent' => 70]);
    expect($request->criteria['mortgageEstimate'])->toBe(['amount' => 300000, 'percent' => 70]);

    $request = rq_answer($this->crm, $request, 'mortgage', false);
    expect($request->criteria)->not->toHaveKeys(['mortgageState', 'mortgageEstimate']);
});

it('deduplicates tags on answer', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Tag', 'phone' => '3331230011']]);
    rq_answer($this->crm, $request, 'operation', 'Acquisto');
    expect(rq_answer($this->crm, $request, 'tags', ['Arredato', ' arredato ', '#Terrazzo', 'terrazza'])->criteria['tags'])->toBe(['Arredato', 'Terrazzo']);
    rq_rejected(fn () => rq_answer($this->crm, $request, 'tags', [str_repeat('a', 61)]), 400, '60');
});

it('saves, resumes and finishes an incomplete profile; advanced statuses survive finish', function () {
    Event::fake([PropertyRequestCompleted::class, PropertyRequestCriteriaChanged::class]);
    $request = rq_create($this->crm, ['client' => ['name' => 'Wizard', 'phone' => '3331230012']]);
    rq_answer($this->crm, $request, 'operation', 'Acquisto');
    expect(rq_step($this->crm, $request, 'purpose')->fresh()->step_id)->toBe('purpose');

    $finished = rq_finish($this->crm, $request);
    expect($finished->status)->toBe('Da completare')->and($finished->finished)->toBeTrue()->and($finished->step_id)->toBe('review')
        ->and(Questionnaire::forAgency(null)->completeness($finished->criteria)['percent'])->toBeLessThan(100);
    Event::assertNotDispatched(PropertyRequestCompleted::class);

    rq_answer($this->crm, $request, 'purpose', 'Abitazione principale');
    expect(rq_finish($this->crm, $request)->status)->toBe('Ricerca attiva');
    Event::assertDispatched(PropertyRequestCompleted::class);
    Event::assertDispatched(PropertyRequestCriteriaChanged::class);

    $request->fresh()->forceFill(['status' => 'Visita programmata'])->save();
    expect(rq_finish($this->crm, $request)->status)->toBe('Visita programmata');
});

it('requires and checks the revision on request commands (428 / 409), nothing written', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Concorrenza', 'phone' => '3331230013']]);
    $revision = Commands::revision($request);
    $this->actingAs($this->crm->user);

    rq_rejected(fn () => app(AnswerPropertyRequestQuestion::class)->handle($this->crm, $request, ['question_id' => 'operation', 'value' => 'Acquisto']), 428, 'Ricarica la scheda prima di salvare.');
    app(AnswerPropertyRequestQuestion::class)->handle($this->crm, $request, ['question_id' => 'operation', 'value' => 'Acquisto', 'expected_updated_at' => $revision]);
    $before = rq_counts();
    rq_rejected(fn () => app(AnswerPropertyRequestQuestion::class)->handle($this->crm, $request, ['question_id' => 'operation', 'value' => 'Locazione', 'expected_updated_at' => $revision]), 409, 'I dati sono cambiati.');
    rq_rejected(fn () => app(FinishPropertyRequest::class)->handle($this->crm, $request, $revision), 409, 'I dati sono cambiati.');
    expect(rq_counts())->toBe($before)->and($request->fresh()->criteria['operation'])->toBe('Acquisto');
});

it('enforces roles: scout 403 everywhere, crm only on own requests, admin on all', function () {
    $mine = rq_create($this->crm, ['client' => ['name' => 'Mia', 'phone' => '3331230014']]);
    $rev = Commands::revision($mine);

    $this->actingAs($this->scout->user);
    rq_rejected(fn () => app(CreatePropertyRequest::class)->handle($this->scout, ['client' => ['name' => 'S', 'phone' => '3331230015']]), 403, 'Operazione non consentita all’Operatore 1.');
    rq_rejected(fn () => app(AnswerPropertyRequestQuestion::class)->handle($this->scout, $mine, ['question_id' => 'operation', 'value' => 'Acquisto', 'expected_updated_at' => $rev]), 403, 'Operazione non consentita all’Operatore 1.');
    rq_rejected(fn () => app(SavePropertyRequest::class)->handle($this->scout, ['status' => 'Sospesa', 'expected_updated_at' => $rev], $mine), 403, 'Operazione non consentita');

    $this->actingAs($this->crm2->user);
    rq_rejected(fn () => app(AnswerPropertyRequestQuestion::class)->handle($this->crm2, $mine, ['question_id' => 'operation', 'value' => 'Acquisto', 'expected_updated_at' => $rev]), 403, 'Record non accessibile con questo ruolo.');
    rq_rejected(fn () => app(StepPropertyRequest::class)->handle($this->crm2, $mine, 'purpose', $rev), 403, 'Record non accessibile');
    rq_rejected(fn () => app(FinishPropertyRequest::class)->handle($this->crm2, $mine, $rev), 403, 'Record non accessibile');
    rq_rejected(fn () => app(CreatePropertyRequest::class)->handle($this->crm2, ['contact_id' => $mine->contact_id]), 403, 'Record non accessibile');

    expect(rq_answer($this->admin, $mine, 'operation', 'Acquisto')->criteria['operation'])->toBe('Acquisto')
        ->and(PropertyRequest::query()->visibleTo($this->crm)->pluck('id')->all())->toBe([$mine->id])
        ->and(PropertyRequest::query()->visibleTo($this->crm2)->count())->toBe(0)
        ->and(PropertyRequest::query()->visibleTo($this->scout)->count())->toBe(0)
        ->and(PropertyRequest::query()->visibleTo($this->admin)->count())->toBe(1);
});

it('isolates requests across agencies', function () {
    $mine = rq_create($this->admin, ['client' => ['name' => 'Locale', 'phone' => '3331230016']]);
    $foreign = AgencyMembership::factory()->admin()->create();

    $this->actingAs($foreign->user);
    expect(PropertyRequest::query()->count())->toBe(0)->and(PropertyRequest::query()->find($mine->id))->toBeNull();
    rq_rejected(fn () => app(AnswerPropertyRequestQuestion::class)->handle($foreign, $mine, ['question_id' => 'operation', 'value' => 'Acquisto', 'expected_updated_at' => Commands::revision($mine)]), 403, 'Record non accessibile');
    rq_rejected(fn () => app(CreatePropertyRequest::class)->handle($foreign, ['contact_id' => $mine->contact_id]), 403, 'Record non accessibile');

    // Same phone in another agency is not a duplicate there.
    expect(app(CreatePropertyRequest::class)->handle($foreign, ['client' => ['name' => 'Locale', 'phone' => '3331230016']])->agency_id)->toBe($foreign->agency_id);
});

it('archives and restores requests (record.lifecycle)', function () {
    $request = rq_create($this->crm, ['client' => ['name' => 'Archivio', 'phone' => '3331230017']]);
    app(SetRecordLifecycle::class)->handle($this->crm, $request, 'archive');
    expect($request->fresh()->lifecycle_state)->toBe('archived')
        ->and(PropertyRequest::query()->activeRecords()->count())->toBe(0);
    app(SetRecordLifecycle::class)->handle($this->crm, $request->fresh(), 'restore');
    expect($request->fresh()->lifecycle_state)->toBeNull();
});

it('replays request.create with the same idempotency key and refuses different data', function () {
    $input = ['client' => ['name' => 'Idem', 'phone' => '3331230018'], 'idempotency_key' => 'request-create-0001'];
    $first = rq_create($this->crm, $input);
    $again = rq_create($this->crm, $input);

    expect($again->id)->toBe($first->id)->and(PropertyRequest::query()->count())->toBe(1);
    expect(fn () => rq_create($this->crm, [...$input, 'client' => ['name' => 'Altro', 'phone' => '3331230019']]))->toThrow(IdempotencyConflict::class);
});

it('enforces client, agency and referent foreign keys in the database', function () {
    $request = rq_create($this->admin, ['client' => ['name' => 'FK', 'phone' => '3331230020']]);
    $foreign = AgencyMembership::factory()->admin()->create();
    $owner = Contact::factory()->owner()->create(['agency_id' => $this->admin->agency_id]);
    $row = fn (array $over) => [...['agency_id' => $request->agency_id, 'contact_id' => $request->contact_id, 'agent_user_id' => $request->agent_user_id,
        'title' => 'x', 'created_at' => now(), 'updated_at' => now()], ...$over];

    foreach ([['contact_id' => 999999], ['agency_id' => $foreign->agency_id], ['contact_id' => $owner->id], ['agent_user_id' => $foreign->user_id]] as $over) {
        expect(fn () => DB::transaction(fn () => DB::table('property_requests')->insert($row($over))))->toThrow(QueryException::class);
    }
    // No UNIQUE: legacy data may hold two requests per client.
    expect(DB::table('property_requests')->insert($row([])))->toBeTrue();
});
