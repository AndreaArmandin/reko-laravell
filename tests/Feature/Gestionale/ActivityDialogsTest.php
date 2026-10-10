<?php

use App\Gestionale\Actions\Activities\CreateActivity;
use App\Gestionale\CommandRejected;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $contact = Contact::factory()->create(['agency_id' => $this->admin->agency_id]);
    ClientProfile::factory()->agent($this->crm)->create(['contact_id' => $contact->id]);
    $this->client = $contact;
    $this->actingAs($this->crm->user);
});

it('shows the record block with the original buttons and the empty summary', function () {
    $this->actingAs($this->crm->user);
    Livewire::test('pages::gestionale.activities.record-panel', ['context' => ['contact_id' => $this->client->id]])
        ->assertSee('Nessuna attività o promemoria registrato.')
        ->assertSee('Registra attività')
        ->assertDontSee('Nuovo promemoria')
        ->assertSee('Storico attività');
});

it('requires a reminder to be created with a new activity', function () {
    Livewire::test('pages::gestionale.home')->set('fullOverview', true)
        ->assertDontSee('Nuovo promemoria')->assertSee('Attività e scadenze');

    Livewire::test('pages::gestionale.activities.dialogs', ['scope' => 's1', 'context' => ['contact_id' => $this->client->id]])
        ->dispatch('activity-form', scope: 's1', mode: 'reminder')
        ->assertSet('dialog', null)
        ->assertSee('Crea il promemoria insieme a una nuova attività.');

    expect(Activity::query()->count())->toBe(0);
    expect(fn () => app(\App\Gestionale\Actions\Activities\SaveActivity::class)->handle($this->crm, null, [
        'type' => 'Promemoria', 'title' => 'Promemoria senza attività', 'due_at' => now()->addDay()->toDateTimeString(),
    ]))->toThrow(CommandRejected::class, 'Crea il promemoria insieme a una nuova attività.');
});

it('requires outcome and comment to record an activity, then saves it linked to the record', function () {
    $dialogs = Livewire::test('pages::gestionale.activities.dialogs', ['scope' => 's1', 'context' => ['contact_id' => $this->client->id]])
        ->dispatch('activity-form', scope: 's1', mode: 'activity')
        ->assertSee('Registra attività')
        ->assertSee('Cliente collegato')
        ->set('type', 'Telefonata')
        ->set('outcome', '')
        ->call('save')
        ->assertSee('Completa l’esito e il commento dell’attività.');
    expect(Activity::query()->count())->toBe(0);

    $dialogs->set('outcome', 'Interessato')->set('note', 'Vuole visitare')->call('save')->assertSet('dialog', null);

    $a = Activity::query()->firstOrFail();
    expect($a->kind)->toBe('Telefonata')->and($a->status)->toBe('Completata')->and($a->outcome)->toBe('Interessato')
        ->and($a->subject)->toBe('Telefonata · Interessato')->and($a->contact_id)->toBe($this->client->id)
        ->and($a->completed_at)->not->toBeNull();
});

it('creates a reminder together with a new activity in the future', function () {
    Livewire::test('pages::gestionale.activities.dialogs', ['scope' => 's2', 'context' => ['contact_id' => $this->client->id]])
        ->dispatch('activity-form', scope: 's2', mode: 'activity')
        ->set('outcome', 'Informazione registrata')->set('note', 'ok')->set('withReminder', true)
        ->set('reminderTitle', 'Richiamare per risposta')->set('reminderAt', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->call('save')->assertSet('dialog', null);

    $first = Activity::query()->where('kind', 'Telefonata')->firstOrFail();
    $reminder = Activity::query()->where('subject', 'Richiamare per risposta')->firstOrFail();
    expect($first->next_activity_id)->toBe($reminder->id)->and($reminder->previous_activity_id)->toBe($first->id)
        ->and($reminder->kind)->toBe('Promemoria')->and($reminder->contact_id)->toBe($this->client->id);
});

it('ignores events of another scope', function () {
    Livewire::test('pages::gestionale.activities.dialogs', ['scope' => 's1', 'context' => ['contact_id' => $this->client->id]])
        ->dispatch('activity-form', scope: 'altro', mode: 'activity')
        ->assertSet('dialog', null);
});

it('answers an assigned activity with the history and notifies the creator', function () {
    $activity = app(CreateActivity::class)->handle($this->admin, ['type' => 'Visita', 'title' => 'Visita', 'due_at' => now()->addDay()->toDateTimeString(), 'assigned_to_user_id' => $this->crm->user_id]);
    expect($activity->status)->toBe('Da svolgere');

    Livewire::test('pages::gestionale.activities.dialogs', ['scope' => 'a'])
        ->dispatch('activity-accept', scope: 'a', id: $activity->id);
    expect($activity->refresh()->status)->toBe('Accettata')
        ->and($activity->events()->where('kind', 'response')->count())->toBe(1);

    Livewire::test('pages::gestionale.activities.dialogs', ['scope' => 'a'])
        ->dispatch('activity-response', scope: 'a', id: $activity->id, response: 'Annulla')
        ->assertSee('Motivo')
        ->call('respond')->assertSee('Indica il motivo dell’annullamento.')
        ->set('reason', 'Non serve più')->call('respond')->assertSet('dialog', null);
    expect($activity->refresh()->status)->toBe('Annullata');
    expect(\App\Models\GestionaleNotification::query()->where('user_id', $this->admin->user_id)->pluck('title')->all())
        ->toBe(['Accetta · attività assegnata', 'Annulla · attività assegnata']);
});
