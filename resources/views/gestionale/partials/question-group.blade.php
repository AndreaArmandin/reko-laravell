{{--
    profile.tsx QuestionGroup: titolo, domande, avviso, errore, pulsante di avanzamento e nota di salvataggio.
    $title, $questions, $editable, $operationKnown; $advance = etichetta del pulsante "Continua" (nessuno nell'approfondimento).
    Le risposte in attesa partono insieme al comando di Continua/Indietro/Riepilogo: Livewire invia lo stato già digitato.
--}}
@php
    $error = collect($errors->getBag('default')->messages())->filter(fn ($messages, $key) => $key === 'command' || str_starts_with($key, 'answers.'))->flatten()->first();
    $ready = collect($questions)->every(fn ($q) => ($q['skippable'] ?? true) || App\Gestionale\Questionnaire\Questionnaire::hasAnswer($request->criteria[$q['id']] ?? null));
@endphp
<div class="crm-question">
    <h2 tabindex="-1">{{ $title }}</h2>
    @foreach ($questions as $question)
        @include('gestionale.partials.question', ['question' => $question, 'disabled' => ! $editable || (! $operationKnown && $question['id'] !== 'operation')])
    @endforeach
    @if (! $operationKnown)<p class="crm-info">Scegli prima Acquisto o Locazione.</p>@endif
    @if ($error)<p class="crm-error" role="alert">{{ $error }}</p>@endif
    @if ($advance ?? null)
        <div class="crm-question-actions"><button class="crm-btn" type="button" wire:click="advance" wire:loading.attr="disabled" @disabled(! $ready)>{{ $advance }} <x-gestionale.lucide name="arrow-right" :size="17" /></button></div>
    @endif
    <p class="crm-save-hint" role="status"><x-gestionale.lucide name="save" :size="14" /><span wire:loading.remove>Le risposte vengono salvate. Puoi interrompere e riprendere.</span><span wire:loading>Salvataggio delle risposte…</span></p>
    <p class="crm-muted">I campi facoltativi possono restare da definire. Una risposta già salvata si modifica dal relativo controllo.</p>
</div>
