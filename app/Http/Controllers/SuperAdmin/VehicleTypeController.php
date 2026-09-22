<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use App\Models\VehicleCategory;
use App\Models\TruckType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return $truckTypes->sortBy(fn ($truck) => $classOrder[$truck->class] ?? 99)->values();
    }

    protected function buildMainGroups($truckTypes, $vehicleCategories, Request $request): array
    {
        $orderedTruckTypes = $this->orderTruckTypesByClass($truckTypes);
        $searchTerm = trim((string) $request->input('search', ''));
        $categoryFilter = $request->input('category');
        $statusFilter = $request->input('status');
        $autoExpand = $searchTerm !== '';
        $isFiltering = $searchTerm !== '' || $categoryFilter || $statusFilter;

        $vehicles = VehicleType::withCount('bookings')
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($inner) use ($searchTerm) {
                    $inner->where('name', 'like', "%{$searchTerm}%")
                        ->orWhere('description', 'like', "%{$searchTerm}%");
                });
            })
            ->when($categoryFilter, fn ($query) => $query->where('category', $categoryFilter))
            ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $groups = [];
        $seenIds = [];

        foreach ($orderedTruckTypes as $truckType) {
            $categoryGroups = [];

            foreach ($vehicleCategories as $category) {
                $matched = $vehicles
                    ->filter(fn ($vehicle) => (int) $vehicle->required_truck_type_id === $truckType->id && $vehicle->category === $category->slug)
                    ->values();

                if ($matched->isEmpty()) {
                    continue;
                }

                foreach ($matched as $vehicle) {
                    $seenIds[] = $vehicle->id;
                }

                $categoryGroups[] = [
                    'category' => $category,
                    'vehicles' => $matched,
                    'open' => $autoExpand,
                ];
            }

            if (empty($categoryGroups) && $isFiltering) {
                continue;
            }

            $groups[] = [
                'truckType' => $truckType,
                'categories' => $categoryGroups,
                'open' => $autoExpand,
            ];
        }

        $remaining = $vehicles->reject(fn ($vehicle) => in_array($vehicle->id, $seenIds, true))->values();

        if ($remaining->isNotEmpty()) {
            $groups[] = [
                'truckType' => (object) ['id' => 0, 'name' => 'Other'],
                'categories' => [[
                    'category' => (object) ['slug' => null, 'name' => 'Uncategorized'],
                    'vehicles' => $remaining,
                    'open' => $autoExpand,
                ]],
                'open' => $autoExpand,
            ];
        }

        return $groups;
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

    public function index(Request $request)
    {
        $truckTypes = TruckType::where('status', 'active')->orderBy('name')->get();

        $activeIds = $this->activeOrderedIds();

        $positionByVehicleId = [];
        foreach ($activeIds as $index => $id) {
            $positionByVehicleId[$id] = $index + 1;
        }

        $activeCount = count($activeIds);

        $vehicleCategories = VehicleCategory::orderBy('name')->get();

        $mainGroups = $this->buildMainGroups($truckTypes, $vehicleCategories, $request);
        $hasResults = $request->hasAny(['search', 'category', 'status'])
            ? collect($mainGroups)->contains(fn ($group) => collect($group['categories'])->contains(fn ($cat) => $cat['vehicles']->isNotEmpty()))
            : ! empty($mainGroups);

        return view('superadmin.vehicle-types.index', compact(
            'truckTypes',
            'positionByVehicleId',
            'activeCount',
            'vehicleCategories',
            'mainGroups',
            'hasResults'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_types,name',
            'category' => 'required|exists:vehicle_categories,slug',
            'weight_kg' => 'nullable|numeric|min:0',
            'required_truck_type_id' => 'required|exists:truck_types,id',
            'description' => 'nullable|string|max:500',
        ]);

        $requiredTruckType = TruckType::findOrFail($validated['required_truck_type_id']);
        $weightKg = $validated['weight_kg'] ?? null;
        $this->assertRequiredTruckTypeCompatible($requiredTruckType, $weightKg !== null ? (float) $weightKg : null);

        $validated['status'] = 'active';
        $validated['display_order'] = 0;

        $vehicleType = VehicleType::create($validated);
        $vehicleType->truckTypes()->sync([$requiredTruckType->id]);

        $this->appendToActiveOrder($vehicleType->id);

        return redirect()->route('superadmin.vehicle-types.index')
            ->with('success', 'Vehicle type created successfully.');
    }

    public function update(Request $request, VehicleType $vehicleType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_types,name,' . $vehicleType->id,
            'category' => 'required|exists:vehicle_categories,slug',
            'weight_kg' => 'nullable|numeric|min:0',
            'required_truck_type_id' => 'required|exists:truck_types,id',
            'description' => 'nullable|string|max:500',
        ]);

        $requiredTruckType = TruckType::findOrFail($validated['required_truck_type_id']);
        $weightKg = $validated['weight_kg'] ?? null;
        $this->assertRequiredTruckTypeCompatible($requiredTruckType, $weightKg !== null ? (float) $weightKg : null);

        $vehicleType->update($validated);
        $vehicleType->truckTypes()->sync([$requiredTruckType->id]);

        return redirect()->route('superadmin.vehicle-types.index')
            ->with('success', 'Vehicle type updated successfully.');
    }

    public function toggleStatus(VehicleType $vehicleType)
    {
        $nextStatus = $vehicleType->status === 'active' ? 'inactive' : 'active';

        $vehicleType->update(['status' => $nextStatus]);

        if ($nextStatus === 'active') {
            $this->appendToActiveOrder($vehicleType->id);
        }

        return back()->with('success', 'Vehicle type status updated successfully.');
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

        return back()->with('success', 'Vehicle type deleted successfully.');
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
