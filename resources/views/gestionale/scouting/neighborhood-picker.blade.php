<x-gestionale.scouting.dialog title="Quartieri e frazioni REKO" close="$wire.closePublicZones()" :wide="true">
    <div class="crm-public-zone-content crm-form">
        <p>Scegli un’area del Comune sulla mappa oppure nell’elenco.</p>
        <div class="crm-public-zone-map"><x-gestionale.map key="public-zone-map" :config="$this->pickerPayload" :sync="$this->pickerPayload" label="Quartieri e frazioni del Comune" /></div>
        <label class="crm-field"><span>Quartiere o frazione</span><select wire:model.live="pickedPublicZone"><option value="">Tutto il Comune</option>@foreach ($this->neighborhoods as $zone)<option value="{{ $zone['id'] }}">{{ $zone['name'] }}</option>@endforeach</select></label>
        @php($pickedZone = collect($this->neighborhoods)->firstWhere('id', (int) $pickedPublicZone))
        @if (($pickedZone['notice'] ?? '') !== '')<p class="crm-import-warning">{{ $pickedZone['notice'] }}</p>@endif
        @if ($this->neighborhoods === [])<p class="crm-muted">Non ci sono ancora poligoni di quartiere per questo Comune.</p>@endif
        <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="clearPublicZone">Tutto il Comune</button><button type="button" class="crm-btn" wire:click="applyPublicZone">Applica zona</button></div>
    </div>
</x-gestionale.scouting.dialog>
