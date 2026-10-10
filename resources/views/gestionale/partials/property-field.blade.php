{{-- properties.tsx PropertyValue: un campo di features. $spec = PropertyFields::spec(); il valore sta in $this->features[id]. --}}
@php($id = $spec['id'])
@if ($spec['kind'] === 'boolean')
    <label class="crm-field"><span>{{ $spec['label'] }}</span>
        <select wire:model="features.{{ $id }}"><option value="">Da verificare</option><option value="yes">Sì</option><option value="no">No</option></select>
    </label>
@elseif ($spec['kind'] === 'checks')
    <fieldset class="crm-fieldset"><legend>{{ $spec['label'] }}</legend>
        <div class="crm-inline-checks">
            @foreach ($spec['options'] as $option)
                <label class="crm-check" wire:key="f-{{ $id }}-{{ $loop->index }}"><input type="checkbox" wire:model="features.{{ $id }}" value="{{ $option }}">{{ $option }}</label>
            @endforeach
        </div>
    </fieldset>
@elseif ($spec['kind'] === 'select')
    <label class="crm-field"><span>{{ $spec['label'] }}</span>
        <select wire:model="features.{{ $id }}"><option value="">Da verificare</option>@foreach ($spec['options'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select>
    </label>
@else
    <label class="crm-field"><span>{{ $spec['label'] }}</span>
        <input type="{{ $spec['kind'] === 'number' ? 'number' : ($spec['kind'] === 'date' ? 'date' : 'text') }}" @if ($spec['kind'] === 'number') @if ($id !== 'floor') min="0" @endif step="any" @endif wire:model="features.{{ $id }}">
    </label>
@endif
