<?php

use App\Gestionale\Actions\Activities\CreateActivity;
use App\Gestionale\CommandRejected;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\GestionaleNotification;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->crm2 = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id]);
});

function act_create(AgencyMembership $actor, array $input): Activity
{
    test()->actingAs($actor->user);

    return app(CreateActivity::class)->handle($actor, $input);
}

it('creates an activity assigned to the actor with the original defaults', function () {
    $activity = act_create($this->crm, ['type' => 'Telefonata', 'title' => 'Richiamare Rossi', 'due_at' => '2026-10-12 15:00']);

    expect($activity->kind)->toBe('Telefonata')
        ->and($activity->status)->toBe('Da svolgere')
        ->and($activity->priority)->toBe('Normale')
        ->and($activity->assigned_to_user_id)->toBe($this->crm->user_id)
        ->and($activity->created_by_user_id)->toBe($this->crm->user_id)
        ->and($activity->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-12 15:00')
        ->and(GestionaleNotification::query()->count())->toBe(0);
});

it('notifies the assignee and the people the activity is shared with', function () {
    $activity = act_create($this->crm, [
        'type' => 'Visita', 'title' => 'Visita Via Roma', 'due_at' => '2026-10-12 15:00',
        'assigned_to_user_id' => $this->crm2->user_id, 'shared_with' => [$this->admin->user_id],
    ]);

    expect(GestionaleNotification::query()->where('user_id', $this->crm2->user_id)->value('title'))->toBe('Nuova attività assegnata')
        ->and(GestionaleNotification::query()->where('user_id', $this->admin->user_id)->value('title'))->toBe('Attività condivisa')
        ->and($activity->visibility)->toBe('workflow')
        ->and($activity->participants()->pluck('user_id')->all())->toBe([$this->admin->user_id]);
});

it('applies activities.assign and activities.share for real', function () {
    $this->crm->forceFill(['permissions' => ['exports']])->save();

    expect(fn () => act_create($this->crm, ['type' => 'Nota', 'title' => 'x', 'assigned_to_user_id' => $this->crm2->user_id]))
        ->toThrow(CommandRejected::class, 'Assegnazione non consentita.');
    expect(fn () => act_create($this->crm, ['type' => 'Nota', 'title' => 'x', 'shared_with' => [$this->admin->user_id]]))
        ->toThrow(CommandRejected::class, 'Condivisione non consentita.');

    $this->crm->forceFill(['permissions' => ['activities.assign', 'activities.share']])->save();
    expect(act_create($this->crm, ['type' => 'Nota', 'title' => 'x', 'assigned_to_user_id' => $this->crm2->user_id, 'shared_with' => [$this->admin->user_id]]))
        ->toBeInstanceOf(Activity::class);
});

it('rejects unknown types, empty titles, bad priorities and inactive assignees', function () {
    expect(fn () => act_create($this->crm, ['type' => 'Chiamata', 'title' => 'x']))->toThrow(CommandRejected::class, 'Completa tipo, titolo e data dell’attività.');
    expect(fn () => act_create($this->crm, ['type' => 'Nota', 'title' => ' ']))->toThrow(CommandRejected::class, 'Completa tipo, titolo e data dell’attività.');
    expect(fn () => act_create($this->crm, ['type' => 'Nota', 'title' => 'x', 'priority' => 'Massima']))->toThrow(CommandRejected::class, 'Priorità non valida.');
    $this->crm2->forceFill(['deactivated_at' => now()])->save();
    expect(fn () => act_create($this->crm, ['type' => 'Nota', 'title' => 'x', 'assigned_to_user_id' => $this->crm2->user_id]))->toThrow(CommandRejected::class, 'Scegli un destinatario attivo.');
});
