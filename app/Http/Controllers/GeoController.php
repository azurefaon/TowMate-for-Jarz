<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GeoController extends Controller
{
    protected BookingService $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:255'],
        ]);

        if ($this->shouldUseGoogleMaps()) {
            return response()->json([
                'features' => $this->resolveGoogleSearchResults($validated['q']),
            ]);
        }

        $response = $this->client()->get($this->baseUrl() . '/geocode/autocomplete', [
            'text' => $validated['q'],
            'boundary.country' => 'PH',
            'size' => 5,
        ]);

        if (! $response->successful()) {
            return response()->json(['features' => $this->resolveNominatimSearchResults($validated['q'])]);
        }

        $features = collect($response->json('features', []))
            ->map(function (array $feature) {
                return [
                    'label' => $feature['properties']['label'] ?? 'Unknown location',
                    'coordinates' => $feature['geometry']['coordinates'] ?? [0, 0],
                ];
            })
            ->values();

        if ($features->isEmpty()) {
            return response()->json(['features' => $this->resolveNominatimSearchResults($validated['q'])]);
        }

        return response()->json(['features' => $features]);
    }

    public function reverse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        if ($this->shouldUseGoogleMaps()) {
            $address = $this->resolveGoogleReverseAddress($lat, $lng);
            return response()->json(['address' => $address !== 'Unknown location' ? $address : ($this->resolveNominatimReverseAddress($lat, $lng) ?: 'Unknown location')]);
        }

        $response = $this->client()->get($this->baseUrl() . '/geocode/reverse', [
            'point.lat' => $lat,
            'point.lon' => $lng,
        ]);

        if ($response->successful()) {
            $address = (string) $response->json('features.0.properties.label', '');
            if ($address !== '') {
                return response()->json(['address' => $address]);
            }
        }

        $nominatim = $this->resolveNominatimReverseAddress($lat, $lng);
        return response()->json(['address' => $nominatim ?: 'Unknown location']);
    }

    public function route(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'drop_lat' => ['required', 'numeric', 'between:-90,90'],
            'drop_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($this->bookingService->estimateDirectDistanceKm(
            (float) $validated['pickup_lat'],
            (float) $validated['pickup_lng'],
            (float) $validated['drop_lat'],
            (float) $validated['drop_lng'],
        ) <= 0.05) {
            return response()->json([
                'message' => 'Pickup and dropoff must be different points to calculate the route.',
                'errors' => [
                    'drop_lat' => ['Pickup and dropoff must be different points to calculate the route.'],
                ],
            ], 422);
        }

        return response()->json($this->resolveRouteData(
            (float) $validated['pickup_lat'],
            (float) $validated['pickup_lng'],
            (float) $validated['drop_lat'],
            (float) $validated['drop_lng'],
        ));
    }

    public function pricingPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'truck_type_id' => ['required', 'integer', 'exists:truck_types,id,status,active'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'drop_lat' => ['required', 'numeric', 'between:-90,90'],
            'drop_lng' => ['required', 'numeric', 'between:-180,180'],
            'customer_type' => ['nullable', 'in:regular,pwd,senior'],
            'vehicle_category' => ['nullable', 'in:2_wheeler,3_wheeler,4_wheeler,heavy_vehicle,other'],
            'service_type' => ['nullable', 'in:book_now,schedule'],
            'discount_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\s]+$/'],
        ]);

        if ($this->bookingService->estimateDirectDistanceKm(
            (float) $validated['pickup_lat'],
            (float) $validated['pickup_lng'],
            (float) $validated['drop_lat'],
            (float) $validated['drop_lng'],
        ) <= 0.05) {
            return response()->json([
                'message' => 'Pickup and dropoff must be different points to calculate the fare preview.',
                'errors' => [
                    'drop_lat' => ['Pickup and dropoff must be different points to calculate the fare preview.'],
                ],
            ], 422);
        }

        $route = $this->resolveRouteData(
            (float) $validated['pickup_lat'],
            (float) $validated['pickup_lng'],
            (float) $validated['drop_lat'],
            (float) $validated['drop_lng'],
        );

        $pricing = $this->bookingService->calculatePricing([
            ...$validated,
            'distance_km' => $route['distance_km'],
        ]);

        return response()->json([
            'route' => $route,
            'pricing' => [
                'distance_km' => (float) $pricing['distance_km'],
                'base_rate' => 0.0,
                'per_km_rate' => 0.0,
                'km_increments' => (int) ($pricing['km_increments'] ?? 0),
                'km_charge_per_increment' => 200,
                'distance_fee' => (float) $pricing['distance_fee'],
                'excess_km_threshold' => 0.0,
                'excess_km_rate' => 200.0,
                'excess_km' => 0.0,
                'excess_fee' => 0.0,
                'discount_percentage' => (float) $pricing['discount_percentage'],
                'discount_amount' => (float) $pricing['discount_amount'],
                'additional_fee' => (float) $pricing['additional_fee'],
                'final_total' => (float) $pricing['final_total'],
            ],
            'availability' => $this->bookingService->dispatchAvailability(),
        ]);
    }

    protected function resolveRouteData(float $pickupLat, float $pickupLng, float $dropLat, float $dropLng): array
    {
        $resolvedRoute = $this->shouldUseGoogleMaps()
            ? $this->resolveGoogleDirectionsRoute($pickupLat, $pickupLng, $dropLat, $dropLng)
            : ($this->resolveOpenRouteServiceRoute($pickupLat, $pickupLng, $dropLat, $dropLng)
                ?? $this->resolveOsrmRoute($pickupLat, $pickupLng, $dropLat, $dropLng));

        if ($resolvedRoute !== null) {
            return $resolvedRoute;
        }

        $estimatedDistanceKm = $this->bookingService->estimateDirectDistanceKm($pickupLat, $pickupLng, $dropLat, $dropLng);

        return [
            'distance_km' => $estimatedDistanceKm,
            'duration_min' => $this->bookingService->estimateFallbackDurationMinutes($estimatedDistanceKm),
            'coordinates' => [
                [$pickupLat, $pickupLng],
                [$dropLat, $dropLng],
            ],
            'is_fallback' => true,
        ];
    }

    protected function resolveOpenRouteServiceRoute(float $pickupLat, float $pickupLng, float $dropLat, float $dropLng): ?array
    {
        try {
            $response = $this->client()->post($this->baseUrl() . '/v2/directions/driving-car/geojson', [
                'coordinates' => [
                    [$pickupLng, $pickupLat],
                    [$dropLng, $dropLat],
                ],
            ]);

            if (! $response->successful()) {
                return null;
            }

            $feature = $response->json('features.0', []);
            $distanceMeters = (float) data_get($feature, 'properties.summary.distance', 0);
            $durationSeconds = (float) data_get($feature, 'properties.summary.duration', 0);
            $geometry = collect(data_get($feature, 'geometry.coordinates', []))
                ->map(fn(array $coordinate) => [$coordinate[1] ?? 0, $coordinate[0] ?? 0])
                ->filter(fn(array $coordinate) => count($coordinate) === 2)
                ->values()
                ->all();

            if ($distanceMeters <= 0 || count($geometry) < 2) {
                return null;
            }

            return [
                'distance_km' => round($distanceMeters / 1000, 2),
                'duration_min' => round($durationSeconds / 60, 1),
                'coordinates' => $geometry,
                'is_fallback' => false,
            ];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function resolveOsrmRoute(float $pickupLat, float $pickupLng, float $dropLat, float $dropLng): ?array
    {
        try {
            $url = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%s,%s;%s,%s',
                $pickupLng,
                $pickupLat,
                $dropLng,
                $dropLat,
            );

            $response = Http::timeout(12)
                ->acceptJson()
                ->get($url, [
                    'overview' => 'full',
                    'geometries' => 'geojson',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $route = $response->json('routes.0', []);
            $distanceMeters = (float) data_get($route, 'distance', 0);
            $durationSeconds = (float) data_get($route, 'duration', 0);
            $geometry = collect(data_get($route, 'geometry.coordinates', []))
                ->map(fn(array $coordinate) => [$coordinate[1] ?? 0, $coordinate[0] ?? 0])
                ->filter(fn(array $coordinate) => count($coordinate) === 2)
                ->values()
                ->all();

            if ($distanceMeters <= 0 || count($geometry) < 2) {
                return null;
            }

            return [
                'distance_km' => round($distanceMeters / 1000, 2),
                'duration_min' => round($durationSeconds / 60, 1),
                'coordinates' => $geometry,
                'is_fallback' => false,
            ];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function resolveGoogleSearchResults(string $query): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($this->googleGeocodeUrl(), [
                    'address' => $query,
                    'components' => 'country:PH',
                    'region' => 'ph',
                    'key' => $this->googleMapsKey(),
                ]);

            if (! $response->successful() || $response->json('status') !== 'OK') {
                return [];
            }

            return collect($response->json('results', []))
                ->take(5)
                ->map(function (array $result) {
                    return [
                        'label' => $result['formatted_address'] ?? 'Unknown location',
                        'coordinates' => [
                            data_get($result, 'geometry.location.lng', 0),
                            data_get($result, 'geometry.location.lat', 0),
                        ],
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            return [];
        }
    }

    protected function resolveGoogleReverseAddress(float $lat, float $lng): string
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($this->googleGeocodeUrl(), [
                    'latlng' => $lat . ',' . $lng,
                    'key' => $this->googleMapsKey(),
                ]);

            if (! $response->successful() || $response->json('status') !== 'OK') {
                return 'Unknown location';
            }

            return (string) $response->json('results.0.formatted_address', 'Unknown location');
        } catch (\Throwable $exception) {
            return 'Unknown location';
        }
    }

    protected function resolveGoogleDirectionsRoute(float $pickupLat, float $pickupLng, float $dropLat, float $dropLng): ?array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($this->googleDirectionsUrl(), [
                    'origin' => $pickupLat . ',' . $pickupLng,
                    'destination' => $dropLat . ',' . $dropLng,
                    'mode' => 'driving',
                    'alternatives' => 'false',
                    'units' => 'metric',
                    'key' => $this->googleMapsKey(),
                ]);

            if (! $response->successful() || $response->json('status') !== 'OK') {
                return null;
            }

            $route = $response->json('routes.0', []);
            $distanceMeters = (float) data_get($route, 'legs.0.distance.value', 0);
            $durationSeconds = (float) data_get($route, 'legs.0.duration.value', 0);
            $geometry = $this->decodeGooglePolyline((string) data_get($route, 'overview_polyline.points', ''));

            if ($distanceMeters <= 0 || count($geometry) < 2) {
                return null;
            }

            return [
                'distance_km' => round($distanceMeters / 1000, 2),
                'duration_min' => round($durationSeconds / 60, 1),
                'coordinates' => $geometry,
                'is_fallback' => false,
            ];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function decodeGooglePolyline(string $encoded): array
    {
        if ($encoded === '') {
            return [];
        }

        $coordinates = [];
        $index = 0;
        $lat = 0;
        $lng = 0;
        $length = strlen($encoded);

        while ($index < $length) {
            $shift = 0;
            $result = 0;

            do {
                if ($index >= $length) {
                    break 2;
                }

                $byte = ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1f) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $lat += ($result & 1) ? ~($result >> 1) : ($result >> 1);

            $shift = 0;
            $result = 0;

            do {
                if ($index >= $length) {
                    break 2;
                }

                $byte = ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1f) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $lng += ($result & 1) ? ~($result >> 1) : ($result >> 1);

            $coordinates[] = [$lat / 1e5, $lng / 1e5];
        }

        return $coordinates;
    }

    protected function shouldUseGoogleMaps(): bool
    {
        return ! app()->environment('testing') && $this->googleMapsKey() !== '';
    }

    protected function googleMapsKey(): string
    {
        return trim((string) config('services.google_maps.key'));
    }

    protected function googleGeocodeUrl(): string
    {
        return (string) config('services.google_maps.geocode_url', 'https://maps.googleapis.com/maps/api/geocode/json');
    }

    protected function googleDirectionsUrl(): string
    {
        return (string) config('services.google_maps.directions_url', 'https://maps.googleapis.com/maps/api/directions/json');
    }

    protected function resolveNominatimReverseAddress(float $lat, float $lng): string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'TowMate/1.0 (towing-dispatch)'])
                ->acceptJson()
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'json',
                    'zoom' => 18,
                ]);

            if (! $response->successful()) {
                return '';
            }

            return (string) $response->json('display_name', '');
        } catch (\Throwable) {
            return '';
        }
    }

    protected function resolveNominatimSearchResults(string $query): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'TowMate/1.0 (towing-dispatch)'])
                ->acceptJson()
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query,
                    'format' => 'json',
                    'countrycodes' => 'ph',
                    'limit' => 5,
                    'addressdetails' => 0,
                ]);

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json() ?: [])
                ->map(function (array $place) {
                    return [
                        'label' => $place['display_name'] ?? 'Unknown location',
                        'coordinates' => [
                            (float) ($place['lon'] ?? 0),
                            (float) ($place['lat'] ?? 0),
                        ],
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function client()
    {
        $key = trim((string) config('services.openrouteservice.key'));

        return Http::timeout(15)
            ->acceptJson()
            ->withHeaders(array_filter([
                'Authorization' => $key !== '' ? $key : null,
            ]));
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.openrouteservice.base_url', 'https://api.openrouteservice.org'), '/');
    }
}
