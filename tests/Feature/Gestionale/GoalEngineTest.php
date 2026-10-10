<?php

use App\Events\Gestionale\PropertyRequestCompleted;
use App\Gestionale\Goals\GoalBoard;
use App\Gestionale\Goals\GoalCatalog;
use App\Gestionale\Goals\GoalEngine;
use App\Gestionale\Goals\GoalRules;
use App\Models\Activity;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\PropertyRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->agency = $this->admin->agency;
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->agency->id]);
    $this->actingAs($this->admin->user);
});

afterEach(fn () => Carbon::setTestNow());

function goalCall(object $test, array $attributes = []): Activity
{
    $owner = $attributes['owner'] ?? Contact::factory()->owner()->create(['agency_id' => $test->agency->id]);
    unset($attributes['owner']);
    $activity = new Activity;
    $activity->forceFill([
        'agency_id' => $test->agency->id, 'user_id' => $test->scout->user_id, 'assigned_to_user_id' => $test->scout->user_id,
        'created_by_user_id' => $test->scout->user_id, 'owner_contact_id' => $owner->id, 'kind' => 'Telefonata', 'subject' => 'Chiamata al proprietario',
        'status' => 'Completata', 'completed_at' => now(), 'completed_by_user_id' => $test->scout->user_id, 'outcome' => 'Disponibile ad approfondire',
        'outcome_confirmed_at' => now(), 'answered' => true, 'contact_operation' => 'Vendita', 'metadata' => (object) [],
        ...$attributes,
    ])->save();

    return $activity;
}

function contactLedger()
{
    return DB::table('goal_ledger')->join('goals', 'goals.id', '=', 'goal_ledger.goal_id')->where('goals.key', 'initial-valid-contacts');
}

function goalValue(object $test, string $key = 'initial-valid-contacts'): float
{
    return (float) DB::table('goal_ledger')->join('goals', 'goals.id', '=', 'goal_ledger.goal_id')
        ->where('goal_ledger.agency_id', $test->agency->id)->where('goals.key', $key)->sum('value');
}

it('counts an answered call once per person and day for the contacts goal, and not an unanswered or a reminder', function () {
    $person = Contact::factory()->owner()->create(['agency_id' => $this->agency->id]);
    goalCall($this, ['owner' => $person]);
    expect(goalValue($this))->toBe(1.0);

    // the same person again the same day does not count twice
    goalCall($this, ['owner' => $person, 'outcome' => 'Non interessato']);
    expect(goalValue($this))->toBe(1.0);
    expect(contactLedger()->where('reason', 'Duplicato nel periodo')->count())->toBe(1);

    // another person counts
    goalCall($this);
    expect(goalValue($this))->toBe(2.0);

    // no answer, and a reminder, do not count
    goalCall($this, ['answered' => false, 'outcome' => 'Non risponde']);
    goalCall($this, ['kind' => 'Promemoria']);
    expect(goalValue($this))->toBe(2.0);

    $board = new GoalBoard($this->scout->fresh());
    $row = $board->dailyRows('2026-10-07')[0];
    expect($row['primary']['value'])->toBe(2.0)->and($row['primary']['version']['target'])->toBe(20.0);
});

it('does not count open activities and drops the count when a completed one is reopened or cancelled', function () {
    $open = goalCall($this, ['status' => 'Da svolgere', 'completed_at' => null, 'completed_by_user_id' => null, 'outcome' => null, 'outcome_confirmed_at' => null, 'answered' => null]);
    expect(DB::table('goal_events')->count())->toBe(0);

    $open->forceFill(['status' => 'Completata', 'completed_at' => now(), 'completed_by_user_id' => $this->scout->user_id, 'outcome' => 'Disponibile ad approfondire', 'answered' => true, 'contact_operation' => 'Vendita'])->save();
    expect(goalValue($this))->toBe(1.0);

    $open->forceFill(['status' => 'Annullata', 'cancelled_at' => now()])->save();
    expect(goalValue($this))->toBe(0.0)
        ->and(contactLedger()->where('reason', 'Annullato')->count())->toBe(1);
});

it('keeps closed periods and recomputes them only for a corrected activity', function () {
    Carbon::setTestNow('2026-10-05 09:00:00');
    $call = goalCall($this, ['completed_at' => now()]);
    expect(goalValue($this))->toBe(1.0);

    // next day: the closed day stays in the ledger, today starts empty
    Carbon::setTestNow('2026-10-06 09:00:00');
    goalCall($this);
    $rows = contactLedger()->orderBy('period_start')->get();
    expect($rows->pluck('period_start')->map(fn ($d) => substr($d, 0, 10))->all())->toBe(['2026-10-05', '2026-10-06']);

    // an administrator corrects the old call: the answer was not valid
    $call->forceFill(['answered' => false, 'outcome' => 'Non risponde'])->save();
    expect(contactLedger()->where('period_start', '2026-10-05')->sum('value'))->toEqual(0)
        ->and(contactLedger()->where('period_start', '2026-10-06')->sum('value'))->toEqual(1);
});

it('keeps the instant an event was first recorded', function () {
    $call = goalCall($this);
    $first = DB::table('goal_events')->value('occurred_at');
    Carbon::setTestNow('2026-10-07 18:00:00');
    $call->forceFill(['subject' => 'Cambio titolo'])->save();
    expect(DB::table('goal_events')->value('occurred_at'))->toBe($first)->and(DB::table('goal_events')->count())->toBe(1);
});

it('records the request milestone once, for the person who completed it', function () {
    $profile = ClientProfile::factory()->create(['agency_id' => $this->agency->id, 'agent_user_id' => $this->admin->user_id, 'contact_id' => Contact::factory()->create(['agency_id' => $this->agency->id])->id]);
    $request = PropertyRequest::factory()->forClient($profile)->create(['criteria' => ['operation' => 'Acquisto', 'purpose' => 'Abitazione principale']]);

    PropertyRequestCompleted::dispatch($request->id);
    PropertyRequestCompleted::dispatch($request->id);

    $event = DB::table('goal_events')->where('event_key', "request:{$request->id}:complete")->get();
    expect($event)->toHaveCount(1)
        ->and(json_decode($event[0]->event_types, true))->toBe(['Cliente profilato', 'Richiesta completata'])
        ->and((int) $event[0]->operator_user_id)->toBe($this->admin->user_id)
        ->and($event[0]->subject)->toBe('client:'.$profile->contact_id);
});

it('creates the two initial goals once, for the role scout', function () {
    app(GoalEngine::class)->refreshLedger($this->agency->id);
    app(GoalEngine::class)->refreshLedger($this->agency->id);
    expect(DB::table('goals')->where('agency_id', $this->agency->id)->count())->toBe(2)
        ->and(DB::table('goal_versions')->count())->toBe(2);
    $goal = collect((new GoalBoard($this->admin))->goals())->firstWhere('key', 'initial-valid-contacts');
    expect($goal['versions'][0]['roles'])->toBe(['scout'])->and($goal['versions'][0]['target'])->toBe(20.0)->and($goal['readOnly'])->toBeFalse();
});

it('works out the Europe/Rome day of an instant and the period windows', function () {
    expect(GoalCatalog::day('2026-10-06 22:30:00+00:00'))->toBe('2026-10-07')
        ->and(GoalCatalog::day('2026-01-01 00:30:00+00:00'))->toBe('2026-01-01');
    $v = fn (string $period, string $start = '2026-01-01', string $end = '') => ['period' => $period, 'start' => $start, 'end' => $end];
    expect(GoalRules::window($v('Giornaliera'), '2026-10-07'))->toBe(['start' => '2026-10-07', 'end' => '2026-10-07'])
        ->and(GoalRules::window($v('Settimanale'), '2026-10-07'))->toBe(['start' => '2026-10-05', 'end' => '2026-10-11'])
        ->and(GoalRules::window($v('Mensile'), '2026-02-10'))->toBe(['start' => '2026-02-01', 'end' => '2026-02-28'])
        ->and(GoalRules::window($v('Trimestrale'), '2026-11-10'))->toBe(['start' => '2026-10-01', 'end' => '2026-12-31'])
        ->and(GoalRules::window($v('Annuale'), '2026-11-10'))->toBe(['start' => '2026-01-01', 'end' => '2026-12-31'])
        ->and(GoalRules::window($v('Intervallo personalizzato', '2026-10-01', '2026-10-20'), '2026-10-07'))->toBe(['start' => '2026-10-01', 'end' => '2026-10-20'])
        ->and(GoalRules::window($v('Mensile', '2026-10-15', '2026-10-20'), '2026-10-17'))->toBe(['start' => '2026-10-15', 'end' => '2026-10-20']);
});
