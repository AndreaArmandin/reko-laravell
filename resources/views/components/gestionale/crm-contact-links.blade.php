@props(['name', 'phone' => null, 'email' => null])
@php($links = \App\Gestionale\Clients\ClientCard::contactLinks($phone, $email))
{{-- client-contact-links.tsx: chiama (con il numero), WhatsApp, scrivi (con l'indirizzo); un dato non valido resta testo. --}}
<div class="crm-client-contact-links" aria-label="{{ 'Recapiti di '.$name }}">
    @if ($links['tel'])<a href="{{ $links['tel'] }}" aria-label="{{ 'Chiama '.$name.' · '.$links['phone'] }}">Chiama · {{ $links['phone'] }}</a>@elseif ($links['phone'])<span>{{ $links['phone'] }}</span>@endif
    @if ($links['whatsapp'])<a href="{{ $links['whatsapp'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ 'Apri WhatsApp per '.$name }}">WhatsApp</a>@endif
    @if ($links['emailHref'])<a href="{{ $links['emailHref'] }}" aria-label="{{ 'Scrivi a '.$name.' · '.$links['email'] }}">Email · {{ $links['email'] }}</a>@elseif ($links['email'])<span>{{ $links['email'] }}</span>@endif
</div>
