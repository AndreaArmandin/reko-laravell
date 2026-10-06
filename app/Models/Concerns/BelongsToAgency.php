<?php

namespace App\Models\Concerns;

use App\Gestionale\CrossAgencyWrite;
use App\Gestionale\CurrentAgency;
use App\Gestionale\MissingAgencyContext;
use App\Models\Agency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant row of an agency.
 * - Reads: global AgencyScope (current agency only, nothing without one).
 * - Create: agency_id comes from the current agency, never from user input
 *   (it is guarded against mass assignment). An explicit agency_id of another agency fails.
 * - Update/delete: agency_id is immutable, and a row of another agency cannot be changed
 *   even if it was loaded without the scope.
 * The database repeats the rule with composite FKs (agency_id, id) on child tables.
 *
 * @mixin Model
 */
trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(new AgencyScope);

        static::creating(function (Model $model): void {
            $current = app(CurrentAgency::class)->id();

            if ($model->getAttribute('agency_id') === null) {
                if ($current === null) {
                    if ($model->agencyIsOptional()) {
                        return;
                    }

                    throw new MissingAgencyContext('Impossibile salvare '.class_basename($model).' senza agenzia corrente.');
                }

                $model->setAttribute('agency_id', $current);

                return;
            }

            if ($current !== null && (int) $model->getAttribute('agency_id') !== $current) {
                throw new CrossAgencyWrite(class_basename($model).': agenzia diversa da quella corrente.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('agency_id')) {
                throw new CrossAgencyWrite(class_basename($model).': l’agenzia di una scheda non si cambia.');
            }

            $model->assertSameAgencyAsCurrent();
        });

        static::deleting(fn (Model $model) => $model->assertSameAgencyAsCurrent());
    }

    public function initializeBelongsToAgency(): void
    {
        $this->mergeGuarded(['agency_id']);
    }

    /** Platform rows (e.g. audit events outside an agency) may override this. */
    public function agencyIsOptional(): bool
    {
        return false;
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    protected function assertSameAgencyAsCurrent(): void
    {
        $current = app(CurrentAgency::class)->id();
        $original = $this->getOriginal('agency_id');

        if ($current !== null && $original !== null && (int) $original !== $current) {
            throw new CrossAgencyWrite(class_basename($this).': scheda di un’altra agenzia.');
        }
    }
}
