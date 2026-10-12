@php
    $pickedZone = collect($this->neighborhoods)->firstWhere('id', (int) $pickedPublicZone);
@endphp
<x-gestionale.scouting.dialog title="Quartieri e frazioni REKO" :label="$municipalityCode === 'F205' ? 'Quartieri di Milano' : 'Zone indicative REKO'" close="$wire.closePublicZones()" :wide="true">
    {{-- public-neighborhood-picker.tsx: il nome sotto la mappa segue il passaggio del mouse (evento map-area-hover), altrimenti la zona scelta. --}}
    <div class="crm-form crm-public-zone-content" x-data="{ hover: '' }" x-on:map-area-hover="if ($event.detail.key === 'public-zone-map') hover = $event.detail.name">
        <label>Zona del Comune<select aria-label="Quartiere o frazione REKO" wire:model.live="pickedPublicZone"><option value="">Tutto il Comune</option>@foreach ($this->neighborhoods as $zone)<option value="{{ $zone['id'] }}">{{ $zone['name'] }}</option>@endforeach</select></label>
        <div class="crm-public-zone-map"><x-gestionale.map key="public-zone-map" :config="$this->pickerPayload" :sync="$this->pickerPayload" label="Quartieri e frazioni del Comune" /></div>
        <p role="status"><span x-show="hover" x-cloak x-text="hover"></span><span x-show="! hover">{{ $pickedZone['name'] ?? 'Tocca una zona sulla mappa oppure scegli dall’elenco.' }}</span></p>
        <small>{{ \App\Gestionale\Scouting\MapContext::NEIGHBORHOOD_NOTICE }} Il filtro considera un punto interno alla particella, senza modificarne il perimetro. Le zone operative dell’agenzia restano separate.</small>
        <div class="crm-actions"><button type="button" class="crm-btn" wire:click="applyPublicZone">Applica zona</button><button type="button" class="crm-btn secondary" wire:click="clearPublicZone">Tutto il Comune</button></div>
    </div>
</x-gestionale.scouting.dialog>
