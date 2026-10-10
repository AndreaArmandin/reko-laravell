<?php

use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityCatalog;
use App\Gestionale\Livewire\HandlesCommands;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
| activities.tsx "Activities": agenda con vista Giorno / Settimana / Elenco, scadute, filtri per gruppo,
| stato e assegnazione, ordinamento. Collegamenti dalle altre schermate: ?id=<attività> (una sola attività,
| elenco, stato "Tutte") e ?filter=oggi|scadute|visite|riscontri (elenco filtrato).
*/
new #[Layout('layouts::gestionale'), Title('Agenda')] class extends Component
{
    use HandlesCommands;

    #[Url(as: 'id')]
    public ?int $focusId = null;

    #[Url(as: 'filter')]
    public ?string $focus = null;

    #[Url(as: 'q')]
    public string $search = '';

    public string $calendar = 'Settimana';

    public string $day = '';

    public string $order = 'priority';

    public string $type = 'Tutti';

    public string $status = 'Da svolgere';

    public string $origin = 'Tutte';

    public function mount(): void
    {
        $this->actor();
        $this->day = Carbon::now()->toDateString();
        // activities.tsx: arriving on one activity shows every status, as a list.
        if ($this->focusId !== null) {
            $this->status = 'Tutte';
        }
    }

    public function updatedDay(string $value): void
    {
        // The date field ignores an empty value (e.target.value && setDay).
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->day = Carbon::now()->toDateString();
        }
    }

    public function updatedOrder(string $value): void
    {
        if (! array_key_exists($value, ActivityCatalog::SORT_LABELS)) {
            $this->order = 'priority';
        }
    }

    #[Computed]
    public function shownCalendar(): string
    {
        return $this->focusId !== null || $this->focus ? 'Elenco' : $this->calendar;
    }

    public function showCalendar(string $view): void
    {
        if (in_array($view, ['Giorno', 'Settimana', 'Elenco'], true)) {
            $this->calendar = $view;
            $this->clearFocus();
        }
    }

    public function clearFocus(): void
    {
        $this->focusId = null;
        $this->focus = null;
    }

    public function move(int $days): void
    {
        $this->day = CarbonImmutable::parse($this->day)->addDays($days)->toDateString();
    }

    public function today(): void
    {
        $this->day = Carbon::now()->toDateString();
    }

    #[On('activities-changed')]
    public function refresh(): void
    {
        unset($this->activities);
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Activity> */
    #[Computed]
    public function activities()
    {
        $actor = $this->actor();
        $userId = (int) $actor->user_id;
        $term = mb_strtolower($this->search);
        $today = Carbon::now()->toDateString();
        $now = Carbon::now();

        return app(ActivityAccess::class)->visible($actor)->filter(function ($a) use ($userId, $term, $today, $now) {
            return ($this->focusId === null || $a->id === $this->focusId)
                && ($term === '' || str_contains(mb_strtolower($a->subject.' '.$a->notes), $term))
                && ActivityCatalog::matchesGroup($a, $this->type)
                && match ($this->status) {
                    'Completate' => $a->isDone(),
                    'Annullate' => $a->status === 'Annullata',
                    'Da svolgere' => ! $a->isDone() && $a->status !== 'Annullata',
                    default => true,
                }
                && match ($this->origin) {
                    'Assegnate a me' => (int) $a->assigned_to_user_id === $userId,
                    'Create da me' => (int) $a->created_by_user_id === $userId,
                    'Ricevute da altri' => (int) $a->assigned_to_user_id === $userId && (int) $a->created_by_user_id !== $userId,
                    default => true,
                }
                && ($this->focus !== 'oggi' || $a->scheduled_at?->toDateString() === $today)
                && ($this->focus !== 'scadute' || ActivityCatalog::isOverdue($a, $now))
                && ($this->focus !== 'visite' || $a->kind === 'Visita')
                && ($this->focus !== 'riscontri' || ($a->kind === 'Visita' && ! $a->isDone() && $a->scheduled_at !== null && $a->scheduled_at->lt($now)));
        })->values();
    }

    /** agenda-view.ts agendaDays() */
    #[Computed]
    public function days(): array
    {
        $anchor = CarbonImmutable::parse($this->day);
        $week = $this->shownCalendar === 'Settimana';
        $first = $week ? $anchor->startOfWeek() : $anchor;

        return array_map(fn (int $i) => $first->addDays($i)->toDateString(), range(0, $week ? 6 : 0));
    }
}; ?>

@php
    $shown = $this->shownCalendar;
    $partition = \App\Gestionale\Activities\ActivityCatalog::overduePartition($this->activities);
    $overdue = $partition['overdue'];
    $current = $partition['current'];
    $today = \Illuminate\Support\Carbon::now()->toDateString();
    $step = $shown === 'Settimana' ? 7 : 1;
    $count = $this->activities->count();
@endphp
<div>
    <div class="crm-page-head">
        <div>
            <p class="proto-eyebrow">REKO Gestionale</p>
            <h1>Agenda</h1>
            <p class="crm-muted">Le tue attività e quelle condivise con te, con responsabile ed esito.</p>
        </div>
        <div class="crm-actions">
            <button type="button" class="crm-btn" wire:click="$dispatch('activity-form', { scope: 'agenda', mode: 'activity' })"><x-gestionale.activity-icon name="plus" :size="16" />Registra attività</button>
        </div>
    </div>

    <div class="crm-toolbar">
        <label class="crm-field"><span>Ordina attività</span>
            <select wire:model.live="order">
                @foreach (\App\Gestionale\Activities\ActivityCatalog::SORT_LABELS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
    </div>

    @if ($shown !== 'Elenco' && count($overdue) > 0)
        <section class="crm-panel crm-overdue-section" aria-label="Attività scadute">
            <h2>Scadute · {{ count($overdue) }}</h2>
            <x-gestionale.activity-timeline :activities="\App\Gestionale\Activities\ActivityCatalog::sort($overdue, $order)" scope="agenda" order="given" />
        </section>
    @endif

    <nav class="crm-tabs" aria-label="Vista agenda">
        @foreach (['Giorno', 'Settimana', 'Elenco'] as $view)
            <button type="button" wire:click="showCalendar('{{ $view }}')" @if ($shown === $view) aria-current="page" @endif>{{ $view }}</button>
        @endforeach
    </nav>

    @if ($shown !== 'Elenco')
        <div class="crm-calendar-controls">
            <button type="button" class="crm-btn secondary" aria-label="Periodo precedente" wire:click="move({{ -$step }})">←</button>
            <label class="crm-field"><span>Data agenda</span><input type="date" wire:model.live="day"></label>
            <button type="button" class="crm-btn secondary" aria-label="Periodo successivo" wire:click="move({{ $step }})">→</button>
            <button type="button" class="crm-btn secondary" wire:click="today">Oggi</button>
        </div>
    @endif

    <details class="crm-operational-filters" wire:ignore.self>
        <summary>Filtri · {{ $count }} attività</summary>
        <div class="crm-toolbar">
            <label class="crm-field"><span>Tipo</span>
                <select wire:model.live="type">
                    <option value="Tutti">Tutti</option>
                    @foreach (\App\Gestionale\Activities\ActivityCatalog::GROUPS as $group)<option value="{{ $group['id'] }}">{{ $group['label'] }}</option>@endforeach
                </select>
            </label>
            <label class="crm-field"><span>Stato</span>
                <select wire:model.live="status">
                    @foreach (['Da svolgere', 'Completate', 'Annullate', 'Tutte'] as $value)<option>{{ $value }}</option>@endforeach
                </select>
            </label>
            <label class="crm-field"><span>Assegnazioni</span>
                <select wire:model.live="origin">
                    @foreach (['Tutte', 'Assegnate a me', 'Create da me', 'Ricevute da altri'] as $value)<option>{{ $value }}</option>@endforeach
                </select>
            </label>
            <span>{{ $count }} attività</span>
            @if ($focus || $focusId !== null)<button type="button" class="crm-link" wire:click="clearFocus">Mostra tutte</button>@endif
        </div>
    </details>

    @if ($shown === 'Elenco')
        <section class="crm-panel">
            <x-gestionale.activity-timeline :activities="\App\Gestionale\Activities\ActivityCatalog::sort($this->activities, $order)" scope="agenda" order="given" />
        </section>
    @else
        <div class="crm-agenda-days">
            @foreach ($this->days as $date)
                @php $daily = \App\Gestionale\Activities\ActivityCatalog::sort(array_filter($current, fn ($a) => $a->scheduled_at?->toDateString() === $date), $order); @endphp
                <section class="crm-panel" wire:key="day-{{ $date }}">
                    <h2>{{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($date) }}{{ $date === $today ? ' · Oggi' : '' }}</h2>
                    @if (count($daily))
                        <x-gestionale.activity-timeline :activities="$daily" scope="agenda" order="given" />
                    @else
                        <p class="crm-muted">Nessuna attività con questi filtri.</p>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    <livewire:pages::gestionale.activities.dialogs scope="agenda" key="agenda-dialogs" />
</div>
