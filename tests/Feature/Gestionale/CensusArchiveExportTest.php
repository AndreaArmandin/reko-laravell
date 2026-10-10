<?php

use App\Gestionale\ContactKeys;
use App\Models\AgencyMembership;
use App\Models\Contact;

beforeEach(function () {
    $this->admin = AgencyMembership::factory()->admin()->create();
    $this->actingAs($this->admin->user);
});

function census_export_phone(Contact $contact, AgencyMembership $membership, string $value, string $status): void
{
    DB::table('contact_channels')->insert([
        'agency_id' => $membership->agency_id,
        'contact_id' => $contact->id,
        'kind' => 'phone',
        'value' => $value,
        'normalized_value' => ContactKeys::phone($value),
        'label' => 'Cellulare',
        'status' => $status,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function census_export_contact(AgencyMembership $membership, string $name, string $cf): Contact
{
    return Contact::query()->create([
        'display_name' => $name,
        'tax_code' => $cf,
        'origin' => 'manual',
        'imported_by_user_id' => $membership->user_id,
        'recapito' => '',
    ]);
}

it('exports only valid owner codes without a usable phone and gives scouts the export permission gate', function () {
    census_export_contact($this->admin, 'ROSSI MARIO', 'RSSMRA80A01F205X');
    census_export_contact($this->admin, 'BIANCHI ANNA', 'BNCNNA82B41F205Y');
    census_export_contact($this->admin, 'VERDI LUCA', 'VRDLCA84C41F205Z');
    census_export_contact($this->admin, 'Identificativo incompleto', 'ABC123');

    census_export_phone(Contact::query()->where('tax_code', 'BNCNNA82B41F205Y')->firstOrFail(), $this->admin, '3331112222', 'Errato');
    census_export_phone(Contact::query()->where('tax_code', 'VRDLCA84C41F205Z')->firstOrFail(), $this->admin, '3332223333', 'Verificato');

    $response = $this->get(route('gestionale.archive.export.missing-phone'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('content-disposition', 'attachment; filename=reko-codici-senza-telefono.csv');

    $csv = $response->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('"RSSMRA80A01F205X"', '"BNCNNA82B41F205Y"')
        ->and($csv)->not->toContain('"VRDLCA84C41F205Z"', 'ABC123');

    $scout = AgencyMembership::factory()->scout()->create(['agency_id' => $this->admin->agency_id, 'permissions' => []]);
    $this->actingAs($scout->user)->get(route('gestionale.archive.export.missing-phone'))->assertForbidden();
});
