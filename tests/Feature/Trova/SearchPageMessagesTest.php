<?php

use App\Models\Municipality;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DemoCatalogSeeder::class);
    $this->actingAs(User::factory()->create());
});

it('shows wrong criteria as an error without retry', function () {
    Livewire::test('pages::community.search.index')
        ->set('min', '5')->set('max', '3')
        ->call('search')
        ->assertHasErrors('search')
        ->assertSet('unavailable', null)
        ->assertDontSee('Riprova');
});

it('offers a retry when the archive is not available', function () {
    Municipality::query()->create(['cadastral_code' => 'X999', 'name' => 'Senza archivio']);

    Livewire::test('pages::community.search.index')
        ->set('code', 'X999')->set('min', '1')->set('max', '10')
        ->call('search')
        ->assertHasNoErrors()
        ->assertSet('unavailable', 'Archivio del Comune in preparazione. Riprova tra poco.')
        ->assertSet('rows', [])
        ->assertSee('Riprova');
});

it('retries the same search once the archive is back', function () {
    DB::statement('ALTER TABLE parcel_search_points RENAME TO parcel_search_points_broken');

    $page = Livewire::test('pages::community.search.index')
        ->set('min', '1')->set('max', '10')
        ->call('search')
        ->assertSet('unavailable', 'Ricerca momentaneamente non disponibile. Riprova tra poco.')
        ->assertDontSee('SQLSTATE');

    DB::statement('ALTER TABLE parcel_search_points_broken RENAME TO parcel_search_points');

    $page->call('retry')
        ->assertSet('unavailable', null);
    expect($page->get('total'))->toBeGreaterThan(0);
});

it('suggests a wider range when nothing matches', function () {
    Livewire::test('pages::community.search.index')
        ->set('min', '900')->set('max', '1000')
        ->call('search')
        ->assertSet('total', 0)
        ->assertSee('Nessuna particella corrisponde ai criteri.')
        ->assertSee('Amplia l\'intervallo del 15%', false)
        ->call('widenRange')
        ->assertSet('min', '765')
        ->assertSet('max', '1150');
});

it('suggests a wider radius when nothing matches around the point', function () {
    Livewire::test('pages::community.search.index')
        ->set('min', '1')->set('max', '10')
        ->call('setPoint', 0.0, 0.0) // nothing near the Gulf of Guinea
        ->call('search')
        ->assertSet('total', 0)
        ->assertSee('Amplia il raggio a 1 km')
        ->call('widenRadius')
        ->assertSet('radius', 1000)
        ->assertSee('Amplia il raggio a 2 km');
});
