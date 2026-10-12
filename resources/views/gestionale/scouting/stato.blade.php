@php
    use App\Gestionale\Scouting\Format;

    $progress = $this->progress;
    $zone = $this->planZone;
@endphp
<section class="crm-panel">
    <div class="crm-between"><h2>Stato del censimento</h2>@if ($isAdmin)<button type="button" class="crm-btn secondary" wire:click="openZoneForm">Nuova Scouting Zone</button>@endif</div>
    @if ($zone === null)
        <div class="crm-empty">Nessun piano definito. L’importazione libera resta disponibile nella mappa.</div>
    @else
        @php
            $plan = (array) $zone->plan;
            $towns = array_values(array_unique(array_column($plan, 'municipality')));
            $localities = array_values(array_filter(array_unique(array_column($plan, 'locality'))));
        @endphp
        <label class="crm-field"><span>Scouting Zone</span><select wire:change="selectPlan($event.target.value)">@foreach ($this->allZones as $option)<option value="{{ $option->id }}" @selected($option->id === $zone->id)>{{ $option->name }}</option>@endforeach</select></label>
        <h3>{{ $zone->name }}</h3>
        <p>{{ $zone->notes }}</p>
        <p>{{ $zone->status }} · {{ $this->planOperator }} · Inizio {{ $zone->starts_on?->toDateString() ?? 'non indicato' }} · Aggiornata {{ Format::dateLabel($zone->updated_at, true) }}</p>
        <p>{{ implode(' · ', $towns) }}<br>{{ implode(' · ', $localities) }}</p>
        <div class="crm-actions">
            @if ($canSister)<a class="crm-btn" href="{{ route('gestionale.archive.index', ['importSister' => 1, 'municipalityCode' => \App\Gestionale\Scouting\CensusPlan::zoneMunicipalities($zone)[0]]) }}" wire:navigate>Importa da Sister nella zona</a>@endif
            @if ($isAdmin)<button type="button" class="crm-btn secondary" wire:click="openZoneForm({{ $zone->id }})">Modifica piano</button>@endif
        </div>
        @if ($progress !== null)
            <h3>{{ $progress['complete'] }}/{{ $progress['planned'] }} particelle complete nel piano · {{ $progress['percent'] }}%</h3>
            <p class="crm-muted">Completo significa: importazione verificata, intestatari e recapiti presenti sulle unità attive. Non certifica che i proprietari siano interessati o che il territorio fuori dal piano sia censito.</p>
            <div class="census-import-stats">
                @foreach ([['Importazioni', count($progress['batches'])], ['Comuni', count($progress['municipalities'])], ['Fogli', count($progress['sheets'])], ['Particelle', $progress['parcels']], ['Unità attive', $progress['active']], ['Soppresse', $progress['suppressed']], ['Senza proprietario', $progress['withoutOwner']], ['Proprietari senza recapito', $progress['withoutContact']], ['Righe incomplete', $progress['incomplete']], ['Conflitti', $progress['conflicts']], ['Doppioni evitati', $progress['duplicates']]] as [$statLabel, $statValue])
                    <div><strong>{{ $statValue }}</strong><small>{{ $statLabel }}</small></div>
                @endforeach
            </div>
            <p>Ultimo controllo: {{ $progress['lastCheck'] !== '' ? Format::dateLabel($progress['lastCheck'], true) : 'da eseguire' }}</p>
            <div class="crm-table-wrap"><table>
                <thead><tr><th>Comune / località</th><th>Foglio</th><th>Particella</th><th>Attive / soppresse</th><th>Stato del censimento</th></tr></thead>
                <tbody>@foreach ($progress['rows'] as $row)
                    <tr wire:key="plan-row-{{ $row['key'] }}">
                        <td>{{ $row['item']['municipality'] }} · {{ $row['item']['code'] }}<small>{{ $row['item']['locality'] ?? '' }}</small></td>
                        <td>{{ $row['item']['sheet'] }}</td>
                        <td>{{ $row['item']['parcel'] }}</td>
                        <td>{{ $row['active'] }} / {{ $row['suppressed'] }}</td>
                        <td><x-gestionale.scouting.pill>{{ $row['status'] }}</x-gestionale.scouting.pill>@if ($row['parcelId'] !== null)<a class="crm-link" href="{{ route('gestionale.archive.index', ['parcel' => $row['parcelId']]) }}" wire:navigate>Apri elenco immobili</a>@endif</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($progress['pending'] !== [])
                <details class="census-source" wire:ignore.self>
                    <summary>Righe da verificare ({{ count($progress['pending']) }})</summary>
                    @foreach ($progress['pending'] as $issue)
                        <div wire:key="plan-issue-{{ $issue['unitKey'] ?? $loop->index }}">
                            <p>Riga {{ $issue['line'] ?? '' }} · {{ $issue['context']['municipality'] ?? '' }} @if (($issue['context']['sheet'] ?? '') !== '')· F. {{ $issue['context']['sheet'] }} P. {{ $issue['context']['parcel'] ?? '' }} @endif· {{ $issue['message'] ?? '' }}</p>
                            @if (($issue['rawText'] ?? '') !== '')<pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $issue['rawText'] }}</pre>@endif
                        </div>
                    @endforeach
                </details>
            @endif
            <details class="census-source" wire:ignore.self>
                <summary>Importazioni effettuate ({{ count($progress['batches']) }})</summary>
                @foreach ($progress['batches'] as $batch)
                    <p wire:key="plan-batch-{{ $batch['id'] }}">{{ Format::dateLabel($batch['at'], true) }} · {{ $batch['summary']['rows'] ?? 0 }} righe · {{ $batch['summary']['newUnits'] ?? 0 }} nuovi immobili · {{ $batch['summary']['newOwners'] ?? 0 }} nuovi proprietari · {{ $batch['summary']['reusedOwners'] ?? 0 }} riutilizzati</p>
                @endforeach
            </details>
        @endif
    @endif
</section>
