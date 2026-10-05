<?php

use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\CadastralUnit;
use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

it('creates the Trova foundation and defers CRM tables', function () {
    expect(DB::selectOne("select extname from pg_extension where extname = 'postgis'"))->not->toBeNull();

    foreach (['agencies', 'agency_memberships', 'municipalities', 'parcels', 'cadastral_units',
        'catalog_releases', 'municipality_catalogs', 'parcel_versions', 'cadastral_unit_versions',
        'buildings', 'import_runs', 'import_issues'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }

    foreach (['contacts', 'properties', 'activities', 'acquisitions'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    expect(Schema::hasColumn('parcels', 'agency_id'))->toBeFalse();
    expect(Schema::hasColumn('import_runs', 'agency_id'))->toBeFalse();
});

it('distinguishes cadastral kind and units without subalterno', function () {
    $municipality = Municipality::query()->create(['cadastral_code' => 'F205', 'name' => 'Milano']);
    $fabbricato = Parcel::query()->create([
        'municipality_id' => $municipality->id, 'cadastral_kind' => 'F', 'sheet' => '1', 'number' => '10',
    ]);
    $terreno = Parcel::query()->create([
        'municipality_id' => $municipality->id, 'cadastral_kind' => 'T', 'sheet' => '1', 'number' => '10',
    ]);
    expect($fabbricato->id)->not->toBe($terreno->id);

    CadastralUnit::query()->create(['parcel_id' => $fabbricato->id, 'subalterno' => '1']);
    CadastralUnit::query()->create(['parcel_id' => $fabbricato->id, 'source_ref' => 'source:a']);
    CadastralUnit::query()->create(['parcel_id' => $fabbricato->id, 'source_ref' => 'source:b']);

    expect(CadastralUnit::query()->where('parcel_id', $fabbricato->id)->count())->toBe(3);

    expect(fn () => DB::transaction(fn () => CadastralUnit::query()->create([
        'parcel_id' => $fabbricato->id, 'subalterno' => '1',
    ])))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => CadastralUnit::query()->create([
        'parcel_id' => $fabbricato->id,
    ])))->toThrow(QueryException::class);
});

it('keeps an agency separate from the user and the shared catalog', function () {
    $user = User::factory()->create();
    $agency = Agency::query()->create(['name' => 'Agenzia Milano', 'slug' => 'agenzia-milano']);
    AgencyMembership::query()->create(['agency_id' => $agency->id, 'user_id' => $user->id, 'role' => 'scout']);

    expect($user->agencies()->first()->id)->toBe($agency->id);
    expect($user->agencyMemberships()->first()->role)->toBe('scout');
    expect($user->fresh()->is_admin)->toBeFalse();

    expect(fn () => DB::transaction(fn () => AgencyMembership::query()->create([
        'agency_id' => $agency->id, 'user_id' => User::factory()->create()->id, 'role' => 'platform-admin',
    ])))->toThrow(QueryException::class);
});

it('restricts the platform admin page', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/admin')->assertForbidden();

    $user->forceFill(['is_admin' => true])->save();
    $this->actingAs($user)->get('/admin')->assertOk()->assertSee('Amministrazione REKO');
});

it('lets only a platform admin create agencies and assign existing users', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $member = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.dashboard')
        ->set('agencyName', 'Agenzia Demo')
        ->call('createAgency')
        ->assertHasNoErrors();

    $agency = Agency::query()->where('slug', 'agenzia-demo')->firstOrFail();

    Livewire::test('pages::admin.dashboard')
        ->set('memberEmail', $member->email)
        ->set('agencyId', (string) $agency->id)
        ->set('memberRole', 'scout')
        ->call('assignMember')
        ->assertHasNoErrors();

    expect($member->fresh()->agencies()->first()->id)->toBe($agency->id);

    $this->actingAs($member);
    Livewire::test('pages::admin.dashboard')
        ->set('agencyName', 'Illecita')
        ->call('createAgency')
        ->assertForbidden();
});
