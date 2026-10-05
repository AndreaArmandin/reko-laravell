<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Cerca
    Route::livewire('community/search', 'pages::community.search.index')->name('community.search');
    
    // Singolo risultato
    Route::livewire('community/search/parcels/{parcel}', 'pages::community.search.parcels.show')
    ->name('community.search.parcels.show');
});

// Pannello Admin
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');

    // Agenzie
    Route::livewire('agencies', 'pages::admin.agencies.index')->name('agencies.index');
    Route::livewire('agencies/create', 'pages::admin.agencies.create')->name('agencies.create');
    Route::livewire('agencies/{agency}/edit', 'pages::admin.agencies.edit')->name('agencies.edit');
    
});

require __DIR__ . '/settings.php';
