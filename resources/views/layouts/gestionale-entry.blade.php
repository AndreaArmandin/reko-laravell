<!DOCTYPE html>
<html lang="it">

<head>
    @include('partials.head', ['stylesheet' => 'resources/css/gestionale.css'])
</head>

<body>
    {{ $slot }}

    @persist('toast')
        <flux:toast.group><flux:toast /></flux:toast.group>
    @endpersist
    @fluxScripts
</body>

</html>
