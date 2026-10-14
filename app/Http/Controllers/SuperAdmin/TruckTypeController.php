<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\TruckType;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TruckTypeController extends Controller
{
    /** Truck classes and the vehicle weights each can tow (see TruckType::isCompatibleWithWeight). */
    public const CLASSES = [
        'light' => ['label' => 'Light', 'range' => 'Up to 4,500 kg'],
        'medium' => ['label' => 'Medium', 'range' => '4,501–7,500 kg'],
        'heavy' => ['label' => 'Heavy', 'range' => 'Over 7,500 kg'],
    ];

    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            return response()->json(TruckType::orderBy('name')->get());
        }

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
        ];

        $truckTypes = TruckType::query()
            ->withCount([
                'units as trucks_count' => fn ($query) => $query->whereNull('archived_at'),
                'units as archived_trucks_count' => fn ($query) => $query->whereNotNull('archived_at'),
                'bookings as active_bookings_count' => fn ($query) => $query->whereIn('status', $this->busyBookingStatuses()),
                'bookings as bookings_count',
                'vehicleTypes as vehicle_types_count',
            ])
            ->with(['requiredByVehicleTypes' => fn ($query) => $query->where('status', 'active')->orderBy('name')->select(['id', 'name', 'required_truck_type_id'])])
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $like = '%' . addcslashes($filters['search'], '%_\\') . '%';
                $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('description', 'ilike', $like));
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        // A delete can leave the requested page past the end of the list.
        if ($truckTypes->isEmpty() && $truckTypes->currentPage() > 1) {
            return redirect($truckTypes->url(max(1, $truckTypes->lastPage())));
        }

        $truckTypes->getCollection()->each(function (TruckType $type) {
            $type->disable_blockers = $this->disableBlockers($type);
            $type->disable_guidance = $this->disableGuidance($type);
            $type->delete_blockers = $this->deleteBlockers($type);
        });

        return view('superadmin.truck-types.index', [
            'truckTypes' => $truckTypes,
            'filters' => $filters,
            'hasFilters' => $filters['search'] !== '' || $filters['status'] !== '',
            'classes' => self::CLASSES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateTruckType($request);
        $validated['status'] = 'active';

        $type = TruckType::create($validated);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'truckType' => $type]);
        }

        return $this->backToList()->with('success', "{$type->name} added.");
    }

    public function update(Request $request, TruckType $truckType)
    {
        $validated = $this->validateTruckType($request, $truckType);

        $truckType->update($validated);

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return $this->backToList()->with('success', "{$truckType->name} updated.");
    }

    /** Return to the list page the owner came from, keeping search, filter, and page. */
    protected function backToList()
    {
        $index = route('superadmin.truck-types.index');
        $previous = url()->previous();

        return redirect(str_starts_with($previous, $index) ? $previous : $index);
    }

    /**
     * Shared add/edit validation. Amounts may be typed with thousands
     * separators or a peso sign; they are stored as plain numbers.
     */
    protected function validateTruckType(Request $request, ?TruckType $truckType = null): array
    {
        // Validate a cleaned copy so a failed save sends back exactly what was typed.
        $data = $request->all();
        foreach (['base_rate', 'per_km_rate', 'max_tonnage'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = preg_replace('/[\s,₱]/u', '', $data[$field]);
            }
        }

        // New truck types need a class. Older types saved before classes were
        // required may stay "Not set"; once a type has a class it keeps one.
        $classRule = $truckType === null || $truckType->class !== null ? 'required' : 'nullable';

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100', Rule::unique('truck_types', 'name')->ignore($truckType?->id)],
            'class' => [$classRule, Rule::in(array_keys(self::CLASSES))],
            'base_rate' => 'required|numeric|min:0',
            'per_km_rate' => 'required|numeric|min:0',
            'max_tonnage' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ], [
            'name.required' => 'Enter a name.',
            'name.unique' => 'A truck type with this name already exists.',
            'class.required' => 'Choose a truck class.',
            'class.in' => 'Choose Light, Medium, or Heavy.',
            'base_rate.required' => 'Enter a base rate.',
            'per_km_rate.required' => 'Enter a rate per km.',
            '*.numeric' => 'Enter a number, e.g. 1500.00',
            '*.min' => 'Enter 0 or more.',
        ])->validate();

        if ($truckType && ($validated['class'] ?? null) !== null && $validated['class'] !== $truckType->class) {
            $this->assertLinkedVehiclesFitClass($truckType, $validated['class']);
        }

        return $validated;
    }

    /**
     * Setting or changing a class must not leave existing vehicle types linked
     * to a truck type that can no longer tow them.
     */
    protected function assertLinkedVehiclesFitClass(TruckType $truckType, string $class): void
    {
        $probe = (new TruckType())->forceFill(['class' => $class]);

        $conflicts = $truckType->vehicleTypes()->get()
            ->merge(VehicleType::where('required_truck_type_id', $truckType->id)->get())
            ->unique('id')
            ->filter(fn (VehicleType $vehicle) => $vehicle->weight_kg !== null
                && ! $probe->isCompatibleWithWeight((float) $vehicle->weight_kg))
            ->map(fn (VehicleType $vehicle) => "{$vehicle->name} (" . number_format((float) $vehicle->weight_kg) . ' kg)')
            ->values();

        if ($conflicts->isNotEmpty()) {
            throw ValidationException::withMessages([
                'class' => 'The ' . self::CLASSES[$class]['label'] . ' class can\'t tow '
                    . $conflicts->join(', ', ' and ') . '. Change those vehicle types first.',
            ]);
        }
    }

    /** Why this truck type can't be disabled right now (same rules as toggleStatus). */
    protected function disableBlockers(TruckType $type): array
    {
        if ($type->status !== 'active') {
            return [];
        }

        $reasons = $this->truckParts($type);

        if ($type->active_bookings_count > 0) {
            $reasons[] = $type->active_bookings_count . ' active ' . str('booking')->plural($type->active_bookings_count);
        }

        $vehicles = $type->requiredByVehicleTypes;
        if ($vehicles->isNotEmpty()) {
            $reasons[] = $vehicles->count() . ' active ' . str('vehicle type')->plural($vehicles->count())
                . ' (' . $this->nameList($vehicles->pluck('name')) . ')';
        }

        return $reasons;
    }

    /** What the owner has to do before Disable works, matching the blockers above. */
    protected function disableGuidance(TruckType $type): ?string
    {
        if ($type->status !== 'active') {
            return null;
        }

        $steps = [];
        if ((int) $type->trucks_count + (int) $type->archived_trucks_count > 0) {
            $steps[] = (int) $type->archived_trucks_count > 0
                ? 'move its trucks, including archived ones, to another truck type'
                : 'move its trucks to another truck type';
        }
        if ($type->active_bookings_count > 0) {
            $steps[] = 'let its active bookings finish';
        }
        if ($type->requiredByVehicleTypes->isNotEmpty()) {
            $steps[] = 'in Vehicle Types, give those vehicle types a different required truck type or disable them';
        }

        return $steps ? 'First, ' . collect($steps)->join(', ', ' and ') . '.' : null;
    }

    /**
     * "A", "A and B", "A, B and C", or "A, B, C and 2 more" — sorted
     * case-insensitively so the order doesn't depend on database collation.
     */
    protected function nameList($names, int $limit = 3): string
    {
        $names = collect($names)->sortBy(fn ($name) => mb_strtolower($name))->values();
        $shown = $names->take($limit);

        return $names->count() > $limit
            ? $shown->join(', ') . ' and ' . ($names->count() - $limit) . ' more'
            : $shown->join(', ', ' and ');
    }

    /** Why this truck type can't be deleted (same rules as destroy). */
    protected function deleteBlockers(TruckType $type): array
    {
        $reasons = $this->truckParts($type);

        if ($type->bookings_count > 0) {
            $reasons[] = $type->bookings_count . ' ' . str('booking')->plural($type->bookings_count);
        }

        if ($type->vehicle_types_count > 0) {
            $reasons[] = $type->vehicle_types_count . ' ' . str('vehicle type')->plural($type->vehicle_types_count);
        }

        return $reasons;
    }

    protected function truckParts(TruckType $type): array
    {
        $active = (int) $type->trucks_count;
        $archived = (int) $type->archived_trucks_count;

        $parts = [];
        if ($active > 0) {
            $parts[] = $active . ' ' . str('truck')->plural($active);
        }
        if ($archived > 0) {
            $parts[] = $archived . ' archived ' . str('truck')->plural($archived);
        }

        return $parts;
    }

    public function toggleStatus(Request $request, TruckType $truckType)
    {
        if ($truckType->status === 'active' && $this->truckTypeIsBusy($truckType)) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'This truck type is busy and cannot be disabled while assigned or booked.'], 422);
            }
            return back()->with('error', 'This tow truck type is busy and cannot be set inactive while it is assigned or booked.');
        }

        // Customers keep seeing active vehicle types whose bookings need this
        // truck type, but BookingService refuses them once it is inactive.
        if ($truckType->status === 'active') {
            $dependents = $truckType->requiredByVehicleTypes()->where('status', 'active')->orderBy('name')->pluck('name');

            if ($dependents->isNotEmpty()) {
                $message = "{$truckType->name} can't be disabled while active vehicle types need it: "
                    . $this->nameList($dependents, 10) . '. Customers can still choose '
                    . ($dependents->count() === 1 ? 'it' : 'them') . ', but the booking would fail. '
                    . 'In Vehicle Types, give ' . ($dependents->count() === 1 ? 'it' : 'them')
                    . ' a different required truck type or disable ' . ($dependents->count() === 1 ? 'it' : 'them') . ' first.';

                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $message], 422);
                }

                return back()->with('error', $message);
            }
        }

        $truckType->update([
            'status' => $truckType->status === 'active' ? 'inactive' : 'active',
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'status' => $truckType->status]);
        }

        return back()->with('success', $truckType->status === 'active' ? "{$truckType->name} enabled." : "{$truckType->name} disabled.");
    }

    public function destroy(Request $request, TruckType $truckType)
    {
        if ($truckType->units()->exists()) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Cannot delete truck type with existing units assigned.'], 422);
            }
            return back()->with('error', 'Cannot delete truck type with existing units assigned.');
        }

        if ($truckType->bookings()->exists()) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Cannot delete truck type with existing bookings.'], 422);
            }
            return back()->with('error', 'Cannot delete truck type with existing bookings.');
        }

        if ($truckType->vehicleTypes()->exists()) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Cannot delete truck type that is linked to vehicle types.'], 422);
            }
            return back()->with('error', 'Cannot delete truck type that is linked to vehicle types.');
        }

        $truckType->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', "{$truckType->name} deleted.");
    }

    public function attachVehicleType(TruckType $truckType, VehicleType $vehicleType): \Illuminate\Http\JsonResponse
    {
        if (! $truckType->isCompatibleWithWeight($vehicleType->weight_kg !== null ? (float) $vehicleType->weight_kg : null)) {
            return response()->json([
                'success' => false,
                'message' => "{$vehicleType->name} ({$vehicleType->weight_kg} kg) is not compatible with the {$truckType->name} class.",
            ], 422);
        }

        $truckType->vehicleTypes()->syncWithoutDetaching([$vehicleType->id]);
        return response()->json(['success' => true]);
    }

    public function detachVehicleType(TruckType $truckType, VehicleType $vehicleType): \Illuminate\Http\JsonResponse
    {
        $truckType->vehicleTypes()->detach($vehicleType->id);
        return response()->json(['success' => true]);
    }

    public function getConfig(string $name)
    {
        $type = TruckType::where('name', $name)->first();

        if (! $type || is_null($type->base_rate)) {
            return response()->json(['configured' => false]);
        }

        return response()->json([
            'configured'  => true,
            'base_rate'   => $type->base_rate,
            'per_km_rate' => $type->per_km_rate,
            'capacity'    => $type->max_tonnage,
            'description' => $type->description,
        ]);
    }

    public function saveConfig(\Illuminate\Http\Request $request, string $name)
    {
        if (! in_array($name, ['Heavy', 'Medium', 'Light'])) {
            abort(422, 'Invalid truck type.');
        }

        $validated = $request->validate([
            'base_rate'   => 'required|numeric|min:0',
            'per_km_rate' => 'required|numeric|min:0',
            'capacity'    => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        TruckType::updateOrCreate(
            ['name' => $name],
            [
                'base_rate'   => $validated['base_rate'],
                'per_km_rate' => $validated['per_km_rate'],
                'max_tonnage' => $validated['capacity'],
                'description' => $validated['description'] ?? null,
                'status'      => 'active',
            ]
        );

        return response()->json(['success' => true]);
    }

    protected function truckTypeIsBusy(TruckType $truckType): bool
    {
        return $truckType->units()->exists()
            || $truckType->bookings()->whereIn('status', $this->busyBookingStatuses())->exists();
    }

    protected function busyBookingStatuses(): array
    {
        return [
            'requested',
            'reviewed',
            'quoted',
            'quotation_sent',
            'confirmed',
            'accepted',
            'assigned',
            'on_the_way',
            'in_progress',
            'waiting_verification',
            'on_job',
        ];
    }
}
