<?php

namespace App\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Database helpers for the "never delete data, deactivate instead" rule.
 * (Not named "Db": PHP class names are case-insensitive and it would clash with the DB facade import.)
 */
final class DbGuard
{
    /**
     * A delete refused because other rows still point at the record.
     * PostgreSQL 17 reports RESTRICT/NO ACTION as 23503 (foreign_key_violation),
     * PostgreSQL 18 reports ON DELETE RESTRICT as 23001 (restrict_violation).
     */
    public static function isForeignKeyBlock(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23503', '23001'], true);
    }

    /**
     * Tables that still reference this record through a RESTRICT / NO ACTION foreign key,
     * read from the catalog so new gestionale tables are covered automatically.
     * Composite keys (e.g. agency_id + user_id towards agency_memberships) are supported.
     *
     * @return list<string>
     */
    public static function blockingReferences(Model $model): array
    {
        $constraints = DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS child,
                   array_to_json(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(n, i)
                       JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.n ORDER BY k.i)) AS child_columns,
                   array_to_json(ARRAY(SELECT a.attname FROM unnest(c.confkey) WITH ORDINALITY k(n, i)
                       JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.n ORDER BY k.i)) AS parent_columns
            FROM pg_constraint c
            WHERE c.contype = 'f' AND c.confrelid = ?::regclass AND c.confdeltype IN ('r', 'a')
            ORDER BY 1
        SQL, [$model->getTable()]);

        $blocking = [];

        foreach ($constraints as $constraint) {
            $query = DB::table($constraint->child);

            foreach (array_combine(json_decode($constraint->child_columns), json_decode($constraint->parent_columns)) as $child => $parent) {
                $value = $model->getRawOriginal($parent) ?? $model->getAttribute($parent);
                $query->where($child, $value);
            }

            if ($query->exists()) {
                $blocking[] = $constraint->child;
            }
        }

        return array_values(array_unique($blocking));
    }
}
