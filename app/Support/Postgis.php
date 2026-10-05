<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * PostGIS helpers for REKO migrations.
 *
 * Geometry columns are nullable and have no default. Missing source geometry stays NULL.
 */
class Postgis
{
    public static function enable(): void
    {
        self::assertPgsql();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public static function addGeometry(string $table, string $column, string $type, int $srid = 4326): void
    {
        self::assertPgsql();

        if ($srid !== 4326) {
            throw new InvalidArgumentException('REKO geometries use EPSG:4326.');
        }

        if (! in_array($type, ['MultiPolygon', 'Point'], true)) {
            throw new InvalidArgumentException("Unsupported geometry type [{$type}].");
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ADD COLUMN %s geometry(%s, %d)',
            self::ident($table),
            self::ident($column),
            $type,
            $srid,
        ));
    }

    public static function gist(string $table, string $column): void
    {
        self::assertPgsql();

        DB::statement(sprintf(
            'CREATE INDEX %s ON %s USING GIST (%s)',
            self::ident($table.'_'.$column.'_gist'),
            self::ident($table),
            self::ident($column),
        ));
    }

    public static function uniqueIndex(string $name, string $table, string $expression, ?string $where = null): void
    {
        self::assertPgsql();

        $sql = sprintf(
            'CREATE UNIQUE INDEX %s ON %s (%s)',
            self::ident($name),
            self::ident($table),
            $expression,
        );

        if ($where !== null) {
            $sql .= ' WHERE '.$where;
        }

        DB::statement($sql);
    }

    public static function check(string $table, string $name, string $expression): void
    {
        self::assertPgsql();

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
            self::ident($table),
            self::ident($name),
            $expression,
        ));
    }

    public static function assertPgsql(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            throw new RuntimeException("REKO migrations require PostgreSQL with PostGIS. Current driver: {$driver}.");
        }
    }

    private static function ident(string $name): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Unsafe SQL identifier [{$name}].");
        }

        return '"'.$name.'"';
    }
}
