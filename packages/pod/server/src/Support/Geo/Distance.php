<?php

namespace Fleetbase\Pod\Support\Geo;

final class Distance
{
    /**
     * Great-circle distance in metres.
     */
    public static function metres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r    = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
