<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use App\Models\VehicleCategory;
use App\Models\TruckType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehicleTypeController extends Controller
{
    protected function activeOrderedIds(?int $excludeId = null): array
    {
        return VehicleType::where('status', 'active')
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->orderBy('display_order')
            ->orderBy('name')
            ->pluck('id')
            ->all();
    }

    protected function resequence(array $ids): void
    {
        foreach ($ids as $position => $id) {
            VehicleType::where('id', $id)->update(['display_order' => ($position + 1) * 10]);
        }
    }

    protected function appendToActiveOrder(int $vehicleTypeId): void
    {
        $ids = $this->activeOrderedIds($vehicleTypeId);
        $ids[] = $vehicleTypeId;

        $this->resequence($ids);
    }

    protected function applyGroupOrder(array $globalIds, array $orderedGroupVehicleIds): array
    {
        $indices = [];
        foreach ($globalIds as $index => $id) {
            if (in_array($id, $orderedGroupVehicleIds, true)) {
                $indices[] = $index;
            }
        }

        foreach ($indices as $position => $globalIndex) {
            $globalIds[$globalIndex] = $orderedGroupVehicleIds[$position];
        }

        return $globalIds;
    }

    protected function orderTruckTypesByClass($truckTypes)
    {
        $classOrder = ['light' => 1, 'medium' => 2, 'heavy' => 3];

        return $truckTypes->sortBy(fn ($truck) => [$classOrder[$truck->class] ?? 99, $truck->name])->values();
    }

    /**
     * Groups vehicle types by required truck type, then category (alphabetical,
     * as customers see categories). Within a category, active vehicles keep the
     * customer order (display_order, then name) and are numbered from 1 — the
     * scope saveOrder() reorders. Inactive vehicles follow, unnumbered.
     */
    protected function buildMainGroups($vehicleCategories, array $filters): array
    {
        $isFiltering = $filters['search'] !== '' || $filters['category'] !== '' || $filters['status'] !== '';

        $all = VehicleType::with('requiredTruckType')->withCount('bookings')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        // Order number within each truck type + category, counted over every
        // active vehicle so filtering never changes a number.
        $rankCounters = [];
        $groupRank = [];
        foreach ($all->where('status', 'active') as $vehicle) {
            $key = $vehicle->required_truck_type_id . '|' . $vehicle->category;
            $groupRank[$vehicle->id] = $rankCounters[$key] = ($rankCounters[$key] ?? 0) + 1;
        }

        $matchingIds = VehicleType::query()
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $like = '%' . addcslashes($filters['search'], '%_\\') . '%';
                $query->where(fn ($inner) => $inner->where('name', 'ilike', $like)->orWhere('description', 'ilike', $like));
            })
            ->when($filters['category'] !== '', fn ($query) => $query->where('category', $filters['category']))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->pluck('id')
            ->flip();

        $vehicles = $all->filter(fn ($vehicle) => $matchingIds->has($vehicle->id))
            ->each(fn ($vehicle) => $vehicle->group_rank = $groupRank[$vehicle->id] ?? null)
            ->sortBy(fn ($vehicle) => [$vehicle->group_rank === null ? 1 : 0, $vehicle->group_rank ?? 0, $vehicle->name])
            ->values();

        $truckTypes = $this->orderTruckTypesByClass(TruckType::orderBy('name')->get());
        $categoryNames = $vehicleCategories->keyBy('slug');

        $groups = [];
        $seenIds = [];

        foreach ($truckTypes as $truckType) {
            $own = $vehicles->where('required_truck_type_id', $truckType->id);
            $hasAnyVehicle = $all->contains('required_truck_type_id', $truckType->id);

            // Empty active truck types still show when not filtering, so a new
            // one is visible; inactive ones only appear while they hold vehicles.
            if ($own->isEmpty() && ($isFiltering || $truckType->status !== 'active' || $hasAnyVehicle)) {
                continue;
            }

            $groups[] = $this->truckTypeGroup($truckType, $own, $vehicleCategories, $categoryNames);
            $seenIds = array_merge($seenIds, $own->pluck('id')->all());
        }

        $remaining = $vehicles->reject(fn ($vehicle) => in_array($vehicle->id, $seenIds, true));
        if ($remaining->isNotEmpty()) {
            $groups[] = $this->truckTypeGroup(null, $remaining, $vehicleCategories, $categoryNames);
        }

        return $groups;
    }

    protected function truckTypeGroup(?TruckType $truckType, $vehicles, $vehicleCategories, $categoryNames): array
    {
        $categories = $vehicleCategories
            ->map(fn ($category) => ['slug' => $category->slug, 'name' => $category->name, 'vehicles' => $vehicles->where('category', $category->slug)->values()])
            ->filter(fn ($group) => $group['vehicles']->isNotEmpty())
            ->values();

        // Vehicles whose category slug has no lookup row.
        $unknown = $vehicles->reject(fn ($vehicle) => $categoryNames->has($vehicle->category))->values();
        if ($unknown->isNotEmpty()) {
            $categories->push(['slug' => null, 'name' => 'Unknown category', 'vehicles' => $unknown]);
        }

        return [
            'truckType' => $truckType,
            'count' => $vehicles->count(),
            'categories' => $categories,
            // Reordering needs a real group with at least two active vehicles.
            'reorderable' => $truckType !== null && $categories->contains(fn ($group) => $group['slug'] !== null
                && $group['vehicles']->whereNotNull('group_rank')->count() > 1),
        ];
    }

    protected function assertRequiredTruckTypeCompatible(TruckType $truckType, ?float $weightKg): void
    {
        if ($weightKg === null) {
            return;
        }

        if (! $truckType->isCompatibleWithWeight($weightKg)) {
            throw ValidationException::withMessages([
                'weight_kg' => "This vehicle's weight ({$weightKg} kg) is not compatible with {$truckType->name}.",
            ]);
        }
    }

    /**
     * A vehicle type shown to customers (active) must map to a truck type that
     * can actually be dispatched. Called inside the saving transaction, with the
     * truck type row locked, so it cannot be disabled between check and save.
     * Weight compatibility and pricing are unchanged and checked separately.
     */
    protected function assertRequiredTruckTypeActive(?int $truckTypeId, string $field = 'required_truck_type_id'): TruckType
    {
        $truckType = $truckTypeId ? TruckType::whereKey($truckTypeId)->lockForUpdate()->first() : null;

        if (! $truckType || $truckType->status !== 'active') {
            $name = $truckType?->name ?? 'The required truck type';

            throw ValidationException::withMessages([
                $field => "{$name} is disabled. Re-enable it, or choose an active truck type, before keeping this vehicle type active.",
            ]);
        }

        return $truckType;
    }

    public function index(Request $request)
    {
        $vehicleCategories = VehicleCategory::withCount('vehicleTypes')->orderBy('name')->get();

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => $vehicleCategories->contains('slug', $request->query('category')) ? (string) $request->query('category') : '',
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? (string) $request->query('status') : '',
        ];

        $activeIds = $this->activeOrderedIds();

        // Global customer-list position, kept for existing consumers.
        $positionByVehicleId = [];
        foreach ($activeIds as $index => $id) {
            $positionByVehicleId[$id] = $index + 1;
        }

        $mainGroups = $this->buildMainGroups($vehicleCategories, $filters);

        return view('superadmin.vehicle-types.index', [
            'filters' => $filters,
            'hasFilters' => $filters['search'] !== '' || $filters['category'] !== '' || $filters['status'] !== '',
            'mainGroups' => $mainGroups,
            'shownCount' => collect($mainGroups)->sum('count'),
            'totalCount' => VehicleType::count(),
            'positionByVehicleId' => $positionByVehicleId,
            'activeCount' => count($activeIds),
            'vehicleCategories' => $vehicleCategories,
            'truckTypes' => $this->orderTruckTypesByClass(TruckType::where('status', 'active')->get()),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateVehicleType($request);

        $requiredTruckType = TruckType::findOrFail($validated['required_truck_type_id']);
        $weightKg = $validated['weight_kg'] ?? null;
        $this->assertRequiredTruckTypeCompatible($requiredTruckType, $weightKg !== null ? (float) $weightKg : null);

        $validated['status'] = 'active';
        $validated['display_order'] = 0;

        $vehicleType = DB::transaction(function () use ($validated, $requiredTruckType) {
            $this->assertRequiredTruckTypeActive($requiredTruckType->id);

            $vehicleType = VehicleType::create($validated);
            $vehicleType->truckTypes()->sync([$requiredTruckType->id]);

            $this->appendToActiveOrder($vehicleType->id);

            return $vehicleType;
        });

        return $this->backToList()->with('success', "{$vehicleType->name} added.");
    }

    public function update(Request $request, VehicleType $vehicleType)
    {
        $validated = $this->validateVehicleType($request, $vehicleType);

        $requiredTruckType = TruckType::findOrFail($validated['required_truck_type_id']);
        $weightKg = $validated['weight_kg'] ?? null;
        $this->assertRequiredTruckTypeCompatible($requiredTruckType, $weightKg !== null ? (float) $weightKg : null);

        DB::transaction(function () use ($vehicleType, $validated, $requiredTruckType) {
            // Only a vehicle type customers can see needs a dispatchable truck type.
            if ($vehicleType->status === 'active') {
                $this->assertRequiredTruckTypeActive($requiredTruckType->id);
            }

            $vehicleType->update($validated);
            $vehicleType->truckTypes()->sync([$requiredTruckType->id]);
        });

        return $this->backToList()->with('success', "{$vehicleType->name} updated.");
    }

    /**
     * Validates a copy with the weight's thousands separators removed, so a
     * failed save sends back exactly what the owner typed.
     */
    protected function validateVehicleType(Request $request, ?VehicleType $vehicleType = null): array
    {
        $data = $request->all();
        if (is_string($data['weight_kg'] ?? null)) {
            $data['weight_kg'] = preg_replace('/[\s,]/', '', $data['weight_kg']);
        }

        return Validator::make($data, [
            'name' => 'required|string|max:100|unique:vehicle_types,name' . ($vehicleType ? ',' . $vehicleType->id : ''),
            'category' => 'required|exists:vehicle_categories,slug',
            'weight_kg' => 'nullable|numeric|min:0',
            'required_truck_type_id' => 'required|exists:truck_types,id',
            'description' => 'nullable|string|max:500',
        ], [
            'name.required' => 'Enter a name.',
            'name.unique' => 'A vehicle type with this name already exists.',
            'category.required' => 'Choose a category.',
            'category.exists' => 'Choose a category from the list.',
            'weight_kg.numeric' => 'Enter a number, e.g. 1300',
            'weight_kg.min' => 'Enter 0 or more.',
            'required_truck_type_id.required' => 'Choose the truck type this vehicle needs.',
            'required_truck_type_id.exists' => 'Choose a truck type from the list.',
        ])->validate();
    }

    /** Return to the list the owner came from, keeping search and filters. */
    protected function backToList()
    {
        $index = route('superadmin.vehicle-types.index');
        $previous = url()->previous();

        return redirect(str_starts_with($previous, $index) && ! str_starts_with($previous, $index . '/') ? $previous : $index);
    }

    public function toggleStatus(VehicleType $vehicleType)
    {
        $nextStatus = $vehicleType->status === 'active' ? 'inactive' : 'active';

        try {
            DB::transaction(function () use ($vehicleType, $nextStatus) {
                // A legacy row with no required truck type has nothing to check.
                if ($nextStatus === 'active' && $vehicleType->required_truck_type_id) {
                    $this->assertRequiredTruckTypeActive((int) $vehicleType->required_truck_type_id);
                }

                $vehicleType->update(['status' => $nextStatus]);

                if ($nextStatus === 'active') {
                    $this->appendToActiveOrder($vehicleType->id);
                }
            });
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $nextStatus === 'active'
            ? "{$vehicleType->name} is shown to customers again, last in its category."
            : "{$vehicleType->name} is now hidden from customers.");
    }

    public function saveOrder(Request $request)
    {
        $validated = $request->validate([
            'groups' => 'required|array|min:1',
            'groups.*.required_truck_type_id' => 'required|integer',
            'groups.*.category' => 'required|string',
            'groups.*.vehicle_ids' => 'required|array|min:1',
            'groups.*.vehicle_ids.*' => 'integer',
        ]);

        $allSubmittedIds = collect($validated['groups'])->flatMap(fn ($group) => $group['vehicle_ids'])->values();

        if ($allSubmittedIds->count() !== $allSubmittedIds->unique()->count()) {
            throw ValidationException::withMessages([
                'groups' => 'The submitted order contains a duplicate vehicle.',
            ]);
        }

        $vehicleTypesById = VehicleType::whereIn('id', $allSubmittedIds->all())->get()->keyBy('id');

        foreach ($validated['groups'] as $group) {
            foreach ($group['vehicle_ids'] as $id) {
                $vehicleType = $vehicleTypesById->get($id);

                if (! $vehicleType) {
                    throw ValidationException::withMessages([
                        'groups' => "Vehicle type {$id} does not exist.",
                    ]);
                }

                if ($vehicleType->status !== 'active') {
                    throw ValidationException::withMessages([
                        'groups' => "{$vehicleType->name} is not active and cannot be reordered.",
                    ]);
                }

                if ((int) $vehicleType->required_truck_type_id !== (int) $group['required_truck_type_id']
                    || $vehicleType->category !== $group['category']) {
                    throw ValidationException::withMessages([
                        'groups' => "{$vehicleType->name} no longer belongs to this group. Refresh and try again.",
                    ]);
                }
            }
        }

        $activeByGroup = VehicleType::where('status', 'active')
            ->get()
            ->groupBy(fn ($vehicle) => $vehicle->required_truck_type_id . '|' . $vehicle->category);

        foreach ($validated['groups'] as $group) {
            $key = $group['required_truck_type_id'] . '|' . $group['category'];
            $expected = $activeByGroup->get($key, collect())->pluck('id')->sort()->values()->all();
            $submitted = collect($group['vehicle_ids'])->sort()->values()->all();

            if ($expected !== $submitted) {
                throw ValidationException::withMessages([
                    'groups' => 'The submitted order is missing vehicles for one of the groups. Refresh and try again.',
                ]);
            }
        }

        DB::transaction(function () use ($validated) {
            $globalIds = $this->activeOrderedIds();

            foreach ($validated['groups'] as $group) {
                $globalIds = $this->applyGroupOrder($globalIds, $group['vehicle_ids']);
            }

            $this->resequence($globalIds);
        });

        return response()->json(['success' => true]);
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_categories,name',
        ]);

        $slug = Str::slug($validated['name'], '_');
        $baseSlug = $slug;
        $suffix = 2;
        while (VehicleCategory::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '_' . $suffix;
            $suffix++;
        }

        $category = VehicleCategory::create([
            'slug' => $slug,
            'name' => $validated['name'],
        ]);

        return response()->json(['success' => true, 'category' => $category]);
    }

    public function updateCategory(Request $request, VehicleCategory $vehicleCategory)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_categories,name,' . $vehicleCategory->id,
        ]);

        $vehicleCategory->update(['name' => $validated['name']]);

        return response()->json(['success' => true, 'category' => $vehicleCategory]);
    }

    public function destroy(VehicleType $vehicleType)
    {
        if ($vehicleType->bookings()->exists()) {
            return back()->with('error', 'Cannot delete vehicle type with existing bookings.');
        }

        $vehicleType->delete();

        return back()->with('success', "{$vehicleType->name} deleted.");
    }

    public function getByCategory($category)
    {
        $vehicleTypes = VehicleType::where('category', $category)
            ->where('status', 'active')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        return response()->json(['vehicleTypes' => $vehicleTypes]);
    }

    public function getTruckTypesByVehicle($vehicleTypeId)
    {
        $vehicleType = VehicleType::with('truckTypes')->findOrFail($vehicleTypeId);
        
        $truckTypes = $vehicleType->truckTypes()
            ->where('status', 'active')
            ->orderBy('base_rate')
            ->get(['id', 'name', 'base_rate', 'per_km_rate', 'description']);

        $truckTypeIds = $vehicleType->truckTypes()->pluck('truck_types.id')->toArray();

        return response()->json([
            'truckTypes' => $truckTypes,
            'truckTypeIds' => $truckTypeIds
        ]);
    }
}
