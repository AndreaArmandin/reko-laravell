@props(['title', 'wide' => false, 'label' => 'Simulazione REKO', 'close' => '$wire.closeDialog()'])
@php($uid = 'crm-dialog-'.\Illuminate\Support\Str::random(6))

{{--
    shared.tsx DemoDialog: finestra modale nativa (<dialog>) con titolo, etichetta e pulsante Chiudi.
    Si apre appena compare nella pagina (showModal) e si chiude con Esc, clic sullo sfondo o Chiudi: l'espressione
    "close" (Alpine/Livewire) decide che cosa succede. wire:ignore.self lascia a Livewire il contenuto
    ma non l'attributo "open" della finestra.
--}}
<dialog wire:ignore.self x-data x-init="$nextTick(() => { if ($el.isConnected && ! $el.open) $el.showModal() })"
    class="proto-dialog reko-prototype {{ $wide ? 'proto-dialog-wide' : '' }}" aria-labelledby="{{ $uid }}"
    x-on:cancel.prevent="{{ $close }}" x-on:click="if ($event.target === $el) {{ $close }}">
    <div class="proto-dialog-heading">
        <div><p class="proto-eyebrow">{{ $label }}</p><h2 id="{{ $uid }}">{{ $title }}</h2></div>
        <button type="button" class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg hover:bg-black/5" aria-label="Chiudi" x-on:click="{{ $close }}"><x-gestionale.lucide name="x" :size="16" /></button>
    </div>
    {{ $slot }}
</dialog>
