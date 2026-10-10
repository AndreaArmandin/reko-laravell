@props(['subject' => null, 'context' => null, 'asOwner' => false, 'units' => [], 'compact' => false, 'allowCreate' => true, 'hideEmptySummary' => false, 'hideSummary' => false])
@php
    // <x-gestionale.record-activities :subject="$request" /> (cliente, richiesta, immobile, particella;
    // un contatto senza scheda cliente è un proprietario, oppure :as-owner="true").
    // :context="['contact_id' => 1, 'property_request_id' => 2, …]" per un collegamento diverso;
    // :units="[ids]" restringe a unità catastali di una particella.
    $resolved = $context ?? ($subject ? \App\Gestionale\Activities\ActivityContext::fromSubject($subject, $asOwner, $units) : []);
@endphp
<livewire:pages::gestionale.activities.record-panel
    :context="$resolved"
    :compact="$compact"
    :allow-create="$allowCreate"
    :hide-empty-summary="$hideEmptySummary"
    :hide-summary="$hideSummary"
    :key="'record-activities-'.md5(json_encode($resolved))"
/>
