<?php

use App\Gestionale\Actions\Goals\SaveGoal;
use App\Gestionale\Goals\GoalBoard;
use App\Gestionale\Goals\GoalCatalog;
use App\Gestionale\Goals\GoalEngine;
use App\Gestionale\Goals\GoalRules;
use App\Gestionale\Livewire\HandlesCommands;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Obiettivi (components/prototype/crm/goals.tsx): avanzamento per operatore e periodo, conteggi e regole,
 * obiettivi personali e assegnati, modifica versionata con storico.
 */
new #[Layout('layouts::gestionale'), Title('Obiettivi')] class extends Component
{
    use HandlesCommands;

    public string $operator = '';

    public string $day = '';

    public string $compare = '';

    public string $category = 'Tutte';

    public string $periodFilter = 'Tutti';

    public string $sort = 'Priorità';

    public bool $showForm = false;

    public ?int $goalId = null;

    public string $name = '';

    public string|int|float $target = 1;

    public string $period = 'Giornaliera';

    public string $description = '';

    public string $start = '';

    public string $end = '';

    /** @var list<int|string> */
    public array $recipients = [];

    /** @var list<int> */
    public array $initialRecipients = [];

    public string $effectiveFrom = '';

    public string $reason = '';

    public bool $confirmCurrentPeriod = false;

    public function mount(): void
    {
        app(GoalEngine::class)->ensureInitialGoals((int) $this->actor()->agency_id);
        $m = $this->actor();
        $this->operator = $m->role === 'admin' ? 'all' : (string) $m->user_id;
        $this->day = GoalCatalog::today();
    }

    #[Computed]
    public function membership()
    {
        return $this->actor();
    }

    #[Computed]
    public function board(): GoalBoard
    {
        return new GoalBoard($this->actor());
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return $this->actor()->role === 'admin';
    }

    /** goals.tsx GoalCards: progress of every visible goal for the chosen person (or everybody). */
    #[Computed]
    public function allItems(): array
    {
        $board = $this->board;
        $day = $this->day ?: GoalCatalog::today();
        $users = $this->isAdmin && $this->operator === 'all' ? $board->activeUsers() : [$board->users()[(int) $this->operator] ?? $board->viewer()];

        return collect($users)->flatMap(fn (array $u) => array_map(fn ($g) => [...$g, 'user' => $u], $board->progress($u, $day, true)))->all();
    }

    #[Computed]
    public function items(): array
    {
        $items = collect($this->allItems)
            ->filter(fn ($g) => ($this->category === 'Tutte' || $g['version']['category'] === $this->category) && ($this->periodFilter === 'Tutti' || $g['version']['period'] === $this->periodFilter));
        $collator = class_exists(\Collator::class) ? new \Collator('it_IT') : null;
        $byName = fn ($a, $b) => $collator ? $collator->compare($a['version']['name'], $b['version']['name']) : strcasecmp($a['version']['name'], $b['version']['name']);

        return $items->sort(fn ($a, $b) => match ($this->sort) {
            'Nome' => $byName($a, $b),
            'Avanzamento' => ($b['value'] / $b['version']['target']) <=> ($a['value'] / $a['version']['target']),
            default => (GoalRules::priorityIndex($a['version']['priority']) <=> GoalRules::priorityIndex($b['version']['priority'])) ?: ($a['version']['order'] <=> $b['version']['order']),
        })->values()->all();
    }

    /** The same goal in the comparison period, for the item's person. */
    public function previous(array $item): ?array
    {
        if ($this->compare === '') {
            return null;
        }
        foreach ($this->board->progress($item['user'], $this->compare, true) as $p) {
            if ($p['goal']['id'] === $item['goal']['id']) {
                return $p;
            }
        }

        return null;
    }

    public function updatedDay(): void
    {
        if ($this->day === '') {
            $this->day = GoalCatalog::today();
        }
    }

    public function openForm(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->reset('reason', 'confirmCurrentPeriod');
        $today = GoalCatalog::today();
        $this->goalId = $id;
        $goal = $id !== null ? collect($this->board->goals())->firstWhere('id', $id) : null;
        $previous = $goal ? end($goal['versions']) : null;
        $this->name = $previous['name'] ?? '';
        $this->target = $previous['target'] ?? 1;
        $this->period = $previous['period'] ?? 'Giornaliera';
        $this->description = $previous['description'] ?? '';
        $this->start = $previous['start'] ?? $today;
        $this->end = $previous['end'] ?? '';
        $me = $this->board->viewer();
        $this->initialRecipients = $previous
            ? array_values(array_map(fn ($u) => $u['id'], array_filter($this->board->activeUsers(), fn ($u) => GoalRules::assigned($previous, $u))))
            : [$me['id']];
        $this->recipients = $this->initialRecipients;
        $this->effectiveFrom = $today;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $m = $this->actor();
        $today = GoalCatalog::today();
        $recipients = array_values(array_map('intval', $this->recipients));
        $changed = array_diff($recipients, $this->initialRecipients) !== [] || array_diff($this->initialRecipients, $recipients) !== [];
        $data = [
            'simple' => true, 'name' => $this->name, 'target' => is_numeric($this->target) ? (float) $this->target : NAN, 'period' => $this->period,
            'description' => $this->description, 'start' => $this->start, 'end' => $this->end,
            'effectiveFrom' => $this->goalId !== null ? ($this->effectiveFrom ?: $today) : $today,
            'confirmCurrentPeriod' => $this->goalId !== null && $this->confirmCurrentPeriod,
        ];
        if ($this->goalId === null || $changed) {
            $data['users'] = $m->role === 'admin' ? $recipients : [(int) $m->user_id];
        }
        if ($this->goalId !== null) {
            $data['reason'] = $this->reason;
        }
        if (is_nan($data['target'])) {
            $data['target'] = 0;
        }
        $saved = $this->command(fn () => app(SaveGoal::class)->handle($m, $this->goalId, $data), 'Obiettivo salvato. Lo storico è conservato.');
        if ($saved !== null) {
            $this->showForm = false;
            unset($this->board, $this->allItems, $this->items);
        }
    }
}; ?>

@use('App\Gestionale\Today\Ui')

<div>
@php
    $admin = $this->isAdmin;
    $board = $this->board;
    $today = GoalCatalog::today();
    $day = $this->day ?: $today;
    $items = $this->items;
    $remainingHours = max(0, 24 - (int) now(GoalCatalog::TIMEZONE)->format('G'));
    $editable = $board->editable();
    $categories = collect($this->allItems)->map(fn ($i) => $i['version']['category'])->unique()->values();
    $me = $board->viewer();
    $fmt = fn ($n) => Ui::number($n);
@endphp
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>Obiettivi</h1>
            <p class="crm-muted">{{ $admin ? 'I tuoi obiettivi e quelli assegnati alla squadra.' : 'I tuoi obiettivi, personali o assegnati dal responsabile.' }}</p>
        </div>
        <div class="crm-actions"><button type="button" class="crm-btn" wire:click="openForm">{{ Ui::icon('plus', 16) }}Nuovo obiettivo</button></div>
    </div>

    <x-gestionale.daily-goal-overview :membership="$this->membership" />

    <div class="crm-toolbar">
        @if ($admin)
            <label class="crm-field"><span>Operatore</span><select wire:model.live="operator"><option value="all">Tutti</option>@foreach ($board->activeUsers() as $u)<option value="{{ $u['id'] }}">{{ $u['name'] }}</option>@endforeach</select></label>
        @endif
        <label class="crm-field"><span>Periodo al</span><input type="date" wire:model.live="day"></label>
        <label class="crm-field"><span>Categoria</span><select wire:model.live="category"><option>Tutte</option>@foreach ($categories as $c)<option>{{ $c }}</option>@endforeach</select></label>
        <label class="crm-field"><span>Periodicità</span><select wire:model.live="periodFilter"><option>Tutti</option>@foreach (GoalCatalog::PERIODS as $p)<option>{{ $p }}</option>@endforeach</select></label>
        <label class="crm-field"><span>Ordina</span><select wire:model.live="sort">@foreach (['Priorità', 'Nome', 'Avanzamento'] as $s)<option>{{ $s }}</option>@endforeach</select></label>
        <label class="crm-field"><span>Confronta con</span><input type="date" wire:model.live="compare"></label>
    </div>

    <p class="crm-muted">{{ collect($items)->where('complete', true)->count() }} obiettivi completati su {{ count($items) }}</p>
    <div class="crm-goal-grid">
        @foreach ($items as $item)
            @php
                $v = $item['version'];
                $previous = $this->previous($item);
                $unit = GoalCatalog::unit($item['missing'], $v['unit']);
            @endphp
            @if ($item['complete'])
                <details class="crm-completed-goal" wire:key="goal-{{ $item['goal']['id'] }}-{{ $item['user']['id'] }}"><summary>Completato · {{ $v['name'] }} · {{ $fmt($item['value']) }}/{{ $fmt($v['target']) }}</summary>@include('gestionale.partials.goal-card', compact('item', 'v', 'previous', 'unit', 'admin', 'board', 'day', 'today', 'remainingHours', 'fmt'))</details>
            @else
                <div wire:key="goal-{{ $item['goal']['id'] }}-{{ $item['user']['id'] }}" style="display: contents">@include('gestionale.partials.goal-card', compact('item', 'v', 'previous', 'unit', 'admin', 'board', 'day', 'today', 'remainingHours', 'fmt'))</div>
            @endif
        @endforeach
    </div>
    @if (! count($items))<div class="crm-empty">Nessun obiettivo assegnato per questo periodo.</div>@endif

    @if (count($editable))
        <details class="crm-operational-filters">
            <summary>Modifica obiettivi e storico</summary>
            <section class="crm-panel">
                @foreach ($editable as $g)
                    @php($last = end($g['versions']))
                    <div class="crm-goal-config" wire:key="config-{{ $g['id'] }}">
                        <div class="crm-between">
                            <div><strong>{{ $last['name'] }}</strong><p class="crm-muted">{{ $last['deleted'] ? 'Archiviato' : ($last['active'] ? 'Attivo' : 'Disattivato') }} · {{ $fmt($last['target']) }} {{ $last['unit'] }} · {{ $last['period'] }}</p></div>
                            <button type="button" class="crm-btn secondary" wire:click="openForm({{ $g['id'] }})">{{ Ui::icon('pencil', 15) }}Modifica</button>
                        </div>
                        <details>
                            <summary>{{ count($g['versions']) }} versioni conservate</summary>
                            @foreach (array_reverse($g['versions']) as $ver)
                                <p><strong>{{ $fmt($ver['target']) }} {{ $ver['unit'] }} · {{ $ver['period'] }}</strong><br>Dal {{ Ui::dateLabel($ver['effectiveFrom']) }} · {{ Ui::dateLabel($ver['createdAt'], true) }}<br>{{ $ver['reason'] }}</p>
                            @endforeach
                        </details>
                    </div>
                @endforeach
            </section>
        </details>
    @endif

    @if ($showForm)
        @php($editing = $goalId !== null)
        <dialog class="proto-dialog reko-prototype" aria-labelledby="goal-form-title" wire:key="goal-form-{{ $goalId ?? 'new' }}"
            x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeForm()" x-on:click="if ($event.target === $el) $wire.closeForm()">
            <div class="proto-dialog-heading">
                <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="goal-form-title">{{ $editing ? 'Modifica obiettivo' : 'Nuovo obiettivo' }}</h2></div>
                <button type="button" class="crm-icon-button" aria-label="Chiudi" wire:click="closeForm">{{ Ui::icon('x', 20) }}</button>
            </div>
            <form class="crm-form" wire:submit="save">
                <label class="crm-field"><span>Nome obiettivo</span><input required wire:model="name"></label>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Valore da raggiungere a ogni ripetizione</span><input type="number" min="0.01" step="any" required wire:model="target"></label>
                    <label class="crm-field"><span>Ripetizione dell’obiettivo</span><select wire:model.live="period">@foreach (GoalCatalog::PERIODS as $p)<option>{{ $p }}</option>@endforeach</select></label>
                </div>
                <label class="crm-field"><span>Descrizione · facoltativa</span><textarea wire:model="description"></textarea></label>
                @if ($admin)
                    <fieldset class="crm-fieldset"><legend>Assegna a</legend>
                        @foreach ($board->activeUsers() as $u)
                            <label class="crm-check"><input type="checkbox" value="{{ $u['id'] }}" wire:model="recipients">{{ $u['id'] === $me['id'] ? 'Me stesso' : $u['name'] }}</label>
                        @endforeach
                    </fieldset>
                @else
                    <p>Questo obiettivo è assegnato soltanto a te.</p>
                @endif
                <fieldset class="crm-fieldset"><legend>Durata dell’obiettivo</legend>
                    <div class="crm-form-grid">
                        <label class="crm-field"><span>Inizio</span><input type="date" required wire:model="start"></label>
                        <label class="crm-field"><span>{{ $period === 'Intervallo personalizzato' ? 'Fine' : 'Fine · facoltativa' }}</span><input type="date" @required($period === 'Intervallo personalizzato') min="{{ $start }}" wire:model="end"></label>
                    </div>
                    <p>La ripetizione vale tra queste date. Per esempio: giornaliera, dal 1° al 31 ottobre, significa un obiettivo da raggiungere ogni giorno per un mese. Senza una data di fine continua a ripetersi.</p>
                </fieldset>
                <p class="crm-muted">{{ $editing ? 'Il metodo di conteggio e le altre regole già impostate restano invariati.' : 'Conteggia le attività completate nel periodo scelto.' }}</p>
                @if ($editing)
                    <label class="crm-field"><span>Modifica in vigore dal</span><input type="date" required min="{{ $today }}" wire:model="effectiveFrom"></label>
                    <label class="crm-field"><span>Motivo della modifica</span><textarea required wire:model="reason"></textarea></label>
                    <label class="crm-check"><input type="checkbox" wire:model="confirmCurrentPeriod">Confermo il ricalcolo del periodo in corso se la modifica entra in vigore oggi</label>
                @endif
                @error('command')<p class="crm-error" role="alert">{{ $message }}</p>@enderror
                <button class="crm-btn" wire:loading.attr="disabled">Salva obiettivo</button>
            </form>
        </dialog>
    @endif
</div>
