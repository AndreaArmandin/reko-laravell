@props(['unit'])

{{-- Range di focused-catalog-journey.tsx: Da e A obbligatori in vani o m² --}}
@php($required = in_array($unit, ['vani', 'm²'], true))
<div class="trova-range">
    <label class="crm-field"><span>Da · {{ $unit }}</span><input inputmode="decimal" type="number" @if ($required) required min="0.01" @else min="0" @endif step="any" wire:model="min" placeholder="{{ $required ? 'Obbligatorio' : 'Facoltativo' }}"></label>
    <label class="crm-field"><span>A · {{ $unit }}</span><input inputmode="decimal" type="number" @if ($required) required min="0.01" @else min="0" @endif step="any" wire:model="max" placeholder="{{ $required ? 'Obbligatorio' : 'Facoltativo' }}"></label>
    <p class="trova-short-note" data-field-help>Il confronto usa la consistenza catastale disponibile in archivio; non equivale sempre alla superficie commerciale o interna.</p>
</div>
