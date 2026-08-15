<?php

namespace App\Http\Controllers;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentarySeriesController extends Controller
{
    public function index()
    {
        $series = DocumentarySeries::query()
            ->with('units:id,code,name')
            ->with(['subseries' => function ($query) {
                $query->with('units:id,code,name')->orderBy('code');
            }])
            ->orderBy('code')
            ->get();

        return response()->json(
            $series->map(fn (DocumentarySeries $item) => $this->transformSeries($item))->values()
        );
    }

    public function store(Request $request)
    {
        $this->normalizeCatalogPayload($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^\d+$/', 'unique:documentary_series,code'],
            'name' => [
                'required',
                'string',
                'max:150',
                function ($attribute, $value, $fail) {
                    if ($this->seriesNameExists($value)) {
                        $fail('No se pudo guardar ya que existe otra serie documental con ese nombre.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', 'exists:units,id'],
        ], [
            'code.required' => 'El código de la serie es obligatorio.',
            'code.max' => 'El código de la serie no puede exceder los 20 dígitos.',
            'code.regex' => 'El código de la serie solo puede contener dígitos.',
            'code.unique' => 'Ya existe una serie documental registrada con este código.',
            'name.required' => 'El nombre de la serie es obligatorio.',
            'name.max' => 'El nombre de la serie no puede exceder los 150 caracteres.',
            'unit_ids.*.exists' => 'Una de las unidades productoras seleccionadas no existe.',
        ]);

        $series = DocumentarySeries::create([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);
        $series->units()->sync($validated['unit_ids'] ?? []);

        return response()->json(['message' => 'Serie documental creada con exito']);
    }

    public function update(Request $request, $id)
    {
        $series = DocumentarySeries::findOrFail($id);
        $this->normalizeCatalogPayload($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_series', 'code')->ignore($series->id),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                function ($attribute, $value, $fail) use ($series) {
                    if ($this->seriesNameExists($value, $series->id)) {
                        $fail('No se pudo guardar ya que existe otra serie documental con ese nombre.');
                    }
                },
            ],
            'is_active' => ['required', 'boolean'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', 'exists:units,id'],
        ], [
            'code.required' => 'El código de la serie es obligatorio.',
            'code.max' => 'El código de la serie no puede exceder los 20 dígitos.',
            'code.regex' => 'El código de la serie solo puede contener dígitos.',
            'code.unique' => 'Ya existe una serie documental registrada con este código.',
            'name.required' => 'El nombre de la serie es obligatorio.',
            'name.max' => 'El nombre de la serie no puede exceder los 150 caracteres.',
            'unit_ids.*.exists' => 'Una de las unidades productoras seleccionadas no existe.',
        ]);

        $series->update([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'],
        ]);
        $selectedUnitIds = $validated['unit_ids'] ?? [];
        $series->units()->sync($selectedUnitIds);
        $series->subseries()
            ->with('units:id')
            ->get()
            ->each(function (DocumentarySubseries $subseries) use ($selectedUnitIds) {
                $subseries->units()->sync(
                    $subseries->units
                        ->pluck('id')
                        ->intersect($selectedUnitIds)
                        ->values()
                        ->all()
                );
            });

        return response()->json(['message' => 'Serie documental actualizada']);
    }

    public function destroy($id)
    {
        $series = DocumentarySeries::with('subseries')->findOrFail($id);

        foreach ($series->subseries as $subseries) {
            $subseries->delete();
        }

        $series->delete();

        return response()->json(['message' => 'Serie documental eliminada']);
    }

    public function storeSubseries(Request $request, $seriesId)
    {
        $series = DocumentarySeries::with('units:id')->findOrFail($seriesId);
        $allowedUnitIds = $series->units->pluck('id')->all();
        $this->normalizeCatalogPayload($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_subseries', 'code')
                    ->where(fn ($query) => $query->where('documentary_series_id', $series->id)->whereNull('deleted_at')),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                function ($attribute, $value, $fail) use ($series) {
                    if ($this->subseriesNameExists($series->id, $value)) {
                        $fail('No se pudo guardar ya que existe otra subserie documental con ese nombre dentro de la misma serie.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', Rule::in($allowedUnitIds)],
        ], [
            'code.required' => 'El código de la subserie es obligatorio.',
            'code.max' => 'El código de la subserie no puede exceder los 20 dígitos.',
            'code.regex' => 'El código de la subserie solo puede contener dígitos.',
            'code.unique' => 'Ya existe una subserie registrada con este código dentro de la serie seleccionada.',
            'name.required' => 'El nombre de la subserie es obligatorio.',
            'name.max' => 'El nombre de la subserie no puede exceder los 150 caracteres.',
            'unit_ids.*.in' => 'Las unidades de la subserie deben pertenecer a las unidades asignadas a la serie.',
        ]);

        $subseries = DocumentarySubseries::create([
            'documentary_series_id' => $series->id,
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);
        $subseries->units()->sync($validated['unit_ids'] ?? []);

        return response()->json(['message' => 'Subserie documental creada con exito']);
    }

    public function updateSubseries(Request $request, $id)
    {
        $subseries = DocumentarySubseries::with('series.units:id')->findOrFail($id);
        $allowedUnitIds = $subseries->series?->units?->pluck('id')->all() ?? [];
        $this->normalizeCatalogPayload($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_subseries', 'code')
                    ->where(fn ($query) => $query->where('documentary_series_id', $subseries->documentary_series_id)->whereNull('deleted_at'))
                    ->ignore($subseries->id),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                function ($attribute, $value, $fail) use ($subseries) {
                    if ($this->subseriesNameExists($subseries->documentary_series_id, $value, $subseries->id)) {
                        $fail('No se pudo guardar ya que existe otra subserie documental con ese nombre dentro de la misma serie.');
                    }
                },
            ],
            'is_active' => ['required', 'boolean'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', Rule::in($allowedUnitIds)],
        ], [
            'code.required' => 'El código de la subserie es obligatorio.',
            'code.max' => 'El código de la subserie no puede exceder los 20 dígitos.',
            'code.regex' => 'El código de la subserie solo puede contener dígitos.',
            'code.unique' => 'Ya existe una subserie registrada con este código dentro de la serie seleccionada.',
            'name.required' => 'El nombre de la subserie es obligatorio.',
            'name.max' => 'El nombre de la subserie no puede exceder los 150 caracteres.',
            'unit_ids.*.in' => 'Las unidades de la subserie deben pertenecer a las unidades asignadas a la serie.',
        ]);

        $subseries->update([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'],
        ]);
        $subseries->units()->sync($validated['unit_ids'] ?? []);

        return response()->json(['message' => 'Subserie documental actualizada']);
    }

    public function destroySubseries($id)
    {
        $subseries = DocumentarySubseries::findOrFail($id);
        $subseries->delete();

        return response()->json(['message' => 'Subserie documental eliminada']);
    }

    private function normalizeCatalogPayload(Request $request): void
    {
        $request->merge([
            'code' => is_string($request->input('code')) ? trim($request->input('code')) : $request->input('code'),
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
        ]);
    }

    private function seriesNameExists(string $name, ?int $ignoreId = null): bool
    {
        return DocumentarySeries::query()
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    private function subseriesNameExists(int $seriesId, string $name, ?int $ignoreId = null): bool
    {
        return DocumentarySubseries::query()
            ->where('documentary_series_id', $seriesId)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    private function transformSeries(DocumentarySeries $series): array
    {
        return [
            'id' => $series->id,
            'code' => $series->code,
            'name' => $series->name,
            'is_active' => $series->is_active,
            'subseries_count' => $series->subseries->count(),
            'unit_ids' => $series->units->pluck('id')->values()->all(),
            'units' => $series->units
                ->map(fn ($unit) => [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'name' => $unit->name,
                ])
                ->values()
                ->all(),
            'subseries' => $series->subseries
                ->map(fn (DocumentarySubseries $subseries) => [
                    'id' => $subseries->id,
                    'code' => $subseries->code,
                    'name' => $subseries->name,
                    'is_active' => $subseries->is_active,
                    'documentary_series_id' => $subseries->documentary_series_id,
                    'unit_ids' => $subseries->units->pluck('id')->values()->all(),
                    'units' => $subseries->units
                        ->map(fn ($unit) => [
                            'id' => $unit->id,
                            'code' => $unit->code,
                            'name' => $unit->name,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }
}
