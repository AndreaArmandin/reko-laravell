<?php

use Illuminate\Support\Facades\Route;

// Home di REKO, uguale a quella di Trova
Route::livewire('/', 'pages::trova.home')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Trova: stesso percorso guidato e stessa grafica dell'app originale
    Route::livewire('trova', 'pages::trova.index')->name('trova');
});

// Pannello Admin
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');

    // Agenzie
    Route::livewire('agencies', 'pages::admin.agencies.index')->name('agencies.index');
    Route::livewire('agencies/create', 'pages::admin.agencies.create')->name('agencies.create');
    Route::livewire('agencies/{agency}/edit', 'pages::admin.agencies.edit')->name('agencies.edit');

    // Catalogo: edizioni SISTER in bozza, sagome delle particelle, pubblicazione
    Route::livewire('catalog', 'pages::admin.catalog.index')->name('catalog.index');

    // Zone di ricerca (quartieri, frazioni) da file GeoJSON
    Route::livewire('zones', 'pages::admin.zones.index')->name('zones.index');

});

require __DIR__.'/gestionale.php';
require __DIR__.'/settings.php';
