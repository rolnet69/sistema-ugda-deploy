<?php

namespace App\Http\Controllers;

use App\Models\PhysicalLocationAisle;
use App\Models\PhysicalLocationOffice;
use App\Models\PhysicalLocationShelf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class PhysicalLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return response()->json([
                'message' => 'Solo el administrador UGDA puede gestionar ubicaciones fisicas.',
            ], 403);
        }

        return response()->json($this->cachedPaginatedCatalogPayload($request));
    }

    public function options(): JsonResponse
    {
        return response()->json($this->cachedCatalogPayload(true));
    }

    public function storeOffice(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100', 'unique:physical_location_offices,code'],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $office = PhysicalLocationOffice::query()->create([
                'name' => trim((string) $request->input('name')),
                'code' => $this->normalizeCode((string) $request->input('code')),
                'is_active' => $request->boolean('is_active', true),
            ]);

            $this->refreshCatalogCacheVersion();

            return response()->json([
                'message' => 'Oficina registrada correctamente.',
                'office' => $this->mapOffice($office),
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'No se pudo registrar la oficina.'], 500);
        }
    }

    public function updateOffice(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $office = PhysicalLocationOffice::query()->findOrFail($id);
        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100', Rule::unique('physical_location_offices', 'code')->ignore($office->id)],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $office->update([
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Oficina actualizada correctamente.',
            'office' => $this->mapOffice($office->fresh()),
        ]);
    }

    public function destroyOffice(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $office = PhysicalLocationOffice::query()->withCount('aisles')->findOrFail($id);

        if ($office->aisles_count > 0) {
            return response()->json([
                'message' => 'No se puede eliminar una oficina con pasillos registrados.',
            ], 422);
        }

        $office->delete();
        $this->refreshCatalogCacheVersion();

        return response()->json(['message' => 'Oficina eliminada correctamente.']);
    }

    public function storeAisle(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);

        $validator = Validator::make($request->all(), [
            'office_id' => ['required', 'exists:physical_location_offices,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('physical_location_aisles', 'code')
                    ->where(fn ($query) => $query->where('physical_location_office_id', (int) $request->input('office_id'))),
            ],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $aisle = PhysicalLocationAisle::query()->create([
            'physical_location_office_id' => (int) $request->input('office_id'),
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Pasillo registrado correctamente.',
            'aisle' => $this->mapAisle($aisle->load('office')),
        ], 201);
    }

    public function updateAisle(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $aisle = PhysicalLocationAisle::query()->findOrFail($id);
        $officeId = (int) $request->input('office_id', $aisle->physical_location_office_id);
        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);
        $validator = Validator::make($request->all(), [
            'office_id' => ['required', 'exists:physical_location_offices,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('physical_location_aisles', 'code')
                    ->ignore($aisle->id)
                    ->where(fn ($query) => $query->where('physical_location_office_id', $officeId)),
            ],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $aisle->update([
            'physical_location_office_id' => $officeId,
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Pasillo actualizado correctamente.',
            'aisle' => $this->mapAisle($aisle->fresh('office')),
        ]);
    }

    public function destroyAisle(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $aisle = PhysicalLocationAisle::query()->withCount('shelves')->findOrFail($id);

        if ($aisle->shelves_count > 0) {
            return response()->json([
                'message' => 'No se puede eliminar un pasillo con estantes registrados.',
            ], 422);
        }

        $aisle->delete();
        $this->refreshCatalogCacheVersion();

        return response()->json(['message' => 'Pasillo eliminado correctamente.']);
    }

    public function storeShelf(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);

        $validator = Validator::make($request->all(), [
            'aisle_id' => ['required', 'exists:physical_location_aisles,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('physical_location_shelves', 'code')
                    ->where(fn ($query) => $query->where('physical_location_aisle_id', (int) $request->input('aisle_id'))),
            ],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $shelf = PhysicalLocationShelf::query()->create([
            'physical_location_aisle_id' => (int) $request->input('aisle_id'),
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Estante registrado correctamente.',
            'shelf' => $this->mapShelf($shelf->load('aisle.office')),
        ], 201);
    }

    public function updateShelf(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $shelf = PhysicalLocationShelf::query()->findOrFail($id);
        $aisleId = (int) $request->input('aisle_id', $shelf->physical_location_aisle_id);
        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);
        $validator = Validator::make($request->all(), [
            'aisle_id' => ['required', 'exists:physical_location_aisles,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('physical_location_shelves', 'code')
                    ->ignore($shelf->id)
                    ->where(fn ($query) => $query->where('physical_location_aisle_id', $aisleId)),
            ],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $shelf->update([
            'physical_location_aisle_id' => $aisleId,
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Estante actualizado correctamente.',
            'shelf' => $this->mapShelf($shelf->fresh('aisle.office')),
        ]);
    }

    public function destroyShelf(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        PhysicalLocationShelf::query()->findOrFail($id)->delete();
        $this->refreshCatalogCacheVersion();

        return response()->json(['message' => 'Estante eliminado correctamente.']);
    }

    private function cachedCatalogPayload(bool $onlyActive): array
    {
        $version = $this->catalogCacheVersion();
        $key = "physical_locations:catalog:v{$version}:active:" . (int) $onlyActive;

        return Cache::remember($key, now()->addMinutes(5), fn () => $this->catalogPayload($onlyActive));
    }

    private function cachedPaginatedCatalogPayload(Request $request): array
    {
        $version = $this->catalogCacheVersion();
        $params = [
            'office_page' => $this->pageValue($request, 'office'),
            'office_per_page' => $this->perPageValue($request, 'office'),
            'office_search' => $this->searchValue($request, 'office'),
            'aisle_page' => $this->pageValue($request, 'aisle'),
            'aisle_per_page' => $this->perPageValue($request, 'aisle'),
            'aisle_search' => $this->searchValue($request, 'aisle'),
            'shelf_page' => $this->pageValue($request, 'shelf'),
            'shelf_per_page' => $this->perPageValue($request, 'shelf'),
            'shelf_search' => $this->searchValue($request, 'shelf'),
        ];
        $key = 'physical_locations:paginated:v' . $version . ':' . md5(json_encode($params));

        return Cache::remember($key, now()->addMinutes(5), fn () => $this->paginatedCatalogPayload($request));
    }

    private function catalogCacheVersion(): int
    {
        return (int) Cache::get('physical_locations:version', 1);
    }

    private function refreshCatalogCacheVersion(): void
    {
        Cache::add('physical_locations:version', 1);
        Cache::increment('physical_locations:version');
    }

    private function catalogPayload(bool $onlyActive): array
    {
        $offices = PhysicalLocationOffice::query()
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        $aisles = PhysicalLocationAisle::query()
            ->with('office')
            ->when($onlyActive, fn ($query) => $query->where('is_active', true)->whereHas('office', fn ($office) => $office->where('is_active', true)))
            ->orderBy('name')
            ->get();

        $shelves = PhysicalLocationShelf::query()
            ->with('aisle.office')
            ->when($onlyActive, fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('aisle', fn ($aisle) => $aisle->where('is_active', true)->whereHas('office', fn ($office) => $office->where('is_active', true))))
            ->orderBy('name')
            ->get();

        return [
            'offices' => $offices->map(fn (PhysicalLocationOffice $office) => $this->mapOffice($office))->values(),
            'aisles' => $aisles->map(fn (PhysicalLocationAisle $aisle) => $this->mapAisle($aisle))->values(),
            'shelves' => $shelves->map(fn (PhysicalLocationShelf $shelf) => $this->mapShelf($shelf))->values(),
        ];
    }

    private function paginatedCatalogPayload(Request $request): array
    {
        $offices = PhysicalLocationOffice::query()
            ->when(
                $this->searchValue($request, 'office'),
                fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                    $this->whereTextMatches($query, ['name', 'code'], $search);
                })
            )
            ->orderBy('name')
            ->paginate(
                $this->perPageValue($request, 'office'),
                ['*'],
                'office_page',
                $this->pageValue($request, 'office')
            );

        $aisles = PhysicalLocationAisle::query()
            ->with('office')
            ->when(
                $this->searchValue($request, 'aisle'),
                fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                    $this->whereTextMatches($query, ['name', 'code'], $search);
                    $query->orWhereHas('office', fn (Builder $office) => $this->whereTextMatches($office, ['name'], $search));
                })
            )
            ->orderBy('name')
            ->paginate(
                $this->perPageValue($request, 'aisle'),
                ['*'],
                'aisle_page',
                $this->pageValue($request, 'aisle')
            );

        $shelves = PhysicalLocationShelf::query()
            ->with('aisle.office')
            ->when(
                $this->searchValue($request, 'shelf'),
                fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                    $this->whereTextMatches($query, ['name', 'code'], $search);
                    $query->orWhereHas('aisle', fn (Builder $aisle) => $aisle
                        ->where(fn (Builder $aisle) => $this->whereTextMatches($aisle, ['name'], $search))
                        ->orWhereHas('office', fn (Builder $office) => $this->whereTextMatches($office, ['name'], $search)));
                })
            )
            ->orderBy('name')
            ->paginate(
                $this->perPageValue($request, 'shelf'),
                ['*'],
                'shelf_page',
                $this->pageValue($request, 'shelf')
            );

        return [
            'offices' => $this->paginatedResponse($offices, fn (PhysicalLocationOffice $office) => $this->mapOffice($office)),
            'aisles' => $this->paginatedResponse($aisles, fn (PhysicalLocationAisle $aisle) => $this->mapAisle($aisle)),
            'shelves' => $this->paginatedResponse($shelves, fn (PhysicalLocationShelf $shelf) => $this->mapShelf($shelf)),
        ];
    }

    private function paginatedResponse(LengthAwarePaginator $paginator, callable $mapper): array
    {
        return [
            'data' => $paginator->getCollection()->map($mapper)->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function searchValue(Request $request, string $type): ?string
    {
        $search = trim((string) $request->query("{$type}_search", ''));

        return $search === '' ? null : $search;
    }

    private function pageValue(Request $request, string $type): int
    {
        return max(1, (int) $request->query("{$type}_page", 1));
    }

    private function perPageValue(Request $request, string $type): int
    {
        $perPage = (int) $request->query("{$type}_per_page", 10);

        return min(max($perPage, 5), 50);
    }

    private function whereTextMatches(Builder $query, array $columns, string $search): void
    {
        $pattern = '%' . mb_strtolower($search) . '%';

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}("LOWER({$column}) LIKE ?", [$pattern]);
        }
    }

    private function mapOffice(PhysicalLocationOffice $office): array
    {
        return [
            'id' => $office->id,
            'name' => $office->name,
            'code' => $office->code,
            'is_active' => (bool) $office->is_active,
        ];
    }

    private function mapAisle(PhysicalLocationAisle $aisle): array
    {
        return [
            'id' => $aisle->id,
            'office_id' => $aisle->physical_location_office_id,
            'office_name' => $aisle->office?->name,
            'name' => $aisle->name,
            'code' => $aisle->code,
            'is_active' => (bool) $aisle->is_active,
        ];
    }

    private function mapShelf(PhysicalLocationShelf $shelf): array
    {
        return [
            'id' => $shelf->id,
            'aisle_id' => $shelf->physical_location_aisle_id,
            'aisle_name' => $shelf->aisle?->name,
            'office_id' => $shelf->aisle?->physical_location_office_id,
            'office_name' => $shelf->aisle?->office?->name,
            'name' => $shelf->name,
            'code' => $shelf->code,
            'is_active' => (bool) $shelf->is_active,
        ];
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function validationResponse($validator): JsonResponse
    {
        return response()->json([
            'message' => 'Los datos de la ubicación física no son válidos.',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function adminOnlyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Solo el administrador UGDA puede gestionar ubicaciones físicas.',
        ], 403);
    }

    private function canManagePhysicalLocations(Request $request): bool
    {
        return $request->user()?->activeProfile()?->name === 'Administrador';
    }

    private function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'code.max' => 'El código no puede superar los 100 caracteres.',
            'code.required' => 'El código es obligatorio.',
            'code.unique' => 'Ya existe un registro con este código en el mismo nivel.',
            'office_id.required' => 'Debe seleccionar la oficina.',
            'office_id.exists' => 'La oficina seleccionada no existe.',
            'aisle_id.required' => 'Debe seleccionar el pasillo.',
            'aisle_id.exists' => 'El pasillo seleccionado no existe.',
        ];
    }
}
