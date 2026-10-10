@props(['ownerId', 'text' => '', 'contextUnitId' => null, 'editingId' => null, 'editingUnit' => null, 'noticeText' => ''])

<div class="census-contact" wire:key="census-contact-{{ $ownerId }}-{{ $contextUnitId ?? 'owner' }}">
    @if ((int) $editingId === (int) $ownerId && $editingUnit === ($contextUnitId === null ? null : (int) $contextUnitId))
        <form class="census-inline-editor" wire:submit="saveOwnerContact">
            <label class="crm-field"><span>Recapito</span><textarea wire:model="contactDraft" rows="3" maxlength="10000" placeholder="Telefono, WhatsApp, email o annotazione…" autofocus></textarea></label>
            <p class="crm-muted">Il recapito è condiviso tra le unità di questo proprietario.</p>
            @if ($noticeText !== '')<p class="crm-error" role="alert">{{ $noticeText }}</p>@endif
            <div class="crm-actions"><button class="crm-btn" type="submit" wire:loading.attr="disabled" wire:target="saveOwnerContact">Salva</button><button class="crm-btn secondary" type="button" wire:click="closeContact">Annulla</button></div>
        </form>
    @else
        @if ($text !== '')<p class="census-contact-text">{{ $text }}</p>@endif
        <button type="button" class="crm-link" wire:click="editOwnerContact({{ $ownerId }}, {{ $contextUnitId === null ? 'null' : (int) $contextUnitId }})">{{ $text !== '' ? 'Modifica recapito' : 'Aggiungi recapito' }}</button>
    @endif
</div>
