@props(['id', 'kind' => 'client', 'state' => null, 'at' => null, 'admin' => false])

{{--
    record-lifecycle.tsx LifecycleActions. La pagina espone archive(id), restore(id) e remove(id).
    Archivia e ripristina sono reversibili; "Rimuovi dalle liste" (e, per i clienti, l'informativa sull'anonimizzazione)
    sono del Responsabile. La conferma è una casella nella finestra: il comando parte solo da lì.
--}}
<section class="crm-record-lifecycle" aria-label="Conservazione della scheda" x-data="{ checked: false, confirm: false, privacy: false }">
    @if ($state)
        <p>{{ $state === 'removed' ? 'Rimossa dalla lista' : 'Archiviata' }} · {{ \App\Gestionale\Crm\Presenter::dateLabel($at) }}. Dati e collegamenti conservati.</p>
        <button type="button" class="crm-btn secondary" wire:click="restore({{ $id }})" wire:loading.attr="disabled" wire:target="archive,restore,remove">Ripristina · annulla archiviazione</button>
    @else
        <button type="button" class="crm-link" wire:click="archive({{ $id }})" wire:loading.attr="disabled" wire:target="archive,restore,remove">Archivia</button>
    @endif
    @if ($admin)
        <button type="button" class="crm-link" x-on:click="checked = false; confirm = true">Rimuovi dalle liste</button>
        @if ($kind === 'client')
            <button type="button" class="crm-link" x-on:click="privacy = true">Anonimizzazione · informazioni</button>
        @endif
        <template x-if="privacy">
            <dialog class="proto-dialog reko-prototype" aria-labelledby="privacy-title-{{ $id }}" x-init="$el.showModal()" x-on:cancel.prevent="privacy = false" x-on:click="if ($event.target === $el) privacy = false">
                <div class="proto-dialog-heading">
                    <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="privacy-title-{{ $id }}">Anonimizzazione dei dati</h2></div>
                    <button type="button" class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg hover:bg-black/5" aria-label="Chiudi" x-on:click="privacy = false"><x-gestionale.lucide name="x" :size="16" /></button>
                </div>
                <p>Anonimizzare significa eliminare definitivamente i dati che identificano il cliente, anche dai collegamenti e dallo storico. Non equivale a nascondere una scheda.</p>
                <p class="crm-info">L’anonimizzazione definitiva non è attiva in questo MVP. Nessun dato viene modificato da questa finestra. Per togliere una scheda dalle liste usa “Rimuovi dalle liste”: potrai ripristinarla.</p>
                <button type="button" class="crm-btn" x-on:click="privacy = false">Ho capito</button>
            </dialog>
        </template>
        <template x-if="confirm">
            <dialog class="proto-dialog reko-prototype" aria-labelledby="remove-title-{{ $id }}" x-init="$el.showModal()" x-on:cancel.prevent="confirm = false" x-on:click="if ($event.target === $el) confirm = false">
                <div class="proto-dialog-heading">
                    <div><p class="proto-eyebrow">Simulazione REKO</p><h2 id="remove-title-{{ $id }}">Rimuovi una scheda</h2></div>
                    <button type="button" class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg hover:bg-black/5" aria-label="Chiudi" x-on:click="confirm = false"><x-gestionale.lucide name="x" :size="16" /></button>
                </div>
                <p>Puoi rimuovere la scheda dalle liste attive e ripristinarla in seguito. Non verranno cancellati dati, attività, collegamenti o punteggi.</p>
                <label class="crm-check"><input type="checkbox" x-model="checked">Confermo la rimozione reversibile dalla lista</label>
                <div class="crm-actions">
                    <button type="button" class="crm-btn secondary" x-on:click="confirm = false">Annulla</button>
                    <button type="button" class="crm-btn" x-bind:disabled="! checked" wire:click="remove({{ $id }})" wire:loading.attr="disabled" wire:target="archive,restore,remove" x-on:click="confirm = false">Rimuovi dalla lista</button>
                </div>
            </dialog>
        </template>
    @endif
</section>
