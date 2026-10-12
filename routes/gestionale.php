<?php

use App\Http\Controllers\Gestionale\EnterAgencyController;
use App\Http\Controllers\Gestionale\ExportCensusMissingPhonesController;
use App\Http\Controllers\Gestionale\NotificationReadController;
use App\Gestionale\CurrentAgency;
use App\Gestionale\Scouting\ScoutingMap;
use Illuminate\Http\Request;
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
        // Nuovo cliente / Modifica: come nell'originale, la lista o la scheda con la finestra del form aperta.
        Route::livewire('clienti/nuovo', 'pages::gestionale.clients.index')->name('clients.create');
        Route::livewire('clienti/{contact}', 'pages::gestionale.clients.show')->whereNumber('contact')->name('clients.show');
        Route::livewire('clienti/{contact}/modifica', 'pages::gestionale.clients.show')->whereNumber('contact')->name('clients.edit');

        // Ricerche dei clienti (richieste)
        Route::livewire('richieste', 'pages::gestionale.requests.index')->name('requests.index');
        // La richiesta rapida appare come finestra sopra l'elenco, come nell'originale.
        Route::livewire('richieste/nuova', 'pages::gestionale.requests.index')->name('requests.create');
        Route::livewire('richieste/{propertyRequest}', 'pages::gestionale.requests.show')->whereNumber('propertyRequest')->name('requests.show');

        // Immobili di portafoglio e abbinamenti alle ricerche.
        Route::livewire('immobili', 'pages::gestionale.properties.index')->name('properties.index');
        Route::livewire('immobili/nuovo', 'pages::gestionale.properties.form')->name('properties.create');
        Route::livewire('immobili/{property}', 'pages::gestionale.properties.show')->whereNumber('property')->name('properties.show');
        Route::livewire('immobili/{property}/modifica', 'pages::gestionale.properties.form')->whereNumber('property')->name('properties.edit');

        // Agenda, territorio e configurazione dell’agenzia.
        Route::livewire('attivita', 'pages::gestionale.activities.index')->name('activities.index');
        Route::livewire('mappa-zone', 'pages::gestionale.scouting.index')->name('scouting.index');
        Route::get('cartografia/civici', function (Request $request) {
            $bounds = $request->validate([
                'south' => ['required', 'numeric', 'between:-90,90'],
                'west' => ['required', 'numeric', 'between:-180,180'],
                'north' => ['required', 'numeric', 'between:-90,90'],
                'east' => ['required', 'numeric', 'between:-180,180'],
            ]);
            abort_if((float) $bounds['south'] >= (float) $bounds['north'] || (float) $bounds['west'] >= (float) $bounds['east'], 422);

            return response()->json(ScoutingMap::addressPoints([$bounds['south'], $bounds['west'], $bounds['north'], $bounds['east']]));
        })->name('map.address-points');
        Route::livewire('archivio-catastale', 'pages::gestionale.archive.index')->name('archive.index');
        Route::get('archivio-catastale/esporta/codici-senza-telefono', ExportCensusMissingPhonesController::class)
            ->name('archive.export.missing-phone');
        Route::livewire('obiettivi', 'pages::gestionale.goals.index')->name('goals.index');
        Route::livewire('impostazioni', 'pages::gestionale.settings.index')->name('settings.index');
    });
});
