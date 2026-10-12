<x-gestionale.scouting.dialog :title="$zoneFormId === null ? 'Nuova Scouting Zone' : 'Piano di censimento'" close="$wire.closeZoneForm()" :wide="true">
    <form wire:submit="saveZone" class="crm-form">
        <label class="crm-field"><span>Nome zona</span><input wire:model="zf.name" maxlength="180" required></label>
        <label class="crm-field"><span>Descrizione</span><textarea wire:model="zf.description"></textarea></label>
        <div class="crm-form-grid">
            <label class="crm-field"><span>Operatore assegnato</span><select wire:model="zf.operator_id" required>@foreach ($this->scouts as $scout)<option value="{{ $scout->user_id }}">{{ $scout->user->name }}</option>@endforeach</select></label>
            <label class="crm-field"><span>Stato zona</span><select wire:model="zf.status">@foreach (\App\Gestionale\Scouting\CensusPlan::STATUSES as $status)<option>{{ $status }}</option>@endforeach</select></label>
            <label class="crm-field"><span>Inizio censimento</span><input type="date" wire:model="zf.start"></label>
        </div>
        <label class="crm-field"><span>Particelle previste nel piano</span><textarea wire:model="zf.plan" rows="8" required placeholder="{{ \App\Gestionale\Scouting\CensusPlan::PLACEHOLDER }}"></textarea></label>
        <small>Una particella per riga, colonne separate da tabulazioni. Il piano determina la completezza: le sole importazioni non bastano a dichiarare conclusa la zona.</small>
        @if ($zfError !== '')<p role="alert">{{ $zfError }}</p>@endif
        <button type="submit" class="crm-btn">Salva piano</button>
    </form>
</x-gestionale.scouting.dialog>
