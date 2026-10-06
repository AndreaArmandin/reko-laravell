<?php

use App\Models\AgencyMembership;
use App\Models\ClientProfile;
use App\Models\Contact;
use App\Models\ContactChannel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->member = AgencyMembership::factory()->admin()->create();
    $this->actingAs($this->member->user);
});

function cs_client(AgencyMembership $member): Contact
{
    $contact = Contact::query()->create(['display_name' => 'Cliente']);
    ClientProfile::factory()->agent($member)->create(['contact_id' => $contact->id]);

    return $contact->fresh();
}

function rejects(Closure $write): void
{
    expect(fn () => DB::transaction($write))->toThrow(QueryException::class);
}

it('normalizes tax code and name like the gestionale', function () {
    $contact = Contact::query()->create(['display_name' => '  Niccolò   ROSSI ', 'tax_code' => ' rss mra 80a01 f205x ']);

    expect($contact->display_name)->toBe('Niccolò   ROSSI')
        ->and($contact->name_key)->toBe('niccolo rossi')
        ->and($contact->tax_code)->toBe('RSSMRA80A01F205X')
        ->and($contact->tags)->toBe([])
        ->and($contact->fresh()->origin)->toBe('manual');
});

it('keeps one identity per tax code in an agency but not across agencies', function () {
    Contact::query()->create(['display_name' => 'Mario Rossi', 'tax_code' => 'RSSMRA80A01F205X']);

    rejects(fn () => Contact::query()->create(['display_name' => 'M. Rossi', 'tax_code' => 'rssmra80a01f205x']));

    $other = AgencyMembership::factory()->admin()->create();
    $this->actingAs($other->user);
    expect(Contact::query()->create(['display_name' => 'Mario Rossi', 'tax_code' => 'RSSMRA80A01F205X'])->exists)->toBeTrue();
});

it('enforces the gestionale enums and rules in the database', function () {
    $contact = Contact::query()->create(['display_name' => 'Mario Rossi']);

    rejects(fn () => DB::table('contacts')->insert(['agency_id' => $this->member->agency_id, 'display_name' => ' ', 'name_key' => 'x']));
    rejects(fn () => DB::table('contacts')->where('id', $contact->id)->update(['tax_code' => 'rss mra']));
    rejects(fn () => DB::table('contacts')->where('id', $contact->id)->update(['origin' => 'trova']));
    rejects(fn () => DB::table('contacts')->where('id', $contact->id)->update(['removed_at' => now()]));

    rejects(fn () => ClientProfile::factory()->agent($this->member)->create(['contact_id' => $contact->id, 'status' => 'Inventato']));
    rejects(fn () => ClientProfile::factory()->agent($this->member)->create(['contact_id' => $contact->id, 'preferred_channel' => 'Piccione']));
    rejects(fn () => ClientProfile::factory()->agent($this->member)->create(['contact_id' => $contact->id, 'lifecycle_state' => 'archived']));

    $profile = ClientProfile::factory()->agent($this->member)->create(['contact_id' => $contact->id]);
    expect($profile->status)->toBe('Nuovo');
    rejects(fn () => ClientProfile::factory()->agent($this->member)->create(['contact_id' => $contact->id])); // one profile per contact

    rejects(fn () => ContactChannel::factory()->create(['contact_id' => $contact->id, 'kind' => 'fax', 'value' => '02 1234']));
    rejects(fn () => ContactChannel::factory()->create(['contact_id' => $contact->id, 'status' => 'Boh']));
});

it('stores normalized channel keys and allows one primary per kind', function () {
    $contact = Contact::query()->create(['display_name' => 'Mario Rossi']);

    $phone = ContactChannel::factory()->create(['contact_id' => $contact->id, 'value' => '333 123 4567', 'is_primary' => true]);
    $email = ContactChannel::factory()->email()->create(['contact_id' => $contact->id, 'value' => ' Mario.Rossi@Example.COM ']);

    expect($phone->normalized_value)->toBe('393331234567')
        ->and($phone->status)->toBe('Da verificare')
        ->and($email->normalized_value)->toBe('mario.rossi@example.com');

    rejects(fn () => ContactChannel::factory()->create(['contact_id' => $contact->id, 'value' => '0039 333 1234567'])); // same number
    rejects(fn () => ContactChannel::factory()->create(['contact_id' => $contact->id, 'value' => '02 1234567', 'is_primary' => true]));
});

it('warns about possible duplicate clients without blocking them', function () {
    $mario = cs_client($this->member);
    ContactChannel::factory()->create(['contact_id' => $mario->id, 'value' => '+39 333 1234567', 'is_primary' => true]);
    $mario->forceFill(['display_name' => 'Mario Rossi'])->save();
    $anna = cs_client($this->member);
    $anna->forceFill(['display_name' => 'Anna Bianchi'])->save();
    ContactChannel::factory()->email()->create(['contact_id' => $anna->id, 'value' => 'anna@example.com', 'is_primary' => true]);
    // Archived clients still count (identity.ts compares every client).
    $anna->clientProfile->forceFill(['lifecycle_state' => 'archived', 'lifecycle_at' => now()])->save();

    expect(Contact::query()->possibleDuplicates('Luca', '3331234567', null)->pluck('id')->all())->toBe([$mario->id])
        ->and(Contact::query()->possibleDuplicates(null, null, 'ANNA@example.com ')->pluck('id')->all())->toBe([$anna->id])
        ->and(Contact::query()->possibleDuplicates('mario  rossi', null, null)->pluck('id')->all())->toBe([$mario->id])
        ->and(Contact::query()->possibleDuplicates('', '', '')->count())->toBe(0);

    // A homonym can still be saved: the gestionale asks for confirmation, it does not forbid.
    expect(Contact::query()->create(['display_name' => 'Mario Rossi'])->exists)->toBeTrue();
});

it('compares only clients and only their primary channels', function () {
    $owner = Contact::query()->create(['display_name' => 'Proprietario Verdi']); // no client profile
    ContactChannel::factory()->create(['contact_id' => $owner->id, 'value' => '333 7654321', 'is_primary' => true]);
    $client = cs_client($this->member);
    ContactChannel::factory()->create(['contact_id' => $client->id, 'value' => '333 0000001', 'is_primary' => true]);
    ContactChannel::factory()->create(['contact_id' => $client->id, 'value' => '333 0000002', 'is_primary' => false]);

    expect(Contact::query()->possibleDuplicates(null, '333 7654321', null)->count())->toBe(0)
        ->and(Contact::query()->possibleDuplicates('Proprietario Verdi', null, null)->count())->toBe(0)
        ->and(Contact::query()->possibleDuplicates(null, '3330000002', null)->count())->toBe(0)
        ->and(Contact::query()->possibleDuplicates(null, '3330000001', null)->pluck('id')->all())->toBe([$client->id]);
});
