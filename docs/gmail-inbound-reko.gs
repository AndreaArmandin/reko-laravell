/**
 * Gmail -> REKO "Posta".
 * Incolla in script.google.com (account della casella che riceve le mail),
 * poi: Impostazioni progetto > Proprietà script e crea
 *   REKO_URL = https://<dominio-reko>/api/inbound-email
 *   REKO_KEY = <la chiave generata nella pagina Posta di REKO>
 *   SEARCH   = from:(immobiliare.it)         (per il test: from:(tua.altra@gmail.com))
 * Esegui una volta "forward" per autorizzare, poi Trigger > ogni 5 minuti.
 * Il risultato di ogni giro si legge in "Log esecuzione".
 */
const LABEL = 'reko-inviata';

function forward() {
  const props = PropertiesService.getScriptProperties();
  const url = props.getProperty('REKO_URL'), key = props.getProperty('REKO_KEY');
  const search = props.getProperty('SEARCH');
  if (!url || !key || !search) throw new Error('Imposta REKO_URL, REKO_KEY e SEARCH nelle proprietà script.');
  const label = GmailApp.getUserLabelByName(LABEL) || GmailApp.createLabel(LABEL);
  const query = search + ' newer_than:2d -label:' + LABEL;
  const threads = GmailApp.search(query, 0, 50);
  console.log('Ricerca: ' + query + ' -> conversazioni trovate: ' + threads.length);
  for (const thread of threads) {
    let ok = true;
    for (const m of thread.getMessages()) {
      const res = UrlFetchApp.fetch(url, {
        method: 'post',
        contentType: 'application/json',
        // ngrok (versione gratuita) mette una pagina di avviso davanti ai browser: questo la salta.
        headers: { Authorization: 'Bearer ' + key, 'ngrok-skip-browser-warning': '1' },
        muteHttpExceptions: true,
        payload: JSON.stringify({
          messageId: m.getId(),
          from: m.getFrom(),
          subject: m.getSubject(),
          date: m.getDate().toISOString(),
          body: m.getPlainBody().slice(0, 100000),
          source: 'gmail',
        }),
      });
      const text = res.getContentText();
      console.log(m.getFrom() + ' | ' + m.getSubject() + ' -> ' + res.getResponseCode() + ' ' + text.slice(0, 200));
      // Accettata solo una risposta JSON di REKO: una pagina HTML (es. avviso di un tunnel) non conta.
      let reply = null;
      try { reply = JSON.parse(text); } catch (e) {}
      if (res.getResponseCode() !== 200 || !reply || (!('saved' in reply) && !('ignored' in reply))) ok = false;
    }
    // Il thread viene etichettato solo se tutti i messaggi sono stati accettati: altrimenti riprova al giro dopo.
    if (ok) thread.addLabel(label);
  }
}
