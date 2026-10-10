<?php

use App\Gestionale\Scouting\MapContext;

const TEST_CARTOGRAPHY = [
    ['code' => 'F205', 'municipality' => 'Milano', 'bounds' => [45.3, 9.0, 45.6, 9.3]],
    ['code' => 'D205', 'municipality' => 'Cuneo', 'bounds' => null],
];

it('matches municipality names without accents and reports a point outside loaded coverage', function () {
    expect(MapContext::municipalityForCity('  MILANO ')['code'])->toBe('F205')
        ->and(MapContext::municipalityForCity('Cunéo')['code'])->toBe('D205')
        ->and(MapContext::propertyPointNotice('Milano', ['lat' => 45.7, 'lng' => 9.1], TEST_CARTOGRAPHY))
        ->toBe('Il punto è fuori dalla cartografia caricata per Milano. Verifica Comune e posizione: non viene spostato automaticamente.');
});

it('distinguishes a supported municipality with missing map coverage from an unsupported one', function () {
    expect(MapContext::propertyPointNotice('Cuneo', ['lat' => 44.4, 'lng' => 7.5], TEST_CARTOGRAPHY))
        ->toBe('Cartografia di Cuneo non disponibile: la posizione non può essere verificata.')
        ->and(MapContext::municipalityForCity('Roma'))->toBeNull();
});

it('blocks new or moved points outside supported coverage but preserves an unchanged saved point', function () {
    expect(MapContext::propertyPointSaveError('Milano', ['lat' => 45.7, 'lng' => 9.1], TEST_CARTOGRAPHY, null, true))
        ->toBe('Il punto è fuori dalla cartografia caricata per Milano. Verifica Comune e posizione: non viene spostato automaticamente.')
        ->and(MapContext::propertyPointSaveError('Milano', ['lat' => 45.7, 'lng' => 9.1], TEST_CARTOGRAPHY, ['lat' => 45.7, 'lng' => 9.1], false))
        ->toBe('')
        ->and(MapContext::propertyPointSaveError('Milano', ['lat' => 45.4, 'lng' => 9.1], TEST_CARTOGRAPHY, null, false))
        ->toBe('Indica esplicitamente la posizione sulla mappa. Il punto iniziale non viene attribuito alla nuova scheda.');
});

it('shows an advisory for unsupported cities, allows their explicit valid point, and rejects invalid coordinates', function () {
    expect(MapContext::propertyPointNotice('Roma', ['lat' => 41.9, 'lng' => 12.5], TEST_CARTOGRAPHY))
        ->toBe('Per questo Comune non è disponibile un controllo geografico automatico. Verifica la posizione con la fonte originale.')
        ->and(MapContext::propertyPointSaveError('Roma', ['lat' => 41.9, 'lng' => 12.5], TEST_CARTOGRAPHY, null, true))
        ->toBe('')
        ->and(MapContext::propertyPointSaveError('Milano', ['lat' => 91, 'lng' => 9.1], TEST_CARTOGRAPHY, null, true))
        ->toBe('Coordinate non valide: scegli un punto sulla mappa.');
});
