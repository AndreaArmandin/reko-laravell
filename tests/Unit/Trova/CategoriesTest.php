<?php

use App\Trova\Categories;

it('normalizes the ways a category is written', function (string $raw, string $expected) {
    expect(Categories::normalize($raw))->toBe($expected);
})->with([
    ['A/2', 'A/2'],
    ['a 02', 'A/2'],
    ['A2', 'A/2'],
    [' c/06 ', 'C/6'],
    ['A/10', 'A/10'],
    ['D/10', 'D/10'],
    ['X/1', ''],
    ['A/', ''],
    ['', ''],
]);

it('maps categories to Trova search groups', function (string $category, string $group) {
    expect(Categories::group($category))->toBe($group);
})->with([
    ['A/1', 'A'],
    ['A/9', 'A'],
    ['A/10', 'A10'],
    ['C/1', 'C1'],
    ['C/6', 'C6'],
    ['D/7', 'D'],
    ['B/4', 'B'],
    ['E/1', 'E'],
    ['F/2', 'F'],
    ['nonsense', ''],
]);

it('knows the measure of each category', function (string $category, ?string $unit) {
    expect(Categories::dimensionUnit($category))->toBe($unit);
})->with([
    ['A/2', 'vani'],
    ['A/10', 'vani'],
    ['C/6', 'm²'],
    ['F/1', 'm²'],
    ['F/5', 'm²'],
    ['F/2', null],
    ['B/1', 'm³'],
    ['D/1', null],
]);

it('limits each search path to its groups', function () {
    expect(Categories::scopeGroups('private'))->toBe(['A'])
        ->and(Categories::scopeGroups('private', 'garage'))->toBe(['C6'])
        ->and(Categories::scopeGroups('business'))->toBe(Categories::BUSINESS_GROUPS)
        ->and(Categories::scopeGroups(null))->toBeNull();
});

it('never shows B and E categories', function () {
    expect(Categories::isVisible('A/2'))->toBeTrue()
        ->and(Categories::isVisible('B/1'))->toBeFalse()
        ->and(Categories::isVisible('E/1'))->toBeFalse()
        ->and(Categories::isVisible('A/2', 'business'))->toBeFalse()
        ->and(Categories::isVisible('C/6', 'private', 'garage'))->toBeTrue()
        ->and(Categories::isVisible('C/1', 'private'))->toBeFalse();

    expect(Categories::selectable(['A']))->toBe(['A/1', 'A/2', 'A/3', 'A/4', 'A/5', 'A/6', 'A/7', 'A/8', 'A/9'])
        ->and(Categories::selectable(['A', 'B', 'E']))->not->toContain('B/1')
        ->and(Categories::selectable(['A', 'B', 'E']))->not->toContain('E/1');
});

it('gives readable names', function () {
    expect(Categories::label('A/2'))->toBe('Abitazione civile')
        ->and(Categories::label('a 07'))->toBe('Villino')
        ->and(Categories::label('C/6'))->toBe('Box, rimessa o autorimessa')
        ->and(Categories::label('X/1'))->toBe('Immobile · tipologia non specificata');
});
