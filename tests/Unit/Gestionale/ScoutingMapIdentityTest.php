<?php

use App\Gestionale\Scouting\ScoutingMap;

it('keeps same-number Fabbricati and Terreni parcels distinct on the map', function () {
    $building = ScoutingMap::outlineKey('f205', '', '00012', '00034', 'F');
    $land = ScoutingMap::outlineKey('F205', '_', '12', '34', 'T');

    expect($building)->not->toBe($land)
        ->and($building)->toBe(ScoutingMap::outlineKey('F205', '_', '12', '34', 'F'));
});
