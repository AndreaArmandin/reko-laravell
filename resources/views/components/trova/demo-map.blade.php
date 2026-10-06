@props(['threeD' => false])

{{-- DemoMap di home-examples.tsx: geometrie di fantasia, nessun immobile, coordinata o disponibilità reale --}}
@php
    $footprints = [
        ['x' => 50, 'y' => 40, 'w' => 85, 'd' => 50, 'h' => 28, 'number' => null], ['x' => 165, 'y' => 35, 'w' => 55, 'd' => 65, 'h' => 42, 'number' => null],
        ['x' => 290, 'y' => 35, 'w' => 85, 'd' => 55, 'h' => 28, 'number' => null], ['x' => 425, 'y' => 40, 'w' => 70, 'd' => 65, 'h' => 35, 'number' => null],
        ['x' => 60, 'y' => 205, 'w' => 70, 'd' => 65, 'h' => 46, 'number' => 1], ['x' => 170, 'y' => 210, 'w' => 55, 'd' => 55, 'h' => 24, 'number' => null],
        ['x' => 310, 'y' => 210, 'w' => 80, 'd' => 60, 'h' => 34, 'number' => 2], ['x' => 450, 'y' => 210, 'w' => 45, 'd' => 65, 'h' => 20, 'number' => null],
    ];
    $project = fn ($x, $y, $z = 0) => $threeD ? [48 + $x * .78 + $y * .28, 92 + $y * .58 - $x * .075 - $z] : [$x + 15, $y + 15];
    $polygon = fn (array $points) => implode(' ', array_map(fn ($p) => implode(',', array_map(fn ($n) => round($n, 3), $project($p[0], $p[1], $p[2] ?? 0))), $points));
    // In 3D si vedono le pareti a x minima e y massima: prima i blocchi lontani
    $buildings = $footprints;
    if ($threeD) {
        usort($buildings, fn ($a, $b) => (-.2 * ($a['x'] + $a['w'] / 2) + .78 * ($a['y'] + $a['d'] / 2)) <=> (-.2 * ($b['x'] + $b['w'] / 2) + .78 * ($b['y'] + $b['d'] / 2)));
    }
@endphp
<figure class="reko-demo-map {{ $threeD ? 'is-3d' : 'is-2d' }}">
    <div class="reko-demo-map-label"><x-trova.icon name="map-pin" size="17" /><strong>{{ $threeD ? 'Risultati sulla mappa 3D' : 'Zona di ricerca · mappa 2D' }}</strong><span>DEMO</span></div>
    <svg viewBox="{{ $threeD ? '0 0 570 330' : '0 0 570 320' }}" role="img" aria-label="{{ $threeD ? 'Mappa dimostrativa 3D: due fabbricati evidenziati e numerati corrispondono alle due schede dei risultati.' : 'Mappa dimostrativa 2D: una zona delimitata e una particella selezionata con la sagoma del fabbricato.' }}">
        <polygon points="{{ $polygon([[10, 10], [540, 10], [540, 295], [10, 295]]) }}" fill="#e9eee9" stroke="#c7d1ca" />
        <polygon points="{{ $polygon([[10, 121], [540, 121], [540, 181], [10, 181]]) }}" fill="white" />
        <polygon points="{{ $polygon([[235, 10], [275, 10], [275, 295], [235, 295]]) }}" fill="white" />
        @if (! $threeD)
            <polygon points="{{ $polygon([[32, 25], [224, 22], [228, 104], [288, 190], [411, 190], [415, 289], [33, 289]]) }}" fill="#f5c842" fill-opacity=".1" stroke="#71601d" stroke-width="2" stroke-dasharray="7 5" />
        @else
            @foreach ($footprints as ['x' => $x, 'y' => $y, 'w' => $w, 'd' => $d, 'number' => $number])
                <g aria-hidden="true">
                    @if ($number)
                        <polygon points="{{ $polygon([[$x - 10, $y - 10], [$x + $w + 10, $y - 10], [$x + $w + 10, $y + $d + 10], [$x - 10, $y + $d + 10]]) }}" fill="#fff0ba" stroke="#b3953b" stroke-width="1" stroke-dasharray="4 3" />
                    @endif
                    <polygon points="{{ $polygon([[$x, $y], [$x + $w, $y], [$x + $w + 15, $y + 10], [$x + $w + 15, $y + $d + 10], [$x + 15, $y + $d + 10], [$x, $y + $d]]) }}" fill="#20302d" fill-opacity=".10" />
                </g>
            @endforeach
        @endif
        @foreach ($buildings as ['x' => $x, 'y' => $y, 'w' => $w, 'd' => $d, 'h' => $h, 'number' => $number])
            @php($selected = $threeD ? (bool) $number : $number === 1)
            <g>
                @if (! $threeD)
                    <rect x="{{ $x + 5 }}" y="{{ $y + 5 }}" width="{{ $w + 20 }}" height="{{ $d + 20 }}" rx="2" fill="{{ $selected ? '#ffe491' : 'none' }}" stroke="{{ $selected ? '#8b6510' : '#91ae98' }}" stroke-width="{{ $selected ? 2.5 : 1 }}" />
                @else
                    <polygon points="{{ $polygon([[$x, $y, 0], [$x, $y + $d, 0], [$x, $y + $d, $h], [$x, $y, $h]]) }}" fill="{{ $selected ? '#b38b28' : '#a8b5b4' }}" stroke="{{ $selected ? '#886513' : '#8d9f98' }}" stroke-width="1.2" stroke-linejoin="round" />
                    <polygon points="{{ $polygon([[$x, $y + $d, 0], [$x + $w, $y + $d, 0], [$x + $w, $y + $d, $h], [$x, $y + $d, $h]]) }}" fill="{{ $selected ? '#ddb441' : '#bdc8c6' }}" stroke="{{ $selected ? '#886513' : '#8d9f98' }}" stroke-width="1.2" stroke-linejoin="round" />
                @endif
                @php($z = $threeD ? $h : 0)
                <polygon points="{{ $polygon([[$x, $y, $z], [$x + $w, $y, $z], [$x + $w, $y + $d, $z], [$x, $y + $d, $z]]) }}" fill="{{ $selected ? '#ffcf3f' : '#d5dedb' }}" stroke="{{ $selected ? '#886513' : '#8d9f98' }}" stroke-width="1.5" stroke-linejoin="round" />
                @if ($threeD && $number)
                    <g transform="translate({{ implode(',', array_map(fn ($n) => round($n, 3), $project($x + $w / 2, $y + $d / 2, $h + 10))) }})"><circle r="16" fill="#202c29" stroke="white" stroke-width="2" /><text text-anchor="middle" dy="5" fill="white" font-size="16" font-weight="700">{{ $number }}</text></g>
                @endif
            </g>
        @endforeach
        @if (! $threeD)
            <text x="74" y="270" font-size="16" fill="#473b14" font-weight="700">89</text>
        @endif
        <text x="28" y="{{ $threeD ? 304 : 310 }}" font-size="14" fill="#52665a">{{ $threeD ? '1 e 2 · immobili da approfondire' : 'Zona delimitata · particella 89 selezionata' }}</text>
    </svg>
    <figcaption>Schema illustrativo con geometrie di fantasia, non cartografia reale.</figcaption>
</figure>
