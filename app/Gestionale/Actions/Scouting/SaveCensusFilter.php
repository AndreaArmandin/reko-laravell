<?php

namespace App\Gestionale\Actions\Scouting;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Models\AgencyMembership;
use App\Models\CensusSavedFilter;

/**
 * census.filter.save (census-actions.ts): a named combination of filters of the cadastral archive, one per
 * operator and name (saving again with the same name replaces it).
 */
final class SaveCensusFilter
{
    public function __construct(private readonly Audit $audit) {}

    /** @param array{name?: mixed, view?: mixed, filters?: mixed, search?: mixed} $input */
    public function handle(AgencyMembership $actor, array $input): CensusSavedFilter
    {
        if (! $actor->isActive() || $actor->role === 'crm') {
            throw new CommandRejected('Sezione Proprietari non accessibile.', 403);
        }
        $filters = $input['filters'] ?? null;
        $valid = is_array($filters) && collect($filters)->every(fn ($values) => is_array($values) && collect($values)->every(fn ($v) => is_string($v)));
        if (Commands::text($input['name'] ?? '') === '' || ! $valid) {
            throw new CommandRejected('Assegna un nome ai filtri.');
        }
        $search = $input['search'] ?? null;
        if ($search !== null && (! is_string($search) || mb_strlen($search) > 300)) {
            throw new CommandRejected('La ricerca libera deve essere un testo entro 300 caratteri.');
        }

        $saved = CensusSavedFilter::query()->where('user_id', $actor->user_id)->where('name', Commands::text($input['name']))->first() ?? new CensusSavedFilter;
        $saved->forceFill(['user_id' => $actor->user_id, 'name' => Commands::text($input['name']), 'view' => Commands::text($input['view'] ?? 'Immobili', 30) ?: 'Immobili',
            'filters' => $filters === [] ? (object) [] : $filters, 'search' => is_string($search) ? trim($search) : null])->save();
        $this->audit->record('census.filter.save', $saved, ['name' => $saved->name, 'view' => $saved->view]);

        return $saved;
    }
}
