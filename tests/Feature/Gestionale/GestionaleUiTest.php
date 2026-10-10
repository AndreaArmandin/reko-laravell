<?php

use App\Gestionale\Actions\Clients\SaveClient;
use App\Gestionale\Actions\Requests\CreatePropertyRequest;
use App\Gestionale\Actions\Properties\SaveProperty;
use App\Gestionale\CurrentAgency;
use App\Gestionale\Navigation;
use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\PropertyRequest;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->crm2 = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);
});

function uiClient(AgencyMembership $actor, string $name, string $phone): Contact
{
    test()->actingAs($actor->user);

    return app(SaveClient::class)->handle($actor, ['name' => $name, 'phone' => $phone]);
}

describe('entry points', function () {
    it('resolves the property agent relation used by the latest listings widget', function () {
        $this->actingAs($this->admin->user);
        app(SaveProperty::class)->handle($this->admin, [
            'title' => 'Immobile demo relazione agente',
            'address' => 'Via Fittizia 1',
            'city' => 'Comune Demo',
            'agent_user_id' => $this->crm->user_id,
            'status' => 'Attivo',
            'features' => ['operation' => 'Acquisto'],
        ]);

        $this->get(route('gestionale.home', ['completo' => 1]))->assertOk()->assertSee('Immobile demo relazione agente');
    });

    it('points the Trova home button to the gestionale, not to /admin', function () {
        $this->get(route('home'))->assertOk()->assertSee(route('gestionale.home'), false)->assertDontSee('href="'.route('dashboard').'">Apri Gestionale', false);
        $this->get(route('gestionale.home'))->assertRedirect(route('login'));
    });

    it('shows a clear message to users without membership, platform admins included', function () {
        foreach ([User::factory()->create(), User::factory()->create(['is_admin' => true])] as $user) {
            $this->actingAs($user)->get(route('gestionale.home'))->assertRedirect(route('gestionale.choose'));
            $this->actingAs($user)->get(route('gestionale.choose'))->assertOk()
                ->assertSee('Il tuo account non è collegato a nessuna agenzia attiva');
        }
    });

    it('opens the only agency directly and asks to choose among several', function () {
        $this->actingAs($this->crm->user)->get(route('gestionale.home'))->assertOk()->assertSee('Oggi');

        $second = AgencyMembership::factory()->admin()->create(['user_id' => $this->crm->user_id]);
        app(CurrentAgency::class)->forget();
        $this->actingAs($this->crm->user)->get(route('gestionale.home'))->assertRedirect(route('gestionale.choose'));
        $this->get(route('gestionale.choose'))->assertSee($this->crm->agency->name)->assertSee($second->agency->name);

        $this->post(route('gestionale.enter', $second->agency_id))->assertRedirect(route('gestionale.profile'));
        expect(session(CurrentAgency::SESSION_KEY))->toBe($second->agency_id);
        $this->get(route('gestionale.home'))->assertOk()->assertSee($second->agency->name);
    });

    it('chooses the agency from the Livewire picker, only among own active memberships', function () {
        $second = AgencyMembership::factory()->admin()->create(['user_id' => $this->crm->user_id]);
        $foreign = Agency::factory()->create();
        $this->actingAs($this->crm->user);

        Livewire::test('pages::gestionale.choose')->call('choose', $second->agency_id)->assertRedirect(route('gestionale.profile'));
        Livewire::test('pages::gestionale.choose')->call('choose', $foreign->id)->assertForbidden();
    });

    it('refuses to enter an agency of which the user is not an active member', function () {
        $foreign = Agency::factory()->create();
        $this->actingAs($this->crm->user)->post(route('gestionale.enter', $foreign->id))
            ->assertRedirect(route('gestionale.choose'))->assertSessionHas('gestionale.error');

        $this->crm->forceFill(['deactivated_at' => now()])->save();
        $this->post(route('gestionale.enter', $this->crm->agency_id))->assertRedirect(route('gestionale.choose'));
    });

    it('shows the user agency and links into the gestionale from the account dashboard', function () {
        $this->actingAs($this->crm->user)->get(route('dashboard'))->assertOk()
            ->assertSee(route('gestionale.enter', $this->crm->agency_id), false)->assertSee($this->crm->agency->name);
    });
});

describe('navigation', function () {
    it('shows the current gestionale sections per role, with Italian labels', function () {
        $labels = fn (AgencyMembership $m) => array_column(Navigation::items($m), 'label');

        expect($labels($this->admin))->toBe(['Oggi', 'Mappa e zone', 'Archivio catastale', 'Immobili a portafoglio', 'Clienti', 'Richieste', 'Agenda', 'Obiettivi', 'Impostazioni'])
            ->and($labels($this->crm))->toBe(['Oggi', 'Immobili a portafoglio', 'Clienti', 'Richieste', 'Agenda', 'Obiettivi'])
            ->and($labels($this->scout))->toBe(['Oggi', 'Mappa e zone', 'Archivio catastale', 'Agenda', 'Obiettivi'])
            ->and(Navigation::sectionsFor($this->crm))->toContain('Investitori')
            ->and(Navigation::sectionsFor($this->scout))->not->toContain('Clienti', 'Investitori', 'Richieste');
    });

    it('renders the sidebar and placeholders only for allowed sections', function () {
        $this->actingAs($this->scout->user)->get(route('gestionale.home'))->assertOk()
            ->assertSee('Mappa e zone')->assertDontSee('Ricerche dei clienti');
        $this->get(route('gestionale.section', 'mappa-e-zone'))->assertOk()->assertSee('prossime fasi');
        $this->get(route('gestionale.section', 'impostazioni'))->assertNotFound();

        $this->actingAs($this->crm->user)->get(route('gestionale.section', 'archivio-catastale'))->assertNotFound();
        $this->actingAs($this->admin->user)->get(route('gestionale.section', 'impostazioni'))->assertOk();
    });
});

describe('clients screens', function () {
    it('keeps explicitly marked test clients out of the main list and makes them selectable', function () {
        uiClient($this->crm, 'Cliente Demo Verifica CRM', '3331112222');
        uiClient($this->crm, 'Cliente reale di prova', '3332223333');
        $this->actingAs($this->crm->user);

        Livewire::test('pages::gestionale.clients.index')
            ->assertSee('Cliente reale di prova')->assertDontSee('Cliente Demo Verifica CRM')
            ->set('tests', 'test')->assertSee('Cliente Demo Verifica CRM')->assertDontSee('Cliente reale di prova')
            ->assertSee('TEST · dati fittizi');
    });

    it('lists only visible clients with search and filters', function () {
        $mine = uiClient($this->crm, 'Mario Rossi', '3331112222');
        uiClient($this->crm2, 'Lucia Verdi', '3332223333');

        $this->actingAs($this->crm->user)->get(route('gestionale.clients.index'))->assertOk()->assertSee('Mario Rossi')->assertDontSee('Lucia Verdi');
        $this->actingAs($this->admin->user)->get(route('gestionale.clients.index'))->assertSee('Mario Rossi')->assertSee('Lucia Verdi');

        Livewire::test('pages::gestionale.clients.index')->set('search', '2223333')->assertSee('Lucia Verdi')->assertDontSee('Mario Rossi')
            ->set('search', '')->set('status', 'Da contattare')->assertDontSee('Mario Rossi');

        $mine->clientProfile->forceFill(['status' => 'Da contattare'])->save();
        Livewire::test('pages::gestionale.clients.index', ['filter' => 'richiamare'])->assertSee('Mario Rossi')->assertDontSee('Lucia Verdi');
    });

    it('denies the clients screens to scouts', function () {
        $client = uiClient($this->crm, 'Mario', '3331112222');
        $this->actingAs($this->scout->user);
        $this->get(route('gestionale.clients.index'))->assertForbidden();
        $this->get(route('gestionale.clients.create'))->assertForbidden();
        $this->get(route('gestionale.clients.show', $client))->assertForbidden();
        $this->get(route('gestionale.requests.index'))->assertForbidden();
        $this->get(route('gestionale.requests.create'))->assertForbidden();
    });

    it('creates a client from the form and opens the card', function () {
        $this->actingAs($this->crm->user);
        Livewire::test('pages::gestionale.clients.form')
            ->set('name', 'Nuova Cliente')->set('phone', '3339998888')->set('email', 'nuova@example.it')
            ->call('save')->assertHasNoErrors()->assertDispatched('crm-notice');

        $client = Contact::query()->where('display_name', 'Nuova Cliente')->firstOrFail();
        expect($client->clientProfile->agent_user_id)->toBe($this->crm->user_id);
        $this->get(route('gestionale.clients.show', $client))->assertOk()->assertSee('Nuova Cliente')->assertSee('3339998888')->assertSee($this->crm->user->name);
    });

    it('shows gestionale messages, duplicate hints and the explicit distinct confirmation', function () {
        uiClient($this->crm2, 'Lucia Verdi', '3332223333');
        $this->actingAs($this->crm->user);

        Livewire::test('pages::gestionale.clients.form')->set('name', '')->set('phone', '333')->call('save')
            ->assertHasErrors(['name' => 'Inserisci il nome del cliente.']);

        $form = Livewire::test('pages::gestionale.clients.form')->set('name', 'Lucia V.')->set('phone', '+39 333 2223333')
            ->assertSee('Già presente in')->assertSee('un’altra scheda dell’agenzia')->assertDontSee('Lucia Verdi')
            ->call('save')->assertHasErrors(['duplicate']);
        $form->set('confirm_duplicate', true)->call('save')->assertHasNoErrors();
        expect(Contact::query()->withoutGlobalScopes()->count())->toBe(2);
    });

    it('edits a client with the revision and refuses stale forms', function () {
        $client = uiClient($this->crm, 'Mario', '3331112222');
        $this->actingAs($this->crm->user);

        $stale = Livewire::test('pages::gestionale.clients.form', ['contact' => $client]);
        Livewire::test('pages::gestionale.clients.form', ['contact' => $client])->set('notes', 'Prima modifica')->call('save')->assertHasNoErrors();
        $stale->set('notes', 'Seconda modifica')->call('save')->assertHasErrors(['command' => 'I dati sono cambiati. Aggiorna la vista e riprova.'])
            ->assertDispatched('crm-notice');

        expect($client->clientProfile->fresh()->notes)->toBe('Prima modifica');
        $this->actingAs($this->crm2->user)->get(route('gestionale.clients.edit', $client))->assertForbidden();
    });

    it('archives and restores from the card', function () {
        $client = uiClient($this->crm, 'Mario', '3331112222');
        $this->actingAs($this->crm->user);

        Livewire::test('pages::gestionale.clients.show', ['contact' => $client])->call('lifecycle', 'archive')->assertSee('Archiviato');
        $this->get(route('gestionale.clients.index'))->assertSee('Archiviati e rimossi · 1');
        Livewire::test('pages::gestionale.clients.show', ['contact' => $client])->call('lifecycle', 'remove')
            ->assertHasErrors(['command' => 'La rimozione dalla lista richiede la conferma del Responsabile.']);
        Livewire::test('pages::gestionale.clients.index')->call('restore', $client->id);
        expect($client->clientProfile->fresh()->lifecycle_state)->toBeNull();
    });

    it('never resolves a client of another agency', function () {
        $client = uiClient($this->crm, 'Mario', '3331112222');
        $foreign = AgencyMembership::factory()->admin()->create();
        $this->actingAs($foreign->user)->get(route('gestionale.clients.show', $client))->assertNotFound();
        $this->get(route('gestionale.clients.edit', $client))->assertNotFound();
    });
});

describe('request screens', function () {
    it('filters test requests by the explicit marker on their client', function () {
        $demo = uiClient($this->crm, 'Cliente Demo Verifica CRM', '3331112222');
        $ordinary = uiClient($this->crm, 'Cliente reale di prova', '3332223333');
        app(CreatePropertyRequest::class)->handle($this->crm, ['contact_id' => $demo->id]);
        app(CreatePropertyRequest::class)->handle($this->crm, ['contact_id' => $ordinary->id]);
        $this->actingAs($this->crm->user);

        Livewire::test('pages::gestionale.requests.index')
            ->assertSee('Cliente reale di prova')->assertDontSee('TEST · dati fittizi')
            ->set('tests', 'test')->assertSee('TEST · dati fittizi');
    });

    it('creates a quick request for a new client and opens the profiling', function () {
        $this->actingAs($this->crm->user);
        Livewire::test('pages::gestionale.requests.create')
            ->set('clientName', 'Giulia Bianchi')->set('phone', '3335554444')->set('email', 'giulia@example.it')
            ->set('operation', 'Acquisto')->set('typology', 'Attico')->set('zone', 'Centro storico')->set('budgetMax', '350.000')
            ->call('save')->assertHasNoErrors();

        $request = PropertyRequest::query()->firstOrFail();
        expect($request->criteria['budget'])->toBe(['max' => 350000])->and($request->title)->toBe('Attico da acquistare')
            ->and($request->status)->toBe('Da completare');
        $this->get(route('gestionale.requests.show', $request))->assertOk()->assertSee('Profilazione rapida')->assertSee('Le esigenze del cliente.');
        $this->get(route('gestionale.requests.index'))->assertOk()->assertSee('Attico da acquistare')->assertSee('Giulia Bianchi')->assertSee('fino a 350.000 €');
    });

    it('preselects the client, shows the existing request and blocks a second one', function () {
        $client = uiClient($this->crm, 'Mario Rossi', '3331112222');
        $this->actingAs($this->crm->user);
        $request = app(CreatePropertyRequest::class)->handle($this->crm, ['contact_id' => $client->id]);

        Livewire::test('pages::gestionale.requests.create', ['contactId' => $client->id])
            ->assertSee('Cliente già in archivio · nessun doppione')->assertSee('Il cliente ha già una richiesta.')->assertSee('Apri '.$request->title)
            ->set('operation', 'Acquisto')->set('typology', 'Villa')->set('zone', 'X')->set('budgetMax', '1')
            ->call('save')->assertHasErrors(['contact_id']);
        expect(PropertyRequest::query()->count())->toBe(1);
    });

    it('shows quick request errors with the gestionale messages and saves nothing', function () {
        $this->actingAs($this->crm->user);
        Livewire::test('pages::gestionale.requests.create')
            ->set('clientName', 'Nuovo')->set('phone', '3335554444')
            ->set('operation', 'Acquisto')->set('typology', 'Villa')->set('zone', 'X')
            ->call('save')->assertHasErrors(['quick' => 'Indica almeno un limite del budget.']);
        expect(Contact::query()->count())->toBe(0);
    });

    it('autosaves answers in the wizard, advances and finishes', function () {
        $client = uiClient($this->crm, 'Wizard', '3331112222');
        $this->actingAs($this->crm->user);
        $request = app(CreatePropertyRequest::class)->handle($this->crm, ['contact_id' => $client->id]);

        $page = Livewire::test('pages::gestionale.requests.show', ['propertyRequest' => $request])
            ->assertSee('Passo 1 di 5')->assertSee('Risposta obbligatoria')
            ->set('answers.operation', 'Locazione')->assertHasNoErrors()
            ->set('answers.purpose', 'Abitazione principale')
            ->call('advance')->assertSee('Passo 2 di 5');
        expect($request->fresh()->criteria)->toEqual(['operation' => 'Locazione', 'purpose' => 'Abitazione principale'])
            ->and($request->fresh()->step_id)->toBe('typology');

        $page->set('answers.typology', ['Appartamento'])->call('advance')
            ->set('answers.budget.max', '1200')->assertHasNoErrors()
            ->set('answers.area.min', '90')->set('answers.area.max', '60')->assertHasErrors(['answers.area']);
        expect($page->errors()->first('answers.area'))->toBe('Controlla l’intervallo: il minimo non può superare il massimo.');
        expect($request->fresh()->criteria['budget'])->toBe(['max' => 1200])->and($request->fresh()->title)->toBe('Appartamento in affitto');

        $page->call('clearAnswer', 'budget');
        expect($request->fresh()->criteria['budget'] ?? null)->toBeNull(); // "Dato non ancora noto" stores null, as in the gestionale

        $page->call('goStep', 'notes')->call('advance')->assertSee('Riepilogo delle preferenze');
        expect($request->fresh()->status)->toBe('Ricerca attiva')->and($request->fresh()->finished)->toBeTrue();
    });

    it('lets crm only read nothing of others and admin manage status from the dialog', function () {
        $client = uiClient($this->crm, 'Mario', '3331112222');
        $this->actingAs($this->crm->user);
        $request = app(CreatePropertyRequest::class)->handle($this->crm, ['contact_id' => $client->id]);

        $this->actingAs($this->crm2->user)->get(route('gestionale.requests.show', $request))->assertForbidden();

        $this->actingAs($this->admin->user);
        Livewire::test('pages::gestionale.requests.show', ['propertyRequest' => $request])->set('manageStatus', 'Sospesa')->call('manage')->assertHasNoErrors();
        expect($request->fresh()->status)->toBe('Sospesa');
    });

    it('never resolves a request of another agency', function () {
        $client = uiClient($this->crm, 'Mario', '3331112222');
        $request = app(CreatePropertyRequest::class)->handle($this->crm, ['contact_id' => $client->id]);
        $foreign = AgencyMembership::factory()->admin()->create();
        $this->actingAs($foreign->user)->get(route('gestionale.requests.show', $request))->assertNotFound();
    });
});
