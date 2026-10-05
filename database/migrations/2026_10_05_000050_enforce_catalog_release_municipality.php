<?php

use App\Support\Postgis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every row that ties catalogue data to a release must belong to the release's Comune.
     * Foreign keys alone allow a Cuneo unit version on a Milano release; these triggers refuse it.
     *
     * @var array<string, list<string>> table => expressions giving the row's Comune
     */
    private const CHECKS = [
        'municipality_catalogs' => ['NEW.municipality_id'],
        'parcel_versions' => ['(SELECT municipality_id FROM parcels WHERE id = NEW.parcel_id)'],
        'cadastral_unit_versions' => ['(SELECT p.municipality_id FROM cadastral_units u JOIN parcels p ON p.id = u.parcel_id WHERE u.id = NEW.cadastral_unit_id)'],
        'building_versions' => ['(SELECT municipality_id FROM buildings WHERE id = NEW.building_id)'],
        'building_parcel_links' => [
            '(SELECT municipality_id FROM buildings WHERE id = NEW.building_id)',
            '(SELECT municipality_id FROM parcels WHERE id = NEW.parcel_id)',
        ],
        'catalog_exclusions' => ['NEW.municipality_id'],
        'import_runs' => ['NEW.municipality_id'],
        'parcel_search_points' => ['(SELECT municipality_id FROM parcels WHERE id = NEW.parcel_id)'],
        'parcel_housing_contexts' => ['(SELECT municipality_id FROM parcels WHERE id = NEW.parcel_id)'],
    ];

    /**
     * Identity columns that, if changed later, would move existing versions to another Comune.
     *
     * @var array<string, string>
     */
    private const FIXED = [
        'catalog_releases' => 'municipality_id',
        'parcels' => 'municipality_id',
        'buildings' => 'municipality_id',
        'cadastral_units' => 'parcel_id',
    ];

    public function up(): void
    {
        Postgis::assertPgsql();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reko_assert_release_municipality(release_id bigint, expected bigint, source text)
            RETURNS void LANGUAGE plpgsql AS $$
            DECLARE actual bigint;
            BEGIN
                -- Nullable references are allowed to be empty; a missing release is left to its foreign key.
                IF release_id IS NULL OR expected IS NULL THEN RETURN; END IF;
                SELECT municipality_id INTO actual FROM catalog_releases WHERE id = release_id;
                IF NOT FOUND THEN RETURN; END IF;
                IF actual <> expected THEN
                    RAISE EXCEPTION 'REKO: % appartiene al Comune %, la release % al Comune %', source, expected, release_id, actual
                        USING ERRCODE = 'check_violation';
                END IF;
            END $$;

            CREATE OR REPLACE FUNCTION reko_forbid_identity_move() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'REKO: in % la colonna % non si può cambiare: sposterebbe le versioni in un altro Comune', TG_TABLE_NAME, TG_ARGV[0]
                    USING ERRCODE = 'check_violation';
            END $$;
            SQL);

        foreach (self::CHECKS as $table => $expressions) {
            $this->assertConsistent($table, $expressions);

            $asserts = implode("\n", array_map(
                fn (string $expression) => "PERFORM reko_assert_release_municipality(NEW.catalog_release_id, {$expression}, '{$table} ' || NEW.id);",
                $expressions,
            ));

            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION reko_check_{$table}_municipality() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    {$asserts}
                    RETURN NEW;
                END \$\$;

                CREATE TRIGGER {$table}_release_municipality
                    BEFORE INSERT OR UPDATE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION reko_check_{$table}_municipality();
                SQL);
        }

        foreach (self::FIXED as $table => $column) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_{$column}_fixed
                    BEFORE UPDATE OF {$column} ON {$table}
                    FOR EACH ROW WHEN (OLD.{$column} IS DISTINCT FROM NEW.{$column})
                    EXECUTE FUNCTION reko_forbid_identity_move('{$column}');
                SQL);
        }
    }

    public function down(): void
    {
        foreach (self::FIXED as $table => $column) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_{$column}_fixed ON {$table}");
        }

        foreach (array_keys(self::CHECKS) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_release_municipality ON {$table}");
            DB::unprepared("DROP FUNCTION IF EXISTS reko_check_{$table}_municipality()");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS reko_forbid_identity_move()');
        DB::unprepared('DROP FUNCTION IF EXISTS reko_assert_release_municipality(bigint, bigint, text)');
    }

    /**
     * Refuses to install the rule over rows that already break it, naming the table.
     *
     * @param  list<string>  $expressions
     */
    private function assertConsistent(string $table, array $expressions): void
    {
        foreach ($expressions as $expression) {
            $rowExpression = str_replace('NEW.', 't.', $expression);
            $broken = DB::selectOne(
                "SELECT count(*) n FROM {$table} t JOIN catalog_releases r ON r.id = t.catalog_release_id
                 WHERE {$rowExpression} IS NOT NULL AND {$rowExpression} <> r.municipality_id"
            )->n;

            if ($broken > 0) {
                throw new RuntimeException("{$table}: {$broken} righe collegate a una release di un altro Comune. Correggile prima di migrare.");
            }
        }
    }
};
