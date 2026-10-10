@props(['activities', 'scope' => 'agenda', 'order' => 'recent'])
@php
    // common.tsx Timeline: righe con stato, esito, aggiornamenti e azioni. Le azioni aprono le finestre
    // del componente "dialogs" con lo stesso scope (vedi x-gestionale.record-activities).
    $actor = app(\App\Gestionale\CurrentAgency::class)->membership();
    $access = app(\App\Gestionale\Activities\ActivityAccess::class);
    $items = collect($activities)->values();
    if ($order === 'recent') {
        $items = $items->sort(fn ($a, $b) => ($b->created_at?->getTimestamp() ?? 0) <=> ($a->created_at?->getTimestamp() ?? 0))->values();
    } elseif ($order === 'agenda') {
        $items = collect(\App\Gestionale\Activities\ActivityCatalog::sort($items, 'priority'));
    }
    $names = \App\Gestionale\Activities\ActivityPresentation::userNames($items);
    $nameOf = fn ($id) => $names[$id] ?? 'operatore precedente';
    $go = fn (string $url) => 'Livewire.navigate('.\Illuminate\Support\Js::from($url).')';
    $dispatch = fn (string $event, int $id, string $extra = '') => '$dispatch(\''.$event.'\', { scope: '.\Illuminate\Support\Js::from($scope).', id: '.$id.$extra.' })';
@endphp
@if ($items->isNotEmpty())
    <div class="crm-timeline">
        @foreach ($items as $a)
            @php
                $done = $a->isDone();
                $suspended = \App\Gestionale\Activities\ActivityCatalog::suspendedAcquisition($a);
                $when = $a->crm_operation ? $a->created_at : $a->scheduled_at;
                $responses = $a->events->where('kind', 'response');
                $open = ! $done && $a->outcome_confirmed_at === null && $a->status !== 'Annullata';
            @endphp
            <article wire:key="activity-{{ $scope }}-{{ $a->id }}">
                <span class="crm-timeline-icon {{ $done ? 'done' : '' }}"><x-gestionale.activity-icon :name="$done ? 'check' : 'calendar-days'" :size="16" /></span>
                <div class="crm-grow">
                    <div class="crm-timeline-heading"><strong>{{ $a->subject }}</strong><time datetime="{{ $when?->toIso8601String() }}">{{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($when, true) }}</time></div>
                    <small class="crm-timeline-metadata">{{ $a->kind }} · {{ $done ? 'Registrata da' : 'Responsabile:' }} {{ $nameOf($done ? $a->created_by_user_id : $a->assigned_to_user_id) }}</small>
                    @if ($done)<small>Creata il {{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($a->created_at, true) }}</small>@endif
                    <p>{{ $a->notes ?: $a->kind }}</p>
                    @if ($suspended)<p>Storico incarichi · percorso sospeso</p>@endif
                    @if (\App\Gestionale\Activities\ActivityCatalog::isOverdue($a))
                        <x-gestionale.activity-pill tone="danger">Scaduto</x-gestionale.activity-pill>
                    @else
                        <x-gestionale.activity-pill>{{ $a->status ?: ($done ? 'Completata' : 'Da svolgere') }}</x-gestionale.activity-pill>
                    @endif
                    @if ($a->contact_operation)<p>Sentito per {{ mb_strtolower($a->contact_operation) }}</p>@endif
                    @if ($a->outcome)<p><strong>Esito:</strong> {{ $a->outcome }}</p>@endif
                    @if ($responses->isNotEmpty())
                        <details wire:ignore.self>
                            <summary>Aggiornamenti dell’attività</summary>
                            @foreach ($responses as $r)
                                <p>{{ \App\Gestionale\Activities\ActivityPresentation::dateLabel($r->occurred_at, true) }} · {{ $names[$r->user_id] ?? '' }}<br><strong>{{ \App\Gestionale\Activities\ActivityCatalog::responseLabel($r->payload['action'] ?? '') }}</strong> · {{ $r->payload['reason'] ?? '' }}</p>
                            @endforeach
                        </details>
                    @endif
                    <div class="crm-activity-row-actions">
                        @if (! $suspended && $actor && $access->canComplete($actor, $a) && $open)
                            <button type="button" class="crm-btn" wire:click="{{ $a->kind === 'Promemoria' ? $dispatch('activity-done', $a->id) : $dispatch('activity-form', $a->id, ', done: true') }}">{{ $a->kind === 'Telefonata' ? 'Registra esito' : 'Segna come fatta' }}</button>
                        @endif
                        <details class="crm-row-menu" wire:ignore.self>
                            <summary aria-label="Altre azioni per {{ $a->subject }}">⋯ <span>Azioni</span></summary>
                            <div class="crm-context-actions">
                                @if ($a->owner_contact_id)<button type="button" onclick="{{ $go(route('gestionale.archive.index', ['owner' => $a->owner_contact_id])) }}">Apri proprietario</button>@endif
                                @if ($a->contact_id)<button type="button" onclick="{{ $go(route('gestionale.clients.show', $a->contact_id)) }}">Cliente</button>@endif
                                @if ($a->property_request_id)<button type="button" onclick="{{ $go(route('gestionale.requests.show', $a->property_request_id)) }}">Richiesta</button>@endif
                                @if ($a->property_id)<button type="button" onclick="{{ $go(route('gestionale.properties.show', $a->property_id)) }}">Immobile</button>@endif
                                @if ($a->parcel_id && $actor && $actor->role !== 'crm')<button type="button" onclick="{{ $go(route('gestionale.archive.index', ['parcel' => $a->parcel_id] + ($a->units->count() === 1 ? ['unit' => $a->units->first()->cadastral_unit_id] : []))) }}">Apri immobile catastale</button>@endif
                                @if (! $suspended && $actor && $access->canEdit($actor, $a))
                                    <button type="button" wire:click="{{ $dispatch('activity-form', $a->id) }}">{{ $done || $a->outcome_confirmed_at ? 'Correggi con motivazione' : 'Modifica' }}</button>
                                @endif
                                @if (! $suspended && $actor && $access->canComplete($actor, $a) && $open)
                                    @if ($a->status === 'Da svolgere' && $a->created_by_user_id !== $a->assigned_to_user_id)
                                        <button type="button" wire:click="{{ $dispatch('activity-accept', $a->id) }}">Accetta</button>
                                    @endif
                                    @foreach (['Rinvia', 'Chiedi chiarimenti', 'Annulla'] as $action)
                                        <button type="button" wire:click="{{ $dispatch('activity-response', $a->id, ', response: '.\Illuminate\Support\Js::from($action)) }}">{{ \App\Gestionale\Activities\ActivityCatalog::responseLabel($action) }}</button>
                                    @endforeach
                                @endif
                            </div>
                        </details>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
@else
    <div class="crm-empty">Nessuna attività registrata.</div>
@endif
