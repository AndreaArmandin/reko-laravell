{{-- profile.tsx QuestionGroup: una domanda della profilazione (fieldset), con obbligatorietà, spiegazione, controllo per tipo e "Dato non ancora noto". --}}
@php
    $label = App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($question);
    $skippable = $question['skippable'] ?? true;
@endphp
<fieldset class="crm-fieldset" wire:key="q-{{ $question['id'] }}" @disabled($disabled)>
    <legend>{{ $label }}</legend>
    @if (! $skippable)<p class="crm-required">Risposta obbligatoria</p>@endif
    @if (! empty($question['explanation']))<p class="crm-muted">{{ $question['explanation'] }}</p>@endif
    @if (! empty($question['sensitive']))<x-gestionale.crm-pill>Facoltativo · non entra nel punteggio</x-gestionale.crm-pill>@endif
    <x-gestionale.answer-input :question="$question" :options="$this->questionnaire->options($question, $request->criteria)" model="answers.{{ $question['id'] }}" :value="$answers[$question['id']] ?? null" :disabled="$disabled" />
    @if ($skippable)
        <button type="button" class="crm-link" wire:click="clearAnswer('{{ $question['id'] }}')" wire:loading.attr="disabled" @disabled($disabled) aria-label="Lascia da definire: {{ $label }}">Dato non ancora noto</button><small>Lascia vuota questa risposta; potrai completarla più avanti. Se è già compilata, il comando la svuota.</small>
    @endif
</fieldset>
