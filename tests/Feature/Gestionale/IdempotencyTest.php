<?php

use App\Gestionale\Idempotency;
use App\Gestionale\IdempotencyConflict;
use App\Models\AgencyMembership;
use App\Models\IdempotencyKey;

beforeEach(function () {
    $this->member = AgencyMembership::factory()->scout()->create();
    $this->actingAs($this->member->user);
    $this->runs = 0;
    $this->command = function () {
        $this->runs++;

        return ['activity_id' => 42];
    };
});

it('runs a command once and replays the stored response on retry', function () {
    $idempotency = app(Idempotency::class);

    $first = $idempotency->run('activity.call-result', 'token-0001', ['outcome' => 'Interessato', 'id' => 7], $this->command);
    $retry = $idempotency->run('activity.call-result', 'token-0001', ['id' => 7, 'outcome' => 'Interessato'], $this->command);

    expect($first)->toBe(['activity_id' => 42])
        ->and($retry)->toBe($first)
        ->and($this->runs)->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('refuses the same key with a different payload', function () {
    app(Idempotency::class)->run('activity.call-result', 'token-0002', ['outcome' => 'Interessato'], $this->command);

    expect(fn () => app(Idempotency::class)->run('activity.call-result', 'token-0002', ['outcome' => 'Non risponde'], $this->command))
        ->toThrow(IdempotencyConflict::class);
    expect($this->runs)->toBe(1);
});

it('does not keep a receipt when the command fails, so it can be retried', function () {
    expect(fn () => app(Idempotency::class)->run('census.import', 'token-0003', [], fn () => throw new RuntimeException('down')))
        ->toThrow(RuntimeException::class);

    expect(app(Idempotency::class)->run('census.import', 'token-0003', [], $this->command))->toBe(['activity_id' => 42]);
});

it('keeps keys separate per user and per command', function () {
    app(Idempotency::class)->run('activity.call-result', 'token-0004', [], $this->command);
    app(Idempotency::class)->run('census.import', 'token-0004', [], $this->command);

    $colleague = AgencyMembership::factory()->scout()->create(['agency_id' => $this->member->agency_id]);
    $this->actingAs($colleague->user);
    app(Idempotency::class)->run('activity.call-result', 'token-0004', [], $this->command);

    expect($this->runs)->toBe(3);
});
