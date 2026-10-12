{{--
    scouting-area.tsx UnitRecord: scheda dell'unità, nell'elenco Immobili ($mainOwnerId nullo) oppure sotto un proprietario.
    Variabili: $entry (CensusReader), $mainOwnerId, $cardContactId (recapito in modifica, nullo se lo modifica la finestra dell'anagrafica).
--}}
@php
    $unit = $entry['unit'];
    $active = \App\Gestionale\Census\CensusQuery::active($unit);
    $mainOwner = $mainOwnerId === null ? null : collect($entry['owners'])->firstWhere('id', $mainOwnerId);
    $coowners = collect($entry['owners'])->reject(fn ($o) => $o['id'] === $mainOwnerId)->values()->all();
    $shownOwners = in_array($entry['unitId'], $expandedUnits, true) ? $coowners : array_slice($coowners, 0, 2);
    $holding = $mainOwner['holding'] ?? null;
    $address = $entry['address'] ?: $entry['parcelAddress'];
    $canImport = $this->membership->allows('sister.import') || \App\Gestionale\Census\CensusImporter::scoutMayReplaceOwners($this->membership, $entry['unitId']);
    $date = fn (?string $value, bool $time = false) => \App\Gestionale\Activities\ActivityPresentation::dateLabel($value, $time);
    // toLocaleString('it'): al massimo tre decimali, senza zeri finali.
    $income = $unit['income'] === null ? 'non indicata' : rtrim(rtrim(number_format(round((float) $unit['income'], 3), 3, ',', '.'), '0'), ',').' €';
@endphp
<article class="census-unit-record {{ $active ? '' : 'census-inactive' }}" data-census-unit="{{ $entry['parcelId'] }}:{{ $entry['unitId'] }}" wire:key="census-unit-{{ $mainOwnerId ?? 'list' }}-{{ $entry['unitId'] }}">
    <div class="census-unit-head">
        <div><strong>{{ $address ?: 'Indirizzo non presente nella fonte' }}</strong><small>{{ $entry['context']['municipality'] }} · {{ $unit['source'] === 'sister-text' ? 'Censimento Sister' : ($unit['source'] === 'demo' ? 'Dati di esempio' : 'Archivio catastale') }}@if ($entry['geographyStatus'] === 'missing') · Foglio catastale non caricato @endif</small></div>
        @if (! $active)<x-gestionale.crm-pill>{{ $unit['state'] }}</x-gestionale.crm-pill>@endif
        @if ($mainOwner === null && $this->membership->role === 'admin')<label class="crm-check"><input type="checkbox" aria-label="Seleziona immobile {{ $entry['context']['sheet'] }} · {{ $entry['context']['parcel'] }} · subalterno {{ $unit['sub'] ?: 'non indicato' }}" wire:model.live="selectedUnitIds" value="{{ $entry['unitId'] }}">Seleziona</label>@endif
    </div>
    <div class="census-extra-facts"><dl class="census-unit-facts">
        @foreach (['Foglio' => $entry['context']['sheet'], 'Particella' => $entry['context']['parcel'], 'Subalterno' => $unit['sub'] ?: '—', 'Piano' => \App\Gestionale\Census\SisterText::floorLabel($unit['floor']), 'Categoria' => $unit['category'] ?: '—', 'Consistenza' => \App\Gestionale\Census\SisterText::formatMeasure($unit['consistency'] ?? '') ?: '—'] as $label => $value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl></div>
    @if ($mainOwner && $holding)<p data-reko-guide="holding">{{ $holding['right'] ?: 'Diritto non indicato' }} · quota {{ $holding['fraction'] ?: 'non indicata' }}</p>@endif
    <div class="census-unit-body">
        <div>
            <h4>{{ count($coowners) ? ($mainOwner ? 'Comproprietari' : 'Proprietari').' · '.count($coowners) : ($mainOwner ? 'Nessun comproprietario' : 'Proprietario non associato') }}</h4>
            @foreach ($shownOwners as $owner)
                <div class="census-owner-in-unit" wire:key="census-owner-{{ $entry['unitId'] }}-{{ $owner['id'] }}">
                    <strong>{{ preg_match('/^\d+$/', $owner['name']) ? 'Intestatario '.$owner['name'].' · nome da verificare' : $owner['name'] }}</strong>
                    @if ($owner['multiOwner'])<x-gestionale.crm-pill>Multi proprietario · {{ $owner['unitCount'] }} unità</x-gestionale.crm-pill>@endif
                    <small data-reko-guide="holding" title="Diritto e quota sulla singola unità. Se non indicati, verifica la fonte: non vengono presunti.">{{ $owner['holding']['right'] ?? 'Diritto non indicato' }} {{ $owner['holding']['fraction'] ?? '' }}</small>
                    @if ($mainOwner === null)
                        <x-gestionale.census-contact-editor :owner-id="$owner['id']" :text="\App\Gestionale\Census\CensusReader::contactText($owner)" :context-unit-id="$entry['unitId']" :editing-id="$cardContactId" :editing-unit="$contactContextUnitId" :notice-text="$notice" />
                        <div class="crm-actions">@if ($this->membership->role !== 'crm')<button type="button" class="crm-link" wire:click="openOutcome({{ $entry['unitId'] }}, {{ $owner['id'] }})">Registra contatto</button>@endif<button type="button" class="crm-link" wire:click="openProposal(null, {{ $owner['id'] }}, @js($owner['name']), @js($owner['pid']))">Segnala una correzione</button></div>
                    @endif
                </div>
            @endforeach
            @if (count($coowners) > 2)<button type="button" class="crm-link" wire:click="toggleCoowners({{ $entry['unitId'] }})">{{ in_array($entry['unitId'], $expandedUnits, true) ? 'Mostra meno' : 'Mostra altri '.(count($coowners) - 2) }}</button>@endif
        </div>
        <div>
            @if (! $entry['latest'] && ! $entry['upcoming'])
                <p class="crm-muted">Nessun riscontro o impegno registrato.</p>
            @else
                @if ($entry['latest'])<p><strong>{{ $entry['latest']['outcome'] }}</strong>@if ($entry['latest']['contactOperation'])<small>Sentito per {{ mb_strtolower($entry['latest']['contactOperation']) }}</small>@endif<small>{{ $date($entry['latest']['at'], true) }} · {{ $entry['latest']['author'] }}</small></p>@endif
                @if ($entry['upcoming'])<p>Prossimo impegno: {{ $entry['upcoming']['type'] }} · {{ $date($entry['upcoming']['dueAt'], true) }}</p>@endif
            @endif
            <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="openOutcomeHistory({{ $entry['unitId'] }})"><x-gestionale.activity-icon name="history" :size="17" />Storico attività</button></div>
            <div class="crm-actions">
                <details class="census-actions-menu"><summary aria-label="Azioni per {{ $address ?: 'immobile' }} · foglio {{ $entry['context']['sheet'] }} · particella {{ $entry['context']['parcel'] }} · subalterno {{ $unit['sub'] ?: 'non indicato' }}">⋯ Azioni</summary>
                    <button type="button" wire:click="openProposal({{ $entry['unitId'] }})">Segnala una correzione</button>
                    @if ($canImport)<button type="button" wire:click="openSisterImport({{ $entry['unitId'] }})">Importa o aggiorna proprietari</button>@endif
                    @if ($this->membership->role === 'admin')<button type="button" wire:click="openCadastralEditor({{ $entry['unitId'] }})">Correggi dati catastali</button>@endif
                </details>
            </div>
        </div>
    </div>
    <x-gestionale.record-activities :context="['parcel_id' => $entry['parcelId'], 'unit_ids' => [$entry['unitId']]]" compact hide-summary :allow-create="$active" hide-history />
    @if ($entry['needsReview'])<p class="crm-info">{{ $entry['locationWarning'] }}</p>@endif
    <details class="census-source"><summary>Dati di provenienza e testo originale</summary><p>Catasto {{ $entry['context']['kind'] }} · {{ $entry['context']['code'] }} · Sezione {{ $entry['context']['section'] ?: 'non indicata' }} · Data fonte {{ $date($unit['situationDate'] ?: $entry['context']['situationDate']) }}<br>Classe {{ $unit['cadastralClass'] ?: '—' }} · Rendita {{ $income }} · Partita {{ $unit['batch'] ?: '—' }}</p><pre>{{ $unit['rawText'] ?: 'Nessun testo importato' }}</pre></details>
</article>
