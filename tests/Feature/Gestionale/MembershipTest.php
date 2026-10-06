<?php

use App\Models\AgencyMembership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

it('ports the optional permissions rules of the gestionale', function () {
    $admin = AgencyMembership::factory()->admin()->make();
    $crm = AgencyMembership::factory()->crm()->make();
    $scout = AgencyMembership::factory()->scout()->make();

    foreach (AgencyMembership::OPTIONAL_PERMISSIONS as $permission) {
        expect($admin->allows($permission))->toBeTrue();
    }

    // NULL permissions = defaults
    expect($crm->allows('activities.assign'))->toBeTrue()
        ->and($crm->allows('exports'))->toBeTrue()
        ->and($scout->allows('sister.import'))->toBeFalse()
        ->and($crm->allows('owner.edit'))->toBeFalse();

    // explicit list
    $scout->permissions = ['sister.import', 'owner.edit'];
    $crm->permissions = ['sister.import', 'exports'];
    expect($scout->allows('sister.import'))->toBeTrue()
        ->and($scout->allows('owner.edit'))->toBeFalse()   // never for non-admin
        ->and($scout->allows('activities.share'))->toBeFalse()
        ->and($crm->allows('sister.import'))->toBeFalse()  // never for crm
        ->and($crm->allows('exports'))->toBeTrue();

    $admin->deactivated_at = now();
    expect($admin->allows('exports'))->toBeFalse();
});

it('accepts only known permissions in the database', function () {
    $membership = AgencyMembership::factory()->create(['permissions' => ['exports']]);
    expect($membership->fresh()->permissions)->toBe(['exports']);

    expect(fn () => DB::transaction(fn () => $membership->update(['permissions' => ['billing.manage']])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => $membership->update(['permissions' => ['exports' => true]])))->toThrow(QueryException::class);
});

it('exposes the current membership permissions through a gate', function () {
    $scout = AgencyMembership::factory()->scout()->create(['permissions' => ['sister.import']]);
    $this->actingAs($scout->user);

    expect(Gate::allows('agency-permission', 'sister.import'))->toBeTrue()
        ->and(Gate::allows('agency-permission', 'exports'))->toBeFalse();
});
