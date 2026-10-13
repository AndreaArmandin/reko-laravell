<?php

use App\Models\InboundEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Posta personale: le email dei portali (es. Immobiliare.it) che arrivano dalla casella collegata
| dall'utente. Ogni utente vede solo le proprie. La chiave si mostra una volta sola: nel database
| resta solo l'impronta.
*/
new #[Title('Posta')] class extends Component
{
    public string $senders = '';

    /** Chiave appena generata: vive solo in questa risposta, mai salvata in chiaro. */
    public string $newKey = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->senders = (string) $user->inbound_allowed_senders;

        // Personal tag of the forwarding address, created the first time the page opens.
        if (blank($user->inbound_code)) {
            $user->forceFill(['inbound_code' => Str::lower(Str::random(20))])->save();
        }
    }

    public function saveSenders(): void
    {
        $this->validate(['senders' => ['required', 'string', 'max:500']]);

        Auth::user()->forceFill(['inbound_allowed_senders' => trim($this->senders)])->save();
        session()->flash('saved', 'Mittenti salvati.');
    }

    public function generateKey(): void
    {
        $this->saveSenders();

        $this->newKey = Str::random(48);
        // Una nuova chiave sostituisce la precedente, che smette di valere.
        Auth::user()->forceFill(['inbound_token_hash' => hash('sha256', $this->newKey)])->save();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, InboundEmail> */
    #[Computed]
    public function emails()
    {
        return Auth::user()->inboundEmails()->orderByDesc('received_at')->limit(100)->get();
    }

    /** Address the user forwards portal mail to: <base>+<personal code>@<inbound domain>. Null until configured. */
    #[Computed]
    public function forwardAddress(): ?string
    {
        $base = (string) config('services.postmark_inbound.address');
        $code = (string) Auth::user()->inbound_code;

        if ($code === '' || ! preg_match('/^([^@+\s]+)@([^@\s]+)$/', $base, $m)) {
            return null;
        }

        return $m[1].'+'.$code.'@'.$m[2];
    }

    #[Computed]
    public function connected(): bool
    {
        return filled(Auth::user()->inbound_token_hash);
    }

    /** Only portal listing links are clickable; anything else in a message stays plain text. */
    public function listingLink(string $url): string
    {
        return preg_match('~^https://(?:www\.)?immobiliare\.it/annunci/\d+/?$~i', $url) ? $url : '';
    }
};
?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-8">
    <header>
        <flux:heading size="xl" level="1">Posta</flux:heading>
        <flux:text class="mt-1">Le email dei portali, come Immobiliare.it, che arrivano dalla tua casella. Le vedi solo tu.</flux:text>
    </header>

    @if ($this->forwardAddress)
        <flux:card class="flex flex-col gap-3">
            <flux:heading size="lg">Inoltro dalla tua casella</flux:heading>
            <flux:text>Non serve nessuno script: in Gmail inoltri le mail di Immobiliare.it a questo indirizzo personale. Le originali restano nella tua casella.</flux:text>
            <code class="block break-all rounded bg-zinc-100 p-3">{{ $this->forwardAddress }}</code>
            <ol class="list-decimal ps-5 text-sm">
                <li>In Gmail: Impostazioni → Inoltro e POP/IMAP → Aggiungi un indirizzo di inoltro, e incolla l'indirizzo qui sopra.</li>
                <li>Gmail manda un codice di conferma: comparirà qui sotto, tra le email ricevute. Inseriscilo in Gmail.</li>
                <li>Impostazioni → Filtri → Crea un filtro: Da <code>immobiliare.it</code> → Inoltra a questo indirizzo.</li>
            </ol>
            <flux:text class="text-sm">Qui sotto indichi da quali mittenti accettare le mail (ad esempio <code>immobiliare.it</code>).</flux:text>
        </flux:card>
    @endif

    <flux:card class="flex flex-col gap-4">
        <flux:heading size="lg">{{ $this->forwardAddress ? 'Mittenti consentiti' : 'Collega la tua casella' }}</flux:heading>
        <flux:text>{{ $this->connected ? 'Casella collegata. Puoi cambiare i mittenti o generare una nuova chiave.' : 'Non ancora collegata.' }}</flux:text>

        <form wire:submit="saveSenders" class="flex flex-col gap-3">
            <flux:input wire:model="senders" label="Mittenti consentiti" description="Indirizzi o domini separati da virgola, ad esempio immobiliare.it" placeholder="immobiliare.it" />
            @error('senders') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
            <div class="flex gap-2">
                <flux:button type="submit">Salva mittenti</flux:button>
                <flux:button type="button" variant="primary" wire:click="generateKey" wire:confirm="{{ $this->connected ? 'Generare una nuova chiave? La precedente smette di funzionare.' : 'Generare la chiave?' }}">
                    {{ $this->connected ? 'Genera nuova chiave' : 'Genera chiave' }}
                </flux:button>
            </div>
            @if (session('saved')) <flux:text>{{ session('saved') }}</flux:text> @endif
        </form>

        @if ($newKey !== '')
            <div class="rounded border border-amber-300 bg-amber-50 p-4">
                <flux:heading>La tua chiave (la vedi solo ora)</flux:heading>
                <code class="mt-2 block break-all">{{ $newKey }}</code>
                <flux:text class="mt-2">Indirizzo da usare nello script Gmail: <code>{{ url('/api/inbound-email') }}</code></flux:text>
                <flux:text class="mt-1">Copiala subito nello script: dopo aver lasciato la pagina non si può più rivedere.</flux:text>
            </div>
        @endif
    </flux:card>

    <div class="flex flex-col gap-3">
        <flux:heading size="lg">Email ricevute (ultime 100)</flux:heading>
        @forelse ($this->emails as $email)
            <details class="rounded border border-zinc-200 bg-white p-4" wire:key="mail-{{ $email->id }}">
                <summary class="cursor-pointer">
                    <strong>{{ $email->subject !== '' ? $email->subject : '(senza oggetto)' }}</strong>
                    · {{ $email->sender }}
                    · {{ $email->received_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}
                </summary>
                <div class="mt-3 flex flex-col gap-2">
                    @if ($this->listingLink($email->listing_url) !== '')
                        <div><flux:button size="sm" :href="$this->listingLink($email->listing_url)" target="_blank" rel="noopener noreferrer">Apri annuncio</flux:button></div>
                    @endif
                    @if ($email->phone !== '')
                        <flux:text>Telefono: {{ $email->phone }}</flux:text>
                    @endif
                    <pre class="whitespace-pre-wrap break-words">{{ $email->body }}</pre>
                </div>
            </details>
        @empty
            <flux:text>Nessuna email ricevuta finora.</flux:text>
        @endforelse
    </div>
</section>
