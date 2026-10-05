<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// Pannello Admin
Route::livewire('/admin', 'pages::admin.dashboard')
    ->middleware(['auth', 'admin'])
    ->name('admin.dashboard');

require __DIR__ . '/settings.php';
