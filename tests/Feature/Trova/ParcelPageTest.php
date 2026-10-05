<?php

use App\Models\Parcel;
use App\Models\User;
use Database\Seeders\DemoOsmCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(DemoOsmCatalogSeeder::class));

/**
 * A demo parcel with a building, its address and its units in the active release.
 */
function parcelWithUnits(): Parcel
{
    $id = DB::selectOne(
        "SELECT u.parcel_id FROM cadastral_units u
         JOIN cadastral_unit_versions v ON v.cadastral_unit_id = u.id
         WHERE v.status = 'eligible' AND v.address_raw LIKE 'CORSO NIZZA%' AND v.category LIKE 'A/%'
         ORDER BY u.parcel_id LIMIT 1"
    )->parcel_id;

    return Parcel::query()->findOrFail($id);
}

it('shows a parcel with its units', function () {
    $parcel = parcelWithUnits();

    $this->actingAs(User::factory()->create())
        ->get(route('community.search.parcels.show', $parcel))
        ->assertOk()
        ->assertSee('Fg. '.$parcel->sheet)
        ->assertSee('Part. '.$parcel->number)
        ->assertSee('CORSO NIZZA')
        ->assertSee('Abitazione');
});

it('answers 404 for unknown parcels and asks guests to log in', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('community.search.parcels.show', 999999))
        ->assertNotFound();

    auth()->logout();
    $this->get(route('community.search.parcels.show', parcelWithUnits()))->assertRedirect(route('login'));
});

it('links each search result to its parcel page', function () {
    $this->actingAs(User::factory()->create());

    $page = Livewire::test('pages::community.search.index')
        ->set('code', 'X002')
        ->set('min', '1')
        ->set('max', '20')
        ->call('search')
        ->assertHasNoErrors();

    $page->assertSee(route('community.search.parcels.show', $page->get('rows')[0]['parcel_id']), false);
});

it('turns the five Trova choices into search paths', function () {
    $this->actingAs(User::factory()->create());

    $page = Livewire::test('pages::community.search.index')
        ->assertSee('Cosa cerchi?')
        ->assertSee('Locali e spazi per attività')
        ->set('choice', 'garage');
    expect($page->get('segment'))->toBe('private')->and($page->get('housing'))->toBe('garage');

    $page->set('categories', ['C/6'])->set('choice', 'business');
    expect($page->get('segment'))->toBe('business')
        ->and($page->get('housing'))->toBe('')
        ->and($page->get('categories'))->toBe([])
        ->and($page->get('sort'))->toBe('address');

    $page->set('code', 'X002')->set('choice', 'homes')->set('min', '1')->set('max', '20')->call('search')
        ->assertHasNoErrors()
        ->assertSee('particelle');

    // Grandi fabbricati e Terreni non sono ancora disponibili
    Livewire::test('pages::community.search.index')->set('choice', 'land')->assertStatus(422);
});
