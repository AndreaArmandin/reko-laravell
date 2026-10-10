@props(['answered', 'total'])
@if ($total > 0)
    <span class="crm-answer-progress" data-reko-guide="answers" title="Risposte compilate alle domande del percorso base, non un punteggio di compatibilità. Il totale dipende dal percorso della richiesta."><span>{{ $answered }}/{{ $total }} risposte del percorso base</span><progress max="{{ $total }}" value="{{ min($answered, $total) }}" aria-label="Risposte completate"></progress></span>
@endif
