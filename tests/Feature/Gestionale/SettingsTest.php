<?php

use App\Gestionale\Actions\Settings\SaveMatchingWeights;
use App\Gestionale\Actions\Settings\SaveMembership;
use App\Gestionale\Actions\Settings\SaveQuestionSet;
use App\Gestionale\AgencySettings;
use App\Gestionale\CommandRejected;
use App\Gestionale\Permissions;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\AgencyMembership;
use App\Models\AuditEvent;
use App\Models\ClientProfile;
use App\Models\GestionaleSetting;
use App\Models\QuestionSetVersion;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);
});

it('shows the reserved message instead of a bare 403 to non admins', function () {
    $this->actingAs($this->crm->user)->get(route('gestionale.settings.index'))->assertOk()
        ->assertSee('Configurazione riservata al Responsabile')->assertDontSee('Pesi di compatibilità');
    $this->actingAs($this->scout->user)->get(route('gestionale.settings.index'))->assertOk()->assertSee('Configurazione riservata al Responsabile');
});

it('shows the four tabs and the weights with version 1 to the admin', function () {
    $this->actingAs($this->admin->user)->get(route('gestionale.settings.index'))->assertOk()
        ->assertSee('Abbinamenti')->assertSee('Domande')->assertSee('Utenti e ruoli')->assertSee('Registro operazioni')
        ->assertDontSee('Pulizia esempi')->assertSee('Versione 1')->assertSee('Equilibrato')->assertSee('Budget prima')->assertSee('Zona prima')
        ->assertSee('Totale: 100 / 100');
});

it('keeps presets as drafts and saves only on confirmation, bumping the version', function () {
    $this->actingAs($this->admin->user);
    $page = Livewire::test('pages::gestionale.settings.index')->call('applyPreset', 'Budget prima')
        ->assertSet('weights.budget', 35);
    expect(GestionaleSetting::query()->count())->toBe(0);
    $page->call('restoreWeights')->assertSet('weights.budget', 20)->call('applyPreset', 'Zona prima')->call('saveWeights')->assertHasNoErrors();

    $setting = GestionaleSetting::query()->firstOrFail();
    expect($setting->matching_weights['zone'])->toBe(35)->and($setting->version)->toBe(2)->and($setting->contact_days)->toBe(7);
    $page->assertSee('Versione 2');
    expect(AuditEvent::query()->where('action', 'settings.save')->count())->toBe(1);
});

it('refuses weights that do not sum to 100 or are negative, saving nothing', function () {
    $weights = AgencySettings::WEIGHTS;
    expect(fn () => app(SaveMatchingWeights::class)->handle($this->admin, [...$weights, 'zone' => 21]))
        ->toThrow(CommandRejected::class, 'I pesi devono essere numeri positivi con totale 100.')
        ->and(fn () => app(SaveMatchingWeights::class)->handle($this->admin, [...$weights, 'zone' => -1, 'budget' => 41]))
        ->toThrow(CommandRejected::class);
    expect(GestionaleSetting::query()->count())->toBe(0);
});

it('refuses settings, user and question changes to crm and scout with the old engine messages', function () {
    $weights = AgencySettings::WEIGHTS;
    expect(fn () => app(SaveMatchingWeights::class)->handle($this->crm, $weights))->toThrow(CommandRejected::class, 'Operazione riservata allo scouting o all’Amministratore.')
        ->and(fn () => app(SaveMatchingWeights::class)->handle($this->scout, $weights))->toThrow(CommandRejected::class, 'Operazione non consentita all’Operatore 1.')
        ->and(fn () => app(SaveMembership::class)->handle($this->crm, $this->scout, ['role' => 'admin']))->toThrow(CommandRejected::class)
        ->and(fn () => app(SaveQuestionSet::class)->handle($this->scout, Questionnaire::forAgency($this->admin->agency_id)->questions()))->toThrow(CommandRejected::class);
    $this->actingAs($this->crm->user);
    Livewire::test('pages::gestionale.settings.index')->call('showTab', 'Domande')->assertForbidden();
});

it('blocks non-admin Livewire reads and modal actions in settings', function () {
    $this->actingAs($this->crm->user);
    foreach ([
        ['editUser', [$this->admin->id]], ['closeUser', []], ['saveUser', []],
        ['editQuestion', ['pets']], ['closeQuestion', []], ['newQuestion', []], ['saveQuestion', []],
        ['toggleQuestion', ['pets']], ['restoreWeights', []], ['saveWeights', []], ['applyPreset', ['Equilibrato']],
    ] as [$method, $arguments]) {
        Livewire::test('pages::gestionale.settings.index')->call($method, ...$arguments)->assertForbidden();
    }
});

it('saves the questionnaire as a new agency version and toggles a question', function () {
    $this->actingAs($this->admin->user);
    $page = Livewire::test('pages::gestionale.settings.index')->call('showTab', 'Domande')->assertSee('Questionario configurabile')->assertSee('Nuova domanda');
    $page->call('toggleQuestion', 'pets')->assertHasNoErrors();

    $set = QuestionSetVersion::query()->where('agency_id', $this->admin->agency_id)->firstOrFail();
    expect($set->version)->toBe(1)->and(collect($set->questions)->firstWhere('id', 'pets')['active'])->toBeFalse()
        ->and(count($set->questions))->toBe(count(Questionnaire::defaultQuestions()))
        ->and(Questionnaire::forAgency($this->admin->agency_id)->isActive('pets'))->toBeFalse()
        ->and(GestionaleSetting::query()->first()->version)->toBe(2);
    expect(QuestionSetVersion::query()->whereNull('agency_id')->count())->toBe(1);

    $page->call('toggleQuestion', 'pets');
    expect(QuestionSetVersion::query()->where('agency_id', $this->admin->agency_id)->max('version'))->toBe(2);
});

it('creates and edits a custom question with the original form, and protects sensitive ones', function () {
    $this->actingAs($this->admin->user);
    $page = Livewire::test('pages::gestionale.settings.index')->call('showTab', 'Domande')->call('newQuestion')
        ->assertSee('Salva domanda e ricalcola')->assertSet('form.classification', 'Indispensabile')
        ->set('form.text', 'Vuoi la vista mare?')->set('form.type', 'boolean')->set('form.field', 'balcony')->set('form.bucket', 'preferred')
        ->set('form.conditionField', 'operation')->set('form.conditionValues', 'Acquisto | true')->call('saveQuestion')->assertHasNoErrors()->assertSet('editing', null);

    $saved = collect(Questionnaire::forAgency($this->admin->agency_id)->questions())->firstWhere('text', 'Vuoi la vista mare?');
    expect($saved['id'])->toStartWith('custom-')->and($saved['field'])->toBe('balcony')->and($saved['paths'])->toBe('all')
        ->and($saved['condition'])->toBe(['field' => 'operation', 'values' => ['Acquisto', true]]);

    $page->call('editQuestion', 'capital')->set('form.field', 'price')->call('saveQuestion')->assertHasNoErrors();
    $capital = collect(Questionnaire::forAgency($this->admin->agency_id)->questions())->firstWhere('id', 'capital');
    expect($capital['sensitive'])->toBeTrue()->and($capital)->not->toHaveKey('field');
});

it('validates questions as the old engine does', function () {
    $this->actingAs($this->admin->user);
    $questions = Questionnaire::defaultQuestions();
    $save = fn (array $q) => app(SaveQuestionSet::class)->handle($this->admin, $q);

    expect(fn () => $save(array_values(array_filter($questions, fn ($q) => $q['id'] !== 'operation'))))
        ->toThrow(CommandRejected::class, 'La scelta Acquisto o Locazione è obbligatoria e non può essere rimossa.')
        ->and(fn () => $save([...$questions, $questions[0]]))->toThrow(CommandRejected::class, 'Controlla le domande: identificativi unici e pesi positivi.')
        ->and(fn () => $save(array_map(fn ($q) => $q['id'] === 'pets' ? [...$q, 'weight' => 0] : $q, $questions)))->toThrow(CommandRejected::class, 'Controlla le domande')
        ->and(fn () => $save(array_map(fn ($q) => $q['id'] === 'pets' ? [...$q, 'type' => 'xx'] : $q, $questions)))->toThrow(CommandRejected::class, 'Configurazione della domanda non valida.')
        ->and(fn () => $save(array_map(fn ($q) => $q['id'] === 'pets' ? [...$q, 'field' => 'name'] : $q, $questions)))->toThrow(CommandRejected::class, 'Il campo deve essere una caratteristica immobiliare già prevista, non un dato personale.')
        ->and(fn () => $save(array_map(fn ($q) => $q['id'] === 'pets' ? [...$q, 'bucket' => 'nope'] : $q, $questions)))->toThrow(CommandRejected::class, 'Scegli un gruppo di punteggio valido.');
    expect(QuestionSetVersion::query()->whereNotNull('agency_id')->count())->toBe(0);

    $operation = fn () => collect($save(array_map(fn ($q) => $q['id'] === 'operation' ? [...$q, 'active' => false, 'type' => 'text', 'skippable' => true] : $q, $questions)))->firstWhere('id', 'operation');
    expect($operation())->toMatchArray(['active' => true, 'type' => 'single', 'skippable' => false, 'options' => ['Acquisto', 'Locazione']]);
});

it('edits a member: role, active, group, branch, permissions and catalog package', function () {
    $this->actingAs($this->admin->user);
    $page = Livewire::test('pages::gestionale.settings.index')->call('showTab', 'Utenti e ruoli')
        ->assertSee($this->crm->user->name)->assertSee('Segreteria · Attivo')->assertSee('Agente acquisizioni · Attivo')
        ->call('editUser', $this->crm->id)->assertSee('Importa da Sister')->assertSee('Acquisizione dal catalogo · pacchetto')
        ->set('userForm.group', 'Nord')->set('userForm.branch', 'Milano')->set('userForm.permissions', ['sister.import', 'exports'])
        ->set('userForm.packageName', 'Base')->set('userForm.packageEnabled', true)->set('userForm.packageLimit', 50)
        ->call('saveUser')->assertHasNoErrors();

    $crm = $this->crm->fresh();
    expect($crm->group_name)->toBe('Nord')->and($crm->branch_name)->toBe('Milano')->and($crm->permissions)->toBe(['sister.import', 'exports'])
        ->and($crm->catalog_package)->toEqual(['name' => 'Base', 'enabled' => true, 'maxParcels' => 50, 'unlimited' => false])
        // permissions of the old engine: never sister.import for crm, even if saved
        ->and($crm->allows('sister.import'))->toBeFalse()->and($crm->allows('exports'))->toBeTrue()->and($crm->allows('activities.assign'))->toBeFalse();

    $page->call('editUser', $this->scout->id)->set('userForm.active', false)->call('saveUser')->assertHasNoErrors();
    expect($this->scout->fresh()->isActive())->toBeFalse();
    expect(AuditEvent::query()->where('action', 'user.save')->count())->toBe(2);
});

it('refuses a package without limit, an invalid permission and self demotion', function () {
    $save = fn (AgencyMembership $t, array $d) => app(SaveMembership::class)->handle($this->admin, $t, $d);
    expect(fn () => $save($this->crm, ['catalogPackage' => ['name' => 'X', 'enabled' => true, 'maxParcels' => 0, 'unlimited' => false]]))
        ->toThrow(CommandRejected::class, 'Indica il nome e un limite valido, oppure scegli “Senza limite complessivo”.')
        ->and(fn () => $save($this->crm, ['permissions' => ['root']]))->toThrow(CommandRejected::class, 'Permessi non validi.')
        ->and(fn () => $save($this->admin, ['role' => 'crm']))->toThrow(CommandRejected::class, 'Non puoi rimuovere i tuoi permessi amministrativi da questa sessione.')
        ->and(fn () => $save($this->admin, ['active' => false]))->toThrow(CommandRejected::class, 'Non puoi rimuovere i tuoi permessi amministrativi da questa sessione.')
        ->and(fn () => $save(AgencyMembership::factory()->crm()->create(), ['role' => 'scout']))->toThrow(CommandRejected::class);
    $save($this->crm, ['catalogPackage' => ['name' => 'Tutto', 'enabled' => true, 'maxParcels' => 9, 'unlimited' => true]]);
    expect($this->crm->fresh()->catalog_package)->toEqual(['name' => 'Tutto', 'enabled' => true, 'maxParcels' => 0, 'unlimited' => true]);
});

it('lists the audit with the operation label and before/after when present', function () {
    $this->actingAs($this->admin->user);
    app(\App\Gestionale\Audit::class)->record('client.save', null, ['before' => ['n' => 1], 'after' => ['n' => 2]], 'Correzione');
    Livewire::test('pages::gestionale.settings.index')->call('showTab', 'Registro operazioni')
        ->assertSee('Traccia delle modifiche')->assertSee('Scheda cliente salvata')->assertSee('Riferimento tecnico')->assertSee('client.save')
        ->assertSee('Correzione')->assertSee('Prima e dopo la correzione')->assertDontSee('Pulizia esempi');
    expect(AuditEvent::query()->where('action', 'audit.view')->count())->toBe(1);
});

it('applies the optional permissions through the gate and the helper', function () {
    $this->actingAs($this->crm->user);
    expect(Gate::allows('agency-permission', 'activities.assign'))->toBeTrue()->and(Gate::allows('agency-permission', 'owner.edit'))->toBeFalse();
    $this->crm->forceFill(['permissions' => []])->save();
    app(\App\Gestionale\CurrentAgency::class)->forget();
    expect(Gate::allows('agency-permission', 'exports'))->toBeFalse()
        ->and(fn () => Permissions::require($this->crm->fresh(), 'exports'))->toThrow(CommandRejected::class, 'Esportazione non autorizzata.')
        ->and(fn () => Permissions::require($this->crm->fresh(), 'activities.share'))->toThrow(CommandRejected::class, 'Condivisione non consentita.');
    Permissions::require($this->admin, 'owner.edit');
});

it('reads contact_days saved by the agency, not the env default', function () {
    $this->actingAs($this->admin->user);
    expect(AgencySettings::contactDays())->toBe(7);
    GestionaleSetting::query()->create(['contact_days' => 2, 'retention_days' => 30, 'version' => 2]);
    expect(AgencySettings::contactDays())->toBe(2)->and(AgencySettings::retentionDays())->toBe(30);

    $contact = \App\Models\Contact::factory()->create(['agency_id' => $this->admin->agency_id]);
    $profile = ClientProfile::factory()->agent($this->crm)->create(['contact_id' => $contact->id, 'status' => 'Attivo']);
    $profile->forceFill(['created_at' => now()->subDays(3)])->save();
    expect(ClientProfile::query()->needsCallback()->count())->toBe(1)
        ->and(ClientProfile::query()->needsCallback(5)->count())->toBe(0);
});
