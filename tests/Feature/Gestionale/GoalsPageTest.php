<?php

use App\Gestionale\Actions\Goals\SaveGoal;
use App\Gestionale\CommandRejected;
use App\Gestionale\Goals\GoalBoard;
use App\Models\AgencyMembership;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->agency = $this->admin->agency;
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->agency->id]);
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $this->agency->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('shows the initial goals to the scout with the original texts, and the admin the whole team', function () {
    $this->actingAs($this->scout->user);
    Livewire::test('pages::gestionale.goals.index')
        ->assertSee('I tuoi obiettivi, personali o assegnati dal responsabile.')
        ->assertSee('Contatti validi')->assertSee('Appuntamenti di acquisizione fissati')
        ->assertSee('0 obiettivi completati su 2')->assertSee('circa 14 ore rimaste')->assertSee('Conteggi e regole')
        ->assertDontSee('Modifica obiettivi e storico');

    $this->actingAs($this->admin->user);
    Livewire::test('pages::gestionale.goals.index')
        ->assertSee('I tuoi obiettivi e quelli assegnati alla squadra.')->assertSee($this->scout->user->name)
        ->assertSee('Modifica obiettivi e storico')->assertSee('1 versioni conservate');

    // a secretary has none of the scout goals
    $this->actingAs($this->crm->user);
    Livewire::test('pages::gestionale.goals.index')->assertSee('Nessun obiettivo assegnato per questo periodo.');
});

it('lets a scout set a personal goal for themselves and edit it with a reason', function () {
    $this->actingAs($this->scout->user);
    $page = Livewire::test('pages::gestionale.goals.index')
        ->call('openForm')->set('name', 'Dieci chiamate')->set('target', 10)->call('save')->assertHasNoErrors();

    $goal = DB::table('goals')->where('key', 'like', 'goal-%')->first();
    $version = DB::table('goal_versions')->where('goal_id', $goal->id)->first();
    $def = json_decode($version->definition, true);
    expect($def['users'])->toBe([$this->scout->user_id])->and($def['mode'])->toBe('individual')->and($def['unit'])->toBe('attività')
        ->and($version->reason)->toBe('Creazione obiettivo');

    // the crm does not see it, the scout does
    $this->actingAs($this->crm->user);
    Livewire::test('pages::gestionale.goals.index')->assertDontSee('Dieci chiamate');
    $this->actingAs($this->scout->user);

    $page = Livewire::test('pages::gestionale.goals.index')->assertSee('Dieci chiamate')->assertSee('Modifica obiettivi e storico')
        ->call('openForm', $goal->id)->set('target', 12)
        ->call('save')->assertHasErrors('command'); // reason missing
    $page->set('reason', 'Più ambizioso')->call('save')->assertHasErrors('command'); // confirm the running period
    $page->set('confirmCurrentPeriod', true)->call('save')->assertHasNoErrors();
    expect(DB::table('goal_versions')->where('goal_id', $goal->id)->count())->toBe(2);

    // not another scout's goal
    $other = AgencyMembership::factory()->scout()->create(['agency_id' => $this->agency->id]);
    $this->actingAs($other->user);
    Livewire::test('pages::gestionale.goals.index')->call('openForm', $goal->id)->set('reason', 'x')->set('confirmCurrentPeriod', true)->call('save')->assertHasErrors('command');
});

it('rejects goals for others from a non administrator and wrong data, with the original messages', function () {
    $save = fn (AgencyMembership $m, ?int $id, array $data) => app(SaveGoal::class)->handle($m, $id, $data);
    $this->actingAs($this->scout->user);
    expect(fn () => $save($this->scout, null, ['simple' => true, 'name' => 'A', 'target' => 1, 'users' => [$this->admin->user_id]]))
        ->toThrow(CommandRejected::class, 'Puoi fissare obiettivi soltanto per te.')
        ->and(fn () => $save($this->scout, null, ['name' => 'A', 'target' => 1]))->toThrow(CommandRejected::class, 'Puoi fissare obiettivi soltanto per te.')
        ->and(fn () => $save($this->scout, null, ['simple' => true, 'name' => ' ', 'target' => 1]))->toThrow(CommandRejected::class, 'Completa nome, valore, periodicità, regola ed eventi ammessi.')
        ->and(fn () => $save($this->scout, null, ['simple' => true, 'name' => 'A', 'target' => 1, 'start' => '2026-10-01', 'period' => 'Intervallo personalizzato']))
        ->toThrow(CommandRejected::class, 'Scegli date valide. Le modifiche non possono riscrivere periodi passati.')
        ->and(fn () => $save($this->scout, 999, ['simple' => true, 'name' => 'A', 'target' => 1]))->toThrow(CommandRejected::class, 'Obiettivo non trovato.');

    // an administrator assigns to a group, a role or everybody
    $this->actingAs($this->admin->user);
    $id = $save($this->admin, null, ['name' => 'Squadra', 'target' => 5, 'metric' => 'events', 'roles' => ['crm'], 'mode' => 'team', 'period' => 'Settimanale']);
    $board = new GoalBoard($this->crm->fresh());
    expect(collect($board->goals())->pluck('id')->all())->toContain($id);
    $board = new GoalBoard($this->scout->fresh());
    expect(collect($board->goals())->pluck('id')->all())->not->toContain($id);
    expect(fn () => $save($this->admin, null, ['name' => 'Nessuno', 'target' => 5, 'users' => []]))
        ->toThrow(CommandRejected::class, 'Assegna l’obiettivo ad almeno un destinatario.');
});

it('rejects an assignment to nobody', function () {
    $this->actingAs($this->admin->user);
    expect(fn () => app(SaveGoal::class)->handle($this->admin, null, ['name' => 'Nessuno', 'target' => 5, 'users' => [], 'responsibleId' => $this->admin->user_id]))
        ->toThrow(CommandRejected::class, 'Assegna l’obiettivo ad almeno un destinatario.');
});
