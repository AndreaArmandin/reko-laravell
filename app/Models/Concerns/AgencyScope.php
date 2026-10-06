<?php

namespace App\Models\Concerns;

use App\Gestionale\CurrentAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query to the current agency. Without a current agency it returns nothing
 * (fail closed): platform code that really needs all agencies must say so with
 * withoutGlobalScope(AgencyScope::class).
 */
final class AgencyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $agencyId = app(CurrentAgency::class)->id();

        if ($agencyId === null) {
            $builder->whereRaw('false');

            return;
        }

        $builder->where($model->qualifyColumn('agency_id'), $agencyId);
    }
}
