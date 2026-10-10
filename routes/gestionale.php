<?php

use App\Http\Controllers\Gestionale\EnterAgencyController;
use App\Http\Controllers\Gestionale\ExportCensusMissingPhonesController;
use App\Http\Controllers\Gestionale\NotificationReadController;
use App\Gestionale\CurrentAgency;
use Illuminate\Support\Facades\Route;

/*
| Gestionale (CRM) dell'agenzia. Permessi dal ruolo nell'agenzia (admin, crm, scout), mai da users.is_admin.
| Fuori dal middleware "agency": scelta dell'agenzia (anche per chi non ne ha nessuna, con un messaggio chiaro).
*/
Route::middleware(['auth'])->prefix('gestionale')->name('gestionale.')->group(function () {
    Route::livewire('agenzia', 'pages::gestionale.choose')->name('choose');
    Route::post('agenzia/{agency}', EnterAgencyController::class)->whereNumber('agency')->name('enter');

    Route::middleware('agency')->group(function () {
        // Come vuoi lavorare nel Gestionale? (profilo di lavoro) e campanella delle notifiche.
        Route::livewire('profilo', 'pages::gestionale.profile')->name('profile');
        Route::post('profilo/cambia', function (CurrentAgency $current) {
            $current->clearWorkProfile();

            return redirect()->route('gestionale.profile');
        })->name('profile.clear');
        Route::post('notifiche/{notification}/letta', NotificationReadController::class)->whereNumber('notification')->name('notifications.read');

        Route::livewire('/', 'pages::gestionale.home')->name('home');

        // Clienti
        Route::livewire('clienti', 'pages::gestionale.clients.index')->name('clients.index');
        Route::livewire('clienti/nuovo', 'pages::gestionale.clients.form')->name('clients.create');
        Route::livewire('clienti/{contact}', 'pages::gestionale.clients.show')->whereNumber('contact')->name('clients.show');
        Route::livewire('clienti/{contact}/modifica', 'pages::gestionale.clients.form')->whereNumber('contact')->name('clients.edit');

        // Ricerche dei clienti (richieste)
        Route::livewire('richieste', 'pages::gestionale.requests.index')->name('requests.index');
        Route::livewire('richieste/nuova', 'pages::gestionale.requests.create')->name('requests.create');
        Route::livewire('richieste/{propertyRequest}', 'pages::gestionale.requests.show')->whereNumber('propertyRequest')->name('requests.show');

        // Immobili di portafoglio e abbinamenti alle ricerche.
        Route::livewire('immobili', 'pages::gestionale.properties.index')->name('properties.index');
        Route::livewire('immobili/nuovo', 'pages::gestionale.properties.form')->name('properties.create');
        Route::livewire('immobili/{property}', 'pages::gestionale.properties.show')->whereNumber('property')->name('properties.show');
        Route::livewire('immobili/{property}/modifica', 'pages::gestionale.properties.form')->whereNumber('property')->name('properties.edit');

        // Agenda, territorio e configurazione dell’agenzia.
        Route::livewire('attivita', 'pages::gestionale.activities.index')->name('activities.index');
        Route::livewire('mappa-zone', 'pages::gestionale.scouting.index')->name('scouting.index');
        Route::livewire('archivio-catastale', 'pages::gestionale.archive.index')->name('archive.index');
        Route::get('archivio-catastale/esporta/codici-senza-telefono', ExportCensusMissingPhonesController::class)
            ->name('archive.export.missing-phone');
        Route::livewire('obiettivi', 'pages::gestionale.goals.index')->name('goals.index');
        Route::livewire('impostazioni', 'pages::gestionale.settings.index')->name('settings.index');

        // Sezioni delle fasi successive (segnaposto)
        Route::livewire('sezione/{section}', 'pages::gestionale.section')->name('section');
    });
});
