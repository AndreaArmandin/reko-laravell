<?php

return [
    // Giorni senza contatto dopo cui un cliente va richiamato (gestionale settings.contactDays).
    // Diventerà un'impostazione per agenzia nella fase Impostazioni.
    'contact_days' => (int) env('GESTIONALE_CONTACT_DAYS', 7),
];
