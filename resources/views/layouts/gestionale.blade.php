@php
    use App\Gestionale\Activities\ActivityPresentation;
    use App\Gestionale\Navigation;
    use App\Gestionale\Notifications\NotificationFeed;
    use App\Gestionale\WorkProfile;
    use App\Models\CensusProposal;
    use App\Models\GestionaleNotification;

    $current = app(\App\Gestionale\CurrentAgency::class);
    $membership = $current->membership();
    $agencies = $current->available();
    $groups = Navigation::groupedItems($membership);
    $sections = Navigation::sectionsFor($membership);
    $isAdmin = $membership?->role === 'admin';
    $canSettings = in_array('Impostazioni CRM', $sections, true);
    $section = Navigation::sectionForRoute(request()->route()?->getName());
    $currentLabel = $section ? Navigation::label($section) : 'Gestionale';
    $proposals = $isAdmin ? CensusProposal::query()->where('status', 'Da verificare')->count() : 0;
    $notifications = $membership ? app(NotificationFeed::class)->for($membership) : collect();
    $unread = $notifications->whereNull('read_at')->count();
@endphp
<!DOCTYPE html>
<html lang="it">

<head>
    @include('partials.head', ['stylesheet' => 'resources/css/gestionale.css'])
</head>

<body>
    <div class="reko-prototype crm-app" x-data="{ menu: false }" x-on:keydown.escape.window="menu = false">
        <a href="#crm-main" class="crm-skip">Vai al contenuto</a>

        <aside class="crm-sidebar" x-bind:class="{ 'open': menu }">
            <button type="button" class="crm-drawer-close crm-icon-button" aria-label="Chiudi menu Gestionale" x-on:click="menu = false"><x-gestionale.lucide name="x" /></button>
            <a href="{{ route('home') }}" class="reko-brand-logo" aria-label="REKO — torna alla home"><img src="/reko-logo.svg" width="158" height="48" alt="REKO"></a>
            <p class="crm-sidebar-caption">GESTIONALE<br><span>Le relazioni diventano opportunità.</span></p>

            @include('layouts.partials.gestionale-navigation', ['groups' => $groups])

            <div class="crm-sidebar-footer">
                @if ($isAdmin)
                    <livewire:gestionale.role-preview />
                @endif
                <div class="crm-mobile-account">
                    <a href="{{ route('dashboard') }}">Account</a>
                    @if ($canSettings)
                        <a class="crm-link" href="{{ route('gestionale.settings.index') }}" wire:navigate x-on:click="menu = false"><x-gestionale.lucide name="settings-2" :size="18" />Impostazioni</a>
                    @endif
                </div>
                <a href="{{ route('trova') }}">Trova immobili <x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                <a href="{{ route('home') }}">Torna alla home <x-gestionale.lucide name="arrow-up-right" :size="16" /></a>
                <p><x-gestionale.lucide name="shield-check" :size="15" />Accesso personale riservato</p>
            </div>
        </aside>

        <div class="crm-content">
            <header class="crm-topbar">
                <button type="button" class="crm-icon-button crm-menu" x-bind:aria-label="menu ? 'Chiudi menu Gestionale' : 'Apri menu Gestionale'" aria-label="Apri menu Gestionale" x-bind:aria-expanded="menu" aria-expanded="false" x-on:click="menu = ! menu">
                    <span x-show="! menu"><x-gestionale.lucide name="menu" /></span><span x-show="menu" x-cloak><x-gestionale.lucide name="x" /></span>
                </button>
                <span class="crm-top-location">REKO Gestionale <x-gestionale.lucide name="chevron-right" :size="14" />{{ $currentLabel }}</span>
                <span class="crm-mobile-title">REKO</span>
                @if ($isAdmin && $proposals > 0)
                    <a class="crm-btn secondary crm-approval-counter" aria-label="Proposte da approvare: {{ $proposals }}" href="{{ route('gestionale.home', ['filtro' => 'approvals']) }}" wire:navigate>Da approvare <strong>{{ $proposals }}</strong></a>
                @endif
                <details class="crm-notifications">
                    <summary aria-label="Notifiche, {{ $unread }} da leggere"><x-gestionale.lucide name="bell" :size="18" /><span>{{ $unread }}</span></summary>
                    <div class="crm-notification-list">
                        <strong>Le tue notifiche</strong>
                        @foreach ($notifications->take(20) as $notice)
                            <form method="POST" action="{{ route('gestionale.notifications.read', $notice->id) }}">
                                @csrf
                                <button type="submit"><span>{{ $notice->title }}</span><small>{{ ActivityPresentation::dateLabel($notice->occurred_at, true) }} · {{ $notice->read_at ? 'Letta' : 'Nuova' }}</small></button>
                            </form>
                        @endforeach
                        @if ($notifications->isEmpty())<p>Nessuna notifica.</p>@endif
                    </div>
                </details>
                <div class="crm-user-switch">
                    <span>{{ WorkProfile::label($membership?->role) }}</span>
                    @if ($agencies->count() > 1)
                        <select aria-label="Cambia agenzia" onchange="if (this.value) document.getElementById('agency-switch-' + this.value)?.requestSubmit()">
                            @foreach ($agencies as $option)
                                <option value="{{ $option->agency_id }}" @selected($option->agency_id === $membership?->agency_id)>{{ $option->agency->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <form method="POST" action="{{ route('gestionale.profile.clear') }}">
                        @csrf
                        <button type="submit" class="crm-link">Cambia profilo</button>
                    </form>
                    <a href="{{ route('dashboard') }}" class="crm-link" aria-label="{{ auth()->user()->name }} · Account">Account</a>
                    @if ($canSettings)
                        <a href="{{ route('gestionale.settings.index') }}" class="crm-link crm-settings-shortcut" aria-label="Impostazioni CRM" title="Impostazioni CRM" wire:navigate><x-gestionale.lucide name="settings-2" :size="20" /><span>Impostazioni</span></a>
                    @endif
                    @foreach ($agencies as $option)
                        <form id="agency-switch-{{ $option->agency_id }}" method="POST" action="{{ route('gestionale.enter', $option->agency_id) }}" class="hidden">@csrf</form>
                    @endforeach
                </div>
            </header>

            <div class="crm-demo-strip">
                <span><i></i>Uso interno dell’agenzia · nessun annuncio pubblicato automaticamente sui portali</span>
                <span role="status"><x-gestionale.lucide name="check" :size="13" /><span x-text="$store.crm.busy > 0 ? 'Salvataggio…' : 'Archivio aggiornato'">Archivio aggiornato</span></span>
            </div>
            <div class="crm-global-error" role="alert" x-show="$store.crm.error" x-cloak>
                <span x-text="$store.crm.error"></span>
                <button type="button" aria-label="Chiudi errore" x-on:click="$store.crm.error = ''"><x-gestionale.lucide name="x" :size="17" /></button>
            </div>

            <main id="crm-main" class="crm-main" tabindex="-1">
                {{ $slot }}
            </main>
            <footer class="crm-footer"><x-gestionale.privacy-links /><span>REKO Gestionale · archivio riservato</span><span role="status" aria-live="polite" x-text="$store.crm.notice || 'Dati e ricerche distinti per Comune'">Dati e ricerche distinti per Comune</span></footer>
        </div>
    </div>

    {{-- Guida contestuale globale (ContextGuide montata in client/main.tsx) --}}
    <x-gestionale.context-guide />

    <aside
        class="crm-save-feedback"
        x-data="{
            visible: false, type: 'success', message: '', timer: null,
            receive(event) {
                const detail = event.detail ?? {};
                this.message = typeof detail.text === 'string' ? detail.text : '';
                this.type = detail.type === 'error' ? 'error' : 'success';
                this.visible = this.message !== '';
                if (this.timer) clearTimeout(this.timer);
                if (this.type === 'success') this.timer = setTimeout(() => this.visible = false, 7000);
            },
            dismiss() { this.visible = false; this.message = ''; if (this.timer) clearTimeout(this.timer); }
        }"
        x-on:crm-notice.window="receive($event)"
        x-show="visible"
        x-cloak
        x-bind:class="type === 'error' ? 'is-error' : ''"
        x-bind:role="type === 'error' ? 'alert' : 'status'"
        x-bind:aria-live="type === 'error' ? 'assertive' : 'polite'"
        aria-atomic="true"
    >
        <span x-text="message"></span>
        <button type="button" x-show="type === 'error'" aria-label="Chiudi avviso di salvataggio" x-on:click="dismiss()">×</button>
    </aside>
    {{-- Stato di strip, footer e banner errori (busy, notice ed error di context.tsx): sopravvive a wire:navigate. --}}
    <script data-navigate-once>
        (() => {
            const setup = () => {
                Alpine.store('crm', { busy: 0, notice: '', error: '' });
                window.addEventListener('crm-notice', (event) => {
                    const crm = Alpine.store('crm'), detail = event.detail ?? {}, text = typeof detail.text === 'string' ? detail.text : '';
                    crm.notice = detail.type === 'error' ? '' : text;
                    crm.error = detail.type === 'error' ? text : '';
                });
            };
            // Conta solo le richieste con un'azione della pagina (non $set, $refresh): come il busy di mutate().
            const track = () => Livewire.interceptMessage(({ message, onFinish }) => {
                if (! message.getActions().some((action) => ! action.name.startsWith('$'))) return;
                const crm = Alpine.store('crm');
                crm.busy++;
                crm.notice = '';
                crm.error = '';
                onFinish(() => crm.busy--);
            });
            window.Alpine ? setup() : document.addEventListener('alpine:init', setup);
            window.Livewire ? track() : document.addEventListener('livewire:init', track);
        })();
    </script>
    @fluxScripts
</body>

</html>
