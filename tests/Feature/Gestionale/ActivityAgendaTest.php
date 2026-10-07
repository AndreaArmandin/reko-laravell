<?php

use App\Models\Activity;
use App\Models\AgencyMembership;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($this->admin->user);
    Carbon::setTestNow('2026-10-06 08:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('creates a single reminder and filters it by agenda day', function () {
    Livewire::test('pages::gestionale.activities.index')
        ->set('kind', 'Promemoria')
        ->set('subject', 'Richiamare il cliente demo')
        ->set('scheduledAt', '2026-10-08T09:30')
        ->call('create')
        ->assertHasNoErrors();

    $first = Activity::query()->where('subject', 'Richiamare il cliente demo')->firstOrFail();
    expect($first->metadata)->toBe([])
        ->and($first->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-08 09:30');

    Livewire::test('pages::gestionale.activities.index')
        ->set('view', 'Giorno')
        ->set('agendaDate', '2026-10-08')
        ->assertSee('Richiamare il cliente demo')
        ->set('agendaDate', '2026-10-09')
        ->assertDontSee('Richiamare il cliente demo');

});

it('renders the goals page with the current agency membership', function () {
    Livewire::test('pages::gestionale.goals.index')->assertOk()->assertSee('Obiettivi');
});
