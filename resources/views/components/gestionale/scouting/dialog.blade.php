@props(['title', 'close', 'wide' => false, 'label' => 'Simulazione REKO'])
{{-- shared.tsx DemoDialog: <dialog> modale. Livewire lo crea e lo rimuove con @if, la chiusura da tastiera e dallo sfondo chiama $close. --}}
<dialog wire:ignore.self {{ $attributes->class(['proto-dialog', 'reko-prototype', 'proto-dialog-wide' => $wide]) }} aria-labelledby="dialog-title-{{ md5($title) }}"
    x-data x-init="$el.showModal()" x-on:cancel.prevent="{{ $close }}" x-on:click="if ($event.target === $el) {{ $close }}">
    <div class="proto-dialog-heading">
        <div><p class="proto-eyebrow">{{ $label }}</p><h2 id="dialog-title-{{ md5($title) }}">{{ $title }}</h2></div>
        <button type="button" class="crm-dialog-close" aria-label="Chiudi" x-on:click="{{ $close }}"><x-gestionale.lucide name="x" :size="20" /></button>
    </div>
    {{ $slot }}
</dialog>
