# Revisione del porting del Gestionale (originale → Laravel)

Riferimento: `/Users/andrea/Downloads/gestionale`. Obiettivo: Laravel identico all'originale per grafica e logica.
Controllato leggendo il codice (nessun test lanciato). Il frontend originale su :3102 non è collegato al suo backend
(:8787 risponde 401), quindi il confronto a vista non è stato possibile.

## Già corretto (in questa revisione)
1. **Scout e immobili.** L'originale non dà allo scout nessun immobile (`engine.ts:58`) e blocca `property.*` (`engine.ts:111`).
   Prima `PropertyPolicy`, `Property::scopeVisibleTo` e `SetPropertyLifecycle` glieli lasciavano vedere, modificare e archiviare via URL.
   Ora solo admin e crm. Tolto `updateScoutPrice` (codice mai raggiungibile anche nell'originale).
2. **Perdita dati.** Modificando un immobile il form inviava `cadastral_unit_ids = []` e `SaveProperty` cancellava i collegamenti catastali.
   Ora in modifica la chiave non viene inviata e i collegamenti restano.
3. **Link annuncio.** `publication.url` accettava anche `javascript:`. Ora solo `http(s)://`, in salvataggio e in scheda.

## Da togliere (invenzioni, non esistono nell'originale)
- Promemoria ricorrenti (campo "Ripeti", `metadata.recurrence`, creazione a catena al completamento) in Agenda e nel doc `come-funziona-reko.md`.
- Campi mandato modificabili nel form immobile, "Motivo della modifica", il saluto "Buongiorno", banner e callout aggiunti.

## Critico (comportamento diverso dall'originale)
- **Agenda**: 6 tipi contro 17 (`types.ts:8`, "Telefonata" non "Chiamata"), filtro per i 6 gruppi (`operational-agenda.ts:13`);
  completare richiede esito e commento; permesso di completare solo al destinatario o admin (`permissions.ts:80`), ora anche il creatore;
  mancano scadute, ordinamento, risposte (Accetta, Rinvia, Chiedi chiarimenti, Annulla), griglia di 7 giorni.
- **Obiettivi**: nessun codice scrive `goal_events` e `goal_ledger`, quindi restano a 0 (`goals.ts:40-98`). Assegnazione solo per ruolo.
- **Fuso orario**: `config/app.php` è `UTC`, l'originale usa `Europe/Rome` per "oggi" e per i giorni degli obiettivi.
- **Abbinamenti**: manca `match.state` (13 stati, motivi obbligatori, attività create, stato richiesta aggiornato), la
  "Valutazione del referente", gli esiti di chiamata, la revisione delle proposte di censimento, il punteggio dei tag (alias, prove incrociate).
  Un crm vede abbinamenti con immobili di altri agenti (`show.blade.php:53-55`).
- **Mappa e zone** e **Archivio catastale**: in Laravel sono solo elenchi. Mancano mappa, disegno confini, censimento, schede unità/proprietari,
  import SISTER proprietari, esportazione.
- **Ruoli**: `/mappa-zone` e `/immobili` senza controllo di ruolo nella pagina; permessi opzionali (`activities.assign`, `exports`,
  `sister.import`) mai applicati; nessuna UI per assegnarli. Nome ruolo scout: "Agente acquisizioni" nell'originale, "Operatore" in Laravel.
- **Impostazioni**: l'originale ha 5 tab (Abbinamenti, Domande, Utenti e ruoli, Registro operazioni, Pulizia esempi); Laravel solo i pesi.
- **Clienti**: "Da richiamare" ignora le attività (`client-card.ts:10-17`); `contact_days` salvato per agenzia ma letto da env;
  il cambio referente non riassegna le attività; la domanda zone è vuota (nessuna zona configurata).
- **Grafica**: `app.css` non importa `globals.css` (variabili `--foreground`, `--primary`…, 118 usi in `prototype.css`);
  il layout non mette `reko-prototype` su `.crm-app` (sidebar 232px invece di 248px, font, focus); il `@theme` cambia gli zinc di Flux anche fuori dal gestionale;
  `cartography-reference.css` non importato.

## Importante
- Form immobile molto ridotto (mancano tipologia, finalità, superficie, spese, piano, energia, dotazioni, tag, operatori assegnati, mappa).
  Contratto "Locazione" vs "Affitto"; stati non filtrati per contratto; "Referente" include gli scout.
- `SaveProperty` senza whitelist di `features`, `mandate`, `publication` (Livewire permette di modificare gli array dal client).
- Wizard richiesta: testi e modali diversi ("Approfondimento facoltativo", "Note, tag e specifiche"); `nearPoint` senza mappa; tag con input a virgole.
- Matching: `works` confronta stringhe invece di `expected===true || actual===false`; zona senza distinzione di default; etichette con numeri grezzi;
  eventi `PropertyRequestCompleted`/`CriteriaChanged` senza listener (le pietre miliari non si registrano).
- Lista clienti: contatori che cambiano con la ricerca, frasi mancanti, link contatto senza numero/email visibili, ricerca più larga dell'originale.
- Lunghezze telefono/email troncate prima di validare (`SaveClient.php:72-74`): i controlli `> 60` e `> 160` non scattano mai.
- Utente con membership disattivata durante la sessione: 500 invece di 403 in `scouting` e `activities`.

## Non è un problema (verificato)
- Lo "scout che modifica il prezzo" (`properties.tsx:58`) è codice mai raggiungibile nell'originale: lo scout non vede gli immobili.
- Corretti: isolamento fra agenzie (global scope), concorrenza ottimistica, matrice ruolo-sezione di `Navigation`, regole contatti e duplicati,
  70 domande del questionario (testi, ordine, salti), soglie 80/60/40.
