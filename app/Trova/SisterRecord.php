<?php

namespace App\Trova;

/**
 * One row of a SISTER "fabbricati" export, as read by SisterParser.
 */
final readonly class SisterRecord
{
    public function __construct(
        public string $section,
        public string $sheet,
        public string $parcel,
        public string $sub,
        public string $address,
        public string $zone,
        public string $category,
        public string $class,
        public ?float $value,
        public ?string $unit,
        public ?float $rendita,
        public string $partita,
        public bool $suppressed,
        public bool $common,
        public bool $malformed,
        /** Without a subalterno the row is not a proven unit: only identical normalised details are the same unit. */
        public string $identityDetail,
    ) {}

    /** Original Trova catalogue key, used to relink CRM records: ["D205","Fabbricati","","1","10","1"]. */
    public function legacyKey(string $code): string
    {
        $key = [$code, SisterParser::KIND, $this->section, $this->sheet, $this->parcel, $this->sub];
        if ($this->sub === '') {
            $key[] = $this->identityDetail;
        }

        return json_encode($key, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Stable reference for units without a subalterno (identity_key becomes "n:" + this). */
    public function sourceRef(): ?string
    {
        return $this->sub === '' ? 'sister:'.substr(sha1($this->identityDetail), 0, 20) : null;
    }

    public function identityKey(): string
    {
        return $this->sub !== '' ? 's:'.$this->sub : 'n:'.$this->sourceRef();
    }

    /** "VIA ROMA n. 18 Piano T-1" → the part after "Piano", or null. */
    public function floor(): ?string
    {
        if (! preg_match('/\sPIANO\s+(.+)$/iu', $this->address, $m)) {
            return null;
        }

        return mb_substr(trim($m[1]), 0, 100) ?: null;
    }

    /** @return array{0: string|null, 1: string|null} toponym and civic number */
    public function street(): array
    {
        $street = trim((string) preg_replace('/\sPIANO\s+.+$/iu', '', $this->address));
        if ($street === '') {
            return [null, null];
        }
        if (preg_match('/^(.*?)\s+n\.?\s*([0-9]+[A-Z0-9\/]*)$/iu', $street, $m)) {
            return [mb_substr(trim($m[1]), 0, 255) ?: null, mb_substr($m[2], 0, 50)];
        }

        return [mb_substr($street, 0, 255), null];
    }
}
