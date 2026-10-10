{{-- PrivacyLinks (privacy-cookies.tsx) con config/iubenda.json ancora vuoto: stessi messaggi. --}}
<div class="reko-privacy-links" style="display:flex;flex-wrap:wrap;gap:12px 20px;font-size:.875rem" x-data="{ message: '' }">
    @foreach (['Privacy Policy', 'Cookie Policy', 'Termini e condizioni'] as $label)
        <button type="button" x-on:click="message = @js($label.': documento iubenda non ancora collegato. L’informativa definitiva deve essere completata prima del lancio pubblico.')">{{ $label }}</button>
    @endforeach
    <button type="button" x-on:click="message = 'La gestione privacy e cookie con iubenda è in preparazione. Google Analytics e i cookie pubblicitari restano disattivati.'">Preferenze cookie</button>
    <p x-show="message" x-text="message" role="status" style="flex-basis:100%;margin:0" x-cloak></p>
</div>
