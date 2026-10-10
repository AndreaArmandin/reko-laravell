@props(['question', 'options' => null, 'model', 'value' => null, 'wm' => 'wire:model.live.debounce.650ms'])
@php
    use App\Gestionale\Questionnaire\AnswerPresenter as A;

    // profile.tsx AnswerInput: il controllo giusto per ogni tipo di domanda. Il valore del campo si converte
    // con AnswerPresenter::fromField. $wm sceglie come i campi di testo arrivano al server (autosalvataggio
    // dopo 650 ms nella profilazione, alla conferma nelle finestre); i pulsanti inviano subito.
    $q = $question;
    $type = $q['type'];
    $id = $q['id'];
    $label = A::questionLabel($q);
    $choices = $options ?? $q['options'] ?? [];
    $text = is_scalar($value) ? (string) $value : '';
    $part = fn (string $key) => is_array($value) ? (string) ($value[$key] ?? '') : '';
@endphp

@if ($id === 'tags')
    <x-gestionale.crm-tag-input :model="$model" :value="is_array($value) ? $value : []" label="Tag della richiesta" :live="$wm !== 'wire:model'" />
@elseif ($id === 'activity')
    <textarea aria-label="{{ $label }}" {!! $wm !!}="{{ $model }}" maxlength="4000" rows="3" placeholder="Descrivi liberamente l’attività prevista"></textarea>
@elseif (in_array($type, ['boolean', 'single', 'priority'], true))
    @php
        $pairs = $type === 'boolean' ? [['Sì', '1'], ['No', '0']] : array_map(fn ($option) => [$option, (string) $option], $choices);
    @endphp
    <div class="crm-answer-options" role="group" aria-label="{{ $label }}">
        @foreach ($pairs as [$option, $actual])
            @php
                $selected = $text !== '' && $text === $actual;
            @endphp
            <button type="button" wire:key="opt-{{ $model }}-{{ $loop->index }}" aria-label="{{ $label.': '.$option }}" aria-pressed="{{ $selected ? 'true' : 'false' }}" class="{{ $selected ? 'selected' : '' }}"
                wire:click="$set('{{ $model }}', @js($actual))"><span>{{ $option }}</span>@if ($selected)<x-gestionale.lucide name="check" :size="19" />@else<span class="crm-radio-dot"></span>@endif</button>
        @endforeach
    </div>
@elseif ($type === 'multi')
    @php
        $current = is_array($value) ? $value : [];
    @endphp
    <div class="crm-answer-options compact" role="group" aria-label="{{ $label }}">
        @foreach ($choices as $option)
            <label wire:key="opt-{{ $model }}-{{ $loop->index }}" class="{{ in_array($option, $current, true) ? 'selected' : '' }}"><input type="checkbox" aria-label="{{ $label.': '.$option }}" {!! $wm !!}="{{ $model }}" value="{{ $option }}">{{ $option }}</label>
        @endforeach
    </div>
@elseif ($type === 'range')
    @php
        $min = match ($id) { 'area' => 'Minimo · m²', 'bedrooms' => 'Minimo · camere da letto', 'budget' => 'Da · €', default => 'Minimo' };
        $max = match ($id) { 'area' => 'Massimo · m²', 'bedrooms' => 'Massimo · camere da letto', 'budget' => 'A · €', default => 'Massimo' };
    @endphp
    <div class="crm-form-grid">
        <label class="crm-field"><span>{{ $min }}</span><input type="number" min="0" step="{{ $id === 'bedrooms' ? '1' : 'any' }}" {!! $wm !!}="{{ $model }}.min"></label>
        <label class="crm-field"><span>{{ $max }}</span><input type="number" min="0" step="{{ $id === 'bedrooms' ? '1' : 'any' }}" {!! $wm !!}="{{ $model }}.max"></label>
    </div>
@elseif ($type === 'financing')
    <div class="crm-form-grid">
        <label class="crm-field"><span>Importo indicato · €</span><input type="number" min="0" step="any" placeholder="Se già noto" {!! $wm !!}="{{ $model }}.amount"></label>
        <label class="crm-field"><span>Quota del prezzo · %</span><input type="number" min="0" max="100" step="any" placeholder="In alternativa all’importo" {!! $wm !!}="{{ $model }}.percent"></label>
    </div>
@elseif ($type === 'zone')
    @php
        // Senza risposta il punto parte da Milano, entro 2 km; resta una proposta finché non viene scelto o confermato.
        $has = is_array($value) && $part('lat') !== '' && $part('lng') !== '';
        $point = ['label' => $has ? $part('label') : 'Milano', 'lat' => $has ? (float) $part('lat') : 45.4642, 'lng' => $has ? (float) $part('lng') : 9.19, 'radius' => $has && $part('radius') !== '' ? (float) $part('radius') : 2.0];
    @endphp
    <div class="crm-location-input">
        <p class="crm-muted">Tocca un punto sulla mappa e indica il raggio. Il punto scelto viene salvato nella richiesta.</p>
        <x-gestionale.map key="pick-{{ $model }}" mode="pick-point" label="Mappa per scegliere il punto della ricerca"
            :config="['point' => $point, 'zoom' => 12]" :sync="['point' => $point]" />
        <div class="crm-form-grid">
            <label class="crm-field"><span>Nome del punto</span><input {!! $wm !!}="{{ $model }}.label"></label>
            <label class="crm-field"><span>Entro quanti km</span><input type="number" min="0.1" step="0.1" {!! $wm !!}="{{ $model }}.radius"></label>
        </div>
        <button class="crm-btn secondary" type="button" wire:click="$set('{{ $model }}', @js(array_map('strval', $point)))">Conferma questo punto</button>
    </div>
@elseif ($type === 'amount')
    <input type="number" aria-label="{{ $label }}" min="0" step="any" inputmode="decimal" {!! $wm !!}="{{ $model }}" placeholder="Puoi lasciarlo da definire">
@elseif ($type === 'date')
    <label class="crm-field"><span>Data indicativa</span><input type="date" {!! $wm !!}="{{ $model }}"></label>
@else
    <label class="crm-field"><span>Risposta del cliente</span><textarea maxlength="4000" {!! $wm !!}="{{ $model }}" placeholder="Scrivi soltanto informazioni dimostrative"></textarea></label>
@endif
