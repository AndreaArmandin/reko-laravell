<?php

namespace App\Gestionale;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;

/**
 * Runs a command at most once per (agency, user, scope, key), like the gestionale receipts
 * (activityCallReceipts, censusReceipts). A retried request with the same key and payload
 * gets the stored response; the same key with another payload is refused.
 *
 * The receipt is inserted first, inside the command's transaction: a concurrent retry waits
 * on the unique index and then sees the committed receipt, so the command cannot run twice.
 */
final class Idempotency
{
    public function __construct(
        private readonly CurrentAgency $current,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): (array<string, mixed>|null)  $command
     * @return array<string, mixed>|null
     */
    public function run(string $scope, string $key, array $payload, Closure $command): ?array
    {
        $agencyId = $this->current->require()->getKey();
        $userId = $this->auth->guard()->id() ?? throw new MissingAgencyContext('Accesso richiesto.');
        $fingerprint = hash('sha256', json_encode(self::canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($agencyId, $userId, $scope, $key, $fingerprint, $command) {
            $inserted = DB::table('idempotency_keys')->insertOrIgnore([
                'agency_id' => $agencyId,
                'user_id' => $userId,
                'scope' => $scope,
                'key' => $key,
                'fingerprint' => $fingerprint,
                'created_at' => now(),
            ]);

            $receipt = IdempotencyKey::query()
                ->where(['user_id' => $userId, 'scope' => $scope, 'key' => $key])
                ->lockForUpdate()
                ->firstOrFail();

            if ($inserted === 0) {
                if ($receipt->fingerprint !== $fingerprint) {
                    throw new IdempotencyConflict('Questa operazione è già stata registrata con dati diversi.');
                }

                return $receipt->response;
            }

            $response = $command();
            $receipt->forceFill(['response' => $response])->save();

            return $response;
        });
    }

    /** Sorts associative keys so {"a":1,"b":2} and {"b":2,"a":1} have the same fingerprint. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
