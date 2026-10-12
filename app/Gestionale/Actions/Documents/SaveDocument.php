<?php

namespace App\Gestionale\Actions\Documents;

use App\Gestionale\Actions\Activities\CreateActivity;
use App\Gestionale\Audit;
use App\Gestionale\CommandRejected;
use App\Gestionale\Commands;
use App\Models\AgencyMembership;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * document.save of the gestionale (engine.ts): a document entry on a property or a client. No file is stored.
 * In one transaction it records the entry, touches the record (its revision changes), writes the activity
 * "Documento ricevuto" (already done) or "Documento richiesto" and the audit event.
 *
 * Input: title, kind (Documento|Fotografia|Planimetria|Video), status (Da richiedere|Richiesto|Ricevuto),
 * content, internal (default true). The caller has already authorized the update of the record.
 */
final class SaveDocument
{
    public const KINDS = ['Documento', 'Fotografia', 'Planimetria', 'Video'];

    public const STATUSES = ['Da richiedere', 'Richiesto', 'Ricevuto'];

    public function __construct(
        private readonly CreateActivity $activities,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(AgencyMembership $actor, Property|Contact $record, array $input): Document
    {
        if (! $actor->isActive() || (int) $record->agency_id !== (int) $actor->agency_id) {
            throw new CommandRejected('Scheda non accessibile.', 403);
        }
        $title = Commands::text($input['title'] ?? '', 160);
        if ($title === '') {
            throw new CommandRejected('Indica il nome del documento demo.', 400, 'documentTitle');
        }
        $kind = in_array($input['kind'] ?? null, self::KINDS, true) ? $input['kind'] : 'Documento';
        $status = in_array($input['status'] ?? null, self::STATUSES, true) ? $input['status'] : 'Richiesto';
        $internal = ($input['internal'] ?? true) !== false;
        $received = $status === 'Ricevuto';

        return DB::transaction(function () use ($actor, $record, $title, $kind, $status, $internal, $received, $input): Document {
            $document = Document::query()->create([
                'agency_id' => $record->agency_id,
                'uploaded_by_user_id' => $actor->user_id,
                'title' => $title,
                'storage_path' => null,
                'mime_type' => null,
                'byte_size' => null,
                'documentable_type' => $record->getMorphClass(),
                'documentable_id' => $record->getKey(),
                'metadata' => ['kind' => $kind, 'status' => $status, 'content' => Commands::text($input['content'] ?? '', 5000), 'internal' => $internal],
            ]);
            $record->touch();

            $this->activities->handle($actor, [
                'type' => $received ? 'Documento ricevuto' : 'Documento richiesto',
                'title' => $title,
                'notes' => $internal ? 'Documento interno dimostrativo' : 'Documento dimostrativo',
                'done' => $received,
            ] + ($record instanceof Property ? ['property_id' => $record->getKey()] : ['contact_id' => $record->getKey()]));

            $this->audit->record('document.save', $record, ['document_id' => $document->id, 'title' => $title, 'kind' => $kind, 'status' => $status]);

            return $document;
        });
    }
}
