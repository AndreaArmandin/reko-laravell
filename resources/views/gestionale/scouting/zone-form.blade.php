<x-gestionale.scouting.dialog :title="$zoneFormId === null ? 'Nuova zona di censimento' : 'Modifica zona di censimento'" close="$wire.closeZoneForm()" :wide="true">
    <form wire:submit="saveZone" class="crm-form">
        <p class="crm-muted">Indica la zona e incolla le particelle da censire, una per riga.</p>
        <div class="crm-form-grid">
            <label class="crm-field"><span>Nome</span><input wire:model="zf.name" maxlength="180" required></label>
            <label class="crm-field"><span>Agente acquisizioni</span><select wire:model="zf.operator_id"><option value="">Da assegnare</option>@foreach ($this->scouts as $scout)<option value="{{ $scout->user_id }}">{{ $scout->user->name }}</option>@endforeach</select></label>
            <label class="crm-field"><span>Stato</span><select wire:model="zf.status">@foreach (\App\Gestionale\Scouting\CensusPlan::STATUSES as $status)<option>{{ $status }}</option>@endforeach</select></label>
            <label class="crm-field"><span>Data di inizio</span><input type="date" wire:model="zf.start"></label>
        </div>
        <label class="crm-field"><span>Descrizione</span><textarea wire:model="zf.description" rows="2"></textarea></label>
        <label class="crm-field"><span>Piano delle particelle</span><textarea wire:model="zf.plan" rows="8" placeholder="{{ \App\Gestionale\Scouting\CensusPlan::PLACEHOLDER }}"></textarea></label>
        <p class="crm-muted">Colonne separate da tab: provincia, codice catastale, Comune, Fabbricati o Terreni, sezione, foglio, particella, località.</p>
        @if ($zfError !== '')<p role="alert" class="crm-contact-warning">{{ $zfError }}</p>@endif
        <div class="crm-actions"><button type="button" class="crm-btn secondary" wire:click="closeZoneForm">Annulla</button><button type="submit" class="crm-btn">Salva zona</button></div>
    </form>
</x-gestionale.scouting.dialog>
