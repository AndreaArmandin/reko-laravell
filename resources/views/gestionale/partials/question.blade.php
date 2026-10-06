{{-- Una domanda della profilazione: etichetta per l'operatore, obbligatorietà, spiegazione, controllo per tipo, "Dato non ancora noto". --}}
<flux:field wire:key="q-{{ $question['id'] }}">
    <div class="flex flex-wrap items-center gap-2">
        <flux:label>{{ App\Gestionale\Questionnaire\AnswerPresenter::questionLabel($question) }}</flux:label>
        @if ($question['id'] === 'operation')
            <flux:badge size="sm" color="amber">Risposta obbligatoria</flux:badge>
        @endif
        @if (! empty($question['sensitive']))
            <flux:badge size="sm" color="zinc">Facoltativo · non entra nel punteggio</flux:badge>
        @endif
    </div>
    @if (! empty($question['explanation']))
        <flux:description>{{ $question['explanation'] }}</flux:description>
    @endif
    <x-gestionale.answer-input :question="$question" :options="$this->questionnaire->options($question, $request->criteria)" model="answers.{{ $question['id'] }}" :disabled="$disabled" />
    @error('answers.'.$question['id']) <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
    @if (! $disabled && $question['id'] !== 'operation' && App\Gestionale\Questionnaire\Questionnaire::hasAnswer($request->criteria[$question['id']] ?? null))
        <div><flux:button size="xs" variant="ghost" wire:click="clearAnswer('{{ $question['id'] }}')">Dato non ancora noto</flux:button></div>
    @endif
</flux:field>
