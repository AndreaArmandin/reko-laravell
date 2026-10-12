<?php

use App\Gestionale\Actions\Activities\CreateActivity;
use App\Gestionale\Today\TodayWork;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->admin->agency_id]);
    $this->coAdmin = AgencyMembership::factory()->admin()->create(['agency_id' => $this->admin->agency_id]);

    // A client of the CRM operator; an admin plans a call on it and shares it with another admin only.
    $contact = Contact::factory()->create(['agency_id' => $this->admin->agency_id]);
    ClientProfile::factory()->create(['agency_id' => $this->admin->agency_id, 'agent_user_id' => $this->crm->user_id, 'contact_id' => $contact->id]);
    $this->actingAs($this->admin->user);
    $this->hidden = app(CreateActivity::class)->handle($this->admin, [
        'type' => 'Telefonata', 'title' => 'Chiamata riservata', 'due_at' => '2026-10-06 09:00',
        'contact_id' => $contact->id, 'shared_with' => [$this->coAdmin->user_id],
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('does not show Oggi or the Home counters an activity the operator was not part of, even on their own client', function () {
    expect($this->hidden->visibility)->toBe('workflow');

    $this->actingAs($this->crm->user);
    $work = TodayWork::work($this->crm, CarbonImmutable::parse('2026-10-07 10:00'));
    expect($work['counts']['today'])->toBe(0)
        ->and(collect($work['queues']['today'])->pluck('id')->all())->not->toContain($this->hidden->id);

    Livewire::test('pages::gestionale.home')->set('fullOverview', true)
        ->assertDontSee('Chiamata riservata')
        ->assertSet('activityProgress.overdueCount', 0);
});

it('shows the same activity to the administrators who created or share it', function () {
    foreach ([$this->admin, $this->coAdmin] as $admin) {
        $this->actingAs($admin->user);
        $work = TodayWork::work($admin, CarbonImmutable::parse('2026-10-07 10:00'));
        expect(collect($work['queues']['today'])->pluck('id')->all())->toContain($this->hidden->id);
    }
});

it('counts every visible overdue activity, not just the first hundred', function () {
    $this->actingAs($this->crm->user);
    foreach (range(1, 105) as $i) {
        app(CreateActivity::class)->handle($this->crm, ['type' => 'Telefonata', 'title' => "Scaduta {$i}", 'due_at' => '2026-10-05 09:00']);
    }

    Livewire::test('pages::gestionale.home')->set('fullOverview', true)->assertSet('activityProgress.overdueCount', 105);
});
