<?php

use App\Trova\Street;

it('normalizes addresses as Trova does', function (string $raw, string $expected) {
    expect(Street::normalize($raw))->toBe($expected);
})->with([
    ['Via Roma', 'VIA ROMA'],
    ['  via   roma  ', 'VIA ROMA'],
    ["Via Sant'Anna, 5", 'VIA SANT ANNA 5'],
    ['Via Sant’Anna', 'VIA SANT ANNA'],
    ['Piazza della Libertà', 'PIAZZA DELLA LIBERTA'],
    ['Via XX Settembre 4/B', 'VIA XX SETTEMBRE 4 B'],
    ['12; VIA ROMA', 'VIA ROMA'],
    ['12; Rossi', '12 ROSSI'],
]);
