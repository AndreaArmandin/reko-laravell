<?php

use App\Trova\CatalogSearch;
use App\Trova\SearchException;

$base = ['code' => 'X001', 'segment' => 'private', 'min' => 1, 'max' => 10];

it('fills defaults for a minimal private search', function () use ($base) {
    $criteria = CatalogSearch::validate($base);

    expect($criteria)->toMatchArray([
        'code' => 'X001',
        'segment' => 'private',
        'housing' => null,
        'groups' => ['A'],
        'categories' => [],
        'min' => 1.0,
        'max' => 10.0,
        'address' => '',
        'circle' => null,
        'sort' => 'surface-desc',
        'page' => 1,
        'pageSize' => 10,
    ]);
});

it('derives groups from the path and business type', function () use ($base) {
    expect(CatalogSearch::validate([...$base, 'housing' => 'garage'])['groups'])->toBe(['C6'])
        ->and(CatalogSearch::validate([...$base, 'housing' => ''])['housing'])->toBeNull()
        ->and(CatalogSearch::validate(['code' => 'X001', 'segment' => 'business', 'businessType' => 'shop', 'min' => 10, 'max' => 50])['groups'])->toBe(['C1'])
        ->and(CatalogSearch::validate(['code' => 'X001', 'segment' => 'business'])['groups'])->toContain('A10', 'D', 'F');
});

it('normalizes and deduplicates categories, sheet and address', function () use ($base) {
    $criteria = CatalogSearch::validate([...$base, 'categories' => ['a 07', 'A/7', 'A2'], 'sheet' => ' 012 ', 'address' => "via sant'anna"]);

    expect($criteria['categories'])->toBe(['A/7', 'A/2'])
        ->and($criteria['sheet'])->toBe('12')
        ->and($criteria['address'])->toBe('VIA SANT ANNA')
        ->and(CatalogSearch::cadastralId('0007a'))->toBe('7A')
        ->and(CatalogSearch::cadastralId('000'))->toBe('0');
});

it('accepts numeric strings for the range', function () use ($base) {
    $criteria = CatalogSearch::validate([...$base, 'min' => '3', 'max' => '5.5']);

    expect($criteria['min'])->toBe(3.0)->and($criteria['max'])->toBe(5.5);
});

it('refuses invalid criteria with Trova messages', function (array $input, string $message) use ($base) {
    expect(fn () => CatalogSearch::validate([...$base, ...$input]))->toThrow(SearchException::class, $message);
})->with([
    'no municipality' => [['code' => ''], 'Seleziona un Comune con archivio disponibile.'],
    'unknown path' => [['segment' => 'corporate'], 'Percorso non valido.'],
    'garage outside private' => [['segment' => 'business', 'housing' => 'garage'], 'Scegli abitazioni o box auto nel percorso Private.'],
    'housing not ported yet' => [['housing' => 'apartment'], 'Scegli abitazioni o box auto nel percorso Private.'],
    'unknown business type' => [['segment' => 'business', 'businessType' => 'bar'], 'Categoria non valida.'],
    'category outside the path' => [['categories' => ['C/1']], 'Categoria catastale non valida.'],
    'hidden category' => [['categories' => ['B/1']], 'Categoria catastale non valida.'],
    'not a category' => [['categories' => ['X/1']], 'Categoria catastale non valida.'],
    'min above max' => [['min' => 6, 'max' => 3], 'Il minimo non può superare il massimo.'],
    'negative range' => [['min' => -1], 'Inserisci un intervallo positivo.'],
    'text range' => [['max' => 'tanti'], 'Inserisci un intervallo positivo.'],
    'mixed measures' => [['segment' => 'business', 'min' => 10], 'Per un intervallo scegli solo categorie in vani, solo categorie in m² oppure solo categorie in m³.'],
    'no measure' => [['segment' => 'business', 'businessType' => 'production', 'min' => 10], 'Per un intervallo scegli solo categorie in vani, solo categorie in m² oppure solo categorie in m³.'],
    'long filter' => [['address' => str_repeat('a', 201)], 'Filtro troppo lungo.'],
    'unknown sort' => [['sort' => 'popularity'], 'Ordinamento non valido.'],
    'distance without point' => [['sort' => 'distance'], 'L\'ordinamento per distanza richiede un punto sulla mappa.'],
    'page zero' => [['page' => 0], 'Pagina non valida.'],
    'page as text' => [['page' => '2'], 'Pagina non valida.'],
    'page size' => [['pageSize' => 15], 'Numero di risultati per pagina non valido.'],
    'homes without A' => [['max' => ''], 'Indica entrambi i valori in vani: Da e A.'],
    'homes with zero' => [['min' => 0], 'Inserisci valori maggiori di zero in vani.'],
    'garage without range' => [['housing' => 'garage', 'min' => null, 'max' => null], 'Indica entrambi i valori in m²: Da e A.'],
    'shop without range' => [['segment' => 'business', 'businessType' => 'shop', 'min' => null, 'max' => null], 'Indica entrambi i valori in m²: Da e A.'],
    'office without range' => [['segment' => 'business', 'businessType' => 'office', 'min' => null, 'max' => null], 'Indica entrambi i valori in vani: Da e A.'],
]);

it('keeps the range optional when measures are mixed or missing', function () {
    expect(CatalogSearch::validate(['code' => 'X001', 'segment' => 'business'])['min'])->toBeNull()
        ->and(CatalogSearch::validate(['code' => 'X001', 'segment' => 'business', 'businessType' => 'production'])['max'])->toBeNull()
        ->and(CatalogSearch::requiredMeasure('business', null, ['C1'], []))->toBe('m²')
        ->and(CatalogSearch::requiredMeasure('business', null, ['C1', 'D'], []))->toBeNull()
        ->and(CatalogSearch::requiredMeasure('business', null, ['C1', 'D'], ['A/10']))->toBe('vani');
});


it('accepts a circle with default radius and distance sort', function () use ($base) {
    $criteria = CatalogSearch::validate([
        ...$base,
        'circle' => ['lat' => 44.39, 'lng' => 7.55],
        'sort' => 'distance',
    ]);

    expect($criteria['circle'])->toBe(['lat' => 44.39, 'lng' => 7.55, 'radius' => 500])
        ->and($criteria['sort'])->toBe('distance');
});

it('normalizes circle radius and refuses invalid circles', function () use ($base) {
    expect(CatalogSearch::validate([...$base, 'circle' => ['lat' => '44.4', 'lng' => '7.5', 'radius' => 1000]])['circle']['radius'])->toBe(1000)
        ->and(CatalogSearch::validate([...$base, 'circle' => ['lat' => 44.4, 'lng' => 7.5, 'radius' => 500.0]])['circle']['radius'])->toBe(500);

    expect(fn () => CatalogSearch::validate([...$base, 'circle' => ['lat' => 44.4]]))
        ->toThrow(SearchException::class, 'Indica latitudine e longitudine del punto.')
        ->and(fn () => CatalogSearch::validate([...$base, 'circle' => ['lat' => 91, 'lng' => 0]]))
        ->toThrow(SearchException::class, 'Coordinate fuori intervallo.')
        ->and(fn () => CatalogSearch::validate([...$base, 'circle' => ['lat' => 44.4, 'lng' => 7.5, 'radius' => 275]]))
        ->toThrow(SearchException::class, 'Il raggio deve essere tra 200 e 5000 metri, a passi di 50.')
        ->and(fn () => CatalogSearch::validate([...$base, 'circle' => ['lat' => 44.4, 'lng' => 7.5, 'radius' => 100]]))
        ->toThrow(SearchException::class, 'Il raggio deve essere tra 200 e 5000 metri, a passi di 50.')
        ->and(fn () => CatalogSearch::validate([...$base, 'circle' => 'near']))
        ->toThrow(SearchException::class, 'Punto di ricerca non valido.');
});
