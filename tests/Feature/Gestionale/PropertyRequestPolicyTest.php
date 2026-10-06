<?php

use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\PropertyRequest;
use App\Models\User;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $agency = $this->admin->agency_id;
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $agency]);
    $this->otherCrm = AgencyMembership::factory()->crm()->create(['agency_id' => $agency]);
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $agency]);

    $mine = ClientProfile::factory()->agent($this->crm)->create(['contact_id' => Contact::factory()->create(['agency_id' => $agency])->id]);
    $theirs = ClientProfile::factory()->agent($this->otherCrm)->create(['contact_id' => Contact::factory()->create(['agency_id' => $agency])->id]);
    $this->mine = PropertyRequest::factory()->forClient($mine)->create();
    $this->theirs = PropertyRequest::factory()->forClient($theirs)->create();

    $this->foreignAdmin = AgencyMembership::factory()->admin()->create();
});

function as_member(AgencyMembership $membership): User
{
    app(CurrentAgency::class)->forget();
    test()->actingAs($membership->user);

    return $membership->user;
}

it('lets the agency admin see, edit, archive and remove every request of its agency', function () {
    $user = as_member($this->admin);

    foreach ([$this->mine, $this->theirs] as $request) {
        expect($user->can('view', $request))->toBeTrue()
            ->and($user->can('update', $request))->toBeTrue()
            ->and($user->can('archive', $request))->toBeTrue()
            ->and($user->can('remove', $request))->toBeTrue()
            ->and($user->can('delete', $request))->toBeFalse()
            ->and($user->can('forceDelete', $request))->toBeFalse();
    }
    expect(PropertyRequest::query()->visibleTo($this->admin)->count())->toBe(2);
});

it('lets crm work only on the requests of its own clients and never remove them', function () {
    $user = as_member($this->crm);

    expect($user->can('viewAny', PropertyRequest::class))->toBeTrue()
        ->and($user->can('view', $this->mine))->toBeTrue()
        ->and($user->can('update', $this->mine))->toBeTrue()
        ->and($user->can('archive', $this->mine))->toBeTrue()
        ->and($user->can('remove', $this->mine))->toBeFalse()
        ->and($user->can('view', $this->theirs))->toBeFalse()
        ->and($user->can('update', $this->theirs))->toBeFalse()
        ->and(PropertyRequest::query()->visibleTo($this->crm)->pluck('id')->all())->toBe([$this->mine->id]);
});

it('gives scouts (Operatore 1) no access to requests', function () {
    $user = as_member($this->scout);

    expect($user->can('viewAny', PropertyRequest::class))->toBeFalse()
        ->and($user->can('create', PropertyRequest::class))->toBeFalse()
        ->and($user->can('view', $this->mine))->toBeFalse()
        ->and(PropertyRequest::query()->visibleTo($this->scout)->count())->toBe(0);
});

it('keeps a removed request read-only until it is restored', function () {
    $this->mine->forceFill(['lifecycle_state' => 'removed', 'lifecycle_at' => now(), 'lifecycle_by_user_id' => $this->admin->user_id])->save();
    $user = as_member($this->admin);

    expect($user->can('view', $this->mine->fresh()))->toBeTrue()
        ->and($user->can('update', $this->mine->fresh()))->toBeFalse();
});

it('never grants access across agencies, platform admins included', function () {
    $this->foreignAdmin->user->forceFill(['is_admin' => true])->save();
    $user = as_member($this->foreignAdmin);

    expect($user->can('view', $this->mine))->toBeFalse()
        ->and($user->can('update', $this->mine))->toBeFalse()
        ->and($user->can('remove', $this->mine))->toBeFalse()
        ->and(PropertyRequest::query()->visibleTo($this->foreignAdmin)->count())->toBe(0);
});
