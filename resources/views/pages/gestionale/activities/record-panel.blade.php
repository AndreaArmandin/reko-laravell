<?php

use App\Gestionale\Activities\ActivityAccess;
use App\Gestionale\Activities\ActivityCatalog;
use App\Gestionale\Activities\ActivityContext;
use App\Gestionale\Activities\ActivityPresentation as P;
use App\Gestionale\CurrentAgency;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
| record-activities.tsx: blocco "Attività collegate" di una scheda (cliente, richiesta, immobile, proprietario,
| particella). Ultima attività, prossimo impegno, "Registra attività" e "Storico attività".
| Si usa con <x-gestionale.record-activities :subject="$model" />.
*/
new class extends Component
{
    /** @var array<string, mixed> */
    #[Locked]
    public array $context = [];

    #[Locked]
    public bool $compact = false;

    #[Locked]
    public bool $allowCreate = true;

    #[Locked]
    public bool $hideEmptySummary = false;

    #[Locked]
    public bool $hideSummary = false;

    #[Locked]
    public string $scope = '';

    public bool $historyOpen = false;

    public function mount(array $context = [], bool $compact = false, bool $allowCreate = true, bool $hideEmptySummary = false, bool $hideSummary = false): void
    {
        $this->context = ActivityContext::normalize($context);
        $this->compact = $compact;
        $this->allowCreate = $allowCreate;
        $this->hideEmptySummary = $hideEmptySummary;
        $this->hideSummary = $hideSummary;
        $this->scope = 'rec-'.substr(md5(json_encode($this->context)), 0, 10);
    }

    #[Computed]
    public function membership()
    {
        return app(CurrentAgency::class)->membership();
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Activity> */
    #[Computed]
    public function activities()
    {
        return ActivityContext::activities($this->membership, $this->context);
    }

    /** record-activities.tsx "accessible": every linked record is visible to the person. */
    #[Computed]
    public function accessible(): bool
    {
        return $this->membership !== null && app(ActivityAccess::class)->linksOf($this->membership)->visible($this->context);
    }

    public function openHistory(): void
    {
        $this->historyOpen = true;
    }

    public function closeHistory(): void
    {
        $this->historyOpen = false;
    }

    #[On('activities-changed')]
    public function refresh(): void
    {
        unset($this->activities);
    }
}; ?>

<section class="crm-record-activities{{ $compact ? ' compact' : '' }}" aria-label="Attività collegate">
    @php
        $summary = \App\Gestionale\Activities\ActivityContext::summary($this->activities);
        $last = $summary['last'];
        $next = $summary['next'];
        $can = $allowCreate && $this->accessible && $this->membership?->isActive();
    @endphp
    @if (! $hideSummary)
        @if ($last || $next)
            <div class="crm-activity-summary">
                @if ($last)
                    <div><h3>Ultima attività</h3>
                        <a class="crm-link" href="{{ route('gestionale.activities.index', ['id' => $last->id]) }}" wire:navigate>{{ $last->kind }} · {{ $last->outcome ?: $last->subject }}</a>
                        <p>{{ $summary['lastAt'] ? \App\Gestionale\Activities\ActivityPresentation::dateLabel($summary['lastAt'], true) : 'Data di svolgimento non indicata' }}</p></div>
                @endif
                @if ($next)
                    <div><h3>Prossimo impegno</h3>
                        <a class="crm-link" href="{{ route('gestionale.activities.index', ['id' => $next->id]) }}" wire:navigate>{{ $next->subject }}</a>
                        <p>{{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($next->scheduled_at, true) }}@if (\App\Gestionale\Activities\ActivityCatalog::isOverdue($next)) · <x-gestionale.activity-pill tone="danger">Scaduto</x-gestionale.activity-pill>@endif</p></div>
                @endif
            </div>
        @elseif (! $hideEmptySummary)
            <p class="crm-muted">Nessuna attività o promemoria registrato.</p>
        @endif
    @endif
    <div class="crm-context-actions crm-direct-activities">
        @if ($can)
            <button type="button" class="crm-btn" wire:click="$dispatch('activity-form', { scope: '{{ $scope }}', mode: 'activity' })"><x-gestionale.activity-icon name="plus" :size="17" />Registra attività</button>
        @endif
        <button type="button" class="crm-btn secondary" wire:click="openHistory"><x-gestionale.activity-icon name="history" :size="17" />Storico attività</button>
    </div>
    <livewire:pages::gestionale.activities.dialogs :scope="$scope" :context="$context" :key="'dialogs-'.$scope" />
    @if ($historyOpen)
        <dialog class="proto-dialog proto-dialog-wide reko-prototype" wire:key="history-{{ $scope }}" aria-labelledby="history-title-{{ $scope }}" x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeHistory()" x-on:click="if ($event.target === $el) $wire.closeHistory()">
            <div class="proto-dialog-heading">
                <div><p class="proto-eyebrow">REKO Gestionale</p><h2 id="history-title-{{ $scope }}">Storico attività e promemoria</h2></div>
                <button type="button" class="crm-dialog-close" aria-label="Chiudi" wire:click="closeHistory"><x-gestionale.activity-icon name="x" :size="16" /></button>
            </div>
            <x-gestionale.activity-timeline :activities="$this->activities" :scope="$scope" order="recent" />
        </dialog>
    @endif
</section>
