<?php

use App\Gestionale\CommandRejected;
use App\Gestionale\ContactKeys;
use App\Gestionale\Questionnaire\AnswerValidator;
use App\Gestionale\Questionnaire\ProfileFlow;
use App\Gestionale\Questionnaire\Questionnaire;
use App\Gestionale\Questionnaire\QuickRequestCriteria;
use App\Gestionale\Questionnaire\Tags;
use App\Models\Agency;
use App\Models\QuestionSetVersion;

function qn_questionnaire(): Questionnaire
{
    return Questionnaire::forAgency(null);
}

function qn_guided_ids(array $criteria): array
{
    return array_column(qn_questionnaire()->guided($criteria), 'id');
}

it('seeds the platform question set with the 74 default questions, without prototype zones', function () {
    $set = QuestionSetVersion::current(null);
    $questions = collect($set->questions);

    expect($set->version)->toBe(1)->and($set->profile_version)->toBe(5)->and($set->agency_id)->toBeNull()
        ->and($questions)->toHaveCount(74)
        ->and($questions->where('collection', 'guided'))->toHaveCount(27)
        ->and($questions->where('collection', 'manual'))->toHaveCount(38)
        ->and($questions->where('collection', 'archived'))->toHaveCount(9)
        ->and($questions->where('sensitive', true))->toHaveCount(13)
        ->and($questions->where('skippable', false)->pluck('id')->all())->toBe(['operation'])
        ->and(qn_questionnaire()->zones())->toBe([]);
});

it('uses the agency set when published, else the platform one', function () {
    $agency = Agency::factory()->create();
    $questions = Questionnaire::defaultQuestions();
    foreach ($questions as &$q) {
        if ($q['id'] === 'zones') {
            $q['options'] = ['Centro', 'Lungomare'];
        }
    }
    QuestionSetVersion::query()->create(['agency_id' => $agency->id, 'code' => 'client_profile', 'version' => 2, 'profile_version' => 5,
        'questions' => $questions, 'published_at' => now()]);
    QuestionSetVersion::query()->create(['agency_id' => $agency->id, 'code' => 'client_profile', 'version' => 3, 'profile_version' => 5,
        'questions' => [], 'published_at' => null]); // draft, ignored

    expect(Questionnaire::forAgency($agency->id)->zones())->toBe(['Centro', 'Lungomare'])
        ->and(Questionnaire::forAgency(Agency::factory()->create()->id)->zones())->toBe([]);
});

it('asks the essentials in the rapid profile, never floors or sensitive extras (test-crm: rapid profile)', function () {
    $never = ['floor', 'garden', 'terrace', 'elevator', 'accessible', 'capital', 'financeable', 'energy', 'incomeBand'];
    foreach (['Attico', 'Villa', 'Appartamento'] as $typology) {
        $ids = qn_guided_ids(['operation' => 'Acquisto', 'purpose' => 'Abitazione principale', 'typology' => [$typology]]);
        expect($ids)->toHaveCount(12)->toContain('currentHome', 'bedrooms')->and(end($ids))->toBe('notes')
            ->and(array_intersect($ids, $never))->toBe([]);
    }
    foreach (array_keys(Questionnaire::PURPOSE_PATHS) as $purpose) {
        foreach (['Acquisto', 'Locazione'] as $operation) {
            expect(qn_guided_ids(['operation' => $operation, 'purpose' => $purpose]))->toHaveCount(12);
        }
    }
    expect(qn_guided_ids([]))->toHaveCount(12)
        ->and(qn_guided_ids(['operation' => 'Acquisto']))->toBe(['operation', 'purpose', 'typology', 'zones', 'budget', 'area', 'bedrooms', 'condition', 'availableBy', 'currentHome', 'mortgage', 'notes'])
        ->and(qn_guided_ids(['operation' => 'Locazione']))->toBe(['operation', 'purpose', 'typology', 'zones', 'budget', 'area', 'bedrooms', 'condition', 'availableBy', 'currentHome', 'rentalDuration', 'notes'])
        ->and(qn_guided_ids(['operation' => 'Acquisto', 'purpose' => 'Attività commerciale']))->toBe(['operation', 'purpose', 'typology', 'zones', 'budget', 'area', 'activity', 'catchment', 'condition', 'systems', 'availableBy', 'notes'])
        ->and(array_column(qn_questionnaire()->manual(['operation' => 'Acquisto', 'purpose' => 'Abitazione principale']), 'id'))->toContain('garden');
});

it('has structured comparable fields for more than half of the questions', function () {
    $questions = collect(Questionnaire::defaultQuestions());

    expect($questions->filter(fn ($q) => ! empty($q['field']) && empty($q['sensitive']))->count() / $questions->count())->toBeGreaterThanOrEqual(0.5);
});

it('shows conditional questions only with their parent answers', function () {
    $q = qn_questionnaire();
    $visible = fn (array $c) => array_column($q->visible($c), 'id');

    $owner = ['operation' => 'Acquisto', 'currentHome' => 'In una casa di proprietà'];
    expect($visible($owner))->toContain('sellCurrentHome')->and(qn_guided_ids($owner))->toHaveCount(12)
        ->and($visible(['operation' => 'Locazione', 'currentHome' => 'In una casa di proprietà']))->not->toContain('sellCurrentHome')
        ->and($q->clean([...$owner, 'sellCurrentHome' => 'Sì', 'currentHome' => 'In affitto']))->not->toHaveKey('sellCurrentHome')
        ->and($visible(['operation' => 'Acquisto', 'mortgage' => true]))->toContain('mortgageState')
        ->and($visible(['operation' => 'Acquisto', 'mortgage' => true, 'mortgageState' => 'Non ancora']))->not->toContain('mortgageEstimate')
        ->and($visible(['operation' => 'Locazione', 'mortgage' => true]))->not->toContain('mortgageState', 'mortgage')
        ->and($visible(['operation' => 'Locazione']))->toContain('pets', 'rentalDuration')->not->toContain('guarantees')
        ->and($visible(['operation' => 'Acquisto', 'purpose' => 'Attività commerciale', 'activity' => 'Bar']))->toContain('flue');
});

it('keeps archived answers when cleaning criteria', function () {
    expect(qn_questionnaire()->clean(['operation' => 'Acquisto', 'capital' => 100000, 'ghost' => 1]))->toBe(['operation' => 'Acquisto', 'capital' => 100000]);
});

it('treats empty values and empty objects as unanswered', function () {
    expect(Questionnaire::hasAnswer(['amount' => null, 'percent' => null]))->toBeFalse()
        ->and(Questionnaire::hasAnswer([]))->toBeFalse()
        ->and(Questionnaire::hasAnswer(''))->toBeFalse()
        ->and(Questionnaire::hasAnswer(false))->toBeTrue()
        ->and(Questionnaire::hasAnswer(['min' => 0]))->toBeTrue();
});

it('validates answers with the gestionale messages', function (string $id, mixed $value, string $message) {
    expect(fn () => AnswerValidator::validate($value, qn_questionnaire()->question($id)))->toThrow(CommandRejected::class, $message);
})->with([
    'operation required' => ['operation', null, 'Scegli Acquisto o Locazione: questa risposta è obbligatoria.'],
    'single option' => ['operation', 'Permuta', 'Seleziona una risposta disponibile.'],
    'range min > max' => ['budget', ['min' => 5, 'max' => 1], 'Controlla l’intervallo: il minimo non può superare il massimo.'],
    'range extra key' => ['area', ['min' => 1, 'avg' => 2], 'Controlla l’intervallo'],
    'range negative' => ['bedrooms', ['min' => -1], 'Controlla l’intervallo'],
    'financing percent' => ['mortgageEstimate', ['percent' => 101], 'percentuale tra 0 e 100'],
    'boolean' => ['terrace', 'sì', 'Scegli sì, no oppure salta la domanda.'],
    'date' => ['availableBy', '06/10/2026', 'Inserisci una data valida.'],
    'multi' => ['zones', ['Atlantide'], 'Seleziona le opzioni disponibili.'],
    'text' => ['notes', str_repeat('x', 4001), 'Inserisci un testo valido, entro 4.000 caratteri.'],
    'activity' => ['activity', str_repeat('x', 4001), 'Descrivi l’attività in un testo entro 4.000 caratteri.'],
    'zone' => ['nearPoint', ['lat' => 95, 'lng' => 9, 'radius' => 1], 'Scegli un punto e una distanza validi.'],
    'tags' => ['tags', [str_repeat('a', 61)], 'Usa fino a 30 tag, con un massimo di 60 caratteri ciascuno.'],
]);

it('accepts valid answers, free typologies and free activity text', function () {
    $q = qn_questionnaire();
    expect(AnswerValidator::validate(['amount' => 300000, 'percent' => 70], $q->question('mortgageEstimate')))->toBe(['amount' => 300000, 'percent' => 70])
        ->and(AnswerValidator::validate(['Attico', 'Villa'], $q->question('typology')))->toBe(['Attico', 'Villa'])
        ->and(AnswerValidator::validate('Ristorazione e asporto', $q->question('activity')))->toBe('Ristorazione e asporto')
        ->and(AnswerValidator::validate(null, $q->question('terrace')))->toBeNull()
        ->and(AnswerValidator::validate(['#Arredato', 'arredata'], $q->question('tags')))->toBe(['Arredato']);
});

it('normalizes and deduplicates free tags (test-crm: tags)', function () {
    expect(Tags::normalize(['Arredato', ' arredato ', 'Terrazzo', 'terrazza', 'Vista parco']))->toBe(['Arredato', 'Terrazzo', 'Vista parco'])
        ->and(Tags::normalize(['Box', 'garage', 'Senza barriere architettoniche', 'accessibile']))->toBe(['Box', 'Senza barriere architettoniche']);
});

it('normalizes phones like identity.ts', function () {
    expect(ContactKeys::phone('320 123 4567'))->toBe(ContactKeys::phone('+39 (320) 123-4567'))
        ->and(ContactKeys::phone('0039 320 123 4567'))->toBe(ContactKeys::phone('3201234567'));
});

it('builds automatic titles', function () {
    expect(Questionnaire::autoTitle(['operation' => 'Acquisto', 'typology' => ['Attico']]))->toBe('Attico da acquistare')
        ->and(Questionnaire::autoTitle(['operation' => 'Locazione', 'typology' => ['Box', 'Posto auto'], 'zones' => ['Centro']]))->toBe('Box / Posto auto in affitto · Centro')
        ->and(Questionnaire::autoTitle([]))->toBe('Immobile');
});

it('groups the wizard in 5 pages and resumes from the saved step', function () {
    $q = qn_questionnaire();
    $criteria = ['operation' => 'Acquisto', 'purpose' => 'Abitazione principale'];
    $groups = ProfileFlow::groups($q, $criteria);

    expect(array_column($groups, 'id'))->toBe(['operation', 'typology', 'budget', 'availableBy', 'notes'])
        ->and(array_column($groups[0]['questions'], 'id'))->toBe(['operation', 'purpose'])
        ->and(array_column($groups[4]['questions'], 'id'))->toBe(['notes', 'tags'])
        ->and(array_column(ProfileFlow::optionalQuestions($q, $criteria), 'id'))->toContain('currentHome', 'mortgage')
        ->and(ProfileFlow::groupId($q, [], 'budget'))->toBe('operation')
        ->and(ProfileFlow::groupId($q, $criteria, 'area'))->toBe('budget')
        ->and(ProfileFlow::groupId($q, $criteria, 'mortgage'))->toBe('notes')
        ->and(ProfileFlow::baseCompleteness($q, $criteria))->toMatchArray(['answered' => 2]);
});

describe('quick request', function () {
    function quick(array $input): array
    {
        return QuickRequestCriteria::from($input, qn_questionnaire());
    }

    it('builds draft criteria and keeps a free zone only in the notes', function () {
        expect(quick(['operation' => 'Acquisto', 'typology' => 'Attico', 'zone' => 'Comune di prova', 'budgetMax' => 300000, 'notes' => 'Luminoso',
            'details' => ['purpose' => 'Abitazione principale', 'area' => ['min' => 80], 'bedrooms' => ['min' => 2], 'balcony' => false]]))
            ->toBe(['operation' => 'Acquisto', 'typology' => ['Attico'], 'budget' => ['max' => 300000],
                'notes' => "Luminoso\n\nZona richiesta: Comune di prova (da precisare nella profilazione).",
                'purpose' => 'Abitazione principale', 'area' => ['min' => 80], 'bedrooms' => ['min' => 2], 'balcony' => false]);
    });

    it('scores a configured zone (case insensitive)', function () {
        $questions = Questionnaire::defaultQuestions();
        foreach ($questions as &$q) {
            if ($q['id'] === 'zones') {
                $q['options'] = ['Washington'];
            }
        }
        expect(QuickRequestCriteria::from(['operation' => 'Locazione', 'typology' => 'Appartamento', 'zone' => ' washington ', 'budgetMax' => 100], new Questionnaire($questions)))
            ->toBe(['operation' => 'Locazione', 'typology' => ['Appartamento'], 'budget' => ['max' => 100], 'zones' => ['Washington']]);
    });

    it('accepts min only, max only or both', function () {
        $base = ['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X'];
        expect(quick([...$base, 'budgetMin' => 1, 'budgetMax' => 2])['budget'])->toBe(['min' => 1, 'max' => 2])
            ->and(quick([...$base, 'budgetMin' => 5])['budget'])->toBe(['min' => 5])
            ->and(quick([...$base, 'budgetMax' => 5])['budget'])->toBe(['max' => 5]);
    });

    it('rejects invalid quick requests with the gestionale messages', function (mixed $input, string $message) {
        expect(fn () => quick($input))->toThrow(CommandRejected::class, $message);
    })->with([
        [['Acquisto'], 'Completa i dati della richiesta rapida.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 1, 'priority' => 'Alta'], 'Campo della richiesta rapida non valido.'],
        [['operation' => 'Permuta', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 1], 'Scegli contratto e tipologia.'],
        [['operation' => 'Acquisto', 'typology' => 'Castello', 'zone' => 'X', 'budgetMax' => 1], 'Scegli contratto e tipologia.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => '  ', 'budgetMax' => 1], 'Indica una zona o un Comune entro 200 caratteri.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X'], 'Indica almeno un limite del budget.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMin' => -1], 'Indica un budget valido maggiore di zero.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 0], 'Indica un budget valido maggiore di zero.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMin' => 0], 'Indica un budget valido maggiore di zero.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => '100'], 'Indica un budget valido maggiore di zero.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => NAN], 'Indica un budget valido maggiore di zero.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMin' => 5, 'budgetMax' => 4], 'Il budget Da non può superare il budget A.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 1, 'notes' => 3], 'Le note devono essere un testo.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 1, 'notes' => str_repeat('n', 4000)], 'Riduci le note: massimo 4.000 caratteri, compresa la zona annotata.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 1, 'details' => 'tanti'], 'Dettagli della richiesta non validi.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 1, 'details' => ['budget' => ['max' => 1]]], 'Dettaglio della richiesta non valido.'],
        [['operation' => 'Acquisto', 'typology' => 'Negozio', 'zone' => 'X', 'budgetMax' => 1, 'details' => ['purpose' => 'Abitazione principale']], 'Scegli una tipologia compatibile con la finalità indicata.'],
        [['operation' => 'Acquisto', 'typology' => 'Terreno edificabile', 'zone' => 'X', 'budgetMax' => 1, 'details' => ['purpose' => 'Terreno', 'bedrooms' => ['min' => 1]]], 'Scegli una finalità compatibile con i dettagli indicati.'],
        [['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 1, 'details' => ['bedrooms' => ['min' => 1]]], 'Scegli una finalità compatibile con i dettagli indicati.'],
    ]);

    it('requires the full profile when essentials are disabled, and free notes when the zone is not configured', function () {
        $without = fn (string $id) => new Questionnaire(array_map(fn ($q) => $q['id'] === $id ? [...$q, 'active' => false] : $q, Questionnaire::defaultQuestions()));
        $input = ['operation' => 'Acquisto', 'typology' => 'Villa', 'zone' => 'X', 'budgetMax' => 1];

        expect(fn () => QuickRequestCriteria::from($input, $without('budget')))->toThrow(CommandRejected::class, 'La configurazione richiede il profilo completo. Apri la profilazione.')
            ->and(fn () => QuickRequestCriteria::from($input, $without('notes')))->toThrow(CommandRejected::class, 'Scegli una zona disponibile: le note libere non sono abilitate.');
    });

    it('offers detail questions by path', function () {
        expect(array_column(QuickRequestCriteria::detailQuestions(qn_questionnaire(), []), 'id'))->not->toContain('bedrooms')
            ->and(array_column(QuickRequestCriteria::detailQuestions(qn_questionnaire(), ['purpose' => 'Terreno']), 'id'))->toContain('roadAccess');
    });
});
