<?php

use App\Gestionale\CurrentAgency;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\User;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $agency = $this->admin->agency_id;
    $this->crm = AgencyMembership::factory()->crm()->create(['agency_id' => $agency]);
    $this->otherCrm = AgencyMembership::factory()->crm()->create(['agency_id' => $agency]);
    $this->scout = AgencyMembership::factory()->scout()->create(['agency_id' => $agency]);

    $this->mine = Contact::factory()->create(['agency_id' => $agency]);
    ClientProfile::factory()->agent($this->crm)->create(['contact_id' => $this->mine->id]);
    $this->theirs = Contact::factory()->create(['agency_id' => $agency]);
    ClientProfile::factory()->agent($this->otherCrm)->create(['contact_id' => $this->theirs->id]);
    $this->owner = Contact::factory()->owner()->create(['agency_id' => $agency]);

    $this->foreignAdmin = AgencyMembership::factory()->admin()->create();
});

it('lets the agency admin see and manage every contact of its agency', function () {
    $user = $this->admin->user;
    $this->actingAs($user);

    foreach ([$this->mine, $this->theirs, $this->owner] as $contact) {
        expect($user->can('view', $contact))->toBeTrue()
            ->and($user->can('update', $contact))->toBeTrue()
            ->and($user->can('remove', $contact))->toBeTrue()
            ->and($user->can('updateOwnerIdentity', $contact))->toBeTrue();
    }
    expect(Contact::query()->visibleTo($this->admin)->count())->toBe(3);
});

it('lets crm see only the clients it is the referent of, never owner identity edits', function () {
    $user = $this->crm->user;
    $this->actingAs($user);

    expect($user->can('viewAny', Contact::class))->toBeTrue()
        ->and($user->can('view', $this->mine))->toBeTrue()
        ->and($user->can('archive', $this->mine))->toBeTrue()
        ->and($user->can('remove', $this->mine))->toBeFalse()
        ->and($user->can('updateOwnerIdentity', $this->mine))->toBeFalse()
        ->and($user->can('view', $this->theirs))->toBeFalse()
        ->and($user->can('view', $this->owner))->toBeFalse();

    expect(Contact::query()->visibleTo($this->crm)->pluck('id')->all())->toBe([$this->mine->id]);
});

it('gives the scout no access to clients', function () {
    $user = $this->scout->user;
    $this->actingAs($user);

    expect($user->can('viewAny', Contact::class))->toBeFalse()
        ->and($user->can('create', Contact::class))->toBeFalse()
        ->and($user->can('view', $this->mine))->toBeFalse()
        ->and(Contact::query()->visibleTo($this->scout)->count())->toBe(0);
});

it('denies the admin of another agency, a deactivated member and a platform admin', function () {
    $this->actingAs($this->foreignAdmin->user);
    expect($this->foreignAdmin->user->can('view', $this->mine))->toBeFalse()
        ->and(Contact::query()->visibleTo($this->foreignAdmin)->whereKey($this->mine->id)->exists())->toBeFalse();

    $this->crm->update(['deactivated_at' => now()]);
    app(CurrentAgency::class)->forget(); // a new request resolves the agency again
    $this->actingAs($this->crm->user);
    expect($this->crm->user->can('view', $this->mine))->toBeFalse();

    $platform = User::factory()->create(['is_admin' => true]);
    $this->actingAs($platform);
    expect($platform->can('view', $this->mine))->toBeFalse();
});

it('never allows physical deletion, and freezes removed contacts', function () {
    $this->actingAs($this->admin->user);

    expect($this->admin->user->can('delete', $this->mine))->toBeFalse()
        ->and($this->admin->user->can('forceDelete', $this->mine))->toBeFalse();

    $this->mine->update(['removed_at' => now(), 'removed_reason' => 'Doppione']);
    expect($this->admin->user->can('update', $this->mine))->toBeFalse();
});
