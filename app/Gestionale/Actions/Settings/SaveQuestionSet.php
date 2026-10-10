<?php

namespace App\Gestionale\Actions\Settings;

use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Properties\PropertyMatcher;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Models\AgencyMembership;
use App\Models\GestionaleSetting;
use App\Models\QuestionSetVersion;
use Illuminate\Support\Facades\DB;

/**
 * settings.save, questions part (engine.ts): the agency's questionnaire is saved as a new published
 * version on question_set_versions (older ones stay readable). Same checks as the old engine:
 * unique ids and positive weights, "operation" always present and fixed, personal data never compared,
 * a compared field must be an existing property characteristic. Matches are recalculated.
 */
final class SaveQuestionSet
{
    public const TYPES = ['single', 'multi', 'boolean', 'range', 'amount', 'date', 'zone', 'priority', 'text', 'financing'];

    public const COLLECTIONS = ['guided', 'manual', 'archived'];

    public const PATHS = ['residential', 'commercial', 'investment', 'land', 'parking'];

    /** Personal or sensitive data: kept out of the matching even after an edit (engine.ts protectedFields). */
    public const PROTECTED = ['currentHome', 'sellCurrentHome', 'mortgageEstimate', 'capital', 'mortgage', 'mortgageState', 'financeable', 'occupants', 'incomeBand', 'workSituation', 'guarantees', 'investmentCapital', 'investmentFinancing', 'religion', 'ethnicity', 'nationality', 'health', 'disability', 'age', 'gender', 'maritalStatus'];

    public function __construct(private readonly Audit $audit, private readonly PropertyMatcher $matcher) {}

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return list<array<string, mixed>> the saved questions
     */
    public function handle(AgencyMembership $actor, array $questions): array
    {
        SettingsAccess::admin($actor);
        $agencyId = (int) $actor->agency_id;
        $current = Questionnaire::forAgency($agencyId)->questions();

        $ids = array_map(fn ($q) => is_array($q) ? ($q['id'] ?? null) : null, $questions);
        if (! array_is_list($questions) || count(array_unique($ids, SORT_REGULAR)) !== count($ids)
            || array_filter($questions, fn ($q) => ! is_array($q) || empty($q['id']) || empty($q['text']) || ! is_numeric($q['weight'] ?? null) || (float) $q['weight'] <= 0) !== []) {
            throw new CommandRejected('Controlla le domande: identificativi unici e pesi positivi.');
        }
        if (! in_array('operation', $ids, true)) {
            throw new CommandRejected('La scelta Acquisto o Locazione è obbligatoria e non può essere rimossa.');
        }

        $previous = collect($current)->keyBy('id');
        $allowedFields = collect($current)->filter(fn ($q) => ! empty($q['field']) && empty($q['sensitive']))->pluck('field')->all();
        $saved = [];
        foreach ($questions as $question) {
            if ($question['id'] === 'operation') {
                $question = array_merge($question, ['skippable' => false, 'active' => true, 'collection' => 'guided', 'paths' => 'all', 'type' => 'single', 'options' => ['Acquisto', 'Locazione']]);
                unset($question['condition']);
            }
            $paths = $question['paths'] ?? null;
            if (! in_array($question['type'] ?? null, self::TYPES, true) || ! is_numeric($question['order'] ?? null)
                || ! in_array($question['classification'] ?? null, Questionnaire::CLASSIFICATIONS, true)
                || (isset($question['collection']) && ! in_array($question['collection'], self::COLLECTIONS, true))
                || ($paths !== 'all' && (! is_array($paths) || array_diff($paths, self::PATHS) !== []))) {
                throw new CommandRejected('Configurazione della domanda non valida.');
            }
            $field = $question['field'] ?? null;
            if (! empty($previous[$question['id']]['sensitive']) || in_array($question['id'], self::PROTECTED, true) || in_array($field ?? '', self::PROTECTED, true)) {
                $question['sensitive'] = true;
                unset($question['field'], $question['bucket']);
                $field = null;
            }
            if ($field && ! in_array($field, $allowedFields, true)) {
                throw new CommandRejected('Il campo deve essere una caratteristica immobiliare già prevista, non un dato personale.');
            }
            if ($field && ! in_array($question['bucket'] ?? '', SaveMatchingWeights::BUCKETS, true)) {
                throw new CommandRejected('Scegli un gruppo di punteggio valido.');
            }
            $question['order'] = $question['order'] + 0;
            $question['weight'] = $question['weight'] + 0;
            $saved[] = $question;
        }

        return DB::transaction(function () use ($actor, $agencyId, $saved) {
            $latest = (int) QuestionSetVersion::query()->where('agency_id', $agencyId)->where('code', QuestionSetVersion::CLIENT_PROFILE)->max('version');
            $platform = QuestionSetVersion::current(null);
            $set = new QuestionSetVersion;
            $set->forceFill([
                'agency_id' => $agencyId,
                'code' => QuestionSetVersion::CLIENT_PROFILE,
                'version' => $latest + 1,
                'profile_version' => $platform?->profile_version ?? Questionnaire::PROFILE_VERSION,
                'questions' => $saved,
                'published_at' => now(),
                'created_by_user_id' => $actor->user_id,
            ])->save();

            $setting = SettingsStore::forUpdate($agencyId);
            $setting->version = SettingsStore::nextVersion($setting);
            $setting->save();

            $this->matcher->refreshAgency($agencyId);
            $this->audit->record('settings.save', $setting);

            return $saved;
        });
    }
}
