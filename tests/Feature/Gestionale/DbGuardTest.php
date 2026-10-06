<?php

use App\Gestionale\DbGuard;
use App\Models\Agency;
use App\Models\Contact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function db_exception(string $sqlState): QueryException
{
    $previous = new class($sqlState) extends PDOException
    {
        public function __construct(string $sqlState)
        {
            parent::__construct("SQLSTATE[{$sqlState}]");
            $this->code = $sqlState;
        }
    };

    return new QueryException('pgsql', 'delete from agencies where id = ?', [1], $previous);
}

it('treats both PostgreSQL 17 (23503) and PostgreSQL 18 (23001) restrict errors as a foreign key block', function () {
    expect(DbGuard::isForeignKeyBlock(db_exception('23503')))->toBeTrue()
        ->and(DbGuard::isForeignKeyBlock(db_exception('23001')))->toBeTrue()
        ->and(DbGuard::isForeignKeyBlock(db_exception('23505')))->toBeFalse()
        ->and(DbGuard::isForeignKeyBlock(db_exception('23514')))->toBeFalse();
});

it('recognises the real error raised by this server when a referenced agency is deleted', function () {
    $agency = Agency::factory()->create();
    Contact::factory()->create(['agency_id' => $agency->id]);

    try {
        DB::transaction(fn () => $agency->delete());
        $this->fail('The delete should have been refused.');
    } catch (QueryException $e) {
        expect(DbGuard::isForeignKeyBlock($e))->toBeTrue();
    }

    expect(Agency::query()->whereKey($agency->id)->exists())->toBeTrue();
});

it('lists the tables that still reference a record before deleting it', function () {
    $empty = Agency::factory()->create();
    expect(DbGuard::blockingReferences($empty))->toBe([]);

    $agency = Agency::factory()->create();
    Contact::factory()->create(['agency_id' => $agency->id]);

    // contacts reference agencies with ON DELETE RESTRICT; memberships cascade and are not listed.
    expect(DbGuard::blockingReferences($agency))->toBe(['contacts']);
});
