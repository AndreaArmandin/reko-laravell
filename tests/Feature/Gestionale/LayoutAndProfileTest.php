<?php

use App\Gestionale\Actions\Notifications\MarkNotificationRead;
use App\Gestionale\Activities\Notifier;
use App\Gestionale\CommandRejected;
use App\Gestionale\CurrentAgency;
use App\Gestionale\Navigation;
use App\Gestionale\WorkProfile;
use App\Models\AgencyMembership;
use App\Models\AuditEvent;
use App\Models\GestionaleNotification;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);
});

it('renders the gestionale home with the original shell for every role', function () {
    foreach ([$this->admin, $this->crm, $this->scout] as $membership) {
        $this->actingAs($membership->user)->get(route('gestionale.home'))->assertOk()
            ->assertSee('Vai al contenuto')->assertSee('Chiudi menu Gestionale')->assertSee('crm-drawer-close', false)
            ->assertSee('Accesso personale riservato')->assertSee('Trova immobili')->assertSee('Torna alla home')
            ->assertSee('Cambia profilo')->assertSee('Le tue notifiche')->assertSee('Nessuna notifica.')
            ->assertSee('crm-save-feedback', false)
            ->assertSee(WorkProfile::label($membership->role))->assertSee('Dati e ricerche distinti per Comune')
            ->assertSee('Uso interno dell’agenzia');
    }
});

it('opens the role-specific Oggi preview and shows the full dashboard on request', function () {
    $this->actingAs($this->admin->user)->get(route('gestionale.home'))
        ->assertOk()->assertSee('Oggi, azioni del tuo ruolo', false)
        ->assertSee('Anteprima ridotta: apri la sezione per tutte le voci.')
        ->assertSee('Apri Oggi completo e il layout personalizzato')
        ->assertDontSee('La tua area di lavoro');

    Livewire::test('pages::gestionale.home')
        ->assertSet('fullOverview', false)
        ->call('openFullOverview')
        ->assertSet('fullOverview', true)
        ->assertSee('La tua area di lavoro')
        ->assertDontSee('Nuovo promemoria')
        ->assertSee('Nuovo cliente')
        ->assertDontSee('Nuova richiesta')
        ->assertDontSee('Ripeti ogni');

    $this->get(route('gestionale.home', ['filtro' => 'approvals']))
        ->assertOk()->assertSee('Da approvare')->assertSee('La tua area di lavoro');
});

it('shows Vedi come… and the settings shortcut only to the Responsabile', function () {
    $this->actingAs($this->admin->user)->get(route('gestionale.home'))->assertSee('Vedi come…')->assertSee('Impostazioni CRM');
    $this->actingAs($this->crm->user)->get(route('gestionale.home'))->assertDontSee('Vedi come…')->assertDontSee('Impostazioni CRM');
});

it('renders the settings and the other sections without icon errors', function () {
    $this->actingAs($this->admin->user);
    foreach (['gestionale.settings.index', 'gestionale.clients.index', 'gestionale.requests.index', 'gestionale.properties.index', 'gestionale.activities.index'] as $name) {
        $this->get(route($name))->assertOk();
    }
});

it('shows the role gate message for a section the role cannot open, with 403', function () {
    $this->actingAs($this->scout->user)->get(route('gestionale.clients.index'))->assertForbidden()
        ->assertSee('Questa sezione non è accessibile con il tuo ruolo.', false);
});

it('lets only an admin narrow the work profile, and never widen it', function () {
    $this->actingAs($this->admin->user);
    $this->get(route('gestionale.profile'))->assertOk()->assertSee('Come vuoi lavorare nel Gestionale?')
        ->assertSee('Responsabile')->assertSee('Segreteria')->assertSee('Agente acquisizioni')->assertSee('Squadra, obiettivi e coordinamento');

    request()->setLaravelSession(app('session.store'));
    app(CurrentAgency::class)->forget();
    $membership = app(CurrentAgency::class)->chooseWorkProfile('scout');
    expect($membership->role)->toBe('scout')->and($membership->actualRole())->toBe('admin')->and($membership->isDirty())->toBeFalse()
        ->and(array_column(Navigation::items($membership), 'label'))->not->toContain('Clienti')->not->toContain('Impostazioni')
        ->and(app(CurrentAgency::class)->workProfile())->toBe('scout')
        ->and($this->admin->fresh()->role)->toBe('admin');

    $key = CurrentAgency::PROFILE_KEY.'.'.$this->admin->agency_id;
    $this->withSession([$key => 'scout']);
    $this->get(route('gestionale.clients.index'))->assertForbidden();
    $this->get(route('gestionale.home'))->assertOk()->assertDontSee('Vedi come…')->assertSee('Agente acquisizioni');

    app(CurrentAgency::class)->clearWorkProfile();
    expect(app(CurrentAgency::class)->membership()->role)->toBe('admin');

    $this->actingAs($this->crm->user);
    app(CurrentAgency::class)->forget();
    $this->get(route('gestionale.profile'))->assertSee('Segreteria')->assertDontSee('Agente acquisizioni');
    expect(fn () => app(CurrentAgency::class)->chooseWorkProfile('admin'))->toThrow(CommandRejected::class, 'Profilo non consentito per questo account.')
        ->and(fn () => WorkProfile::apply($this->admin, 'boss'))->toThrow(CommandRejected::class, 'Profilo non valido.');
});

it('requires a work profile before opening Gestionale sections and returns to the requested allowed page', function () {
    $this->actingAs($this->admin->user)->withoutGestionaleProfile($this->admin)
        ->get(route('gestionale.clients.index'))
        ->assertRedirect(route('gestionale.profile'));

    expect(session('gestionale.profile_intended.route'))->toBe('gestionale.clients.index');

    $profileKey = CurrentAgency::PROFILE_KEY.'.'.$this->admin->agency_id;
    Livewire::test('pages::gestionale.profile')
        ->call('choose', 'admin')
        ->assertRedirect(route('gestionale.clients.index'));

    // Carry the profile chosen by the synthetic Livewire request into its follow-up GET.
    $this->withSession([$profileKey => 'admin']);
    app(CurrentAgency::class)->forget(); // PHPUnit reuses the container; a browser request gets a fresh scoped service.
    $this->get(route('gestionale.clients.index'))->assertOk();
});

it('requires a new work profile when the saved profile no longer matches the membership role', function () {
    $key = CurrentAgency::PROFILE_KEY.'.'.$this->admin->agency_id;
    $this->actingAs($this->admin->user)->withSession([$key => 'scout'])
        ->get(route('gestionale.home'))->assertOk();

    $this->admin->forceFill(['role' => 'crm'])->save();
    app(CurrentAgency::class)->forget();

    $this->get(route('gestionale.home'))->assertRedirect(route('gestionale.profile'));
});

it('clears the saved profile when the user chooses Cambia profilo', function () {
    $key = CurrentAgency::PROFILE_KEY.'.'.$this->admin->agency_id;
    $this->actingAs($this->admin->user)->withSession([$key => 'scout'])
        ->post(route('gestionale.profile.clear'))
        ->assertRedirect(route('gestionale.profile'));

    expect(session($key))->toBeNull();
    $this->get(route('gestionale.home'))->assertRedirect(route('gestionale.profile'));
});

it('sends the first entry of the session to the profile choice, then straight to Oggi', function () {
    $this->actingAs($this->crm->user)->withoutGestionaleProfile($this->crm)
        ->post(route('gestionale.enter', $this->crm->agency_id))->assertRedirect(route('gestionale.profile'));
    $this->withSession([CurrentAgency::PROFILE_KEY.'.'.$this->crm->agency_id => 'crm']);
    $this->post(route('gestionale.enter', $this->crm->agency_id))->assertRedirect(route('gestionale.home'));
});

it('lists the notifications in the bell and marks only your own as read', function () {
    $this->actingAs($this->crm->user);
    $mine = app(CurrentAgency::class)->runAs($this->crm->agency, fn () => Notifier::notify($this->crm->user_id, 'Nuova attività assegnata'));
    $theirs = app(CurrentAgency::class)->runAs($this->crm->agency, fn () => Notifier::notify($this->scout->user_id, 'Attività condivisa'));

    $this->get(route('gestionale.home'))->assertSee('Nuova attività assegnata')->assertSee('Nuova')->assertDontSee('Attività condivisa')
        ->assertSee('Notifiche, 1 da leggere');

    $this->post(route('gestionale.notifications.read', $mine->id))->assertRedirect();
    expect($mine->fresh()->read_at)->not->toBeNull();
    $this->get(route('gestionale.home'))->assertSee('Letta')->assertSee('Notifiche, 0 da leggere');
    expect(AuditEvent::query()->where('action', 'notification.read')->count())->toBe(1);

    $this->post(route('gestionale.notifications.read', $theirs->id))->assertForbidden();
    expect($theirs->fresh()->read_at)->toBeNull();
    expect(fn () => app(MarkNotificationRead::class)->handle($this->crm, $theirs->fresh()))->toThrow(CommandRejected::class, 'Notifica non accessibile.');
});

it('opens the activity named by a notification after marking it read', function () {
    $this->actingAs($this->admin->user);
    $activity = app(\App\Gestionale\Actions\Activities\CreateActivity::class)->handle($this->admin, [
        'type' => 'Telefonata', 'title' => 'Richiamare per la visita', 'due_at' => now()->addDay()->toDateTimeString(),
        'assigned_to_user_id' => $this->crm->user_id,
    ]);
    $notice = app(CurrentAgency::class)->runAs($this->admin->agency, fn () => Notifier::notify($this->crm->user_id, 'Attività assegnata', $activity->id));

    $this->actingAs($this->crm->user)->post(route('gestionale.notifications.read', $notice->id))
        ->assertRedirect(route('gestionale.activities.index', ['id' => $activity->id]));
    expect($notice->fresh()->read_at)->not->toBeNull();
});

it('hides and rejects a notification after its linked activity becomes invisible to the recipient', function () {
    $this->actingAs($this->admin->user);
    $activity = app(\App\Gestionale\Actions\Activities\CreateActivity::class)->handle($this->admin, [
        'type' => 'Telefonata', 'title' => 'Richiamare per la visita', 'due_at' => now()->addDay()->toDateTimeString(),
        'assigned_to_user_id' => $this->crm->user_id,
    ]);
    $notice = app(CurrentAgency::class)->runAs($this->admin->agency, fn () => Notifier::notify($this->crm->user_id, 'Attività da non mostrare', $activity->id));
    $activity->forceFill(['assigned_to_user_id' => $this->scout->user_id])->save();

    $this->actingAs($this->crm->user)->get(route('gestionale.home'))
        ->assertOk()->assertDontSee('Attività da non mostrare')->assertSee('Notifiche, 0 da leggere');
    $this->post(route('gestionale.notifications.read', $notice->id))->assertForbidden();

    expect($notice->fresh()->read_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'notification.read')->count())->toBe(0);
});

it('answers 403, not 500, when the membership ends while the page is open', function () {
    $this->actingAs($this->scout->user);
    $this->get(route('gestionale.scouting.index'))->assertOk();
    $this->scout->forceFill(['deactivated_at' => now()])->save();
    app(CurrentAgency::class)->forget();

    // Livewire updates cannot follow a redirect: the persistent middleware refuses them with 403.
    $this->get(route('gestionale.activities.index'))->assertRedirect(route('gestionale.choose'));
    $this->getJson(route('gestionale.activities.index'))->assertForbidden();
    $this->withHeader('X-Livewire', 'true')->get(route('gestionale.scouting.index'))->assertForbidden();
});
