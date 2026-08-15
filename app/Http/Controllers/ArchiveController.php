<?php

namespace App\Http\Controllers;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use App\Models\LoanDocument;
use App\Models\PhysicalLocationShelf;
use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\Unit;
use App\Models\User;
use App\Support\SignedPdfUrl;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ArchiveController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede gestionar el archivo general.',
            ], 403);
        }

        try {
            $nextBoxNumber = ((int) TransferBox::query()->max('box_number')) + 1;

            return response()->json([
                'next_box_number' => $nextBoxNumber,
                'next_box_code' => $this->nextArchiveBoxCode(),
                'units' => Unit::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'code', 'name'])
                    ->map(fn (Unit $unit) => [
                        'label' => $unit->name,
                        'value' => $unit->id,
                        'code' => $unit->code,
                    ])
                    ->values(),
                'series' => $this->archiveClassificationOptions(),
                'locations' => $this->locationOptions(),
                'support_types' => ['Fisico', 'Digital', 'Mixto'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el catalogo de archivo.',
            ], 500);
        }
    }

    public function boxes(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede consultar las cajas del archivo general.',
            ], 403);
        }

        try {
            $term = trim((string) $request->query('q', ''));

            $boxesQuery = TransferBox::query()
                ->with(['documents', 'transfer.unit', 'transfer.units', 'assignedBy.person'])
                ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'));

            if ($term !== '') {
                $boxesQuery->where(function (Builder $query) use ($term) {
                    if (preg_match('/^C-[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+$/', $term) === 1) {
                        $query->whereRaw('LOWER(transfer_boxes.box_code) = ?', [mb_strtolower($term)])
                            ->orWhere(function (Builder $legacyQuery) use ($term) {
                                if (preg_match('/^C-(.+)-(\d+)$/i', $term, $matches) !== 1) {
                                    return;
                                }

                                $transferCode = mb_strtolower($matches[1]);
                                $boxNumber = (int) $matches[2];

                                $legacyQuery->where('transfer_boxes.box_number', $boxNumber)
                                    ->whereHas('transfer', function (Builder $transferQuery) use ($transferCode) {
                                        if ($transferCode === 'ag') {
                                            $transferQuery->where('transfers.view_mode', 'archive');

                                            return;
                                        }

                                        $transferQuery->whereRaw('LOWER(transfers.code) = ?', [$transferCode]);
                                    });
                            });

                        return;
                    }

                    if (preg_match('/^C-(.+)-(\d+)$/i', $term, $matches) === 1) {
                        $transferCode = mb_strtolower($matches[1]);
                        $boxNumber = (int) $matches[2];

                        $query->where('transfer_boxes.box_number', $boxNumber)
                            ->whereHas('transfer', function (Builder $transferQuery) use ($transferCode) {
                                if ($transferCode === 'ag') {
                                    $transferQuery->where('transfers.view_mode', 'archive');

                                    return;
                                }

                                $transferQuery->whereRaw('LOWER(transfers.code) = ?', [$transferCode]);
                            });

                        return;
                    }

                    if (preg_match('/^\d+$/', $term) === 1) {
                        $query->where('transfer_boxes.box_number', (int) $term);

                        return;
                    }

                    $this->addSearchClauses($query, [
                        'transfer_boxes.box_code',
                        'transfer_boxes.series_name',
                        'transfer_boxes.title',
                        'transfer_boxes.period_label',
                        'transfer_boxes.location_code',
                        'transfer_boxes.content_description',
                    ], $term);

                    $query->orWhereHas('transfer.unit', function (Builder $unitQuery) use ($term) {
                        $this->addSearchClauses($unitQuery, ['units.code', 'units.name'], $term);
                    });

                    $query->orWhereHas('transfer.units', function (Builder $unitQuery) use ($term) {
                        $this->addSearchClauses($unitQuery, ['units.code', 'units.name'], $term);
                    });

                    $query->orWhereHas('transfer', function (Builder $transferQuery) use ($term) {
                        $this->addSearchClauses($transferQuery, ['transfers.code'], $term);
                    });

                    $query->orWhereHas('documents', function (Builder $documentQuery) use ($term) {
                        $this->addSearchClauses($documentQuery, [
                            'transfer_box_documents.code',
                            'transfer_box_documents.name',
                            'transfer_box_documents.series_label',
                        ], $term);
                    });
                });
            }

            $page = max(1, (int) $request->query('page', 1));
            $perPage = (int) $request->query('per_page', 10);
            $perPage = min(max($perPage, 5), 50);
            $boxesPaginator = $boxesQuery
                ->orderByDesc('transfer_boxes.created_at')
                ->paginate($perPage, ['*'], 'page', $page);
            $boxes = $boxesPaginator->getCollection()
                ->map(fn (TransferBox $box) => $this->mapBox($box))
                ->values();
            $meta = [
                'current_page' => $boxesPaginator->currentPage(),
                'last_page' => $boxesPaginator->lastPage(),
                'per_page' => $boxesPaginator->perPage(),
                'total' => $boxesPaginator->total(),
                'from' => $boxesPaginator->firstItem(),
                'to' => $boxesPaginator->lastItem(),
            ];

            return response()->json([
                'query' => $term,
                'count' => $boxesPaginator->total(),
                'boxes' => $boxes,
                'data' => $boxes,
                'meta' => $meta,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron cargar las cajas registradas.',
            ], 500);
        }
    }

    public function storeBox(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede crear cajas en el archivo general.',
            ], 403);
        }

        $request->merge([
            'box_code' => mb_strtoupper(trim((string) $request->input('box_code'))),
            'unit_ids' => $this->normalizeArchiveUnitIds($request),
            'classification_ids' => collect($request->input('classification_ids', $request->input('series_ids', [])))
                ->when(
                    $request->filled('series_id'),
                    fn ($classifications) => $classifications->push('series:' . (int) $request->input('series_id'))
                )
                ->map(function ($classification) {
                    if (is_numeric($classification)) {
                        return 'series:' . (int) $classification;
                    }

                    return trim((string) $classification);
                })
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);

        $validator = Validator::make($request->all(), [
            'box_code' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/', 'unique:transfer_boxes,box_code'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['required', 'integer', 'distinct', 'exists:units,id'],
            'classification_ids' => ['required', 'array', 'min:1'],
            'classification_ids.*' => ['required', 'string', 'distinct', 'regex:/^(series|subseries):\d+$/'],
            'start_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'end_year' => ['required', 'integer', 'min:1900', 'max:2100', 'gte:start_year'],
            'office_id' => ['required', 'integer', 'exists:physical_location_offices,id'],
            'aisle_id' => ['required', 'integer', 'exists:physical_location_aisles,id'],
            'shelf_id' => ['required', 'integer', 'exists:physical_location_shelves,id'],
            'documents' => ['nullable', 'array'],
            'documents.*.series' => ['nullable', 'string', 'max:2000'],
            'documents.*.code' => ['nullable', 'string', 'max:120'],
            'documents.*.name' => ['required_with:documents', 'string', 'max:255'],
            'documents.*.year' => ['nullable', 'string', 'max:50'],
            'documents.*.support' => ['nullable', 'string', 'max:30'],
            'documents.*.pages' => ['nullable', 'string', 'max:50'],
            'documents.*.digital_file_name' => ['nullable', 'string', 'max:255'],
            'documents.*.digital_file_base64' => ['nullable', 'string'],
            'existing_document_ids' => ['nullable', 'array'],
            'existing_document_ids.*' => ['integer', 'exists:transfer_box_documents,id'],
        ], [
            'box_code.required' => 'El número de caja es obligatorio.',
            'box_code.regex' => 'El número de caja solo puede contener letras, números y guiones.',
            'box_code.unique' => 'El número de caja ya existe.',
            'unit_ids.required' => 'Debe seleccionar al menos una unidad productora.',
            'unit_ids.min' => 'Debe seleccionar al menos una unidad productora.',
            'unit_ids.*.integer' => 'Seleccione unidades productoras validas.',
            'unit_ids.*.distinct' => 'No repita una unidad productora.',
            'unit_ids.*.exists' => 'Seleccione una unidad productora valida.',
            'classification_ids.required' => 'Debe seleccionar al menos una serie o subserie documental.',
            'classification_ids.min' => 'Debe seleccionar al menos una serie o subserie documental.',
            'office_id.required' => 'Debe seleccionar la oficina.',
            'aisle_id.required' => 'Debe seleccionar el pasillo.',
            'shelf_id.required' => 'Debe seleccionar el estante.',
            'end_year.gte' => 'El ano final no puede ser menor que el ano inicial.',
            'documents.*.name.required_with' => 'El nombre del documento es obligatorio.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la caja no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $unitIds = collect($request->input('unit_ids', []))->map(fn ($id) => (int) $id)->values();
        $selectedUnits = Unit::query()
            ->whereIn('id', $unitIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        if ($selectedUnits->count() !== $unitIds->count()) {
            return response()->json([
                'message' => 'Los datos de la caja no son validos.',
                'errors' => [
                    'unit_ids' => ['Una o mas unidades productoras no existen o no estan habilitadas.'],
                ],
            ], 422);
        }

        $classificationIds = collect($request->input('classification_ids', []))->values();
        $classificationOptions = $this->archiveClassificationOptions();
        $selectedClassifications = $classificationIds
            ->map(fn (string $classificationId) => $classificationOptions->firstWhere('value', $classificationId))
            ->filter()
            ->values();

        if ($selectedClassifications->count() !== $classificationIds->count()) {
            return response()->json([
                'message' => 'Los datos de la caja no son validos.',
                'errors' => [
                    'classification_ids' => ['Una o mas series o subseries documentales no existen o no estan habilitadas.'],
                ],
            ], 422);
        }

        $selectedSeriesName = $selectedClassifications
            ->pluck('label')
            ->implode('; ');

        $allowedSeriesNames = $selectedClassifications
            ->flatMap(fn (array $classification) => [$classification['label'], $classification['name']])
            ->map(fn ($name) => $this->normalizeArchiveClassificationLabel($name))
            ->filter()
            ->values()
            ->all();

        $documentCodes = [];

        foreach ((array) $request->input('documents', []) as $documentPayload) {
            $documentName = trim((string) ($documentPayload['name'] ?? 'documento'));
            $documentSeries = trim((string) ($documentPayload['series'] ?? ''));

            if ($documentSeries === '') {
                if ($selectedClassifications->count() !== 1) {
                    return response()->json([
                        'message' => 'Los datos de la caja no son validos.',
                        'errors' => [
                            'documents' => ['El documento "' . $documentName . '" debe indicar la serie o subserie documental.'],
                        ],
                    ], 422);
                }

                $documentSeries = $selectedClassifications->first()['label'];
            }

            if (!in_array($this->normalizeArchiveClassificationLabel($documentSeries), $allowedSeriesNames, true)) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['La serie documental "' . $documentSeries . '" no existe o no esta habilitada.'],
                    ],
                ], 422);
            }

            $documentCode = mb_strtolower(trim((string) ($documentPayload['code'] ?? '')));

            if ($documentCode !== '') {
                if (isset($documentCodes[$documentCode])) {
                    return response()->json([
                        'message' => 'Los datos de la caja no son validos.',
                        'errors' => [
                            'documents' => ['El codigo "' . trim((string) ($documentPayload['code'] ?? '')) . '" esta repetido dentro de la caja.'],
                        ],
                    ], 422);
                }

                $documentCodes[$documentCode] = true;
            }

            $documentYear = trim((string) ($documentPayload['year'] ?? ''));
            $startYear = (int) $request->input('start_year');
            $endYear = (int) $request->input('end_year');

            if ($documentYear !== '' && (!preg_match('/^\d{4}$/', $documentYear)
                || (int) $documentYear < $startYear
                || (int) $documentYear > $endYear)) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['El año del documento "' . $documentName . '" debe estar dentro del rango de la caja (' . $startYear . ' - ' . $endYear . ').'],
                    ],
                ], 422);
            }

            $pages = trim((string) ($documentPayload['pages'] ?? ''));

            if ($pages !== '' && (!ctype_digit($pages) || (int) $pages < 1)) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['La cantidad de paginas del documento "' . $documentName . '" debe ser un entero positivo.'],
                    ],
                ], 422);
            }

            $support = trim((string) ($documentPayload['support'] ?? 'Fisico'));

            if (!in_array($support, ['Fisico', 'Digital', 'Mixto'], true)) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['El soporte del documento "' . $documentName . '" no es válido.'],
                    ],
                ], 422);
            }

            $digitalFileName = trim((string) ($documentPayload['digital_file_name'] ?? ''));
            $digitalFileBase64 = trim((string) ($documentPayload['digital_file_base64'] ?? ''));

            if (in_array($support, ['Digital', 'Mixto'], true)
                && ($digitalFileBase64 === '' || $digitalFileName === '' || !preg_match('/\.pdf$/i', $digitalFileName))) {
                return response()->json([
                    'message' => 'Los datos de la caja no son validos.',
                    'errors' => [
                        'documents' => ['El documento "' . $documentName . '" requiere un archivo PDF para el soporte ' . $support . '.'],
                    ],
                ], 422);
            }
        }

        $selectedShelf = $this->findValidShelf(
            (int) $request->input('office_id'),
            (int) $request->input('aisle_id'),
            (int) $request->input('shelf_id')
        );

        if ($selectedShelf === null) {
            return response()->json([
                'message' => 'La ubicación fisica seleccionada no respeta la relacion oficina, pasillo y estante.',
            ], 422);
        }

        try {
            $box = DB::transaction(function () use ($request, $selectedShelf, $selectedSeriesName, $unitIds) {
                $now = now();
                $this->lockArchiveBoxCodeSequence($now);
                $transferredStatus = RequestStatusCatalog::query()
                    ->where('code', 'transfer_status_transferred')
                    ->value('id');
                $authorizedStatus = RequestStatusCatalog::query()
                    ->where('code', 'transfer_auth_authorized')
                    ->value('id');

                $transfer = Transfer::query()->create([
                    'code' => $this->nextArchiveTransferCode(),
                    'user_id' => $request->user()->id,
                    'unit_id' => (int) $unitIds->first(),
                    'request_date' => $now->toDateString(),
                    'requested_at' => $now,
                    'status' => 'Aprobada',
                    'authorization_status_id' => $authorizedStatus,
                    'workflow_status_id' => $transferredStatus,
                    'authorized_by_user_id' => $request->user()->id,
                    'authorized_at' => $now,
                    'completed_by_user_id' => $request->user()->id,
                    'completed_at' => $now,
                    'view_mode' => 'archive',
                    'box_display_state' => 'expanded',
                    'show_print_card' => false,
                    'description' => 'Caja creada directamente desde Archivo General.',
                ]);

                $transfer->units()->sync($unitIds->all());

                $boxNumber = ((int) TransferBox::query()->max('box_number')) + 1;
                $boxCode = mb_strtoupper(trim((string) $request->input('box_code')));
                $startYear = (int) $request->input('start_year');
                $endYear = (int) $request->input('end_year');
                $locationCode = $this->buildLocationCode($selectedShelf);

                $box = $transfer->boxes()->create([
                    'series_name' => $selectedSeriesName,
                    'start_year' => $startYear,
                    'end_year' => $endYear,
                    'box_number' => $boxNumber,
                    'box_code' => $boxCode,
                    'title' => 'Caja ' . $boxCode,
                    'period_label' => '01/01/' . $startYear . ' - 31/12/' . $endYear,
                    'location_code' => $locationCode,
                    'assigned_by_user_id' => $request->user()->id,
                    'assigned_at' => $now,
                    'content_description' => $selectedSeriesName,
                ]);

                $sortOrder = 1;
                $existingIds = collect($request->input('existing_document_ids', []))
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values();

                if ($existingIds->isNotEmpty()) {
                    TransferDocument::query()
                        ->whereIn('id', $existingIds)
                        ->get()
                        ->each(function (TransferDocument $document) use ($box, &$sortOrder) {
                            $document->update([
                                'transfer_box_id' => $box->id,
                                'sort_order' => $sortOrder++,
                            ]);
                        });
                }

                foreach ($request->input('documents', []) as $documentPayload) {
                    $documentSeries = trim((string) ($documentPayload['series'] ?? ''));
                    $resolvedSeries = $documentSeries !== '' ? $documentSeries : $selectedSeriesName;
                    $digitalDocument = $this->storeDigitalDocumentPayload($documentPayload, $transfer->code . '-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT));

                    $box->documents()->create([
                        'code' => $documentPayload['code'] ?? 'DOC-' . $transfer->code . '-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT),
                        'name' => $documentPayload['name'],
                        'series_label' => $resolvedSeries,
                        'support_type' => $documentPayload['support'] ?? 'Fisico',
                        'year_label' => $documentPayload['year'] ?? null,
                        'pages_label' => $documentPayload['pages'] ?? null,
                        'digital_file_name' => $digitalDocument['name'] ?? null,
                        'digital_file_path' => $digitalDocument['path'] ?? null,
                        'sort_order' => $sortOrder++,
                    ]);
                }

                $transfer->events()->create([
                    'status_catalog_id' => $transferredStatus,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Caja creada',
                    'description' => 'Caja registrada directamente en Archivo General.',
                    'context' => [
                        'box_number' => $boxNumber,
                        'box_code' => $boxCode,
                        'location_code' => $locationCode,
                    ],
                    'occurred_at' => $now,
                ]);

                return $box->fresh(['documents', 'transfer.unit', 'transfer.units', 'assignedBy.person']);
            });

            return response()->json([
                'message' => 'Caja creada correctamente.',
                'box' => $this->mapBox($box),
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo crear la caja. Intente nuevamente.',
            ], 500);
        }
    }

    public function destroyBox(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede eliminar cajas del archivo general.',
            ], 403);
        }

        try {
            $box = TransferBox::query()->with('transfer')->findOrFail($id);

            if ($box->transfer?->view_mode !== 'archive') {
                return response()->json([
                    'message' => 'Solo se pueden eliminar cajas creadas directamente desde Archivo General.',
                ], 422);
            }

            $box->transfer?->delete();

            return response()->json([
                'message' => 'Caja eliminada correctamente.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo eliminar la caja.',
            ], 500);
        }
    }

    public function updateBoxLocation(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede reasignar cajas del archivo general.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'office_id' => ['required', 'integer', 'exists:physical_location_offices,id'],
            'aisle_id' => ['required', 'integer', 'exists:physical_location_aisles,id'],
            'shelf_id' => ['required', 'integer', 'exists:physical_location_shelves,id'],
        ], [
            'office_id.required' => 'Debe seleccionar la oficina.',
            'aisle_id.required' => 'Debe seleccionar el pasillo.',
            'shelf_id.required' => 'Debe seleccionar el estante.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la nueva ubicación no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $selectedShelf = $this->findValidShelf(
            (int) $request->input('office_id'),
            (int) $request->input('aisle_id'),
            (int) $request->input('shelf_id')
        );

        if ($selectedShelf === null) {
            return response()->json([
                'message' => 'La ubicación física seleccionada no respeta la relación oficina, pasillo y estante.',
            ], 422);
        }

        try {
            $box = DB::transaction(function () use ($id, $request, $selectedShelf) {
                $box = TransferBox::query()
                    ->with(['transfer', 'documents', 'assignedBy.person'])
                    ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->lockForUpdate()
                    ->findOrFail($id);

                $previousLocation = $box->location_code;
                $locationCode = $this->buildLocationCode($selectedShelf);

                $box->update([
                    'location_code' => $locationCode,
                ]);

                $box->transfer?->events()->create([
                    'status_catalog_id' => $box->transfer?->workflow_status_id,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Caja reasignada',
                    'description' => 'La caja #' . $box->formattedBoxNumber() . ' fue reasignada a una nueva ubicación física.',
                    'context' => [
                        'box_number' => $box->box_number,
                        'previous_location_code' => $previousLocation,
                        'new_location_code' => $locationCode,
                    ],
                    'occurred_at' => now(),
                ]);

                return $box->fresh(['documents', 'transfer.unit', 'transfer.units', 'assignedBy.person']);
            });

            return response()->json([
                'message' => 'Ubicación de caja reasignada correctamente.',
                'box' => $this->mapBox($box),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo reasignar la ubicación de la caja.',
            ], 500);
        }
    }

    public function storeDocument(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede crear documentos en el archivo general.',
            ], 403);
        }

        $request->merge([
            'series_id' => is_numeric($request->input('series_id'))
                ? 'series:' . (int) $request->input('series_id')
                : trim((string) $request->input('series_id')),
        ]);

        $currentYear = (int) now()->format('Y');
        $validator = Validator::make($request->all(), [
            'box_id' => ['required', 'integer', 'exists:transfer_boxes,id'],
            'series_id' => ['required', 'string', 'regex:/^(series|subseries):\d+$/'],
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:1900', 'max:' . $currentYear],
            'support' => ['required', 'string', 'in:Fisico,Digital,Mixto'],
            'pages' => ['required', 'integer', 'min:1', 'max:99999'],
            'digital_file_name' => ['nullable', 'string', 'max:255'],
            'digital_file_base64' => ['nullable', 'string'],
        ], [
            'box_id.required' => 'Debe seleccionar la caja donde se asignara el documento.',
            'series_id.required' => 'Debe seleccionar la serie o subserie documental.',
            'code.required' => 'El codigo del documento es obligatorio.',
            'name.required' => 'El nombre del documento es obligatorio.',
            'year.required' => 'El ano del documento es obligatorio.',
            'year.min' => 'El ano del documento debe ser mayor o igual a 1900.',
            'year.max' => 'El ano del documento no puede ser mayor al ano actual.',
            'support.required' => 'Debe seleccionar el tipo de soporte.',
            'support.in' => 'El tipo de soporte seleccionado no es válido.',
            'pages.required' => 'Debe indicar el número de páginas.',
            'pages.min' => 'El número de páginas debe ser mayor que 0.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del documento no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $support = (string) $request->input('support');
        $hasDigitalPayload = trim((string) $request->input('digital_file_base64', '')) !== '';

        if (in_array($support, ['Digital', 'Mixto'], true)) {
            $fileName = trim((string) $request->input('digital_file_name', ''));

            if (!$hasDigitalPayload || $fileName === '') {
                return response()->json([
                    'message' => 'Los datos del documento no son validos.',
                    'errors' => [
                        'digital_file' => ['Debe adjuntar un archivo PDF para documentos digitales o mixtos.'],
                    ],
                ], 422);
            }

            if (!str_ends_with(mb_strtolower($fileName), '.pdf')) {
                return response()->json([
                    'message' => 'Los datos del documento no son validos.',
                    'errors' => [
                        'digital_file' => ['El archivo digital debe estar en formato PDF.'],
                    ],
                ], 422);
            }
        }

        try {
            $document = DB::transaction(function () use ($request, $support, $hasDigitalPayload) {
                $box = TransferBox::query()
                    ->with('transfer')
                    ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->lockForUpdate()
                    ->find((int) $request->input('box_id'));

                if ($box === null) {
                    throw new \RuntimeException('La caja seleccionada no esta disponible para asignar documentos.', 422);
                }

                $classification = $this->archiveClassificationOptions()
                    ->firstWhere('value', (string) $request->input('series_id'));

                if ($classification === null) {
                    throw new \RuntimeException('La serie o subserie documental seleccionada no esta habilitada.', 422);
                }

                $boxSeries = $this->normalizeArchiveClassificationLabel($box->series_name);
                $classificationNames = collect([$classification['label'], $classification['name']])
                    ->map(fn ($name) => $this->normalizeArchiveClassificationLabel($name));

                if (!$classificationNames->contains($boxSeries)) {
                    throw new \RuntimeException('La serie o subserie documental debe coincidir con la clasificacion asignada a la caja.', 422);
                }

                $documentYear = (int) $request->input('year');

                if ($box->start_year && $box->end_year && ($documentYear < (int) $box->start_year || $documentYear > (int) $box->end_year)) {
                    throw new \RuntimeException('El ano del documento debe estar dentro del periodo de la caja (' . $box->start_year . ' - ' . $box->end_year . ').', 422);
                }

                $code = trim((string) $request->input('code'));
                $codeExists = $box->documents()
                    ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
                    ->exists();

                if ($codeExists) {
                    throw new \RuntimeException('Ya existe un documento con ese codigo en la caja seleccionada.', 422);
                }

                $sortOrder = ((int) $box->documents()->max('sort_order')) + 1;
                $digitalDocument = null;

                if ($support !== 'Fisico' && $hasDigitalPayload) {
                    $fallbackName = ($box->transfer?->code ?? 'archivo-general') . '-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT);
                    $digitalDocument = $this->storeDigitalDocumentPayload($request->only([
                        'digital_file_name',
                        'digital_file_base64',
                    ]), $fallbackName);
                }

                return $box->documents()->create([
                    'code' => $code,
                    'name' => trim((string) $request->input('name')),
                    'series_label' => $classification['label'],
                    'support_type' => $support,
                    'year_label' => (string) $documentYear,
                    'pages_label' => (string) ((int) $request->input('pages')),
                    'digital_file_name' => $digitalDocument['name'] ?? null,
                    'digital_file_path' => $digitalDocument['path'] ?? null,
                    'sort_order' => $sortOrder,
                ]);
            });

            return response()->json([
                'message' => 'Documento creado correctamente.',
                'document' => $this->mapDocument($document->fresh(['box.transfer.unit', 'box.assignedBy.person'])),
            ], 201);
        } catch (\RuntimeException $exception) {
            if ((int) $exception->getCode() === 422) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            report($exception);

            return response()->json([
                'message' => 'No se pudo crear el documento. Intente nuevamente.',
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo crear el documento. Intente nuevamente.',
            ], 500);
        }
    }

    public function updateDocumentBox(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede reasignar documentos del archivo general.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'box_id' => ['required', 'integer', 'exists:transfer_boxes,id'],
        ], [
            'box_id.required' => 'Debe seleccionar la caja de destino.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la caja de destino no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $document = DB::transaction(function () use ($id, $request) {
                $document = TransferDocument::query()
                    ->with('box.transfer')
                    ->whereHas('box.transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->lockForUpdate()
                    ->findOrFail($id);

                $destinationBox = TransferBox::query()
                    ->with('transfer')
                    ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->lockForUpdate()
                    ->findOrFail((int) $request->input('box_id'));

                if ((int) $document->transfer_box_id === (int) $destinationBox->id) {
                    throw new \RuntimeException('El documento ya pertenece a la caja seleccionada.', 422);
                }

                $previousBox = $document->box;
                $sortOrder = ((int) $destinationBox->documents()->max('sort_order')) + 1;

                $document->update([
                    'transfer_box_id' => $destinationBox->id,
                    'sort_order' => $sortOrder,
                ]);

                $destinationBox->transfer?->events()->create([
                    'status_catalog_id' => $destinationBox->transfer?->workflow_status_id,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Documento reasignado',
                    'description' => 'El documento "' . $document->name . '" fue movido a la caja #' . $destinationBox->formattedBoxNumber() . '.',
                    'context' => [
                        'document_id' => $document->id,
                        'document_code' => $document->code,
                        'previous_box_id' => $previousBox?->id,
                        'previous_box_number' => $previousBox?->box_number,
                        'destination_box_id' => $destinationBox->id,
                        'destination_box_number' => $destinationBox->box_number,
                    ],
                    'occurred_at' => now(),
                ]);

                return $document->fresh(['box.transfer.unit', 'box.assignedBy.person']);
            });

            return response()->json([
                'message' => 'Documento reasignado correctamente.',
                'document' => $this->mapDocument($document),
            ]);
        } catch (\RuntimeException $exception) {
            if ((int) $exception->getCode() === 422) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            report($exception);

            return response()->json([
                'message' => 'No se pudo reasignar el documento.',
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo reasignar el documento.',
            ], 500);
        }
    }

    public function documents(Request $request): JsonResponse
    {
        if (!$this->canSearchArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede consultar el archivo general.',
            ], 403);
        }

        try {
            $term = trim((string) $request->query('q', ''));
            $searchDate = $this->parseSearchDate($term);

            $documentsBaseQuery = TransferDocument::query()
                ->whereHas('box.transfer', fn (Builder $query) => $query->whereNotNull('completed_at'));

            $totals = [
                'documents' => (clone $documentsBaseQuery)->count(),
                'boxes' => TransferBox::query()
                    ->whereHas('transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                    ->count(),
                'completed_transfers' => Transfer::query()
                    ->whereNotNull('completed_at')
                    ->count(),
            ];

            if ($term === '' && !$request->boolean('all')) {
                return response()->json([
                    'totals' => $totals,
                    'query' => $term,
                    'results_count' => 0,
                    'results' => [],
                ]);
            }

            $resultsQuery = (clone $documentsBaseQuery)
                ->with([
                    'box.assignedBy.person',
                    'box.transfer.unit',
                    'box.transfer.requester.person',
                    'box.transfer.completedBy.person',
                    'reservedBy.person',
                ]);

            if ($term !== '') {
                $resultsQuery->where(function (Builder $query) use ($term, $searchDate) {
                    $this->addSearchClauses($query, [
                        'transfer_box_documents.code',
                        'transfer_box_documents.name',
                        'transfer_box_documents.series_label',
                        'transfer_box_documents.support_type',
                        'transfer_box_documents.year_label',
                        'transfer_box_documents.pages_label',
                        'transfer_box_documents.digital_file_name',
                    ], $term);

                    $query->orWhereHas('box', function (Builder $boxQuery) use ($term, $searchDate) {
                        $this->addSearchClauses($boxQuery, [
                            'transfer_boxes.box_code',
                            'transfer_boxes.series_name',
                            'transfer_boxes.box_number',
                            'transfer_boxes.title',
                            'transfer_boxes.period_label',
                            'transfer_boxes.location_code',
                            'transfer_boxes.content_description',
                        ], $term);

                        if ($searchDate !== null) {
                            $boxQuery->orWhereDate('transfer_boxes.assigned_at', $searchDate);
                        }
                    });

                    $query->orWhereHas('box.transfer', function (Builder $transferQuery) use ($term, $searchDate) {
                        $this->addSearchClauses($transferQuery, [
                            'transfers.code',
                            'transfers.status',
                            'transfers.request_date',
                            'transfers.requested_at',
                            'transfers.completed_at',
                            'transfers.description',
                            'transfers.observation',
                        ], $term);

                        if ($searchDate !== null) {
                            $transferQuery
                                ->orWhereDate('transfers.request_date', $searchDate)
                                ->orWhereDate('transfers.requested_at', $searchDate)
                                ->orWhereDate('transfers.authorized_at', $searchDate)
                                ->orWhereDate('transfers.completed_at', $searchDate)
                                ->orWhereDate('transfers.scheduled_for', $searchDate);
                        }
                    });

                    $query->orWhereHas('box.transfer.unit', function (Builder $unitQuery) use ($term) {
                        $this->addSearchClauses($unitQuery, [
                            'units.code',
                            'units.name',
                        ], $term);
                    });
                });
            }

            $resultsCount = (clone $resultsQuery)->count();
            $loanedDocuments = $this->loanedDocumentMap();
            $results = $resultsQuery
                ->orderBy('transfer_box_documents.name')
                ->limit(100)
                ->get()
                ->map(fn (TransferDocument $document) => $this->mapDocument($document, $loanedDocuments))
                ->values();

            return response()->json([
                'totals' => $totals,
                'query' => $term,
                'results_count' => $resultsCount,
                'results_limit' => 100,
                'results' => $results,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo consultar el archivo general.',
            ], 500);
        }
    }

    public function reservedDocuments(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede consultar documentos reservados.',
            ], 403);
        }

        try {
            $term = trim((string) $request->query('q', ''));
            $query = TransferDocument::query()
                ->with([
                    'reservedBy.person',
                    'box.assignedBy.person',
                    'box.transfer.unit',
                    'box.transfer.requester.person',
                    'box.transfer.completedBy.person',
                ])
                ->where('is_reserved', true)
                ->whereHas('box.transfer', fn (Builder $transferQuery) => $transferQuery->whereNotNull('completed_at'));

            if ($term !== '') {
                $query->where(function (Builder $searchQuery) use ($term) {
                    $this->addSearchClauses($searchQuery, [
                        'transfer_box_documents.code',
                        'transfer_box_documents.name',
                        'transfer_box_documents.series_label',
                        'transfer_box_documents.support_type',
                        'transfer_box_documents.year_label',
                    ], $term);

                    $searchQuery->orWhereHas('box', function (Builder $boxQuery) use ($term) {
                        $this->addSearchClauses($boxQuery, [
                            'transfer_boxes.box_code',
                            'transfer_boxes.box_number',
                            'transfer_boxes.series_name',
                            'transfer_boxes.location_code',
                            'transfer_boxes.title',
                        ], $term);
                    });

                    $searchQuery->orWhereHas('box.transfer.unit', function (Builder $unitQuery) use ($term) {
                        $this->addSearchClauses($unitQuery, [
                            'units.code',
                            'units.name',
                        ], $term);
                    });
                });
            }

            $documents = $query
                ->orderByDesc('reserved_at')
                ->orderBy('transfer_box_documents.name')
                ->get()
                ->map(fn (TransferDocument $document) => $this->mapDocument($document))
                ->values();

            return response()->json([
                'documents' => $documents,
                'count' => $documents->count(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron consultar los documentos reservados.',
            ], 500);
        }
    }

    public function storeReservedDocument(Request $request): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede marcar documentos reservados.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'document_id' => ['required', 'integer', 'exists:transfer_box_documents,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Seleccione un documento válido para reservar.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $document = TransferDocument::query()
                ->with([
                    'reservedBy.person',
                    'box.assignedBy.person',
                    'box.transfer.unit',
                    'box.transfer.requester.person',
                    'box.transfer.completedBy.person',
                ])
                ->whereKey($request->integer('document_id'))
                ->whereHas('box.transfer', fn (Builder $query) => $query->whereNotNull('completed_at'))
                ->first();

            if ($document === null) {
                return response()->json([
                    'message' => 'El documento seleccionado no esta disponible en el archivo general.',
                ], 422);
            }

            if ($document->is_reserved) {
                return response()->json([
                    'message' => 'El documento ya esta marcado como reservado.',
                ], 422);
            }

            $document->forceFill([
                'is_reserved' => true,
                'reserved_by_user_id' => $request->user()->id,
                'reserved_at' => now(),
            ])->save();

            $document->refresh()->load([
                'reservedBy.person',
                'box.assignedBy.person',
                'box.transfer.unit',
                'box.transfer.requester.person',
                'box.transfer.completedBy.person',
            ]);

            return response()->json([
                'message' => 'El documento "' . $document->name . '" fue agregado a reservados.',
                'document' => $this->mapDocument($document),
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo agregar el documento a reservados.',
            ], 500);
        }
    }

    public function destroyReservedDocument(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageArchive($request)) {
            return response()->json([
                'message' => 'Solo el personal UGDA puede quitar documentos reservados.',
            ], 403);
        }

        try {
            $document = TransferDocument::query()
                ->whereKey($id)
                ->where('is_reserved', true)
                ->first();

            if ($document === null) {
                return response()->json([
                    'message' => 'El documento reservado no fue encontrado.',
                ], 404);
            }

            $documentName = $document->name;
            $document->forceFill([
                'is_reserved' => false,
                'reserved_by_user_id' => null,
                'reserved_at' => null,
            ])->save();

            return response()->json([
                'message' => 'El documento "' . $documentName . '" fue removido de reservados.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo remover el documento de reservados.',
            ], 500);
        }
    }

    private function storeDigitalDocumentPayload(array $documentPayload, string $fallbackName): ?array
    {
        $base64Content = trim((string) ($documentPayload['digital_file_base64'] ?? ''));

        if ($base64Content === '') {
            return null;
        }

        $originalName = trim((string) ($documentPayload['digital_file_name'] ?? ''));
        $fileName = $originalName !== '' ? $this->sanitizeFileName($originalName) : $this->sanitizeFileName($fallbackName . '.pdf');
        $decodedContent = base64_decode($base64Content, true);

        if ($decodedContent === false) {
            return null;
        }

        $storagePath = 'uploads/' . $fileName;
        Storage::disk('local')->put($storagePath, $decodedContent);

        return [
            'name' => $fileName,
            'path' => $storagePath,
        ];
    }

    private function sanitizeFileName(string $fileName): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME) ?: 'documento';
        $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: 'pdf';
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'documento';
        $safeExtension = preg_replace('/[^A-Za-z0-9]+/', '', $extension) ?: 'pdf';

        return $safeName . '.' . $safeExtension;
    }

    private function parseSearchDate(string $term): ?string
    {
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!' . $format, $term);
            } catch (Throwable) {
                continue;
            }

            if ($date !== false && $date->format($format) === $term) {
                return $date->toDateString();
            }
        }

        return null;
    }

    private function addSearchClauses(Builder $query, array $columns, string $term): void
    {
        $like = '%' . mb_strtolower($term) . '%';

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}("LOWER(CAST({$column} AS TEXT)) LIKE ?", [$like]);
        }
    }

    private function mapDocument(TransferDocument $document, array $loanedDocuments = []): array
    {
        $box = $document->box;
        $transfer = $box?->transfer;
        $unit = $transfer?->unit;
        $boxCode = $box?->boxCode($transfer?->code);
        $loan = $loanedDocuments[(int) $document->id] ?? null;
        $hasDigitalFile = in_array($document->support_type, ['Digital', 'Mixto'], true)
            && !empty($document->digital_file_name);
        $boxNumber = $box?->box_number !== null
            ? str_pad((string) $box->box_number, 3, '0', STR_PAD_LEFT)
            : null;

        return [
            'id' => $document->id,
            'title' => $document->name,
            'code' => $document->code,
            'series' => $document->series_label,
            'support' => $document->support_type,
            'year' => $document->year_label,
            'pages' => $document->pages_label,
            'created_at' => optional($document->created_at)->toDateTimeString(),
            'created_at_label' => optional($document->created_at)->format('d/m/Y H:i'),
            'is_reserved' => (bool) $document->is_reserved,
            'reserved_at' => optional($document->reserved_at)->toDateTimeString(),
            'reserved_at_label' => optional($document->reserved_at)->format('d/m/Y H:i'),
            'reserved_by' => $this->formatUserName($document->reservedBy),
            'is_loaned' => $loan !== null,
            'loan' => $loan,
            'digital_available' => $hasDigitalFile,
            'digital_file' => $hasDigitalFile ? $document->digital_file_name : null,
            'digital_url' => $hasDigitalFile && $transfer?->code && $document->code
                ? SignedPdfUrl::transferDocument($transfer->code, SignedPdfUrl::transferDocumentReference($document->id))
                : null,
            'box' => [
                'id' => $box?->id,
                'number' => $boxNumber,
                'code' => $boxCode,
                'title' => $box?->title,
                'period' => $box?->period_label,
                'start_year' => $box?->start_year,
                'end_year' => $box?->end_year,
                'location' => $box?->location_code,
                'series' => $box?->series_name,
                'content_description' => $box?->content_description,
                'assigned_at_label' => $box?->assigned_at?->format('d/m/Y H:i'),
                'assigned_by' => $this->formatUserName($box?->assignedBy),
            ],
            'unit' => [
                'id' => $unit?->id,
                'code' => $unit?->code,
                'name' => $unit?->name,
            ],
            'transfer' => [
                'id' => $transfer?->id,
                'code' => $transfer?->code,
                'request_date_label' => optional($transfer?->requested_at)->format('d/m/Y H:i')
                    ?: optional($transfer?->request_date)->format('d/m/Y'),
                'completed_at' => optional($transfer?->completed_at)->toDateTimeString(),
                'completed_at_label' => optional($transfer?->completed_at)->format('d/m/Y H:i'),
                'responsible' => $this->formatUserName($transfer?->completedBy) ?: $this->formatUserName($transfer?->requester),
            ],
        ];
    }

    private function loanedDocumentMap(): array
    {
        return LoanDocument::query()
            ->with(['loan.events'])
            ->where('selected_for_loan', true)
            ->where('returned', false)
            ->get()
            ->reduce(function (array $loanedDocuments, LoanDocument $loanDocument): array {
                $documentMapEvent = $loanDocument->loan?->events
                    ->first(fn ($event) => is_array($event->context) && isset($event->context['document_map']));

                foreach ($documentMapEvent?->context['document_map'] ?? [] as $mapping) {
                    if ((int) ($mapping['loan_document_id'] ?? 0) !== (int) $loanDocument->id) {
                        continue;
                    }

                    $transferDocumentId = (int) ($mapping['transfer_document_id'] ?? 0);

                    if ($transferDocumentId > 0) {
                        $loanedDocuments[$transferDocumentId] = [
                            'number' => $loanDocument->loan?->number,
                            'status' => 'Prestado',
                        ];
                    }

                    break;
                }

                return $loanedDocuments;
            }, []);
    }

    private function formatUserName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $person = $user->person;

        if ($person === null) {
            return $user->email;
        }

        $name = trim(collect([
            $person->first_name,
            $person->second_name,
            $person->first_last_name,
            $person->second_last_name,
        ])->filter()->implode(' '));

        return $name !== '' ? $name : $user->email;
    }

    private function mapBox(TransferBox $box): array
    {
        $transfer = $box->transfer;
        $unit = $transfer?->unit;
        $units = $transfer?->units;

        if ($units === null || $units->isEmpty()) {
            $units = $unit ? collect([$unit]) : collect();
        }

        $boxCode = $box->boxCode($transfer?->code);

        return [
            'id' => $box->id,
            'number' => str_pad((string) $box->box_number, 3, '0', STR_PAD_LEFT),
            'box_number' => $box->box_number,
            'box_code' => $boxCode,
            'boxCode' => $boxCode,
            'code' => $boxCode,
            'title' => $box->title,
            'series' => $box->series_name,
            'unit' => [
                'id' => $unit?->id,
                'code' => $unit?->code,
                'name' => $unit?->name,
            ],
            'units' => $units->map(fn (Unit $associatedUnit) => [
                'id' => $associatedUnit->id,
                'code' => $associatedUnit->code,
                'name' => $associatedUnit->name,
            ])->values()->all(),
            'units_label' => $units->pluck('name')->implode('; '),
            'period' => $box->period_label,
            'start_year' => $box->start_year,
            'end_year' => $box->end_year,
            'location' => $box->location_code,
            'documents_count' => $box->documents->count(),
            'can_delete' => $transfer?->view_mode === 'archive',
            'created_at_label' => $box->created_at?->format('d/m/Y H:i'),
            'assigned_by' => $box->assignedBy
                ? trim(collect([
                    $box->assignedBy?->person?->first_name,
                    $box->assignedBy?->person?->first_last_name,
                ])->filter()->implode(' '))
                : null,
            'documents' => $box->documents
                ->map(function (TransferDocument $document) use ($transfer) {
                    $hasDigitalFile = in_array($document->support_type, ['Digital', 'Mixto'], true)
                        && !empty($document->digital_file_name);

                    return [
                        'id' => $document->id,
                        'series' => $document->series_label,
                        'code' => $document->code,
                        'name' => $document->name,
                        'year' => $document->year_label,
                        'support' => $document->support_type,
                        'pages' => $document->pages_label,
                        'created_at_label' => optional($document->created_at)->format('d/m/Y H:i'),
                        'digital_available' => $hasDigitalFile,
                        'digital_file' => $hasDigitalFile ? $document->digital_file_name : null,
                        'digital_url' => $hasDigitalFile && $transfer?->code
                            ? SignedPdfUrl::transferDocument(
                                $transfer->code,
                                SignedPdfUrl::transferDocumentReference($document->id),
                            )
                            : null,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    private function canManageArchive(Request $request): bool
    {
        return in_array($request->user()?->activeProfile()?->name, ['Administrador', 'Usuario UGDA'], true);
    }

    /**
     * El Archivo General puede clasificar cajas con cualquier elemento activo
     * del catalogo, sin filtrar por las unidades asociadas a la clasificacion.
     */
    private function archiveClassificationOptions()
    {
        return DocumentarySeries::query()
            ->where('is_active', true)
            ->with(['subseries' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('code')])
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->flatMap(function (DocumentarySeries $series) {
                $seriesLabel = $series->code . ' - ' . $series->name;
                $options = collect([[
                    'label' => $seriesLabel,
                    'value' => 'series:' . $series->id,
                    'code' => $series->code,
                    'name' => $series->name,
                    'type' => 'series',
                    'series_id' => $series->id,
                    'subseries_id' => null,
                ]]);

                return $options->merge($series->subseries->map(fn (DocumentarySubseries $subseries) => [
                    'label' => $seriesLabel . ' / ' . $subseries->code . ' - ' . $subseries->name,
                    'value' => 'subseries:' . $subseries->id,
                    'code' => $subseries->code,
                    'name' => $subseries->name,
                    'type' => 'subseries',
                    'series_id' => $series->id,
                    'subseries_id' => $subseries->id,
                ]));
            })
            ->values();
    }

    private function normalizeArchiveClassificationLabel(?string $value): string
    {
        $normalized = str_replace(['–', '—', '−'], '-', trim((string) $value));

        return mb_strtolower(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    private function normalizeArchiveUnitIds(Request $request): array
    {
        $unitIds = $request->input('unit_ids');

        if (!is_array($unitIds)) {
            $unitIds = $request->filled('unit_id') ? [$request->input('unit_id')] : [];
        }

        return collect($unitIds)
            ->map(fn ($unitId) => (int) $unitId)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function canSearchArchive(Request $request): bool
    {
        return in_array($request->user()?->activeProfile()?->name, ['Administrador', 'Usuario UGDA'], true);
    }

    private function nextArchiveTransferCode(): string
    {
        $year = now()->format('Y');
        $prefix = 'AG-' . $year . '-';
        $last = Transfer::query()
            ->where('code', 'like', $prefix . '%')
            ->pluck('code')
            ->map(fn ($code) => (int) str_replace($prefix, '', (string) $code))
            ->max();

        return $prefix . str_pad((string) (($last ?? 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    private function nextArchiveBoxCode(?Carbon $date = null): string
    {
        $date ??= now();
        $prefix = 'C-' . $date->format('dmy') . '-';
        $last = TransferBox::query()
            ->whereNotNull('box_code')
            ->where('box_code', 'like', $prefix . '%')
            ->whereHas('transfer', fn (Builder $query) => $query->where('view_mode', 'archive'))
            ->whereDate('transfer_boxes.created_at', $date->toDateString())
            ->pluck('box_code')
            ->map(function ($code) use ($prefix) {
                $suffix = substr((string) $code, strlen($prefix));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max();

        return $prefix . str_pad((string) (($last ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }

    private function lockArchiveBoxCodeSequence(Carbon $date): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT pg_advisory_xact_lock(hashtext(?))", [
                'archive-box-code-' . $date->toDateString(),
            ]);
        }
    }

    private function locationOptions(): array
    {
        $offices = \App\Models\PhysicalLocationOffice::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($office) => [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
            ])
            ->values();

        $aisles = \App\Models\PhysicalLocationAisle::query()
            ->with('office:id,name,is_active')
            ->where('is_active', true)
            ->whereHas('office', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'physical_location_office_id', 'name', 'code'])
            ->map(fn ($aisle) => [
                'id' => $aisle->id,
                'office_id' => $aisle->physical_location_office_id,
                'office_name' => $aisle->office?->name,
                'name' => $aisle->name,
                'code' => $aisle->code,
            ])
            ->values();

        $shelves = PhysicalLocationShelf::query()
            ->with('aisle.office:id,name,is_active')
            ->where('is_active', true)
            ->whereHas('aisle', fn ($query) => $query->where('is_active', true)->whereHas('office', fn ($office) => $office->where('is_active', true)))
            ->orderBy('name')
            ->get(['id', 'physical_location_aisle_id', 'name', 'code'])
            ->map(fn (PhysicalLocationShelf $shelf) => [
                'id' => $shelf->id,
                'aisle_id' => $shelf->physical_location_aisle_id,
                'aisle_name' => $shelf->aisle?->name,
                'office_id' => $shelf->aisle?->physical_location_office_id,
                'office_name' => $shelf->aisle?->office?->name,
                'name' => $shelf->name,
                'code' => $shelf->code,
            ])
            ->values();

        return [
            'offices' => $offices,
            'aisles' => $aisles,
            'shelves' => $shelves,
        ];
    }

    private function findValidShelf(int $officeId, int $aisleId, int $shelfId): ?PhysicalLocationShelf
    {
        return PhysicalLocationShelf::query()
            ->with('aisle.office')
            ->where('id', $shelfId)
            ->where('is_active', true)
            ->where('physical_location_aisle_id', $aisleId)
            ->whereHas('aisle', fn ($query) => $query
                ->where('id', $aisleId)
                ->where('is_active', true)
                ->where('physical_location_office_id', $officeId)
                ->whereHas('office', fn ($office) => $office->where('is_active', true)))
            ->first();
    }

    private function buildLocationCode(PhysicalLocationShelf $shelf): string
    {
        $shelf->loadMissing('aisle.office');

        return collect([
            $shelf->aisle?->office?->code,
            $shelf->aisle?->code,
            $shelf->code,
        ])
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->implode('-');
    }
}
