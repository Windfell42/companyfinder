<?php
namespace CompanyFinder;

/**
 * Geographic helpers: a lookup table of Dallas-Fort Worth Metroplex city
 * coordinates and a haversine distance calculation. Used to turn a listing's
 * city name into a "miles from the anchor" number for the proximity score.
 */
class Geo
{
    /**
     * City => [lat, lng] for the major DFW Metroplex municipalities. Listings
     * usually only expose a city/area name, so this gives us coordinates
     * without an external geocoding service.
     */
    private const CITIES = [
        'plano'            => [33.0198, -96.6989],
        'dallas'           => [32.7767, -96.7970],
        'fort worth'       => [32.7555, -97.3308],
        'arlington'        => [32.7357, -97.1081],
        'irving'           => [32.8140, -96.9489],
        'garland'          => [32.9126, -96.6389],
        'frisco'           => [33.1507, -96.8236],
        'mckinney'         => [33.1972, -96.6398],
        'mesquite'         => [32.7668, -96.5992],
        'carrollton'       => [32.9756, -96.8900],
        'richardson'       => [32.9483, -96.7299],
        'denton'           => [33.2148, -97.1331],
        'lewisville'       => [33.0462, -96.9942],
        'allen'            => [33.1032, -96.6706],
        'flower mound'     => [33.0146, -97.0970],
        'mansfield'        => [32.5632, -97.1417],
        'grand prairie'    => [32.7459, -96.9978],
        'grapevine'        => [32.9343, -97.0781],
        'euless'           => [32.8371, -97.0820],
        'bedford'          => [32.8440, -97.1431],
        'hurst'            => [32.8235, -97.1706],
        'keller'           => [32.9346, -97.2289],
        'coppell'          => [32.9546, -97.0150],
        'wylie'            => [33.0151, -96.5388],
        'rockwall'         => [32.9312, -96.4597],
        'addison'          => [32.9618, -96.8292],
        'the colony'       => [33.0890, -96.8861],
        'little elm'       => [33.1626, -96.9375],
        'prosper'          => [33.2362, -96.8011],
        'southlake'        => [32.9412, -97.1342],
        'north richland hills' => [32.8343, -97.2289],
        'farmers branch'   => [32.9268, -96.8961],
        'desoto'           => [32.5896, -96.8570],
        'cedar hill'       => [32.5885, -96.9561],
        'duncanville'      => [32.6518, -96.9083],
        'rowlett'          => [32.9029, -96.5638],
        'sachse'           => [32.9762, -96.5950],
        'murphy'           => [33.0151, -96.6131],
        'colleyville'      => [32.8807, -97.1550],
    ];

    /**
     * Best-effort coordinate lookup for a free-text location string. Returns
     * null when no known city is recognised.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function coordsFor(?string $location): ?array
    {
        if ($location === null || $location === '') {
            return null;
        }
        $city = self::cityIn($location);
        return $city !== null ? self::CITIES[strtolower($city)] : null;
    }

    /**
     * Return the canonical DFW city name mentioned in a free-text string, or
     * null if none is recognised. Longer names are checked first so that, e.g.,
     * "North Richland Hills" wins over a shorter overlapping match.
     */
    public static function cityIn(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $needle = strtolower($text);
        $cities = array_keys(self::CITIES);
        usort($cities, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($cities as $city) {
            if (str_contains($needle, $city)) {
                return ucwords($city);
            }
        }
        return null;
    }

    /**
     * Great-circle distance in miles between two lat/lng points.
     */
    public static function milesBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMi = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusMi * $c;
    }
}
