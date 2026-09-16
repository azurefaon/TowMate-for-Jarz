<?php

namespace App\Http\Controllers;

use App\Models\TruckType;
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

    public function autocomplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:255'],
        ]);

        if ($this->shouldUseGoogleMaps()) {
            $suggestions = $this->resolveGooglePlacesSuggestions($validated['q']);

            if (! empty($suggestions)) {
                return response()->json(['suggestions' => $suggestions]);
            }
        } else {
            \Illuminate\Support\Facades\Log::warning('GeoController::autocomplete — GOOGLE_MAPS_SERVER_KEY is not configured, falling back to Nominatim.');
        }

        $fallback = $this->resolveNominatimSearchResults($validated['q']);

        if (empty($fallback)) {
            \Illuminate\Support\Facades\Log::warning('GeoController::autocomplete — both Google and Nominatim returned no suggestions.', ['q' => $validated['q']]);
        }

        return response()->json(['suggestions' => $fallback]);
    }

    public function placeDetails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'place_id' => ['required', 'string', 'max:255'],
        ]);

        if ($this->shouldUseGoogleMaps()) {
            $place = $this->resolveGooglePlaceDetails($validated['place_id']);

            if ($place !== null) {
                return response()->json($place);
            }
        }

        return response()->json(['message' => 'Unable to resolve place details.'], 422);
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
            'truck_type_id' => ['required_without:vehicle_type_id', 'nullable', 'integer', 'exists:truck_types,id,status,active'],
            'vehicle_type_id' => ['required_without:truck_type_id', 'nullable', 'integer', 'exists:vehicle_types,id'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'drop_lat' => ['required', 'numeric', 'between:-90,90'],
            'drop_lng' => ['required', 'numeric', 'between:-180,180'],
            'customer_type' => ['nullable', 'in:regular,pwd,senior'],
            'vehicle_category' => ['nullable', 'in:2_wheeler,3_wheeler,4_wheeler,heavy_vehicle,other'],
            'service_type' => ['nullable', 'in:book_now,schedule'],
            'discount_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\s]+$/'],
            'extra_vehicles' => ['nullable', 'array'],
            'extra_vehicles.*.truck_type_id' => ['required_without:extra_vehicles.*.vehicle_type_id', 'nullable', 'integer', 'exists:truck_types,id,status,active'],
            'extra_vehicles.*.vehicle_type_id' => ['required_without:extra_vehicles.*.truck_type_id', 'nullable', 'integer', 'exists:vehicle_types,id'],
            'extra_vehicles.*.service_type' => ['nullable', 'in:book_now,schedule'],
        ]);

        if (! empty($validated['vehicle_type_id'])) {
            [$derivedTruckType, $derivationError] = $this->bookingService->resolveRequiredTruckType((int) $validated['vehicle_type_id']);
            if ($derivationError !== null) {
                return response()->json([
                    'message' => $derivationError,
                    'errors' => ['vehicle_type_id' => [$derivationError]],
                ], 422);
            }
            $validated['truck_type_id'] = $derivedTruckType->id;
        }

        if (! empty($validated['extra_vehicles'])) {
            foreach ($validated['extra_vehicles'] as $index => $ev) {
                if (empty($ev['vehicle_type_id'])) {
                    continue;
                }
                [$derivedExtraTruckType, $extraDerivationError] = $this->bookingService->resolveRequiredTruckType((int) $ev['vehicle_type_id']);
                if ($extraDerivationError !== null) {
                    return response()->json([
                        'message' => $extraDerivationError,
                        'errors' => ["extra_vehicles.$index.vehicle_type_id" => [$extraDerivationError]],
                    ], 422);
                }
                $validated['extra_vehicles'][$index]['truck_type_id'] = $derivedExtraTruckType->id;
            }
        }

        $authoritativeDistanceKm = $this->bookingService->estimateDirectDistanceKm(
            (float) $validated['pickup_lat'],
            (float) $validated['pickup_lng'],
            (float) $validated['drop_lat'],
            (float) $validated['drop_lng'],
        );

        if ($authoritativeDistanceKm <= 0.05) {
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

        $requestServiceType = $validated['service_type'] ?? 'book_now';
        $extraVehicles = $validated['extra_vehicles'] ?? [];
        $bookNowExtras = $requestServiceType === 'book_now' ? $extraVehicles : [];
        $scheduleExtras = $requestServiceType === 'schedule' ? $extraVehicles : [];

        $pricing = $this->bookingService->calculatePricing([
            ...$validated,
            'distance_km' => $authoritativeDistanceKm,
            'extra_vehicles' => $bookNowExtras,
        ]);

        $scheduledExtraPreviews = array_map(function (array $ev) use ($authoritativeDistanceKm) {
            $truckType = TruckType::find($ev['truck_type_id']);
            $baseRate = (float) ($truckType?->base_rate ?? 0);
            $distanceFee = $this->bookingService->distanceFeeFor($authoritativeDistanceKm, (float) ($truckType?->per_km_rate ?? 0));
            $subtotal = round($baseRate + $distanceFee, 2);
            $vatAmount = round($subtotal * 0.12, 2);
            return [
                'truck_type_id' => (int) $ev['truck_type_id'],
                'vehicle_type_id' => (int) ($ev['vehicle_type_id'] ?? 0),
                'base_rate' => $baseRate,
                'distance_fee' => $distanceFee,
                'vat_amount' => $vatAmount,
                'final_total' => round($subtotal + $vatAmount, 2),
            ];
        }, $scheduleExtras);

        return response()->json([
            'route' => $route,
            'pricing' => [
                'distance_km'         => (float) $pricing['distance_km'],
                'extra_distance'      => (float) $pricing['extra_distance'],
                'base_rate'           => (float) $pricing['base_rate'],
                'base_rate_total'     => (float) $pricing['base_rate_total'],
                'per_km_rate'         => (float) $pricing['per_km_rate'],
                'distance_fee'        => (float) $pricing['distance_fee'],
                'computed_total'      => (float) $pricing['computed_total'],
                'discount_percentage' => (float) $pricing['discount_percentage'],
                'discount_amount'     => (float) $pricing['discount_amount'],
                'additional_fee'      => (float) $pricing['additional_fee'],
                'discounted_total'    => (float) $pricing['discounted_total'],
                'vat_amount'          => (float) $pricing['vat_amount'],
                'final_total'         => (float) $pricing['final_total'],
            ],
            'scheduled_extra_previews' => $scheduledExtraPreviews,
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

    protected function resolveGooglePlacesSuggestions(string $query): array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->googleMapsKey(),
                    'X-Goog-FieldMask' => 'suggestions.placePrediction.placeId,suggestions.placePrediction.text',
                ])
                ->post($this->googlePlacesAutocompleteUrl(), [
                    'input' => $query,
                    'includedRegionCodes' => ['ph'],
                    'locationBias' => [
                        'circle' => [
                            'center' => ['latitude' => 14.5995, 'longitude' => 120.9842],
                            'radius' => 40000.0,
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                \Illuminate\Support\Facades\Log::warning('GeoController::resolveGooglePlacesSuggestions — Google Places request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return collect($response->json('suggestions', []))
                ->take(5)
                ->map(function (array $suggestion) {
                    $prediction = $suggestion['placePrediction'] ?? [];

                    return [
                        'label' => data_get($prediction, 'text.text', 'Unknown location'),
                        'place_id' => (string) data_get($prediction, 'placeId', ''),
                    ];
                })
                ->filter(fn(array $suggestion) => $suggestion['place_id'] !== '')
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('GeoController::resolveGooglePlacesSuggestions — exception thrown.', ['message' => $exception->getMessage()]);

            return [];
        }
    }

    protected function resolveGooglePlaceDetails(string $placeId): ?array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->googleMapsKey(),
                    'X-Goog-FieldMask' => 'location,formattedAddress',
                ])
                ->get($this->googlePlacesDetailsUrl() . '/' . rawurlencode($placeId));

            if (! $response->successful()) {
                return null;
            }

            $lat = (float) data_get($response->json(), 'location.latitude', 0);
            $lng = (float) data_get($response->json(), 'location.longitude', 0);

            if ($lat === 0.0 && $lng === 0.0) {
                return null;
            }

            return [
                'label' => (string) $response->json('formattedAddress', 'Unknown location'),
                'coordinates' => [$lng, $lat],
            ];
        } catch (\Throwable $exception) {
            return null;
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
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->googleMapsKey(),
                    'X-Goog-FieldMask' => 'routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline',
                ])
                ->post($this->googleRoutesUrl(), [
                    'origin' => [
                        'location' => ['latLng' => ['latitude' => $pickupLat, 'longitude' => $pickupLng]],
                    ],
                    'destination' => [
                        'location' => ['latLng' => ['latitude' => $dropLat, 'longitude' => $dropLng]],
                    ],
                    'travelMode' => 'DRIVE',
                    'computeAlternativeRoutes' => false,
                    'units' => 'METRIC',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $route = $response->json('routes.0', []);
            $distanceMeters = (float) data_get($route, 'distanceMeters', 0);
            $durationSeconds = (float) rtrim((string) data_get($route, 'duration', '0s'), 's');
            $geometry = $this->decodeGooglePolyline((string) data_get($route, 'polyline.encodedPolyline', ''));

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

    protected function googleRoutesUrl(): string
    {
        return (string) config('services.google_maps.routes_url', 'https://routes.googleapis.com/directions/v2:computeRoutes');
    }

    protected function googlePlacesAutocompleteUrl(): string
    {
        return (string) config('services.google_maps.places_autocomplete_url', 'https://places.googleapis.com/v1/places:autocomplete');
    }

    protected function googlePlacesDetailsUrl(): string
    {
        return (string) config('services.google_maps.places_details_url', 'https://places.googleapis.com/v1/places');
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
                \Illuminate\Support\Facades\Log::warning('GeoController::resolveNominatimSearchResults — Nominatim request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

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
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('GeoController::resolveNominatimSearchResults — exception thrown.', ['message' => $exception->getMessage()]);

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
