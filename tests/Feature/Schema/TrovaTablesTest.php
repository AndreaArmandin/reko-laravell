<?php

use App\Models\Agency;
use App\Models\AgencyRequest;
use App\Models\AgencyRequestAttachment;
use App\Models\CatalogRelease;
use App\Models\Municipality;
use App\Models\Parcel;
use App\Models\ParcelHousingContext;
use App\Models\TrovaFavorite;
use App\Models\TrovaSearchHistoryEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function trovaParcel(): array
{
    $municipality = Municipality::query()->create(['cadastral_code' => 'D205', 'name' => 'Cuneo']);
    $release = CatalogRelease::query()->create([
        'municipality_id' => $municipality->id, 'code' => 'D205-2026-10', 'label' => 'Cuneo ottobre 2026', 'status' => 'draft',
    ]);
    $parcel = Parcel::query()->create([
        'municipality_id' => $municipality->id, 'cadastral_kind' => 'F', 'sheet' => '12', 'number' => '470',
    ]);

    return [$release, $parcel];
}

function insertSearchPoint(int $parcelId, int $releaseId, ?string $point, string $source = 'SISTER'): void
{
    DB::insert(
        'insert into parcel_search_points (parcel_id, catalog_release_id, source, location, created_at, updated_at)
         values (?, ?, ?, '.($point === null ? 'null' : 'ST_GeomFromText(?, 4326)').', now(), now())',
        array_values(array_filter([$parcelId, $releaseId, $source, $point], fn ($v) => $v !== null)),
    );
}

it('creates the Trova tables without agency on private user data', function () {
    foreach (['parcel_search_points', 'parcel_housing_contexts', 'trova_favorites', 'trova_search_history',
        'agency_requests', 'agency_request_attachments'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }

    expect(Schema::hasColumn('trova_favorites', 'agency_id'))->toBeFalse();
    expect(Schema::hasColumn('trova_search_history', 'agency_id'))->toBeFalse();
});

it('requires a point and a source for every parcel search point', function () {
    [$release, $parcel] = trovaParcel();

    insertSearchPoint($parcel->id, $release->id, 'POINT(7.55 44.39)');
    expect($parcel->searchPoints()->count())->toBe(1);

    $other = Parcel::query()->create([
        'municipality_id' => $parcel->municipality_id, 'cadastral_kind' => 'F', 'sheet' => '12', 'number' => '471',
    ]);
    expect(fn () => DB::transaction(fn () => insertSearchPoint($other->id, $release->id, null)))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => insertSearchPoint($other->id, $release->id, 'POINT(7.55 44.39)', ' ')))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => insertSearchPoint($parcel->id, $release->id, 'POINT(7.56 44.40)')))->toThrow(QueryException::class);
});

it('keeps one housing context per parcel, release and generation', function () {
    [$release, $parcel] = trovaParcel();
    $row = [
        'catalog_release_id' => $release->id, 'parcel_id' => $parcel->id, 'generation' => str_repeat('a', 64),
        'known_units' => 4, 'unknown_units' => 1, 'housing_context' => 'apartments', 'top_floor' => 3,
    ];

    ParcelHousingContext::query()->create($row);
    ParcelHousingContext::query()->create([...$row, 'generation' => str_repeat('b', 64), 'top_floor' => null]);
    expect($parcel->housingContexts()->count())->toBe(2);

    expect(fn () => DB::transaction(fn () => ParcelHousingContext::query()->create($row)))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => ParcelHousingContext::query()->create([...$row, 'generation' => str_repeat('c', 64), 'housing_context' => 'villa'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => ParcelHousingContext::query()->create([...$row, 'generation' => str_repeat('d', 64), 'top_floor' => -1])))->toThrow(QueryException::class);
});

it('stores private favorites and expiring search history per user', function () {
    $user = User::factory()->create();

    TrovaFavorite::query()->create(['user_id' => $user->id, 'result_key' => 'r1', 'snapshot' => ['address' => 'Via Roma 1']]);
    expect($user->trovaFavorites()->first()->snapshot)->toBe(['address' => 'Via Roma 1']);
    expect(fn () => DB::transaction(fn () => TrovaFavorite::query()->create([
        'user_id' => $user->id, 'result_key' => 'r1', 'snapshot' => [],
    ])))->toThrow(QueryException::class);

    $entry = ['user_id' => $user->id, 'search_id' => 'search-0001', 'kind' => 'catalog', 'criteria' => ['category' => 'A/2'],
        'total' => 12, 'completed_at' => now(), 'expires_at' => now()->addHours(72)];
    TrovaSearchHistoryEntry::query()->create($entry);
    expect($user->trovaSearchHistory()->count())->toBe(1);

    expect(fn () => DB::transaction(fn () => TrovaSearchHistoryEntry::query()->create([...$entry, 'search_id' => 'search-0002', 'kind' => 'crm'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => TrovaSearchHistoryEntry::query()->create([...$entry, 'search_id' => 'search-0003', 'expires_at' => now()->subHour()])))->toThrow(QueryException::class);

    $user->delete();
    expect(TrovaFavorite::query()->count())->toBe(0);
    expect(TrovaSearchHistoryEntry::query()->count())->toBe(0);
});

it('links agency requests to a real agency and keeps retries idempotent', function () {
    $user = User::factory()->create();
    $agency = Agency::query()->create(['name' => 'Agenzia Cuneo', 'slug' => 'agenzia-cuneo']);
    $request = [
        'agency_id' => $agency->id, 'requester_user_id' => $user->id, 'client_request_id' => 'req-00000001',
        'reference' => ['code' => 'D205', 'sheet' => '12', 'parcel' => '470'], 'message' => 'Vorrei informazioni.',
    ];

    $saved = AgencyRequest::query()->create($request);
    expect($saved->fresh()->status)->toBe('nuova');
    expect($agency->requests()->first()->requester->id)->toBe($user->id);

    expect(fn () => DB::transaction(fn () => AgencyRequest::query()->create($request)))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => AgencyRequest::query()->create([...$request, 'client_request_id' => 'req-00000002', 'status' => 'archiviata'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => AgencyRequest::query()->create([...$request, 'client_request_id' => 'req-00000003', 'message' => str_repeat('x', 2001)])))->toThrow(QueryException::class);

    $file = ['agency_request_id' => $saved->id, 'upload_id' => 'upload-0001', 'private_path' => 'agency-private/a/b',
        'sha256' => str_repeat('f', 64), 'original_name' => 'visura.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 1024];
    AgencyRequestAttachment::query()->create($file);
    expect($saved->attachments()->count())->toBe(1);

    expect(fn () => DB::transaction(fn () => AgencyRequestAttachment::query()->create($file)))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => AgencyRequestAttachment::query()->create([...$file, 'upload_id' => 'upload-0002', 'mime_type' => 'text/html'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => AgencyRequestAttachment::query()->create([...$file, 'upload_id' => 'upload-0003', 'byte_size' => 10_000_001])))->toThrow(QueryException::class);
});
