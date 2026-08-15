<?php

namespace App\Http\Controllers;

use App\Models\DocumentarySeries;
use App\Models\RequestStatusCatalog;
use App\Models\PhysicalLocationShelf;
use App\Models\Transfer;
use App\Services\SystemNotificationService;
use App\Support\RequestNumberGenerator;
use App\Support\SignedPdfUrl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;
use ZipArchive;

class TransferController extends Controller
{
    public function nextCode(Request $request): JsonResponse
    {
        try {
            $requestDate = $request->filled('request_date')
                ? Carbon::parse($request->input('request_date'), config('app.timezone'))
                : now();

            return response()->json(['code' => RequestNumberGenerator::transfer($requestDate)]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo generar el correlativo de transferencia.',
            ], 500);
        }
    }

    public function documentImportTemplate()
    {
        $headers = [
            'Serie del documento',
            'Nombre del documento',
            'Código',
            'Soporte',
            'Año',
            'Cantidad de páginas',
        ];

        $path = $this->createDocumentImportWorkbook([$headers]);

        return response()
            ->download($path, 'plantilla-importacion-documentos.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    public function importDocuments(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
            'current_box_series' => ['nullable', 'string', 'max:2000'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'box_year_start' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'box_year_end' => ['nullable', 'integer', 'min:1900', 'max:2100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe seleccionar un archivo Excel válido en formato .xlsx.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $rows = $this->readXlsxRows($request->file('file')->getRealPath());

            if (count($rows) < 2) {
                return response()->json([
                    'message' => 'El archivo no contiene documentos para importar.',
                ], 422);
            }

            $headers = array_map(fn ($header) => $this->normalizeImportKey($header), $rows[0]);
            $documents = [];
            $currentBoxSeries = trim((string) $request->input('current_box_series', ''));
            $currentBoxSeriesLabels = $this->splitSeriesPayloadLabels($currentBoxSeries);

            foreach (array_slice($rows, 1) as $index => $row) {
                if (!collect($row)->contains(fn ($cell) => trim((string) $cell) !== '')) {
                    continue;
                }

                $series = $this->getImportValue($row, $headers, ['seriedeldocumento']);
                $name = $this->getImportValue($row, $headers, ['nombredeldocumento']);
                $code = $this->getImportValue($row, $headers, ['codigo', 'cdigo']);
                $support = $this->resolveSupportLabel($this->getImportValue($row, $headers, ['soporte']));
                $year = $this->getImportValue($row, $headers, ['ano', 'ao']);
                $pages = $this->getImportValue($row, $headers, ['cantidaddepaginas', 'cantidaddepginas']);

                if ($name === '' || $code === '' || $support === '' || $year === '') {
                    return response()->json([
                        'message' => 'La fila ' . ($index + 2) . ' debe tener Nombre del documento, Código, Año y Soporte válido.',
                    ], 422);
                }

                if ($series === '') {
                    if (count($currentBoxSeriesLabels) !== 1) {
                        return response()->json([
                            'message' => 'La fila ' . ($index + 2) . ' debe indicar la Serie del documento.',
                        ], 422);
                    }

                    $series = $currentBoxSeriesLabels[0];
                }

                $documents[] = [
                    'series' => $series,
                    'name' => $name,
                    'code' => $code,
                    'date' => $year,
                    'pages' => $pages,
                    'support' => $support,
                ];
            }

            if (count($documents) === 0) {
                return response()->json([
                    'message' => 'El archivo no contiene filas con documentos.',
                ], 422);
            }

            if ($request->filled('unit_id')) {
                $unitId = (int) $request->input('unit_id');

                if (!$request->user()->units()->where('units.id', $unitId)->exists()) {
                    return response()->json([
                        'message' => 'No puede importar documentos para una unidad que no tiene asignada.',
                    ], 403);
                }

                $businessError = $this->validateTransferBoxBusinessRules([[
                    'series' => $currentBoxSeries,
                    'year_start' => $request->input('box_year_start'),
                    'year_end' => $request->input('box_year_end'),
                    'box_number' => 1,
                    'documents_list' => $documents,
                ]], $unitId);

                if ($businessError !== null) {
                    return response()->json(['message' => $businessError], 422);
                }
            }

            return response()->json([
                'documents' => $documents,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo procesar el archivo Excel. Revise que use la plantilla vigente.',
            ], 500);
        }
    }

    public function authorizeByUnit(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los comentarios no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transfer = Transfer::query()
                ->with(['authorizationStatus', 'workflowStatus'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canUnitDirectorAct($request, $transfer)) {
                return response()->json([
                    'message' => 'Solo la jefatura de la unidad productora puede autorizar esta solicitud.',
                ], 403);
            }

            if ($transfer->authorizationStatus?->code !== 'transfer_auth_pending' || $transfer->workflowStatus?->code !== 'transfer_status_pending') {
                return response()->json([
                    'message' => 'Esta transferencia ya no esta pendiente de autorización por jefatura.',
                ], 422);
            }

            $comments = trim($request->string('comments')->toString());
            $authAuthorized = RequestStatusCatalog::query()->where('code', 'transfer_auth_authorized')->value('id');

            DB::transaction(function () use ($request, $transfer, $authAuthorized, $comments) {
                $transfer->update([
                    'authorization_status_id' => $authAuthorized,
                    'authorized_by_user_id' => $request->user()->id,
                    'authorized_at' => now(),
                    'view_mode' => 'review',
                    'box_display_state' => 'collapsed',
                ]);

                $transfer->events()->create([
                    'status_catalog_id' => $authAuthorized,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Autorizado',
                    'description' => $comments !== ''
                        ? 'Solicitud autorizada por jefatura de unidad. Comentario: ' . $comments
                        : 'Solicitud autorizada por jefatura de unidad.',
                    'context' => [
                        'decision_scope' => 'unit',
                        'comments' => $comments !== '' ? $comments : null,
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->transferAuthorizedByUnit($transfer->fresh(['unit']), $request->user());

            return response()->json([
                'message' => 'Transferencia autorizada por jefatura de unidad.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo autorizar la transferencia.',
            ], 500);
        }
    }

    public function denyByUnit(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe proporcionar un motivo de denegación.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transfer = Transfer::query()
                ->with(['authorizationStatus', 'workflowStatus'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canUnitDirectorAct($request, $transfer)) {
                return response()->json([
                    'message' => 'Solo la jefatura de la unidad productora puede denegar esta solicitud.',
                ], 403);
            }

            if ($transfer->authorizationStatus?->code !== 'transfer_auth_pending' || $transfer->workflowStatus?->code !== 'transfer_status_pending') {
                return response()->json([
                    'message' => 'Esta transferencia ya no esta pendiente de autorización por jefatura.',
                ], 422);
            }

            $reason = trim($request->string('reason')->toString());
            $authDenied = RequestStatusCatalog::query()->where('code', 'transfer_auth_denied')->value('id');
            $statusDenied = RequestStatusCatalog::query()->where('code', 'transfer_status_denied')->value('id');

            DB::transaction(function () use ($request, $transfer, $authDenied, $statusDenied, $reason) {
                $transfer->update([
                    'authorization_status_id' => $authDenied,
                    'workflow_status_id' => $statusDenied,
                    'authorized_by_user_id' => $request->user()->id,
                    'authorized_at' => now(),
                    'status' => 'Rechazada',
                    'view_mode' => 'detail',
                    'show_print_card' => false,
                    'box_display_state' => 'collapsed',
                ]);

                $transfer->events()->create([
                    'status_catalog_id' => $authDenied,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'decision',
                    'title' => 'Denegado por jefatura',
                    'description' => $reason,
                    'context' => [
                        'reason_label' => 'Motivo de denegación:',
                        'reason' => $reason,
                        'decision_scope' => 'unit',
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->transferDeniedByUnit($transfer->fresh(), $request->user(), $reason);

            return response()->json([
                'message' => 'Transferencia denegada por jefatura de unidad.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo denegar la transferencia.',
            ], 500);
        }
    }

    public function editData(Request $request, string $number): JsonResponse
    {
        try {
            $transfer = Transfer::query()
                ->with(['authorizationStatus', 'workflowStatus', 'requester.person', 'events.actor.person', 'boxes.documents'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canRequesterCorrectObserved($request, $transfer)) {
                return response()->json([
                    'message' => 'Solo la unidad solicitante puede editar una transferencia observada de su unidad.',
                ], 403);
            }

            if (!in_array($transfer->workflowStatus?->code, ['transfer_status_observed', 'transfer_status_physical_observed'], true)) {
                return response()->json([
                        'message' => 'Esta transferencia no está en un estado observable para corrección.',
                ], 422);
            }

            return response()->json([
                'transfer' => [
                    'code' => $transfer->code,
                    'request_date' => optional($transfer->request_date)->format('Y-m-d'),
                    'unit_id' => $transfer->unit_id,
                    'responsible' => trim(collect([
                        $transfer->requester?->person?->first_name,
                        $transfer->requester?->person?->second_name,
                        $transfer->requester?->person?->first_last_name,
                        $transfer->requester?->person?->second_last_name,
                    ])->filter()->implode(' ')),
                    'description' => $transfer->description,
                ],
                'boxes' => $transfer->boxes->map(fn ($box) => [
                    'id_temp' => 'box-' . $box->id,
                    'series' => $box->series_name,
                    'year_start' => (string) $box->start_year,
                    'year_end' => (string) $box->end_year,
                    'box_number' => (int) $box->box_number,
                    'documents_count' => $box->documents->count(),
                    'documents_list' => $box->documents->map(fn ($document) => [
                        'id_doc' => 'doc-' . $document->id,
                        'series' => $document->series_label,
                        'name' => $document->name,
                        'code' => $document->code,
                        'date' => $document->year_label,
                        'pages' => $document->pages_label,
                        'support' => $document->support_type,
                        'file_name' => $this->supportRequiresDigitalFile($document->support_type)
                            ? $document->digital_file_name
                            : null,
                        'file_url' => $this->supportRequiresDigitalFile($document->support_type) && $document->digital_file_name
                            ? SignedPdfUrl::transferDocument($transfer->code, SignedPdfUrl::transferDocumentReference($document->id))
                            : null,
                    ])->values()->all(),
                ])->values()->all(),
                'observations' => $transfer->events
                    ->where('event_type', 'observation')
                    ->sortBy('occurred_at')
                    ->map(fn ($event) => [
                        'actor' => trim(collect([
                            $event->actor?->person?->first_name,
                            $event->actor?->person?->second_name,
                            $event->actor?->person?->first_last_name,
                            $event->actor?->person?->second_last_name,
                        ])->filter()->implode(' ')) ?: 'UGDA',
                        'dateTime' => optional($event->occurred_at)->format('d/m/Y H:i'),
                        'message' => $event->description,
                    ])->values()->all(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar la transferencia para edicion.',
            ], 500);
        }
    }

    public function resubmitCorrection(Request $request, string $number): JsonResponse
    {
        $this->normalizeTransferPayload($request);

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:60'],
            'request_date' => ['required', 'date'],
            'unit_id' => ['required', 'exists:units,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'boxes' => ['required', 'array', 'min:1'],
            'boxes.*.series' => ['required', 'string', 'max:2000'],
            'boxes.*.year_start' => ['required', 'integer', 'min:1900', 'max:2100'],
            'boxes.*.year_end' => ['required', 'integer', 'min:1900', 'max:2100'],
            'boxes.*.box_number' => ['required', 'integer', 'min:1'],
            'boxes.*.documents_list' => ['required', 'array', 'min:1'],
            'boxes.*.documents_list.*.series' => ['nullable', 'string', 'max:2000'],
            'boxes.*.documents_list.*.name' => ['required', 'string', 'max:255'],
            'boxes.*.documents_list.*.code' => ['nullable', 'string', 'max:120'],
            'boxes.*.documents_list.*.date' => ['required', 'string', 'max:50'],
            'boxes.*.documents_list.*.pages' => ['nullable', 'string', 'max:50'],
            'boxes.*.documents_list.*.support' => ['required', 'string', 'max:30'],
            'boxes.*.documents_list.*.file_name' => ['nullable', 'string', 'max:255'],
            'document_files' => ['nullable', 'array'],
            'document_files.*.*' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'correction_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la transferencia no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transfer = Transfer::query()
                ->with(['authorizationStatus', 'workflowStatus', 'boxes.documents'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canRequesterCorrectObserved($request, $transfer)) {
                return response()->json([
                    'message' => 'Solo la unidad solicitante puede corregir esta transferencia.',
                ], 403);
            }

            if (!$request->user()->units()->where('units.id', (int) $request->input('unit_id'))->exists()) {
                return response()->json([
                    'message' => 'No puede reenviar la transferencia para una unidad que no tiene asignada.',
                ], 403);
            }

            $previousWorkflowCode = $transfer->workflowStatus?->code;

            if (!in_array($previousWorkflowCode, ['transfer_status_observed', 'transfer_status_physical_observed'], true)) {
                return response()->json([
                        'message' => 'Esta transferencia no está en un estado observable para corrección.',
                ], 422);
            }

            if ($previousWorkflowCode === 'transfer_status_physical_observed' && blank($request->input('correction_notes'))) {
                return response()->json([
                    'message' => 'Debe indicar cómo fueron atendidas las observaciónes de revisión física.',
                ], 422);
            }

            $businessError = $this->validateTransferBoxBusinessRules(
                $request->input('boxes', []),
                (int) $request->input('unit_id')
            );

            if ($businessError !== null) {
                return response()->json(['message' => $businessError], 422);
            }

            DB::transaction(function () use ($request, $transfer, $previousWorkflowCode) {
                $requestDate = Carbon::parse($request->input('request_date'), config('app.timezone'));
                $requestedAt = now();
                $correctionNotes = trim($request->string('correction_notes')->toString());
                $isPhysicalCorrection = $previousWorkflowCode === 'transfer_status_physical_observed';
                $authStatus = $isPhysicalCorrection
                    ? RequestStatusCatalog::query()->where('code', 'transfer_auth_authorized')->value('id')
                    : RequestStatusCatalog::query()->where('code', 'transfer_auth_pending')->value('id');
                $workflowStatus = $isPhysicalCorrection
                    ? RequestStatusCatalog::query()->where('code', 'transfer_status_subsanated')->value('id')
                    : RequestStatusCatalog::query()->where('code', 'transfer_status_pending')->value('id');

                $transfer->update([
                    'unit_id' => (int) $request->input('unit_id'),
                    'request_date' => $requestDate->toDateString(),
                    'requested_at' => $requestedAt,
                    'status' => $isPhysicalCorrection ? 'Aprobada' : 'Enviada',
                    'authorization_status_id' => $authStatus,
                    'workflow_status_id' => $workflowStatus,
                    'authorized_by_user_id' => $isPhysicalCorrection ? $transfer->authorized_by_user_id : null,
                    'authorized_at' => $isPhysicalCorrection ? $transfer->authorized_at : null,
                    'completed_by_user_id' => null,
                    'completed_at' => null,
                    'scheduled_for' => $isPhysicalCorrection ? $transfer->scheduled_for : null,
                    'view_mode' => $isPhysicalCorrection ? 'review' : 'detail',
                    'box_display_state' => 'collapsed',
                    'show_print_card' => false,
                    'description' => $request->input('description'),
                ]);

                $existingDigitalFiles = $this->existingDigitalFilesByDocumentCode($transfer);

                $transfer->boxes->each(function ($box) {
                    $box->documents()->delete();
                });
                $transfer->boxes()->delete();
                $this->syncTransferBoxes($transfer, $request->input('boxes', []), $request, $existingDigitalFiles);

                $transfer->events()->create([
                    'status_catalog_id' => $workflowStatus,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => $isPhysicalCorrection ? 'Subsanada' : 'Corregido y reenviado',
                    'description' => $isPhysicalCorrection
                        ? 'Solicitud corregida por la unidad solicitante y reenviada a UGDA para validar las correcciones.'
                        : 'Solicitud corregida por la unidad solicitante y reenviada a jefatura para autorización.',
                    'context' => [
                        'previous_status' => $previousWorkflowCode,
                        'correction_notes' => $correctionNotes !== '' ? $correctionNotes : null,
                    ],
                    'occurred_at' => now(),
                ]);
            });

            if ($previousWorkflowCode === 'transfer_status_physical_observed') {
                app(SystemNotificationService::class)->transferCorrectionSubmitted($transfer->fresh(['unit']), $request->user());
            } else {
                app(SystemNotificationService::class)->transferResubmitted($transfer->fresh(['unit']), $request->user());
            }

            return response()->json([
                'message' => $previousWorkflowCode === 'transfer_status_physical_observed'
                    ? 'Solicitud corregida y reenviada a UGDA para revisión.'
                    : 'Solicitud corregida y reenviada a jefatura.',
                'number' => $transfer->code,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo guardar la corrección de la transferencia.',
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeTransferPayload($request);

        $validator = Validator::make($request->all(), [
            'code' => ['nullable', 'string', 'max:60'],
            'request_date' => ['required', 'date'],
            'unit_id' => ['required', 'exists:units,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'boxes' => ['required', 'array', 'min:1'],
            'boxes.*.series' => ['required', 'string', 'max:2000'],
            'boxes.*.year_start' => ['required', 'integer', 'min:1900', 'max:2100'],
            'boxes.*.year_end' => ['required', 'integer', 'min:1900', 'max:2100'],
            'boxes.*.box_number' => ['required', 'integer', 'min:1'],
            'boxes.*.documents_list' => ['required', 'array', 'min:1'],
            'boxes.*.documents_list.*.series' => ['nullable', 'string', 'max:2000'],
            'boxes.*.documents_list.*.name' => ['required', 'string', 'max:255'],
            'boxes.*.documents_list.*.code' => ['nullable', 'string', 'max:120'],
            'boxes.*.documents_list.*.date' => ['required', 'string', 'max:50'],
            'boxes.*.documents_list.*.pages' => ['nullable', 'string', 'max:50'],
            'boxes.*.documents_list.*.support' => ['required', 'string', 'max:30'],
            'boxes.*.documents_list.*.file_name' => ['nullable', 'string', 'max:255'],
            'document_files' => ['nullable', 'array'],
            'document_files.*.*' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la transferencia no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$request->user()->units()->where('units.id', (int) $request->input('unit_id'))->exists()) {
            return response()->json([
                'message' => 'No puede crear una transferencia para una unidad que no tiene asignada.',
            ], 403);
        }

        $businessError = $this->validateTransferBoxBusinessRules(
            $request->input('boxes', []),
            (int) $request->input('unit_id')
        );

        if ($businessError !== null) {
            return response()->json(['message' => $businessError], 422);
        }

        try {
            $transfer = DB::transaction(function () use ($request) {
                $requestDate = Carbon::parse($request->input('request_date'), config('app.timezone'));
                $requestedAt = now();
                $authPending = RequestStatusCatalog::query()->where('code', 'transfer_auth_pending')->value('id');
                $statusPending = RequestStatusCatalog::query()->where('code', 'transfer_status_pending')->value('id');

                $transfer = Transfer::query()->create([
                    'code' => RequestNumberGenerator::transfer($requestDate),
                    'user_id' => $request->user()->id,
                    'unit_id' => (int) $request->input('unit_id'),
                    'request_date' => $requestDate->toDateString(),
                    'requested_at' => $requestedAt,
                    'status' => 'Enviada',
                    'authorization_status_id' => $authPending,
                    'workflow_status_id' => $statusPending,
                    'view_mode' => 'detail',
                    'box_display_state' => 'collapsed',
                    'show_print_card' => false,
                    'description' => $request->input('description'),
                ]);

                $this->syncTransferBoxes($transfer, $request->input('boxes', []), $request);

                $transfer->events()->create([
                    'status_catalog_id' => null,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Creado',
                    'description' => 'Solicitud de transferencia creada por la unidad productora.',
                    'context' => null,
                    'occurred_at' => $requestedAt,
                ]);

                return $transfer;
            });

            app(SystemNotificationService::class)->transferCreated($transfer->fresh(['unit']), $request->user());

            return response()->json([
                'message' => 'Solicitud de transferencia registrada correctamente.',
                'number' => $transfer->code,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Ocurrio un problema al guardar la transferencia. Intente nuevamente.',
            ], 500);
        }
    }

    private function syncTransferBoxes(Transfer $transfer, array $boxesPayload, ?Request $request = null, array $existingDigitalFiles = []): void
    {
        foreach ($boxesPayload as $boxIndex => $boxPayload) {
            $boxNumber = (int) $boxPayload['box_number'];

            $box = $transfer->boxes()->create([
                'series_name' => $boxPayload['series'],
                'start_year' => (int) $boxPayload['year_start'],
                'end_year' => (int) $boxPayload['year_end'],
                'box_number' => $boxNumber,
                'title' => 'Caja C-' . $transfer->code . '-' . str_pad((string) $boxNumber, 3, '0', STR_PAD_LEFT),
                'period_label' => '01/01/' . $boxPayload['year_start'] . ' - 31/12/' . $boxPayload['year_end'],
                'content_description' => $boxPayload['series'],
            ]);

            $seenDocuments = [];
            $sortOrder = 1;

            foreach ($boxPayload['documents_list'] as $documentIndex => $documentPayload) {
                $fingerprint = $this->documentFingerprint($documentPayload, $boxPayload['series']);

                if (isset($seenDocuments[$fingerprint])) {
                    continue;
                }

                $seenDocuments[$fingerprint] = true;
                $requiresDigitalFile = $this->supportRequiresDigitalFile($documentPayload['support'] ?? null);
                $uploadedFile = $requiresDigitalFile
                    ? $request?->file("document_files.$boxIndex.$documentIndex")
                    : null;
                $storedFile = $uploadedFile
                    ? $this->storeTransferDocumentFile($uploadedFile, $transfer->code, (string) ($documentPayload['code'] ?? $sortOrder))
                    : null;
                $existingFile = $this->resolveExistingDigitalFile($documentPayload, $existingDigitalFiles);
                $fileName = $storedFile['name'] ?? $existingFile['name'] ?? ($documentPayload['file_name'] ?? null);
                $filePath = $storedFile['path'] ?? $existingFile['path'] ?? null;

                if (!$requiresDigitalFile) {
                    $fileName = null;
                    $filePath = null;
                }

                $documentCode = trim((string) ($documentPayload['code'] ?? ''));

                $box->documents()->create([
                    'code' => $documentCode !== ''
                        ? $documentCode
                        : 'DOC-' . $transfer->code . '-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT),
                    'name' => $documentPayload['name'],
                    'series_label' => $documentPayload['series'] ?? $boxPayload['series'],
                    'support_type' => $documentPayload['support'],
                    'year_label' => $documentPayload['date'] ?? null,
                    'pages_label' => $documentPayload['pages'] ?? null,
                    'digital_file_name' => $fileName,
                    'digital_file_path' => $filePath,
                    'sort_order' => $sortOrder,
                ]);

                $sortOrder++;
            }
        }
    }

    private function normalizeTransferPayload(Request $request): void
    {
        $boxes = $request->input('boxes');

        if (!is_string($boxes)) {
            return;
        }

        $decodedBoxes = json_decode($boxes, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBoxes)) {
            $request->merge(['boxes' => $decodedBoxes]);
        }
    }

    private function storeTransferDocumentFile($file, string $transferCode, string $documentCode): array
    {
        $safeTransferCode = preg_replace('/[^A-Za-z0-9._-]+/', '-', $transferCode) ?: 'transferencia';
        $safeDocumentCode = preg_replace('/[^A-Za-z0-9._-]+/', '-', $documentCode) ?: 'documento';
        $originalName = $this->sanitizeFileName($file->getClientOriginalName() ?: ($safeDocumentCode . '.pdf'));
        $storedName = now()->format('YmdHis') . '-' . uniqid('', true) . '-' . $originalName;
        $path = $file->storeAs('uploads/transfer-documents/' . $safeTransferCode, $storedName, 'local');

        return [
            'name' => $originalName,
            'path' => $path,
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

    private function existingDigitalFilesByDocumentCode(Transfer $transfer): array
    {
        $files = [];

        foreach ($transfer->boxes->flatMap(fn ($box) => $box->documents) as $document) {
            if (!$this->supportRequiresDigitalFile($document->support_type)
                || empty($document->digital_file_name)
                || empty($document->digital_file_path)) {
                continue;
            }

            $file = [
                'name' => $document->digital_file_name,
                'path' => $document->digital_file_path,
            ];

            // The document id is authoritative; the code remains as a legacy fallback.
            $files['document-' . $document->id] = $file;
            $files[(string) $document->code] ??= $file;
        }

        return $files;
    }

    private function resolveExistingDigitalFile(array $documentPayload, array $existingDigitalFiles): ?array
    {
        if (!empty($documentPayload['file_removed'])) {
            return null;
        }

        $documentReference = (string) ($documentPayload['id_doc'] ?? '');
        $documentId = preg_match('/^doc-(\d+)$/', $documentReference, $matches)
            ? (int) $matches[1]
            : null;
        $documentCode = (string) ($documentPayload['code'] ?? '');
        $lookupKeys = $documentId !== null
            ? ['document-' . $documentId]
            : array_filter([$documentCode]);
        $existingFile = null;

        foreach ($lookupKeys as $lookupKey) {
            if (!empty($existingDigitalFiles[$lookupKey])) {
                $existingFile = $existingDigitalFiles[$lookupKey];
                break;
            }
        }

        if ($existingFile === null) {
            return null;
        }

        $payloadFileName = (string) ($documentPayload['file_name'] ?? '');

        if ($payloadFileName !== '' && $payloadFileName !== (string) ($existingFile['name'] ?? '')) {
            return null;
        }

        return $existingFile;
    }

    private function supportRequiresDigitalFile(?string $support): bool
    {
        return in_array($this->normalizeImportKey((string) $support), ['digital', 'mixto'], true);
    }

    private function documentFingerprint(array $documentPayload, string $defaultSeries): string
    {
        $values = [
            $documentPayload['series'] ?? $defaultSeries,
            $documentPayload['name'] ?? '',
            $documentPayload['code'] ?? '',
            $documentPayload['support'] ?? '',
            $documentPayload['date'] ?? '',
            $documentPayload['pages'] ?? '',
        ];

        return collect($values)
            ->map(fn ($value) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $value)) ?? ''))
            ->implode('|');
    }

    private function validateTransferBoxBusinessRules(array $boxesPayload, int $unitId): ?string
    {
        $allowedSeriesLabels = $this->allowedSeriesLabelsForUnit($unitId);

        foreach ($boxesPayload as $boxIndex => $boxPayload) {
            $boxNumber = $boxPayload['box_number'] ?? ($boxIndex + 1);
            $boxLabels = $this->splitSeriesPayloadLabels($boxPayload['series'] ?? '');
            $normalizedBoxLabels = collect($boxLabels)
                ->map(fn ($label) => $this->normalizeSeriesLabel($label))
                ->filter()
                ->values()
                ->all();

            if (empty($normalizedBoxLabels)) {
                return 'La caja #' . $boxNumber . ' debe tener una serie o subserie definida.';
            }

            foreach ($boxLabels as $boxLabel) {
                if (!isset($allowedSeriesLabels[$this->normalizeSeriesLabel($boxLabel)])) {
                    return 'La serie "' . $boxLabel . '" no existe o no esta habilitada para la unidad productora.';
                }
            }

            $startYear = (int) ($boxPayload['year_start'] ?? 0);
            $endYear = (int) ($boxPayload['year_end'] ?? 0);
            $seenDocumentCodes = [];

            foreach (($boxPayload['documents_list'] ?? []) as $documentPayload) {
                $documentName = trim((string) ($documentPayload['name'] ?? 'documento'));
                $documentCode = trim((string) ($documentPayload['code'] ?? ''));

                if ($documentCode !== '') {
                    $normalizedDocumentCode = mb_strtolower(preg_replace('/\s+/', ' ', $documentCode) ?? '');

                    if (isset($seenDocumentCodes[$normalizedDocumentCode])) {
                        return 'El codigo "' . $documentCode . '" esta repetido dentro de la caja #' . $boxNumber . '. Cada codigo debe ser unico por caja.';
                    }

                    $seenDocumentCodes[$normalizedDocumentCode] = true;
                }

                $documentSeries = trim((string) ($documentPayload['series'] ?? ''));

                if ($documentSeries === '' && count($boxLabels) === 1) {
                    $documentSeries = $boxLabels[0];
                }

                $normalizedDocumentSeries = $this->normalizeSeriesLabel($documentSeries);

                if ($normalizedDocumentSeries === '' || !isset($allowedSeriesLabels[$normalizedDocumentSeries])) {
                    return 'La serie del documento "' . $documentName . '" no existe o no esta habilitada para la unidad productora.';
                }

                if (!in_array($normalizedDocumentSeries, $normalizedBoxLabels, true)) {
                    return 'El documento "' . $documentName . '" pertenece a una serie o subserie que no forma parte de la caja #' . $boxNumber . '.';
                }

                $year = trim((string) ($documentPayload['date'] ?? ''));

                if ($year !== '') {
                    if (!preg_match('/^\d{4}$/', $year)) {
                        return 'El año del documento "' . $documentName . '" debe tener formato YYYY.';
                    }

                    $yearNumber = (int) $year;

                    if ($yearNumber < $startYear || $yearNumber > $endYear) {
                        return 'El año del documento "' . $documentName . '" debe estar dentro del rango de la caja (' . $startYear . ' - ' . $endYear . ').';
                    }
                }

                $fileName = trim((string) ($documentPayload['file_name'] ?? ''));

                if ($fileName !== '' && !$this->isPdfFileName($fileName)) {
                    return 'El archivo digital del documento "' . $documentName . '" debe estar en formato PDF.';
                }
            }
        }

        return null;
    }

    private function allowedSeriesLabelsForUnit(int $unitId): array
    {
        $labels = [];

        DocumentarySeries::query()
            ->where('is_active', true)
            ->with([
                'units:id',
                'subseries' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with('units:id'),
            ])
            ->get()
            ->each(function (DocumentarySeries $series) use ($unitId, &$labels) {
                $seriesUnitIds = $series->units->pluck('id')->map(fn ($id) => (int) $id);

                if (!$seriesUnitIds->contains($unitId)) {
                    return;
                }

                $seriesLabel = $series->code . ' - ' . $series->name;
                $labels[$this->normalizeSeriesLabel($seriesLabel)] = $seriesLabel;

                foreach ($series->subseries as $subseries) {
                    $subseriesUnitIds = $subseries->units->pluck('id')->map(fn ($id) => (int) $id);

                    if (!$subseriesUnitIds->contains($unitId)) {
                        continue;
                    }

                    $subseriesLabel = $seriesLabel . ' / ' . $subseries->code . ' - ' . $subseries->name;
                    $labels[$this->normalizeSeriesLabel($subseriesLabel)] = $subseriesLabel;
                }
            });

        return $labels;
    }

    private function splitSeriesPayloadLabels(string $value): array
    {
        return collect(explode(';', $value))
            ->map(fn ($label) => trim($label))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeSeriesLabel(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($value)) ?? '');
    }

    private function isPdfFileName(string $fileName): bool
    {
        return preg_match('/\.pdf$/i', $fileName) === 1;
    }

    private function createDocumentImportWorkbook(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ugda-document-import-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Documentos" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');

        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach ($row as $columnIndex => $value) {
                $cellRef = $this->columnName($columnIndex + 1) . ($rowIndex + 1);
                $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $cells .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
            }
            $sheetRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <cols>
    <col min="1" max="2" width="32" customWidth="1"/>
    <col min="3" max="4" width="18" customWidth="1"/>
    <col min="5" max="6" width="20" customWidth="1"/>
  </cols>
  <sheetData>' . $sheetRows . '</sheetData>
</worksheet>');
        $zip->close();

        return $path;
    }

    private function readXlsxRows(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo Excel.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('El archivo no contiene la hoja esperada.');
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new \RuntimeException('No se pudo leer la hoja del archivo.');
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = [];

        foreach ($sheet->children($namespace)->sheetData->children($namespace)->row as $rowNode) {
            $row = [];

            foreach ($rowNode->children($namespace)->c as $cellNode) {
                $attributes = $cellNode->attributes();
                $reference = (string) ($attributes['r'] ?? '');
                preg_match('/([A-Z]+)/', $reference, $matches);
                $columnIndex = isset($matches[1]) ? $this->columnIndex($matches[1]) - 1 : count($row);
                $type = (string) ($attributes['t'] ?? '');
                $value = '';

                if ($type === 's') {
                    $value = $sharedStrings[(int) $cellNode->children($namespace)->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cellNode->children($namespace)->is->children($namespace)->t ?? '');
                } else {
                    $value = (string) ($cellNode->children($namespace)->v ?? '');
                }

                $row[$columnIndex] = trim($value);
            }

            if (count($row) > 0) {
                ksort($row);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sharedStrings = simplexml_load_string($xml);
        if ($sharedStrings === false) {
            return [];
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $values = [];

        foreach ($sharedStrings->children($namespace)->si as $item) {
            $texts = $item->xpath('.//*[local-name()="t"]');
            $values[] = collect($texts)->map(fn ($text) => (string) $text)->implode('');
        }

        return $values;
    }

    private function getImportValue(array $row, array $headers, array $acceptedKeys): string
    {
        foreach ($acceptedKeys as $key) {
            $index = array_search($key, $headers, true);

            if ($index !== false) {
                return trim((string) ($row[$index] ?? ''));
            }
        }

        return '';
    }

    private function resolveSupportLabel(string $value): string
    {
        return match ($this->normalizeImportKey($value)) {
            'fisico', 'fsico' => 'Físico',
            'digital' => 'Digital',
            'mixto' => 'Mixto',
            default => '',
        };
    }

    private function normalizeImportKey(string $value): string
    {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
        $normalized = strtolower($normalized !== false ? $normalized : trim($value));

        return preg_replace('/[^a-z0-9]/', '', $normalized) ?? '';
    }

    private function columnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function columnIndex(string $name): int
    {
        $index = 0;

        foreach (str_split($name) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    private function canUnitDirectorAct(Request $request, Transfer $transfer): bool
    {
        $user = $request->user();
        $profileName = $user->activeProfile()?->name;

        if ($profileName !== 'Director/Jefe de Unidad') {
            return false;
        }

        return $user->units()
            ->where('units.id', $transfer->unit_id)
            ->exists();
    }

    private function canRequesterCorrectObserved(Request $request, Transfer $transfer): bool
    {
        $user = $request->user();

        if ($user->activeProfile()?->name !== 'Unidad Solicitante') {
            return false;
        }

        return $user->units()
            ->where('units.id', $transfer->unit_id)
            ->exists();
    }

    private function canUgdaManage(Request $request): bool
    {
        return in_array($request->user()?->activeProfile()?->name, ['Administrador', 'Usuario UGDA'], true);
    }

    public function schedule(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scheduled_for' => ['required', 'date'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe indicar la fecha de entrega.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $comments = trim($request->string('comments')->toString());

        $scheduledFor = Carbon::parse($request->input('scheduled_for'));

        return $this->changeWorkflow($request, $number, 'transfer_status_scheduled', 'Agendado', $comments !== ''
            ? 'Transferencia autorizada para entrega fisica. Comentario: ' . $comments
            : 'Transferencia autorizada para entrega fisica', [
            'scheduled_for' => $scheduledFor->format('d/m/Y') . ' a las ' . $scheduledFor->format('h:i a'),
            'comments' => $comments !== '' ? $comments : null,
        ], [
            'scheduled_for' => $scheduledFor,
            'view_mode' => 'detail',
            'show_print_card' => true,
            'box_display_state' => 'first',
        ]);
    }

    public function startPhysicalReview(Request $request, string $number): JsonResponse
    {
        try {
            $transfer = Transfer::query()
                ->with(['workflowStatus'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canUgdaManage($request)) {
                return response()->json([
                    'message' => 'Solo UGDA puede iniciar la revisión física.',
                ], 403);
            }

            $currentWorkflow = $transfer->workflowStatus?->code;

            if (!in_array($currentWorkflow, ['transfer_status_scheduled', 'transfer_status_subsanated'], true)) {
                return response()->json([
                    'message' => 'La transferencia debe estar agendada o subsanada para iniciar la revisión física.',
                ], 422);
            }

            $statusPhysicalReview = RequestStatusCatalog::query()
                ->where('code', 'transfer_status_physical_review')
                ->value('id');

            DB::transaction(function () use ($request, $transfer, $statusPhysicalReview, $currentWorkflow) {
                $transfer->update([
                    'workflow_status_id' => $statusPhysicalReview,
                    'view_mode' => 'review',
                    'show_print_card' => false,
                    'box_display_state' => 'first',
                ]);

                $transfer->events()->create([
                    'status_catalog_id' => $statusPhysicalReview,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'En Revision',
                    'description' => $currentWorkflow === 'transfer_status_subsanated'
                        ? 'UGDA inició una nueva revisión física para validar las correcciones de la unidad solicitante.'
                        : 'UGDA inició la revisión física de la documentación.',
                    'context' => [
                        'previous_status' => $currentWorkflow,
                    ],
                    'occurred_at' => now(),
                ]);
            });

            return response()->json([
                'message' => 'Revision fisica iniciada correctamente.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo iniciar la revisión física.',
            ], 500);
        }
    }

    public function completeLocations(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'locations' => ['required', 'array', 'min:1'],
            'locations.*.box_number' => ['required', 'integer', 'min:1'],
            'locations.*.office_id' => ['required', 'integer', 'exists:physical_location_offices,id'],
            'locations.*.aisle_id' => ['required', 'integer', 'exists:physical_location_aisles,id'],
            'locations.*.shelf_id' => ['required', 'integer', 'exists:physical_location_shelves,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe asignar ubicación física a todas las cajas.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transfer = Transfer::query()
                ->with(['workflowStatus', 'boxes'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canUgdaManage($request)) {
                return response()->json([
                    'message' => 'Solo UGDA puede completar el proceso de transferencia.',
                ], 403);
            }

            if ($transfer->workflowStatus?->code !== 'transfer_status_physical_review') {
                return response()->json([
                    'message' => 'La transferencia debe estar en revisión física para asignar ubicaciones.',
                ], 422);
            }

            $locations = collect($request->input('locations', []))->keyBy('box_number');
            $missingBoxes = $transfer->boxes
                ->filter(fn ($box) => !$locations->has($box->box_number))
                ->pluck('box_number')
                ->values();

            if ($missingBoxes->isNotEmpty()) {
                return response()->json([
                    'message' => 'Debe asignar ubicación física a todas las cajas.',
                    'missing_boxes' => $missingBoxes,
                ], 422);
            }

            $validShelves = [];

            foreach ($locations as $boxNumber => $location) {
                $shelf = $this->findValidShelf(
                    (int) ($location['office_id'] ?? 0),
                    (int) ($location['aisle_id'] ?? 0),
                    (int) ($location['shelf_id'] ?? 0)
                );

                if ($shelf === null) {
                    return response()->json([
                        'message' => 'La ubicación física de la caja #' . $boxNumber . ' no respeta la relación oficina, pasillo y estante.',
                    ], 422);
                }

                $validShelves[(int) $boxNumber] = $shelf;
            }

            DB::transaction(function () use ($request, $transfer, $locations, $validShelves) {
                foreach ($transfer->boxes as $box) {
                    $locationCode = $this->buildPhysicalLocationCode($validShelves[(int) $box->box_number]);

                    $box->update([
                        'location_code' => $locationCode,
                        'assigned_by_user_id' => $request->user()->id,
                        'assigned_at' => now(),
                    ]);
                }

                $statusTransferred = RequestStatusCatalog::query()->where('code', 'transfer_status_transferred')->value('id');

                $transfer->update([
                    'workflow_status_id' => $statusTransferred,
                    'completed_by_user_id' => $request->user()->id,
                    'completed_at' => now(),
                    'status' => 'Aprobada',
                    'view_mode' => 'detail',
                    'box_display_state' => 'all',
                    'show_print_card' => true,
                ]);

                $transfer->events()->create([
                    'status_catalog_id' => $statusTransferred,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Transferido',
                    'description' => 'Ubicaciones fisicas asignadas y transferencia completada por UGDA.',
                    'context' => [
                        'locations' => $locations->values()->all(),
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->transferCompleted($transfer->fresh(), $request->user());

            return response()->json([
                'message' => 'Transferencia completada correctamente.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo completar el proceso de transferencia.',
            ], 500);
        }
    }

    public function observe(Request $request, string $number): JsonResponse
    {
        return $this->changeWorkflow($request, $number, 'transfer_status_observed', 'Observación UGDA', $request->input('message', ''), null, [], 'observation', true);
    }

    public function observeReview(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'La observación no puede superar 1000 caracteres.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $message = trim($request->string('message')->toString());

        if ($message === '') {
            return response()->json([
                'message' => 'Debe ingresar la observación de la transferencia.',
            ], 422);
        }

        try {
            $transfer = Transfer::query()
                ->with(['workflowStatus'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            $workflowCode = $transfer->workflowStatus?->code;

            if (in_array($workflowCode, ['transfer_status_physical_review', 'transfer_status_physical_observed'], true)) {
                if (!$this->canUgdaManage($request)) {
                    return response()->json([
                    'message' => 'Solo UGDA puede registrar observaciónes de revisión física.',
                    ], 403);
                }

                $statusObserved = RequestStatusCatalog::query()
                    ->where('code', 'transfer_status_physical_observed')
                    ->value('id');

                DB::transaction(function () use ($request, $transfer, $workflowCode, $statusObserved, $message) {
                    $transfer->update([
                        'workflow_status_id' => $statusObserved,
                        'view_mode' => 'detail',
                        'show_print_card' => false,
                        'box_display_state' => 'collapsed',
                    ]);

                    $transfer->events()->create([
                        'status_catalog_id' => $statusObserved,
                        'actor_user_id' => $request->user()->id,
                        'event_type' => 'observation',
                        'title' => 'Observación de revisión física',
                        'description' => $message,
                        'context' => [
                            'observation_scope' => 'physical_review',
                            'previous_status' => $workflowCode,
                        ],
                        'occurred_at' => now(),
                    ]);
                });

                app(SystemNotificationService::class)->transferObserved($transfer->fresh(), $request->user(), $message);

                return response()->json([
                    'message' => 'Observación de revisión física registrada correctamente.',
                ]);
            }

            return $this->changeWorkflow($request, $number, 'transfer_status_observed', 'Observación UGDA', $message, null, [], 'observation', true);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo registrar la observación de la transferencia.',
            ], 500);
        }
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

    private function buildPhysicalLocationCode(PhysicalLocationShelf $shelf): string
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

    public function deny(Request $request, string $number): JsonResponse
    {
        return $this->changeWorkflow($request, $number, 'transfer_status_denied', 'Denegado', $request->input('reason', ''), [
            'reason_label' => 'Motivo de rechazo:',
            'reason' => $request->input('reason', ''),
            'decision_scope' => 'ugda',
        ], [
            'view_mode' => 'detail',
            'show_print_card' => false,
            'box_display_state' => 'collapsed',
        ], 'decision', true);
    }

    private function changeWorkflow(
        Request $request,
        string $number,
        string $statusCode,
        string $eventTitle,
        string $description,
        ?array $context = null,
        array $attributes = [],
        string $eventType = 'status',
        bool $requireMessage = false
    ): JsonResponse {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        $rules = $requireMessage ? ['message' => ['sometimes', 'nullable', 'string', 'max:1000'], 'reason' => ['sometimes', 'nullable', 'string', 'max:1000']] : [];
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos enviados no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($requireMessage && blank($description)) {
            return response()->json([
                'message' => 'Debe proporcionar un comentario para esta accion.',
            ], 422);
        }

        try {
            $transfer = Transfer::query()->where('code', $number)->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            DB::transaction(function () use ($request, $transfer, $statusCode, $eventTitle, $description, $context, $attributes, $eventType) {
                $transfer->update(array_merge([
                    'workflow_status_id' => RequestStatusCatalog::query()->where('code', $statusCode)->value('id'),
                ], $attributes));

                $transfer->events()->create([
                    'status_catalog_id' => RequestStatusCatalog::query()->where('code', $statusCode)->value('id'),
                    'actor_user_id' => $request->user()->id,
                    'event_type' => $eventType,
                    'title' => $eventTitle,
                    'description' => $description,
                    'context' => $context,
                    'occurred_at' => now(),
                ]);
            });

            $notificationService = app(SystemNotificationService::class);
            $freshTransfer = $transfer->fresh();

            match ($statusCode) {
                'transfer_status_observed' => $notificationService->transferObserved($freshTransfer, $request->user(), $description),
                'transfer_status_denied' => $notificationService->transferDeniedByUgda($freshTransfer, $request->user(), $description),
                'transfer_status_scheduled' => $notificationService->transferScheduled($freshTransfer, $request->user()),
                default => null,
            };

            $message = 'Estado de la transferencia actualizado correctamente.';

            if ($statusCode === 'transfer_status_scheduled') {
                $scheduledFor = $context['scheduled_for'] ?? null;
                $message = 'La transferencia #' . $transfer->code . ' ha sido agendada'
                    . ($scheduledFor ? ' para el ' . $scheduledFor : '')
                    . '.';
            }

            return response()->json([
                'message' => $message,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo actualizar la transferencia.',
            ], 500);
        }
    }
}
