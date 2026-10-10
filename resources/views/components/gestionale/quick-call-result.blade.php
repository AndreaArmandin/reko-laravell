@props(['activity'])
{{-- Esito rapido di una telefonata (quick-call-result.tsx): <x-gestionale.quick-call-result :activity="$activity" />.
     Dopo il salvataggio invia l'evento "activities-changed" alla pagina. --}}
<livewire:pages::gestionale.activities.call-result :activity-id="$activity->id" :key="'call-result-'.$activity->id" />
