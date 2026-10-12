@php
    use App\Gestionale\Navigation;

    // Sezione della rotta corrente: titolo di ripiego quando la pagina non ha un titolo visibile.
    $guideSection = Navigation::sectionForRoute(request()->route()?->getName());
    $guideFallback = $guideSection ? Navigation::label($guideSection) : 'REKO';
@endphp
{{--
    Guida contestuale (components/context-guide.tsx): toggle persistito, «Guida alla pagina»
    e marcatori sui campi rilevati automaticamente (data-reko-guide e label dei campi).
    Lo stato vive nello store Alpine «rekoGuide»; il contenitore .reko-guide-ui viene spostato
    nella <dialog> modale aperta (come il portale dell'originale), quindi ogni binding usa $store.
--}}
<div style="display:contents" x-data="{ t: 0, init() { this.t = this.$store.rekoGuide.mount(this.$el.querySelector('.reko-guide-ui'), @js($guideFallback)); }, destroy() { this.$store.rekoGuide.unmount(this.t); } }">
    <div class="reko-guide-ui" style="display:contents" data-reko-guide-ui x-data>
        <button type="button" data-reko-guide-ui class="reko-guide-toggle" x-show="$store.rekoGuide.ready" x-cloak
            x-bind:aria-pressed="$store.rekoGuide.enabled"
            x-bind:aria-label="$store.rekoGuide.enabled ? 'Disattiva Guida contestuale' : 'Attiva Guida contestuale'"
            aria-label="Attiva Guida contestuale" aria-pressed="false"
            x-on:click="$store.rekoGuide.toggle()">
            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="lucide lucide-circle-question-mark"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>
            <span x-text="$store.rekoGuide.enabled ? 'Guida attiva' : 'Guida'">Guida</span>
        </button>

        <template x-if="$store.rekoGuide.ready && $store.rekoGuide.enabled">
            <div style="display:contents">
                <button type="button" class="reko-page-guide" data-reko-guide-ui aria-haspopup="dialog"
                    x-bind:aria-expanded="$store.rekoGuide.open === 'page'"
                    x-on:click="$store.rekoGuide.openPage($el)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="lucide lucide-circle-question-mark"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>Guida alla pagina
                </button>
                <div data-reko-guide-ui class="reko-guide-markers">
                    <template x-for="m in $store.rekoGuide.markers" x-bind:key="m.key">
                        <button type="button" class="reko-guide-indicator" aria-haspopup="dialog"
                            x-bind:style="'left:' + m.left + 'px;top:' + m.top + 'px'"
                            x-bind:aria-label="m.label"
                            x-bind:aria-expanded="$store.rekoGuide.open === m.key"
                            x-on:click="$store.rekoGuide.openMarker(m, $el)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="lucide lucide-circle-question-mark"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>
                        </button>
                    </template>
                </div>
            </div>
        </template>

        {{-- Un solo riquadro condiviso da guida di pagina e marcatori (uno aperto alla volta) --}}
        <div data-reko-guide-ui class="reko-guide-positioner" x-show="$store.rekoGuide.open !== null" x-cloak
            x-bind:style="$store.rekoGuide.popup.style">
            <div role="dialog" class="reko-guide-popup" aria-labelledby="reko-guide-title" aria-describedby="reko-guide-copy"
                x-bind:data-side="$store.rekoGuide.popup.side">
                <div aria-hidden="true" class="reko-guide-arrow" x-bind:data-side="$store.rekoGuide.popup.side"
                    x-bind:style="'position:absolute;left:' + $store.rekoGuide.popup.arrow + 'px'"></div>
                <h2 class="reko-guide-title" id="reko-guide-title" x-text="$store.rekoGuide.popup.title"></h2>
                <div class="reko-guide-copy"><p id="reko-guide-copy" x-text="$store.rekoGuide.popup.text"></p></div>
                <button type="button" class="reko-guide-close" aria-label="Chiudi spiegazione" x-on:click="$store.rekoGuide.close(true)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
        </div>
    </div>
</div>

@verbatim
<script data-navigate-once>
    (() => {
        const setup = () => {
            const preference = 'reko:context-guide:v1';
            // Testi copiati esatti da components/context-guide.tsx
            const topics = {
                height: { title: 'Altezza del fabbricato', text: 'È l’altezza rilevata in metri, non il numero dei piani. Il filtro è facoltativo e compare solo dove il dato è presente. Lascia vuoto se non serve; con due limiti, il minimo non può superare il massimo.' },
                municipality: { title: 'Comune', text: 'Indica il Comune dell’immobile o della ricerca. Se il campo propone dei suggerimenti, scegli la voce corretta. In Archivio vedrai le schede già acquisite; in Trova puoi cercare nei Comuni coperti dai dati REKO.' },
                contactOutcome: { title: 'Esito dell’attività', text: 'Riporta il risultato della telefonata, della visita o del contatto. Salva l’attività per registrarlo nello storico. Non indica automaticamente che l’immobile sia in vendita.' },
                multi: { title: 'Multi proprietario e comproprietà', text: 'Multi proprietario indica più di 2 unità distinte intestate alla stessa persona, anche in Comuni diversi. Comproprietà indica invece più intestatari della stessa unità.' },
                civic: { title: 'Via e civico', text: 'Separa la via dal numero civico, per esempio Via Roma e 10/A. Se l’indirizzo importato contiene numeri ambigui, controlla la fonte prima di correggerlo.' },
                bedrooms: { title: 'Camere da letto', text: 'Indica quante camere da letto cerca il cliente. Non sono i vani catastali: soggiorno e servizi non vanno contati come camere.' },
                budget: { title: 'Budget del cliente', text: 'Indica il limite di spesa in euro. Per un affitto è il canone mensile; per un acquisto è il prezzo complessivo. Non inserire il simbolo € nel campo numerico.' },
                vani: { title: 'Vani catastali', text: 'Sono una misura catastale: non corrispondono alle camere da letto e non si possono convertire automaticamente in m². Inserisci minimo e massimo richiesti per proseguire.' },
                footprint: { title: 'Superficie del fabbricato', text: 'Misuriamo lo spazio occupato a terra, senza sommare i piani. In Grandi fabbricati inserisci entrambi i limiti: da 500 a 1.000.000 m², con almeno 20 m² tra minimo e massimo. Poi premi Avanti.' },
                lot: { title: 'Superficie del lotto', text: 'Comprende il terreno della particella, incluso lo spazio occupato dal fabbricato. È facoltativa: puoi lasciare vuoti entrambi i campi. Ogni limite inserito deve essere almeno pari al minimo scelto per il fabbricato. Con due limiti, lascia almeno 20 m² di differenza.' },
                groups: { title: 'Gruppi catastali', text: 'A: abitazioni e uffici. B: servizi collettivi. C: negozi, depositi e autorimesse. D: immobili per attività speciali. F: immobili senza rendita ordinaria. In Grandi fabbricati puoi scegliere un solo gruppo, oppure nessuno. Basta che sia presente almeno un’unità del gruppo scelto; gli altri usi non escludono il risultato.' },
                comparison: { title: 'Singolo, complesso o entrambi?', text: 'Singolo: confrontiamo la superficie di un fabbricato. Intero complesso: sommiamo le sagome collegate allo stesso lotto verificato, non i piani. Entrambi: cerchiamo tutte e due le possibilità. Non significa che vi sia un unico proprietario. Scegli la modalità, poi prosegui.' },
                outcome: { title: 'Esito del contatto', text: 'Tutti non limita la ricerca. Nessun esito mostra le schede senza un riscontro confermato. Gli altri valori cercano l’ultimo esito registrato: l’elenco comprende tutti gli esiti previsti, anche se nessuna scheda del Comune li usa ancora. Il filtro non modifica i riscontri.' },
                sheet: { title: 'Foglio', text: 'È il numero della tavola catastale del Comune. Va letto insieme a Comune e particella: da solo non identifica un immobile.' },
                parcel: { title: 'Particella', text: 'Identifica un’area nella mappa catastale. Usa sempre anche Comune e foglio. Una particella può contenere più fabbricati e più unità.' },
                sub: { title: 'Subalterno', text: 'Identifica una singola unità all’interno della particella. Se nella fonte non compare, lascia il campo vuoto: non inventare un numero.' },
                section: { title: 'Sezione', text: 'È una suddivisione catastale presente solo in alcuni Comuni. Riporta il valore della fonte; se assente, lascia vuoto.' },
                census: { title: 'Zona censuaria', text: 'È una suddivisione usata dal Catasto per le valutazioni. Non coincide necessariamente con un quartiere o con una zona di ricerca.' },
                area: { title: 'Superficie in m²', text: 'Indica la superficie richiesta in metri quadrati. I m² non sono i vani catastali. Compila i limiti indicati e rispetta gli aiuti visibili sotto il campo, poi premi Avanti o Continua.' },
                answers: { title: 'Risposte del percorso base', text: 'Il numero conta le domande del percorso base già compilate rispetto a quelle previste per questa richiesta. Di solito sono 12, ma il totale dipende dal percorso e dalle domande configurate. Non è un punteggio di compatibilità. Apri la profilazione per completare le risposte mancanti.' },
                holding: { title: 'Diritto sull’immobile', text: 'Indica a quale titolo la persona è collegata all’immobile, per esempio proprietà o usufrutto, con la relativa quota. Non indicato significa che il dato manca nella scheda: non prova che la persona non abbia diritti. Negli esempi demo può non essere compilato; negli altri casi verifica la fonte catastale.' },
                profile: { title: 'Completa la richiesta', text: 'Raccogli le esigenze del cliente. Compila i campi richiesti nel passaggio e premi Continua; i campi indicati come facoltativi possono essere completati in seguito.' },
            };

            const topicFor = (label, context) => {
                const text = label.toLocaleLowerCase('it-IT');
                if (/^(ultimo )?esito$/.test(text.trim())) return 'outcome';
                if (/^comune/.test(text)) return 'municipality';
                if (/multi proprietario|comproprietà/.test(text)) return 'multi';
                if (/civico/.test(text)) return 'civic';
                if (/camere da letto/.test(text)) return 'bedrooms';
                if (/budget/.test(text)) return 'budget';
                if (/zona censuaria/.test(text)) return 'census';
                if (/subalterno/.test(text)) return 'sub';
                if (/particella/.test(text)) return 'parcel';
                if (/foglio/.test(text)) return 'sheet';
                if (/sezione/.test(text)) return 'section';
                if (/gruppo catastale/.test(text)) return 'groups';
                if (/vani/.test(text) || /vani catastali/.test(context)) return 'vani';
                if (/m²|m2|metri quadrati/.test(text)) return 'area';
                return undefined;
            };

            // Testo della guida di pagina scelto dal titolo della schermata (PageGuide)
            const pageText = (title) => {
                const name = title.toLowerCase();
                return /archivio/.test(name) ? 'Scegli prima il Comune, poi uno dei quattro modi di ricerca. I filtri operativi sono facoltativi. Apri una scheda per vedere unità, proprietari e attività.'
                    : /richiest|esigenze|profilazione/.test(name) ? 'Parti da una richiesta del cliente. Completa i passaggi con le sue esigenze, poi consulta gli abbinamenti. I punti interrogativi spiegano i campi: premi una volta per aprire, X per chiudere.'
                    : /client/.test(name) ? 'Apri un cliente per vedere richieste e storico, oppure usa Nuovo cliente. Per registrare telefonate, visite o note usa Aggiungi attività.'
                    : /oggi|lavoro di oggi/.test(name) ? 'Questo riepilogo raccoglie le attività del tuo ruolo. Apri Agenda per gli impegni, Clienti e Richieste per il lavoro commerciale, Archivio catastale per i dati acquisiti.'
                    : /account/.test(name) ? 'Qui controlli il tuo account e puoi cambiare la password. Esci da tutte le aree chiude la sessione sia in Trova sia nel Gestionale.'
                    : /obiettiv/.test(name) ? 'Consulta gli obiettivi e l’avanzamento per persona e periodo. Le proposte da verificare restano nella sezione dedicata e non sono approvate automaticamente.'
                    : /agenda/.test(name) ? 'Scegli Giorno o Settimana, controlla le scadute e apri un’attività per completarla o spostarla. Filtri permette di restringere l’elenco.'
                    : /portafoglio/.test(name) ? 'Qui trovi le schede commerciali dell’agenzia, distinte dalle unità catastali. Apri una scheda per caratteristiche, contatti, attività e abbinamenti.'
                    : /cerc|trova|superficie|zona|comune/.test(name) ? 'Scegli il Comune e il percorso, poi compila zona e caratteristiche. Premi i punti interrogativi per chiarire i campi. Compila i valori obbligatori prima di proseguire.'
                    : 'Premi i punti interrogativi accanto ai campi per leggere una spiegazione. La guida resta attiva tra le pagine; usa Guida attiva per disattivarla senza cambiare i dati.';
            };

            const hiddenSelector = '[hidden],[inert],[aria-hidden="true"],[data-reko-guide-ui]';
            // Stato non reattivo (elementi DOM, osservatori, timer)
            const ids = new WeakMap();
            let nextId = 0, frame = 0, token = 0, ui = null, fallback = 'REKO', host = null, stack = [], targets = new Map(), anchor = null, opener = null, stop = null;

            const store = {
                ready: false,
                enabled: false,
                markers: [],
                open: null, // null | 'page' | chiave del marcatore
                pageTitle: 'REKO',
                popup: { title: '', text: '', side: 'bottom', style: 'position:fixed;left:0;top:0', arrow: 14 },

                toggle() {
                    const value = !this.enabled;
                    try { localStorage.setItem(preference, value ? 'on' : 'off'); } catch (e) {}
                    apply(value);
                },
                openPage(trigger) {
                    if (this.open === 'page') return this.close(true);
                    this.popup.title = this.pageTitle;
                    this.popup.text = pageText(this.pageTitle);
                    show('page', trigger, trigger, 'top', 8);
                },
                openMarker(marker, trigger) {
                    if (this.open === marker.key) return this.close(true);
                    const target = targets.get(marker.key);
                    if (!target || !topics[marker.topic]) return;
                    this.popup.title = topics[marker.topic].title;
                    this.popup.text = topics[marker.topic].text;
                    show(marker.key, target, trigger, 'bottom', 10);
                },
                close(restoreFocus = false) {
                    if (this.open === null) return;
                    this.open = null;
                    anchor = null;
                    if (restoreFocus && opener?.isConnected) opener.focus();
                    opener = null;
                },

                // Chiamati dal componente a ogni caricamento di pagina (anche con wire:navigate)
                mount(element, fallbackTitle) {
                    stop?.();
                    ui = element;
                    fallback = fallbackTitle || 'REKO';
                    this.pageTitle = fallback;
                    const mine = ++token;
                    stop = start();
                    this.ready = true;
                    return mine;
                },
                unmount(mine) {
                    if (mine !== token) return;
                    stop?.();
                    stop = null;
                    this.ready = false;
                    this.markers = [];
                    this.open = null;
                },
            };
            Alpine.store('rekoGuide', store);
            const s = Alpine.store('rekoGuide');

            function apply(value) {
                s.enabled = value;
                if (!value) { s.close(false); s.markers = []; targets = new Map(); return; }
                schedule();
            }

            // Posiziona il riquadro rispetto all'ancora: lato preferito, ribaltato se non c'è spazio
            function place(preferred, offset) {
                const popup = ui?.querySelector('.reko-guide-popup');
                if (!anchor || !popup || s.open === null) return;
                const rect = anchor.getBoundingClientRect(), width = popup.offsetWidth, height = popup.offsetHeight, pad = 12;
                const below = innerHeight - rect.bottom - offset - pad, above = rect.top - offset - pad;
                let side = preferred;
                if (side === 'bottom' && below < height && above > below) side = 'top';
                else if (side === 'top' && above < height && below > above) side = 'bottom';
                const center = rect.left + rect.width / 2;
                const left = Math.max(pad, Math.min(innerWidth - pad - width, center - width / 2));
                const style = 'position:fixed;left:' + left + 'px;' + (side === 'bottom' ? 'top:' + (rect.bottom + offset) + 'px' : 'bottom:' + (innerHeight - rect.top + offset) + 'px');
                const arrow = Math.max(14, Math.min(width - 26, center - left - 6));
                if (s.popup.side !== side) s.popup.side = side;
                if (s.popup.style !== style) s.popup.style = style;
                if (s.popup.arrow !== arrow) s.popup.arrow = arrow;
            }
            let placement = ['bottom', 10];
            function show(key, anchorElement, trigger, side, offset) {
                anchor = anchorElement;
                opener = trigger;
                placement = [side, offset];
                s.popup.side = side;
                s.open = key;
                Alpine.nextTick(() => {
                    place(side, offset);
                    ui?.querySelector('.reko-guide-close')?.focus({ preventScroll: true });
                });
            }

            // Titolo della schermata (o del passaggio della richiesta) che sceglie il testo della guida di pagina
            function readTitle() {
                const visible = (el) => !el.closest(hiddenSelector) && el.getBoundingClientRect().height > 0;
                const step = [...host.querySelectorAll('#catalog-question,#wizard-question')].find(visible);
                const heading = step || [...host.querySelectorAll('h1,h2')].find(visible);
                const title = heading?.textContent?.trim() || fallback;
                if (title !== s.pageTitle) {
                    s.pageTitle = title;
                    if (s.open === 'page') s.close(false);
                }
            }

            // Marcatori sui campi e sui blocchi con data-reko-guide, rilevati nell'host corrente (GuideMarkers)
            function collect() {
                frame = 0;
                if (!s.enabled || !host) return;
                readTitle();
                const result = [], found = new Map(), seen = new Set();
                const add = (target, topic) => {
                    if (!topics[topic] || seen.has(target) || target.closest(hiddenSelector)) return;
                    const rect = target.getBoundingClientRect(), style = getComputedStyle(target);
                    if (rect.width < 5 || rect.height < 5 || style.visibility === 'hidden' || style.display === 'none') return;
                    // Il marcatore fisso segue la parte visibile del campo, non un campo nascosto
                    // dietro intestazione o piè di pagina di un pannello che scorre.
                    let left = Math.max(8, rect.left), right = Math.min(innerWidth - 8, rect.right), top = Math.max(8, rect.top), bottom = Math.min(innerHeight - 8, rect.bottom);
                    for (let parent = target.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
                        const css = getComputedStyle(parent), box = parent.getBoundingClientRect();
                        if (/auto|scroll|hidden|clip/.test(css.overflowX)) { left = Math.max(left, box.left); right = Math.min(right, box.right); }
                        if (/auto|scroll|hidden|clip/.test(css.overflowY)) { top = Math.max(top, box.top); bottom = Math.min(bottom, box.bottom); }
                    }
                    if (right - left < 44 || bottom - top < 24) return;
                    const hit = document.elementsFromPoint((left + right) / 2, Math.min(bottom - 1, top + 12)).find((el) => !el.closest('[data-reko-guide-ui]'));
                    if (hit && !target.contains(hit) && !hit.contains(target)) return;
                    const markerLeft = right - 44, markerTop = Math.min(innerHeight - 52, top);
                    if (result.some((m) => Math.abs(m.left - markerLeft) < 40 && Math.abs(m.top - markerTop) < 40)) return;
                    seen.add(target);
                    if (!ids.has(target)) ids.set(target, ++nextId);
                    const key = ids.get(target);
                    found.set(key, target);
                    result.push({ key, topic, left: markerLeft, top: markerTop, label: 'Guida: ' + topics[topic].title });
                };
                host.querySelectorAll('[data-reko-guide]').forEach((el) => add(el, el.dataset.rekoGuide || ''));
                host.querySelectorAll('input:not([type=hidden]),select,textarea').forEach((control) => {
                    if (control.closest('[data-reko-guide],[data-reko-guide-ui]')) return;
                    const label = control.getAttribute('aria-label') || [...(control.labels ?? [])].map((el) => el.querySelector(':scope > span')?.textContent || [...el.childNodes].filter((n) => n.nodeType === Node.TEXT_NODE).map((n) => n.textContent).join(' ')).join(' ') || '';
                    const field = control.closest('.crm-field,label') || control;
                    const context = control.closest('section')?.querySelector('#catalog-question,#wizard-question')?.textContent?.toLowerCase() || '';
                    const topic = label.trim().toLocaleLowerCase('it-IT') === 'esito' && control.tagName !== 'SELECT' ? 'contactOutcome' : topicFor(label, context);
                    if (topic) add(field, topic);
                });
                host.querySelectorAll('.crm-question-actions').forEach((el) => add(el, 'profile'));
                targets = found;
                const old = s.markers;
                if (!(old.length === result.length && old.every((m, i) => m.key === result[i].key && m.topic === result[i].topic && m.left === result[i].left && m.top === result[i].top))) s.markers = result;
                if (typeof s.open === 'number' && !found.has(s.open)) s.close(false);
                if (s.open !== null) place(...placement);
            }
            function schedule() { if (!frame) frame = requestAnimationFrame(collect); }

            // Host dei controlli: l'ultima <dialog> modale aperta, altrimenti il body
            // (fuori da una modale il resto della pagina è inerte).
            function syncHost() {
                const current = [...document.querySelectorAll('dialog:modal')];
                stack = [...stack.filter((el) => current.includes(el)), ...current.filter((el) => !stack.includes(el))];
                const next = stack.at(-1) || document.body;
                if (ui && ui.parentNode !== next) next.appendChild(ui);
                if (next !== host) { host = next; if (s.open !== null) s.close(false); schedule(); }
            }

            const inGuide = (record) => {
                const el = record.target instanceof Element ? record.target : record.target.parentElement;
                return !!el?.closest('[data-reko-guide-ui]');
            };

            function start() {
                let live = true;
                stack = [];
                host = null;
                try { apply(localStorage.getItem(preference) === 'on'); } catch (e) { apply(false); }
                const hostObserver = new MutationObserver(syncHost);
                hostObserver.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['open'] });
                const observer = new MutationObserver((records) => { if (records.some((r) => !inGuide(r))) schedule(); });
                observer.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['hidden', 'class', 'style', 'aria-hidden', 'aria-label', 'open'] });
                const resize = new ResizeObserver(schedule);
                resize.observe(document.body);
                const onStorage = (e) => { if (e.key === preference) apply(e.newValue === 'on'); };
                // Fuori dal riquadro e dai suoi pulsanti chiude la spiegazione aperta
                const onClick = (e) => { if (s.open !== null && !e.target.closest?.('.reko-guide-popup,.reko-guide-indicator,.reko-page-guide')) s.close(false); };
                const onKey = (e) => {
                    if (e.key !== 'Escape' || s.open === null) return;
                    // Il riquadro di un campo non deve chiudere anche la finestra modale che lo contiene
                    if (typeof s.open === 'number' && e.target.closest?.('.reko-guide-popup')) e.stopPropagation();
                    s.close(true);
                };
                window.addEventListener('resize', schedule);
                window.addEventListener('scroll', schedule, true);
                window.addEventListener('storage', onStorage);
                document.addEventListener('click', onClick);
                document.addEventListener('keydown', onKey);
                // Dopo l'inizializzazione di Alpine: syncHost sposta il contenitore dei controlli
                Alpine.nextTick(syncHost);
                return () => {
                    if (!live) return;
                    live = false;
                    hostObserver.disconnect();
                    observer.disconnect();
                    resize.disconnect();
                    cancelAnimationFrame(frame);
                    frame = 0;
                    window.removeEventListener('resize', schedule);
                    window.removeEventListener('scroll', schedule, true);
                    window.removeEventListener('storage', onStorage);
                    document.removeEventListener('click', onClick);
                    document.removeEventListener('keydown', onKey);
                };
            }
        };
        window.Alpine ? setup() : document.addEventListener('alpine:init', setup);
    })();
</script>
@endverbatim
