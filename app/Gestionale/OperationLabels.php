<?php

namespace App\Gestionale;

/**
 * human-labels.ts operationLabel(): the wording of the audit log. Presentation only: never a stored value,
 * filter or audit action key. Codes the Laravel commands record with another spelling than the old
 * engine (activity.create, property.lifecycle, …) carry the wording of their old equivalent.
 */
final class OperationLabels
{
    public const LABELS = [
        'demo.tests.purge' => 'Esempi di portafoglio e proprietari rimossi',
        'demo.agenda.refresh' => 'Date degli esempi di Agenda aggiornate',
        'sister.owner.close' => 'Intestazione precedente chiusa e conservata nello storico',
        'sister.source.date' => 'Data della fonte Sister aggiornata',
        'record.lifecycle' => 'Conservazione della scheda aggiornata',
        'client.save' => 'Scheda cliente salvata',
        'request.create' => 'Richiesta creata',
        'request.save' => 'Richiesta salvata',
        'request.answer' => 'Risposta della richiesta aggiornata',
        'request.finish' => 'Profilazione della richiesta completata',
        'request.step' => 'Passaggio della profilazione aggiornato',
        'property.save' => 'Scheda immobile salvata',
        'match.state' => 'Stato dell’abbinamento aggiornato',
        'activity.call-result' => 'Riscontro della telefonata registrato',
        'activity.save' => 'Attività salvata',
        'crm.activity.record' => 'Azione cliente registrata',
        'activity.done' => 'Completamento dell’attività aggiornato',
        'activity.respond' => 'Riscontro sull’attività registrato',
        'parcel.assign' => 'Operatore della particella assegnato',
        'owner.save' => 'Scheda proprietario salvata',
        'archive.save' => 'Dati catastali aggiornati',
        'sister.import' => 'Importazione da Sister registrata',
        'catalog.acquire' => 'Immobili del catalogo aggiunti al censimento',
        'census.contact' => 'Recapito del proprietario aggiornato',
        'census.filter.save' => 'Combinazione di filtri salvata',
        'census.propose' => 'Proposta di aggiornamento inviata',
        'census.proposal.review' => 'Proposta di aggiornamento esaminata',
        'census.owners.replace' => 'Intestazioni catastali aggiornate',
        'census.associate' => 'Proprietario associato all’immobile',
        'census.unlink' => 'Associazione del proprietario rimossa',
        'census.unit.delete' => 'Unità rimossa dall’elenco attivo',
        'census.owner.delete' => 'Proprietario rimosso dall’elenco attivo',
        'census.update' => 'Riscontro di contatto registrato',
        'census.zone.boundary' => 'Confini della zona operativa salvati',
        'census.zone.save' => 'Piano di censimento salvato',
        'census.sheet.load' => 'Foglio cartografico caricato',
        'goal.save' => 'Obiettivo salvato',
        'notification.read' => 'Notifica letta',
        'document.save' => 'Documento registrato',
        'settings.save' => 'Configurazione aggiornata',
        'user.save' => 'Profilo operatore aggiornato',
        'audit.view' => 'Registro delle operazioni consultato',
        // Historical records remain readable, without re-enabling these workflows.
        'acquisition.approve' => 'Pratica di incarico approvata',
        'acquisition.return' => 'Integrazione della pratica richiesta',
        'acquisition.save' => 'Pratica di incarico aggiornata',
        // Spellings of the Laravel commands.
        'activity.create' => 'Attività salvata',
        'activity.complete' => 'Completamento dell’attività aggiornato',
        'property.lifecycle' => 'Conservazione della scheda aggiornata',
        'match.status' => 'Stato dell’abbinamento aggiornato',
        'goal.target' => 'Obiettivo salvato',
        'scouting-zone.create' => 'Piano di censimento salvato',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? 'Operazione registrata';
    }
}
