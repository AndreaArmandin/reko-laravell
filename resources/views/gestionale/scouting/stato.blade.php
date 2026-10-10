@php
    $progress = $this->progress;
@endphp
<section class="crm-panel crm-zone-layout" aria-label="Stato del censimento">
    <div class="crm-between"><div><h2>Stato del censimento</h2><p class="crm-muted">Il progresso riguarda le particelle nel piano della zona scelta.</p></div>@if ($isAdmin)<button type="button" class="crm-btn" wire:click="openZoneForm">Nuova zona</button>@endif</div>
    <div class="crm-toolbar"><label class="crm-field"><span>Zona</span><select wire:model.live="planId"><option value="">Scegli una zona</option>@foreach ($this->allZones as $zone)<option value="{{ $zone->id }}">{{ $zone->name }}</option>@endforeach</select></label>@if ($isAdmin && $this->planZone)<button type="button" class="crm-btn secondary" wire:click="openZoneForm({{ $this->planZone->id }})">Modifica piano</button>@endif</div>
    @if ($progress === null)
        <div class="crm-empty">Nessuna zona di lavoro. Crea una zona per iniziare il censimento.</div>
    @else
        <div class="crm-form-grid"><div><strong>{{ $progress['percent'] }}%</strong><p>{{ $progress['complete'] }} di {{ $progress['planned'] }} particelle complete</p></div><div><strong>{{ $progress['active'] }}</strong><p>Unità attive</p></div><div><strong>{{ $progress['withoutOwner'] }}</strong><p>Senza proprietario</p></div><div><strong>{{ $progress['withoutContact'] }}</strong><p>Senza recapito</p></div></div>
        <progress max="{{ max(1, $progress['planned']) }}" value="{{ $progress['complete'] }}" aria-label="Avanzamento del censimento"></progress>
        @if ($progress['rows'] === [])
            <div class="crm-empty">Il piano di questa zona non contiene ancora particelle.</div>
        @else
            <div class="overflow-x-auto"><table class="w-full"><thead><tr><th>Comune</th><th>Foglio</th><th>Particella</th><th>Stato</th><th>Unità attive</th><th></th></tr></thead><tbody>@foreach ($progress['rows'] as $row)<tr><td>{{ $row['item']['municipality'] }}</td><td>{{ $row['item']['sheet'] }}</td><td>{{ $row['item']['parcel'] }}</td><td>{{ $row['status'] }}</td><td>{{ $row['active'] }}</td><td>@if ($row['parcelId'])<button type="button" class="crm-link" wire:click="selectPlan({{ $this->planZone->id }})">Apri mappa</button>@endif</td></tr>@endforeach</tbody></table></div>
        @endif
        @if ($progress['lastCheck'] !== '')<p class="crm-muted">Ultima importazione: {{ $progress['lastCheck'] }}</p>@endif
    @endif
</section>
