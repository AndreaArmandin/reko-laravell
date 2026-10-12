<?php

namespace App\Gestionale\Census;

use App\Models\AgencyMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reads acquired land perimeters as parcel records, never as fabricated cadastral units. */
final class TerrainParcelReader
{
    public static function hasInMunicipality(AgencyMembership $actor, string $municipalityCode, ?int $parcelId = null): bool
    {
        if (! Schema::hasTable('agency_terrain_acquisitions')) {
            return false;
        }

        $query = DB::table('agency_terrain_acquisitions as ta')->join('parcels as p', 'p.id', '=', 'ta.parcel_id')
            ->join('municipalities as m', 'm.id', '=', 'p.municipality_id')->where('ta.agency_id', $actor->agency_id)
            ->where('m.cadastral_code', strtoupper($municipalityCode))->when($parcelId !== null, fn ($q) => $q->where('p.id', $parcelId));
        if ($actor->role === 'scout') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('scouting_assignments as sa')->where('sa.agency_id', $actor->agency_id)
                ->where('sa.user_id', $actor->user_id)->whereColumn('sa.parcel_id', 'p.id'));
        } elseif ($actor->role !== 'admin') {
            return false;
        }

        return $query->exists();
    }

    /** @return list<array<string,mixed>> */
    public static function archiveRows(AgencyMembership $actor, ?string $municipalityCode = null, ?int $parcelId = null): array
    {
        if (! Schema::hasTable('agency_terrain_acquisitions')) {
            return [];
        }

        $query = DB::table('agency_terrain_acquisitions as ta')
            ->join('parcels as p', 'p.id', '=', 'ta.parcel_id')->join('municipalities as m', 'm.id', '=', 'p.municipality_id')
            ->join('catalog_releases as cr', 'cr.id', '=', 'ta.catalog_release_id')
            ->leftJoin('territorial_provinces as tp', 'tp.id', '=', 'm.territorial_province_id')
            ->where('ta.agency_id', $actor->agency_id)->where('p.cadastral_kind', 'T')
            ->when($municipalityCode !== null, fn ($q) => $q->where('m.cadastral_code', strtoupper($municipalityCode)))
            ->when($parcelId !== null, fn ($q) => $q->where('p.id', $parcelId));
        if ($actor->role === 'scout') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('scouting_assignments as sa')->where('sa.agency_id', $actor->agency_id)
                ->where('sa.user_id', $actor->user_id)->whereColumn('sa.parcel_id', 'p.id'));
        } elseif ($actor->role !== 'admin') {
            return [];
        }

        return $query->orderBy('m.name')->orderBy('p.sheet')->orderBy('p.number')->get([
            'ta.id as acquisition_id', 'ta.parcel_id', 'ta.catalog_release_id', 'ta.acquired_at', 'ta.area_sqm', 'p.section', 'p.sheet', 'p.number',
            'm.name as municipality', 'm.cadastral_code as code', 'tp.abbreviation as province', 'cr.released_on', 'ta.acquired_by_user_id',
        ])->map(fn ($row) => [
            'kind' => 'terrain', 'parcelId' => (int) $row->parcel_id, 'acquisitionId' => (int) $row->acquisition_id,
            'context' => ['municipality' => $row->municipality, 'code' => $row->code, 'province' => (string) $row->province,
                'kind' => 'Terreni', 'section' => (string) $row->section, 'sheet' => (string) $row->sheet, 'parcel' => (string) $row->number],
            'address' => $row->municipality.' · Terreni · F. '.$row->sheet.' P. '.$row->number,
            'acquiredAt' => (string) $row->acquired_at, 'releaseDate' => (string) ($row->released_on ?? ''),
            'areaSqm' => $row->area_sqm === null ? null : (float) $row->area_sqm, 'acquiredBy' => $row->acquired_by_user_id === null ? null : (int) $row->acquired_by_user_id,
        ])->all();
    }
}
