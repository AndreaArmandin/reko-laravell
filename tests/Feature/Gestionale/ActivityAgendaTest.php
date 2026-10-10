<?php

use App\Gestionale\Actions\Activities\CreateActivity;
use App\Gestionale\Activities\ActivityCatalog;
use App\Models\Activity;
use App\Models\AgencyMembership;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00'); // mercoledì
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->actingAs($this->crm->user);
});

afterEach(function () {
    Carbon::setTestNow();
});

function agenda_make(AgencyMembership $actor, string $type, string $title, string $due, array $extra = []): Activity
{
    test()->actingAs($actor->user);

    return app(CreateActivity::class)->handle($actor, ['type' => $type, 'title' => $title, 'due_at' => $due] + $extra);
}

it('has the 17 types of the original and the six presentation groups', function () {
    expect(ActivityCatalog::TYPES)->toHaveCount(17)->and(ActivityCatalog::TYPES)->toContain('Telefonata')->not->toContain('Chiamata')
        ->and(collect(ActivityCatalog::GROUPS)->pluck('label')->all())->toBe(['Contatti', 'Appuntamenti e visite', 'Documenti', 'Proposte e trattative', 'Promemoria', 'Note e altro'])
        ->and(collect(ActivityCatalog::GROUPS)->flatMap(fn ($g) => $g['types'])->unique()->count())->toBe(17)
        ->and(ActivityCatalog::SORT_LABELS)->toHaveCount(4);
});

it('opens an activity row from Oggi on that activity in the agenda', function () {
    $activity = agenda_make($this->crm, 'Telefonata', 'Richiamo cliente', '2026-10-07 11:00');

    $work = \App\Gestionale\Today\TodayWork::work($this->crm, \Carbon\CarbonImmutable::parse('2026-10-07 10:00'));

    expect($work['queues']['today'][0]['href'])->toBe(route('gestionale.activities.index', ['id' => $activity->id]));
});

it('counts all activities scheduled today and reports overdue activities separately on Oggi', function () {
    agenda_make($this->crm, 'Telefonata', 'Da fare oggi', '2026-10-07 11:00');
    agenda_make($this->crm, 'Telefonata', 'Fatta oggi', '2026-10-07 09:00', [
        'done' => true, 'outcome' => 'Interessato', 'notes' => 'Contatto completato', 'recorded' => true,
    ]);
    agenda_make($this->crm, 'Visita', 'Da recuperare', '2026-10-06 09:00');

    Livewire::test('pages::gestionale.home')->set('fullOverview', true)
        ->assertSee('1/2')
        ->assertSee('Attività di oggi da completare')
        ->assertSee('Da recuperare · 1')
        ->assertSee('In programma oggi · 1');
});

it('shows the week as seven day panels, with today marked, and the overdue section', function () {
    agenda_make($this->crm, 'Visita', 'Visita di giovedì', '2026-10-08 15:00');
    agenda_make($this->crm, 'Telefonata', 'Chiamata scaduta', '2026-10-05 09:00');

    $page = Livewire::test('pages::gestionale.activities.index')
        ->assertSee('Le tue attività e quelle condivise con te, con responsabile ed esito.')
        ->assertDontSee('?>')
        ->assertSee('Scadute · 1')
        ->assertSee('7 ott 2026 · Oggi')
        ->assertSee('Visita di giovedì')
        ->assertSee('Nessuna attività con questi filtri.');
    expect(substr_count($page->html(), 'class="crm-panel"'))->toBe(7);

    // La scaduta sta solo nella sezione scadute, non nel suo giorno.
    expect(substr_count($page->html(), 'Chiamata scaduta'))->toBeGreaterThanOrEqual(1);
    $page->set('calendar', 'Giorno')->set('day', '2026-10-08')->assertSee('Visita di giovedì')->assertSee('Scadute · 1')
        ->set('day', '2026-10-09')->assertDontSee('Visita di giovedì');
});

it('filters by group, status and assignment, and sorts in the four orders', function () {
    agenda_make($this->crm, 'Telefonata', 'B telefonata', '2026-10-09 10:00');
    agenda_make($this->crm, 'Visita', 'A visita', '2026-10-10 10:00');
    $done = agenda_make($this->crm, 'Nota', 'Nota fatta', '2026-10-09 12:00', ['done' => true, 'outcome' => 'Svolta', 'notes' => 'ok', 'recorded' => true]);
    agenda_make($this->admin, 'Email', 'Da admin', '2026-10-09 13:00', ['assigned_to_user_id' => $this->crm->user_id]);
    $this->actingAs($this->crm->user);

    Livewire::test('pages::gestionale.activities.index')->set('calendar', 'Elenco')
        ->set('type', 'contacts')->assertSee('B telefonata')->assertSee('Da admin')->assertDontSee('A visita')
        ->set('type', 'Tutti')->assertDontSee('Nota fatta')
        ->set('status', 'Completate')->assertSee('Nota fatta')->assertDontSee('B telefonata')
        ->set('status', 'Da svolgere')->set('origin', 'Ricevute da altri')->assertSee('Da admin')->assertDontSee('B telefonata')
        ->set('origin', 'Create da me')->assertDontSee('Da admin')->assertSee('A visita');

    $titles = fn (string $order) => collect(ActivityCatalog::sort(Activity::query()->where('status', '<>', 'Completata')->get(), $order))->pluck('subject')->all();
    expect($titles('title'))->toBe(['A visita', 'B telefonata', 'Da admin'])
        ->and($titles('dateDesc')[0])->toBe('A visita')
        ->and($titles('date')[0])->toBe('B telefonata');
    // "Da svolgere, in ordine di scadenza": le aperte prima delle fatte.
    expect(collect(ActivityCatalog::sort(Activity::query()->get(), 'priority'))->last()->subject)->toBe('Nota fatta');
});

it('opens one activity as a list through ?id and filters through ?filter', function () {
    $today = agenda_make($this->crm, 'Visita', 'Visita di oggi', '2026-10-07 18:00');
    agenda_make($this->crm, 'Nota', 'Altra nota', '2026-10-12 18:00');

    Livewire::withQueryParams(['id' => $today->id])->test('pages::gestionale.activities.index')
        ->assertSee('Visita di oggi')->assertDontSee('Altra nota')->assertSee('Mostra tutte')
        ->assertDontSee('Scadute ·')->assertSet('status', 'Tutte');
    Livewire::withQueryParams(['filter' => 'oggi'])->test('pages::gestionale.activities.index')
        ->assertSee('Visita di oggi')->assertDontSee('Altra nota');
    Livewire::withQueryParams(['filter' => 'visite'])->test('pages::gestionale.activities.index')
        ->assertSee('Visita di oggi')->assertDontSee('Altra nota');
});

it('does not show other people’s activities, but shows the ones shared with me', function () {
    $other = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    agenda_make($other, 'Nota', 'Privata di altri', '2026-10-08 10:00');
    agenda_make($this->admin, 'Nota', 'Condivisa con me', '2026-10-08 11:00', ['shared_with' => [$this->crm->user_id]]);
    $this->actingAs($this->crm->user);

    Livewire::test('pages::gestionale.activities.index')->assertSee('Condivisa con me')->assertDontSee('Privata di altri');
    $this->actingAs($this->admin->user);
    Livewire::test('pages::gestionale.activities.index')->assertSee('Condivisa con me')->assertSee('Privata di altri');
});
