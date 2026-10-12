<!DOCTYPE html>
<html lang="it">

<head>
    @include('partials.head', ['stylesheet' => 'resources/css/gestionale.css'])
</head>

<body>
    {{ $slot }}

    @fluxScripts
</body>

</html>
