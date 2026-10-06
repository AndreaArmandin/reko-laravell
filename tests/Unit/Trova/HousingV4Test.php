<?php

use App\Trova\Floors;
use App\Trova\HousingV4;

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $units  [sub, category, address]
 */
function classifyParcel(array $units): array
{
    return HousingV4::classify(array_map(fn ($u) => ['sub' => $u[0], 'categoria' => $u[1], 'address' => $u[2]], $units))['units'];
}

it('splits a SISTER address into street, civic, staircase and floor', function () {
    expect(HousingV4::address('VIA ROMA n. 12 scala B Piano 2'))
        ->toBe(['via' => 'VIA ROMA', 'civico' => '12', 'scala' => 'B', 'piano' => '2'])
        ->and(HousingV4::address('VIA PO n. 3; VIA DORA n. 9 Piano T-1')['civico'])->toBe('3')
        ->and(HousingV4::address('STRADA SENZA NUMERO')['piano'])->toBe('');
});

it('reads floor levels without inventing any', function (?string $piano, ?array $levels) {
    expect(HousingV4::levels($piano))->toBe($levels);
})->with([
    'ground and first' => ['T-1', [0, 1]],
    'basement' => ['S1', [-1]],
    'raised ground' => ['R', [0]],
    'numbered with P' => ['P2', [2]],
    'separators list levels' => ['3 - 1', [1, 3]],
    'unknown text' => ['ATTICO', null],
    'missing' => ['', null],
]);

it('classifies homes stacked at the same civic number as apartments', function () {
    $units = classifyParcel([
        ['1', 'A/2', 'VIA PO n. 1 Piano T'],
        ['2', 'A/3', 'VIA PO n. 1 Piano 1'],
        ['3', 'A/2', 'VIA PO n. 1 Piano 2'],
    ]);

    expect(array_column($units, 'esito'))->toBe(['APPARTAMENTO', 'APPARTAMENTO', 'APPARTAMENTO'])
        ->and($units[0]['note'])->toBe(['abitazione sopra: sub 2, 3'])
        ->and($units[2]['note'])->toBe(['abitazione sotto: sub 1, 2'])
        ->and($units[1]['conf'])->toBe('alta');
});

it('recognises a vertical independent house from the ground floor', function () {
    $units = classifyParcel([['1', 'A/7', 'VIA PO n. 3 Piano T-1'], ['2', 'C/6', 'VIA PO n. 3 Piano S1']]);

    expect($units)->toHaveCount(1)
        ->and($units[0]['esito'])->toBe('CASA INDIPENDENTE')
        ->and($units[0]['livelli_txt'])->toBe('2 livelli')
        ->and($units[0]['vertical'])->toBeTrue();
});

it('keeps one-level houses and doubtful starts out of the independent search', function () {
    $shop = classifyParcel([['1', 'A/2', 'VIA PO n. 5 Piano 1'], ['2', 'C/1', 'VIA PO n. 5 Piano T']]);
    $nothingBelow = classifyParcel([['1', 'A/2', 'VIA PO n. 7 Piano 1']]);

    expect($shop[0]['esito'])->toBe('CASA INDIPENDENTE')
        ->and($shop[0]['note'])->toBe(["parte dal piano 1: al T c'e' C/1 (non abitazione)"])
        ->and($shop[0]['vertical'])->toBeFalse()
        ->and($nothingBelow[0]['esito'])->toBe('DA VERIFICARE')
        ->and($nothingBelow[0]['note'])->toBe(["parte dal piano 1 ma al T non risulta nessuna unita'"]);
});

it('marks unreadable floors as to be verified', function () {
    $units = classifyParcel([['1', 'A/3', 'VIA PO n. 9 Piano ATTICO']]);

    expect($units[0]['esito'])->toBe('DA VERIFICARE')
        ->and($units[0]['conf'])->toBe('-')
        ->and($units[0]['note'])->toBe(['piano mancante o illeggibile']);
});

it('needs two distinct homes for the apartment search', function () {
    expect(HousingV4::apartmentParcel([['sub' => '1', 'categoria' => 'A/2'], ['sub' => '01', 'categoria' => 'A/3']]))->toBeFalse()
        ->and(HousingV4::apartmentParcel([['sub' => '1', 'categoria' => 'A/2'], ['sub' => '2', 'categoria' => 'A/7']]))->toBeTrue()
        ->and(HousingV4::apartmentParcel([['sub' => '1', 'categoria' => 'A/2'], ['sub' => '2', 'categoria' => 'A/8']]))->toBeFalse();
});

it('summarises the outcomes of a parcel like Trova cards', function () {
    expect(HousingV4::summary(['APPARTAMENTO', 'APPARTAMENTO', 'DA VERIFICARE']))->toBe('2 appartamenti · 1 DA VERIFICARE')
        ->and(HousingV4::summary(['CASA INDIPENDENTE']))->toBe('1 casa indipendente');
});

it('reads residential floors for garages and floor choices', function () {
    expect(Floors::residential('VIA PO n. 1 Piano S1'))->toBe([-1])
        ->and(Floors::residential('VIA PO n. 1 Piano S1-T'))->toBe([-1, 0])
        ->and(Floors::residential('VIA PO n. 1 Piano 10 - 5'))->toBe([5, 10])
        ->and(Floors::residential('VIA PO n. 1 Piano R'))->toBeNull()
        ->and(Floors::label(0))->toBe('Piano terra')
        ->and(Floors::label(-1))->toBe('Piano interrato S1');
});
