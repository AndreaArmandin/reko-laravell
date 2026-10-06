<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * jsonb object <-> PHP associative array. An empty array is stored as {} (not []),
 * so CHECK (jsonb_typeof(col) = 'object') holds.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class JsonObject implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return $value === null ? [] : (array) json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $value = (array) ($value ?? []);

        return json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
