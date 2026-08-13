<?php

namespace App\Services;

use App\Models\Zone;

class ZoneLocatorService
{
    /**
     * Resolve which Zone a live coordinate falls inside, if any. Zones are
     * simple circles (center + radius) rather than polygons — the nearest
     * zone whose radius contains the point wins. Returns null if the point
     * isn't inside any zone's boundary, since a unit currently outside every
     * defined zone should show as zoneless rather than keep a stale label.
     */
    public function resolve(float $lat, float $lng): ?Zone
    {
        return Zone::query()
            ->whereNotNull('center_lat')
            ->whereNotNull('center_lng')
            ->whereNotNull('radius_km')
            ->get()
            ->map(fn (Zone $zone) => [$zone, $this->haversineKm($lat, $lng, $zone->center_lat, $zone->center_lng)])
            ->filter(fn (array $pair) => $pair[1] <= $pair[0]->radius_km)
            ->sortBy(fn (array $pair) => $pair[1])
            ->first()[0] ?? null;
    }

    protected function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
