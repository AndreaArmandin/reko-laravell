<?php

use App\Trova\SisterParser;

it('reads a unit row with every column', function () {
    $r = SisterParser::parse("220\t286\t1\tVIA CAVOUR n. 18 Piano T-1\t002\tA10\t03\t3,5 vani\tR.Euro:1.563,57\t0416099\t");

    expect($r)->not->toBeNull()
        ->and([$r->section, $r->sheet, $r->parcel, $r->sub])->toBe(['', '220', '286', '1'])
        ->and($r->category)->toBe('A/10')
        ->and($r->class)->toBe('03')
        ->and($r->zone)->toBe('002')
        ->and($r->value)->toBe(3.5)
        ->and($r->unit)->toBe('vani')
        ->and($r->rendita)->toBe(1563.57)
        ->and($r->partita)->toBe('0416099')
        ->and($r->floor())->toBe('T-1')
        ->and($r->street())->toBe(['VIA CAVOUR', '18'])
        ->and($r->malformed)->toBeFalse()
        ->and($r->legacyKey('D205'))->toBe('["D205","Fabbricati","","220","286","1"]');
});

it('keeps empty cells so category and consistency do not shift', function () {
    $r = SisterParser::parse("\t001\t0042\t\tVIA ROMA n. 3 Piano 1\t\tC06\t\t20 m²\tR.Euro:100,10\t\t");

    expect($r->sheet)->toBe('1')
        ->and($r->parcel)->toBe('42')
        ->and($r->sub)->toBe('')
        ->and($r->category)->toBe('C/6')
        ->and($r->value)->toBe(20.0)
        ->and($r->unit)->toBe('m²')
        ->and($r->sourceRef())->toStartWith('sister:')
        ->and($r->identityKey())->toStartWith('n:sister:');
});

it('understands sections, thousands separators and cubic metres', function () {
    $r = SisterParser::parse("A/12\t5\t3\tVIA X n. 1\t001\tB01\t1\t1.250 m³\t\t\t");

    expect($r->section)->toBe('A')
        ->and($r->sheet)->toBe('12')
        ->and($r->value)->toBe(1250.0)
        ->and($r->unit)->toBe('m³');
});

it('reads Markdown tables', function () {
    $r = SisterParser::parse('| 12 | 7 | 2 | VIA Y n. 4 Piano 2 | 001 | A02 | 04 | 5 vani | R.Euro:300,00 |  |');

    expect($r->sheet)->toBe('12')->and($r->category)->toBe('A/2')->and($r->value)->toBe(5.0);
});

it('flags suppressed units, common goods and rows that do not fit the columns', function () {
    expect(SisterParser::parse("1\t2\t3\tVIA Z n. 1\t001\tA02\t01\t4 vani\t\t\t\tUnità soppressa")->suppressed)->toBeTrue()
        ->and(SisterParser::parse("1\t2\t3\tVIA Z n. 1\t001\t\t\t\t\t\tBene comune non censibile")->common)->toBeTrue()
        ->and(SisterParser::parse("1\t2\t3\tVIA Z")->malformed)->toBeTrue()
        ->and(SisterParser::parse("1\t2\t3\tVIA Z n. 1\t001\tA02\t01\t4 vani\t\t\tx\ty\tz")->malformed)->toBeTrue();
});

it('does not take notes, headers and totals for data', function () {
    foreach (['', '   ', 'SISTER - Visura', 'Comune: CUNEO', 'Foglio\tParticella\tSub', '| --- | --- |', 'Totale unità: 12', '# nota'] as $line) {
        expect(SisterParser::isNotData($line))->toBeTrue("[{$line}]");
    }
    expect(SisterParser::parse('Sezione A'))->toBeNull()
        ->and(SisterParser::parse("x\ty\tz"))->toBeNull()
        ->and(SisterParser::isNotData("220\t286\t1\tVIA CAVOUR n. 18"))->toBeFalse();
});

it('has no floor or number when the address does not say', function () {
    $r = SisterParser::parse("1\t2\t3\tLOCALITA BOSCO\t001\tA03\t01\t4 vani\t\t\t");

    expect($r->floor())->toBeNull()->and($r->street())->toBe(['LOCALITA BOSCO', null]);
});
