{{-- census-import.tsx CensusImport: finestra «Importa da SISTER». Logica di anteprima e importazione nei metodi della pagina. --}}
@php
    $split = \App\Gestionale\Census\SisterText::split(implode("\n", array_filter([$sisterEstatesText, $sisterOwnersText])));
    $hasTarget = $sisterTargetUnitId !== '';
    $busyTarget = 'submitSisterImport,sisterEstateFile,sisterOwnerFile';
@endphp
<x-gestionale.crm-dialog title="Importa da SISTER" label="Archivio catastale" wide :close="'$wire.closeSisterImport()'">
    @if ($sisterResult)
        <section class="crm-form" role="status">
            <h3>{{ $sisterResult['title'] ?? 'Importazione completata' }}</h3>
            <p>{{ $sisterResult['detail'] ?? 'Dati catastali salvati.' }}</p>
            <button type="button" class="crm-btn" wire:click="openImportedSisterResults">Apri la scheda nell’Archivio catastale</button>
            <button type="button" class="crm-btn secondary" wire:click="closeSisterImport">Chiudi</button>
        </section>
    @else
        <form class="crm-form" novalidate wire:submit="submitSisterImport">
            @if ($sisterTargetLocked && $hasTarget)
                <section class="census-import-target" aria-label="Immobile collegato"><span>Immobile collegato</span><strong>{{ $this->sisterTargetLabel }}</strong><p>Gli intestatari saranno associati soltanto a questo immobile.</p></section>
            @endif
            <div class="census-paste-grid">
                <section class="census-paste-section" aria-label="Dati degli immobili">
                    <label class="crm-field"><span>{{ $sisterTargetLocked ? 'Testo immobili · facoltativo, immobile già selezionato' : 'Testo immobili' }}</span><textarea wire:model="sisterEstatesText" rows="5" maxlength="500000" placeholder="Incolla qui solo l’elenco immobili: foglio, particella, subalterno e dati catastali, mantenendo le colonne."></textarea></label>
                    <p class="crm-muted">{{ $sisterTargetLocked ? 'Puoi lasciare vuoto questo campo.' : 'Puoi importare i soli immobili oppure aggiungere gli intestatari nel campo seguente.' }}</p>
                    <details><summary>Carica un file di testo immobili</summary><label class="crm-field"><span>File immobili (.txt)</span><input type="file" accept=".txt,text/plain" wire:model="sisterEstateFile"></label></details>
                </section>
                <section class="census-paste-section" aria-label="Dati dei proprietari">
                    <label class="crm-field"><span>Testo proprietari</span><textarea wire:model="sisterOwnersText" rows="5" maxlength="150000" placeholder="Incolla qui solo la tabella intestatari: nominativo, codice fiscale o P.IVA, diritto e quota."></textarea></label>
                    <p class="crm-muted">{{ $sisterTargetLocked ? 'Incolla gli intestatari dell’immobile indicato sopra.' : 'Per più immobili dello stesso soggetto, incolla qui il proprietario e sopra il suo elenco immobili.' }}</p>
                    <details><summary>Carica un file di testo proprietari</summary><label class="crm-field"><span>File proprietari (.txt)</span><input type="file" accept=".txt,text/plain" wire:model="sisterOwnerFile"></label></details>
                </section>
            </div>
            <small>Le righe soppresse e i beni comuni non censibili vengono scartati. Controlla l’anteprima prima di salvare.</small>
            @if (! $sisterTargetLocked && ($split['owners'] !== '' || $hasTarget) && ! $split['subjectRows'])
                <section aria-label="Unità da collegare">
                    <h3>Unità a cui collegare i proprietari</h3>
                    @if ($hasTarget)
                        <p><strong>{{ $this->sisterTargetLabel }}</strong> <button type="button" class="crm-link" wire:click="chooseSisterTarget('')">Cambia unità</button></p>
                    @else
                        <label class="crm-field"><span>Cerca unità per Comune, foglio, particella o indirizzo</span><input type="search" wire:model.live.debounce.300ms="sisterTargetQuery" placeholder="Es. 309 · 387 oppure l’indirizzo"></label>
                        <div class="census-picker-results">@foreach ($this->sisterTargetMatches as $candidate)<button type="button" class="crm-btn secondary" wire:key="sister-target-{{ $candidate['id'] }}" wire:click="chooseSisterTarget('{{ $candidate['id'] }}')">{{ $candidate['label'] }}</button>@endforeach</div>
                        @if ($this->sisterTargetMatches === [])<p>Nessuna unità trovata nell’archivio caricato. Puoi incollare la riga catastale insieme agli intestatari.</p>@endif
                    @endif
                </section>
            @endif
            @unless ($sisterTargetLocked)
                <details @if ($sisterCode === '') open @endif>
                    <summary>Comune e catasto · {{ $sisterCode !== '' ? $sisterMunicipality : 'da indicare' }}</summary>
                    <div class="crm-form-grid">
                        @include('gestionale.archive.municipality-picker', ['id' => 'sister-municipality', 'model' => 'sisterMunicipality', 'openProperty' => 'sisterPickerOpen',
                            'options' => $sisterPickerOpen ? $this->sisterMunicipalityOptions : [], 'activeIndex' => $sisterOptionIndex, 'isOpen' => $sisterPickerOpen,
                            'select' => 'selectSisterMunicipality', 'move' => 'moveSisterOption', 'choose' => 'chooseSisterOption', 'close' => 'closeSisterPicker',
                            'listLabel' => 'Comuni italiani', 'emptyText' => 'Nessun Comune con questo nome.',
                            'hint' => 'Cerca e seleziona un Comune italiano. Le schede compaiono solo se già acquisite in REKO.'])
                        <label class="crm-field"><span>Provincia</span><input value="{{ $sisterProvince }}" readonly></label>
                        <label class="crm-field"><span>Codice catastale</span><input value="{{ $sisterCode }}" readonly></label>
                        <label class="crm-field"><span>Catasto</span><select wire:model="sisterKind"><option>Fabbricati</option><option>Terreni</option></select></label>
                        <label class="crm-field"><span>Sezione · se presente</span><input wire:model="sisterSection" maxlength="12"></label>
                    </div>
                </details>
                <label class="crm-field"><span>Data fonte · se non presente nel testo SISTER</span><input type="date" wire:model="sisterSituationDate"></label>
            @endunless
            @if ($sisterPreview)
                <section class="crm-panel" aria-label="Anteprima importazione">
                    <h3>{{ $sisterPreview['detected'] }}</h3>
                    <p>{{ $sisterPreview['context']['municipality'] }} · {{ count($sisterPreview['valid']) }} unità · {{ count($sisterPreview['owners']) }} {{ count($sisterPreview['owners']) === 1 ? 'proprietario' : 'proprietari' }}</p>
                    @foreach ($sisterPreview['warnings'] as $warning)<p class="crm-import-warning" role="status">{{ $warning }}</p>@endforeach
                    @foreach ($sisterPreview['unresolvedCommunes'] as $commune)<p class="crm-form-error" role="alert">Comune non riconosciuto: {{ $commune['municipality'] }} ({{ $commune['province'] }}). Controlla provincia e codice catastale prima di importare.</p>@endforeach
                    @if ($sisterPreview['ownerPreview'] !== [])
                        <div class="crm-table-wrap"><table><thead><tr><th>Nominativo</th><th>CF / P.IVA</th><th>Diritto</th><th>Quota</th><th>Esito</th></tr></thead><tbody>
                            @foreach ($sisterPreview['ownerPreview'] as $owner)
                                <tr><td>{{ $owner['name'] }}</td><td>{{ $owner['pid'] }}</td><td>{{ $owner['right'] ?: 'Da indicare per ogni immobile' }}</td><td>{{ $owner['fraction'] ?: 'Vedi immobili' }}</td><td>{{ $owner['outcome'] }}
                                    @if ($owner['outcome'] === 'Nome diverso')
                                        <small>In archivio: {{ $owner['previousName'] }}</small>
                                        <small>Senza conferma, il nome già salvato resta invariato.</small>
                                        @if ($this->membership->role === 'admin')
                                            <label class="crm-check"><input type="checkbox" aria-label="Aggiorna nome di {{ $owner['pid'] }}" wire:model.live="sisterConfirmNameUpdates" value="{{ $owner['pid'] }}">Aggiorna nome?</label>
                                            @if ($owner['existingOwnerId'])<button type="button" class="crm-link" wire:click="openOwnerEditor({{ $owner['existingOwnerId'] }})">Modifica anagrafica</button>@endif
                                        @else
                                            <small>Solo l’Amministratore può cambiare il nome.</small>
                                        @endif
                                    @endif
                                </td></tr>
                            @endforeach
                        </tbody></table></div>
                    @endif
                    <div class="crm-table-wrap"><table><thead><tr><th>Comune</th><th>Unità</th><th>Categoria</th><th>Esito</th></tr></thead><tbody>
                        @foreach ($sisterPreview['rows'] as $row)<tr><td>{{ $row['context']['municipality'] }}</td><td>F. {{ $row['context']['sheet'] }} · P. {{ $row['context']['parcel'] }} · Sub. {{ $row['unit']['sub'] ?: '—' }}</td><td>{{ $row['unit']['category'] ?: '—' }}</td><td>{{ $row['status'] }}{{ $row['message'] !== '' ? ' · '.$row['message'] : '' }}</td></tr>@endforeach
                    </tbody></table></div>
                    @if ($sisterPreview['missingOwners'] !== [])
                        <fieldset class="crm-fieldset"><legend>Intestatari assenti dalla nuova visura</legend>
                            @foreach ($sisterPreview['missingOwners'] as $owner)
                                <div><strong>{{ $owner['name'] }} · {{ $owner['pid'] ?: 'CF non presente' }}</strong><p>Non compare più nella visura: mantenere o chiudere?</p>
                                    <label class="crm-check"><input type="radio" name="owner-decision-{{ $owner['key'] }}" wire:model.live="sisterOwnerDecisions.{{ $owner['key'] }}" value="maintain">Mantieni l’intestazione attuale</label>
                                    <label class="crm-check"><input type="radio" name="owner-decision-{{ $owner['key'] }}" wire:model.live="sisterOwnerDecisions.{{ $owner['key'] }}" value="close">Chiudi l’intestazione e conservala nello storico</label>
                                </div>
                            @endforeach
                            <small>La chiusura riguarda soltanto questa unità. Anagrafica, altre proprietà e attività restano conservate. La data esatta del trasferimento resta da verificare.</small>
                        </fieldset>
                    @endif
                    <p>Controlla Comune, unità e collegamenti. Il pulsante finale conferma e salva le scelte mostrate.</p>
                    @if (collect($sisterPreview['rows'])->contains(fn ($row) => in_array($row['status'], ['Conflitto', 'Incompleto', 'Non riconosciuto'], true)))
                        <label class="crm-check"><input type="checkbox" wire:model.live="sisterSkipInvalid">Importa solo le righe valide, lasciando invariate quelle escluse.</label>
                    @endif
                </section>
            @endif
            @if ($sisterError !== '')<p class="crm-form-error" role="alert" tabindex="-1" x-data x-init="$nextTick(() => { $el.focus(); $el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }) })">{{ $sisterError }}</p>@endif
            @error('sisterEstateFile')<p class="crm-form-error" role="alert">{{ $message }}</p>@enderror
            @error('sisterOwnerFile')<p class="crm-form-error" role="alert">{{ $message }}</p>@enderror
            <button class="crm-btn" wire:loading.attr="disabled" wire:target="{{ $busyTarget }}" @disabled($sisterPreview && ($sisterPreview['errors'] !== [] || $sisterPreview['unresolvedCommunes'] !== []))>
                <span wire:loading.remove wire:target="{{ $busyTarget }}">{{ $sisterPreview ? 'Conferma e salva i collegamenti' : 'Controlla il testo' }}</span><span wire:loading wire:target="{{ $busyTarget }}">Operazione in corso…</span>
            </button>
        </form>
    @endif
</x-gestionale.crm-dialog>
