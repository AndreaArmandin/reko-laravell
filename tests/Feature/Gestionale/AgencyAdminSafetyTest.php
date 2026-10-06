<?php

use App\Gestionale\CurrentAgency;
use App\Models\Agency;
use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('deletes an agency that only has members', function () {
    $membership = AgencyMembership::factory()->create();

    Livewire::test('pages::admin.agencies.index')->call('delete', $membership->agency_id);

    expect(Agency::query()->find($membership->agency_id))->toBeNull()
        ->and(AgencyMembership::query()->find($membership->id))->toBeNull();
});

it('refuses to delete an agency with gestionale data and offers deactivation', function () {
    $agency = Agency::factory()->create();
    Contact::factory()->create(['agency_id' => $agency->id]);

    Livewire::test('pages::admin.agencies.index')->call('delete', $agency->id)->assertHasNoErrors();
    expect(Agency::query()->find($agency->id))->not->toBeNull()
        ->and(Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->count())->toBe(1);

    Livewire::test('pages::admin.agencies.index')->call('toggleActive', $agency->id);
    expect($agency->fresh()->isActive())->toBeFalse();

    Livewire::test('pages::admin.agencies.index')->call('toggleActive', $agency->id);
    expect($agency->fresh()->isActive())->toBeTrue();
});

it('makes a deactivated agency invisible to its members', function () {
    $membership = AgencyMembership::factory()->create();
    Livewire::test('pages::admin.agencies.index')->call('toggleActive', $membership->agency_id);

    $this->actingAs($membership->user);
    app(CurrentAgency::class)->forget();
    expect(app(CurrentAgency::class)->id())->toBeNull();
});

it('deactivates instead of removing a member who is still a client referent', function () {
    $crm = AgencyMembership::factory()->crm()->create();
    $contact = Contact::factory()->create(['agency_id' => $crm->agency_id]);
    ClientProfile::factory()->agent($crm)->create(['contact_id' => $contact->id]);

    Livewire::test('pages::admin.agencies.edit', ['agency' => $crm->agency])->call('removeMember', $crm->id);
    expect($crm->fresh())->not->toBeNull();

    Livewire::test('pages::admin.agencies.edit', ['agency' => $crm->agency])->call('toggleMember', $crm->id);
    expect($crm->fresh()->isActive())->toBeFalse();

    // Linking the same e-mail again reactivates the membership.
    Livewire::test('pages::admin.agencies.edit', ['agency' => $crm->agency])
        ->set('memberEmail', $crm->user->email)->set('memberRole', 'crm')->call('addMember')->assertHasNoErrors();
    expect($crm->fresh()->isActive())->toBeTrue();
});
