<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanDocument;
use App\Models\RequestStatusCatalog;
use App\Models\TransferDocument;
use App\Services\SystemNotificationService;
use App\Support\RequestNumberGenerator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LoanController extends Controller
{
    public function createCatalog(Request $request): JsonResponse
    {
        $editNumber = trim($request->string('loan_number')->toString());

        if (!$this->canRequesterCreate($request)
            && ($editNumber === '' || !$this->canRequesterCorrectCatalog($request))) {
            return response()->json([
                'message' => 'Solo la unidad solicitante puede crear solicitudes de prestamo.',
            ], 403);
        }

        $user = $request->user()->load(['person', 'units']);
        $catalogUser = $user;

        if ($editNumber !== '') {
            $editingLoan = Loan::query()
                ->with(['authorizationStatus', 'workflowStatus', 'requester.person', 'unit'])
                ->where('number', $editNumber)
                ->first();

            if ($editingLoan === null) {
                return response()->json(['message' => 'Préstamo no encontrado.'], 404);
            }

            if (!$this->canRequesterCorrectObserved($request, $editingLoan)
                || $editingLoan->authorizationStatus?->code !== 'loan_auth_authorized'
                || $editingLoan->workflowStatus?->code !== 'loan_status_observed') {
                return response()->json([
                    'message' => 'Este préstamo no está disponible para corrección.',
                ], 403);
            }

            $catalogUser = $editingLoan->requester?->load(['person', 'units']) ?? $user;
        }

        $activeUnit = $catalogUser->units
            ->first(fn ($unit) => (bool) ($unit->pivot->is_active ?? false))
            ?? $catalogUser->units->first();
        $loanedOriginalDocumentIds = $this->loanedOriginalDocumentIds();

        $documents = TransferDocument::query()
            ->with(['box.transfer.unit', 'box.transfer.workflowStatus'])
            ->where('is_reserved', false)
            ->whereHas('box.transfer', function ($query) use ($catalogUser) {
                $query->where('user_id', $catalogUser->id)
                    ->whereHas('workflowStatus', fn ($statusQuery) => $statusQuery->where('code', 'transfer_status_transferred'));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (TransferDocument $document) => [
                'id' => $document->id,
                'title' => $document->name,
                'code' => $document->code,
                'series' => $document->series_label ?? $document->box?->series_name,
                'box' => $document->box?->boxCode($document->box?->transfer?->code),
                'year' => $document->year_label,
                'unit' => $document->box?->transfer?->unit?->name,
                'transferredAt' => optional($document->box?->transfer?->completed_at ?? $document->box?->transfer?->requested_at)->format('d/m/Y H:i'),
                'support' => $document->support_type,
                'transferNumber' => $document->box?->transfer?->code,
                'isLoanedOriginal' => isset($loanedOriginalDocumentIds[$document->id]),
            ])
            ->values();

        return response()->json([
            'requester' => [
                'name' => $this->userName($catalogUser),
                'unit_id' => $activeUnit?->id,
                'unit_name' => $activeUnit?->name,
            ],
            'documents' => $documents,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->canRequesterCreate($request)) {
            return response()->json([
                'message' => 'Solo la unidad solicitante puede crear solicitudes de prestamo.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'requester_name' => ['required', 'string', 'max:255'],
            'unit_name' => ['required', 'string', 'max:255'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer'],
            'system_documents' => ['nullable', 'array'],
            'system_documents.*.id' => ['required_with:system_documents', 'integer'],
            'system_documents.*.document_type' => ['required_with:system_documents', 'in:Copia,Original'],
            'additional_documents' => ['nullable', 'array'],
            'additional_documents.*.name' => ['required_with:additional_documents', 'string', 'max:255'],
            'additional_documents.*.year' => ['required_with:additional_documents', 'integer', 'min:1900', 'max:' . now()->year],
            'additional_documents.*.quantity' => ['required_with:additional_documents', 'string', 'max:120'],
            'additional_documents.*.unit' => ['required_with:additional_documents', 'string', 'max:255'],
            'additional_documents.*.observation' => ['required_with:additional_documents', 'string', 'max:1000'],
            'additional_documents.*.document_type' => ['required_with:additional_documents', 'in:Copia,Original'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la solicitud de préstamo no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $systemDocuments = collect($request->input('system_documents', []))
            ->filter(fn ($document) => filled($document['id'] ?? null))
            ->values();
        $selectedIds = ($systemDocuments->isNotEmpty()
            ? $systemDocuments->pluck('id')
            : collect($request->input('document_ids', [])))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $systemDocumentTypes = $systemDocuments
            ->mapWithKeys(fn ($document) => [(int) $document['id'] => $document['document_type'] ?? 'Copia']);
        $additionalDocuments = collect($request->input('additional_documents', []))
            ->filter(fn ($document) => filled($document['name'] ?? null))
            ->values();

        if ($selectedIds->isEmpty() && $additionalDocuments->isEmpty()) {
            return response()->json([
                'message' => 'Debe seleccionar o agregar al menos un documento.',
            ], 422);
        }

        try {
            $user = $request->user()->load('units');
            $activeUnit = $user->units()
                ->wherePivot('is_active', true)
                ->first()
                ?? $user->units()->first();

            if ($activeUnit === null) {
                return response()->json([
                    'message' => 'El usuario no tiene una unidad solicitante asignada.',
                ], 422);
            }

            $additionalDocuments = $additionalDocuments
                ->map(fn (array $document) => array_merge($document, [
                    'unit' => $activeUnit->name,
                ]))
                ->values();

            $transferDocuments = TransferDocument::query()
                ->with(['box.transfer.unit'])
                ->whereIn('id', $selectedIds)
                ->where('is_reserved', false)
                ->whereHas('box.transfer', function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->whereHas('workflowStatus', fn ($statusQuery) => $statusQuery->where('code', 'transfer_status_transferred'));
                })
                ->get();

            if ($transferDocuments->count() !== $selectedIds->count()) {
                return response()->json([
                    'message' => 'Uno o mas documentos seleccionados no estan disponibles para prestamo.',
                ], 422);
            }

            $loanedOriginalDocumentIds = $this->loanedOriginalDocumentIds();
            $loanedOriginalDocuments = $transferDocuments
                ->filter(fn (TransferDocument $document) => isset($loanedOriginalDocumentIds[$document->id]));

            if ($loanedOriginalDocuments->isNotEmpty()) {
                return response()->json([
                    'message' => 'Los documentos prestados como Original no están disponibles para una nueva solicitud.',
                ], 422);
            }

            $loan = DB::transaction(function () use ($request, $user, $activeUnit, $transferDocuments, $systemDocumentTypes, $additionalDocuments) {
                $authPending = $this->statusId('loan_auth_pending');
                $statusPending = $this->statusId('loan_status_pending');
                $requestedAt = now();

                $loan = Loan::query()->create([
                    'number' => RequestNumberGenerator::loan($requestedAt),
                    'user_id' => $user->id,
                    'unit_id' => $activeUnit->id,
                    'requested_at' => $requestedAt,
                    'authorization_status_id' => $authPending,
                    'workflow_status_id' => $statusPending,
                    'view_mode' => 'detail',
                    'description' => 'Solicitud de prestamo documental creada por unidad solicitante.',
                ]);

                $documentMap = [];

                foreach ($transferDocuments->values() as $index => $document) {
                    $documentType = $systemDocumentTypes->get($document->id, 'Copia');

                    $loanDocument = $loan->documents()->create([
                        'document_kind' => 'system',
                        'group_title' => 'Documentos del Sistema (' . $transferDocuments->count() . ')',
                        'title' => $document->name,
                        'series_label' => $document->series_label,
                        'box_code' => $document->box?->boxCode($document->box?->transfer?->code),
                        'year_label' => $document->year_label,
                        'unit_name_snapshot' => $document->box?->transfer?->unit?->name,
                        'document_type_label' => $documentType,
                        'document_type_tone' => $documentType === 'Original' ? 'warning' : 'info',
                        'sort_order' => $index + 1,
                    ]);

                    $documentMap[] = [
                        'loan_document_id' => $loanDocument->id,
                        'transfer_document_id' => $document->id,
                    ];
                }

                foreach ($additionalDocuments as $index => $document) {
                    $documentType = $document['document_type'] ?? 'Copia';

                    $loan->documents()->create([
                        'document_kind' => 'additional',
                        'group_title' => 'Documentos Adicionales (' . $additionalDocuments->count() . ')',
                        'title' => $document['name'],
                        'series_label' => null,
                        'box_code' => null,
                        'year_label' => $document['year'] ?? null,
                        'unit_name_snapshot' => $document['unit'] ?? null,
                        'document_type_label' => $documentType,
                        'document_type_tone' => $documentType === 'Original' ? 'warning' : 'info',
                        'quantity_label' => $document['quantity'] ?? null,
                        'note' => $document['observation'] ?? null,
                        'sort_order' => $transferDocuments->count() + $index + 1,
                    ]);
                }

                $loan->events()->create([
                    'status_catalog_id' => $statusPending,
                    'actor_user_id' => $user->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => 'Creado',
                    'description' => 'Solicitud de prestamo creada por ' . $request->string('requester_name')->toString(),
                    'context' => [
                        'system_documents' => $transferDocuments->count(),
                        'additional_documents' => $additionalDocuments->count(),
                        'document_map' => $documentMap,
                    ],
                    'occurred_at' => now(),
                ]);

                return $loan;
            });

            app(SystemNotificationService::class)->loanCreated($loan->fresh(['unit']), $request->user());

            return response()->json([
                'message' => 'La solicitud de prestamo #' . $loan->number . ' fue registrada por la Unidad solicitante y enviada a Director de unidad para autorización.',
                'number' => $loan->number,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo registrar la solicitud de prestamo.',
            ], 500);
        }
    }

    public function editData(Request $request, string $number): JsonResponse
    {
        try {
            $loan = Loan::query()
                ->with(['authorizationStatus', 'workflowStatus', 'requester.person', 'unit', 'documents', 'events.actor.person'])
                ->where('number', $number)
                ->first();

            if ($loan === null) {
                return response()->json(['message' => 'Préstamo no encontrado.'], 404);
            }

            if (!$this->canRequesterCorrectObserved($request, $loan)) {
                return response()->json([
                    'message' => 'Solo la unidad solicitante puede editar un prestamo observado de su unidad.',
                ], 403);
            }

            if ($loan->authorizationStatus?->code !== 'loan_auth_authorized'
                || $loan->workflowStatus?->code !== 'loan_status_observed') {
                return response()->json([
                    'message' => 'Este préstamo no está en un estado observable para corrección.',
                ], 422);
            }

            $documentMap = collect($loan->events)
                ->first(fn ($event) => is_array($event->context) && isset($event->context['document_map']))?->context['document_map'] ?? [];
            $transferDocumentIds = collect($documentMap)
                ->mapWithKeys(fn (array $item) => [(int) ($item['loan_document_id'] ?? 0) => (int) ($item['transfer_document_id'] ?? 0)]);

            return response()->json([
                'requester' => [
                    'name' => $this->userName($loan->requester),
                    'unit_name' => $loan->unit?->name,
                ],
                'system_documents' => $loan->documents
                    ->where('document_kind', 'system')
                    ->map(fn (LoanDocument $document) => [
                        'id' => $transferDocumentIds->get($document->id),
                        'document_type' => $document->document_type_label ?: 'Copia',
                    ])
                    ->filter(fn (array $document) => $document['id'] > 0)
                    ->values()
                    ->all(),
                'additional_documents' => $loan->documents
                    ->where('document_kind', 'additional')
                    ->map(fn (LoanDocument $document, $index) => [
                        'id' => 'existing-' . $document->id,
                        'name' => $document->title,
                        'year' => $document->year_label,
                        'quantity' => $document->quantity_label,
                        'unit' => $document->unit_name_snapshot ?: $loan->unit?->name,
                        'observation' => $document->note,
                        'document_type' => $document->document_type_label ?: 'Copia',
                    ])
                    ->values()
                    ->all(),
                'observations' => $loan->events
                    ->where('event_type', 'observation')
                    ->sortBy('occurred_at')
                    ->map(fn ($event) => [
                        'actor' => $this->userName($event->actor) ?: ($event->actor_name_snapshot ?: 'UGDA'),
                        'dateTime' => optional($event->occurred_at)->format('d/m/Y H:i'),
                        'message' => $event->description,
                    ])
                    ->values()
                    ->all(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el prestamo para edicion.',
            ], 500);
        }
    }

    public function resubmitCorrection(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'requester_name' => ['required', 'string', 'max:255'],
            'unit_name' => ['required', 'string', 'max:255'],
            'system_documents' => ['nullable', 'array'],
            'system_documents.*.id' => ['required_with:system_documents', 'integer'],
            'system_documents.*.document_type' => ['required_with:system_documents', 'in:Copia,Original'],
            'additional_documents' => ['nullable', 'array'],
            'additional_documents.*.name' => ['required_with:additional_documents', 'string', 'max:255'],
            'additional_documents.*.year' => ['required_with:additional_documents', 'integer', 'min:1900', 'max:' . now()->year],
            'additional_documents.*.quantity' => ['required_with:additional_documents', 'string', 'max:120'],
            'additional_documents.*.unit' => ['required_with:additional_documents', 'string', 'max:255'],
            'additional_documents.*.observation' => ['required_with:additional_documents', 'string', 'max:1000'],
            'additional_documents.*.document_type' => ['required_with:additional_documents', 'in:Copia,Original'],
            'correction_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la corrección no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user()->load('units');
            $loan = Loan::query()
                ->with(['authorizationStatus', 'workflowStatus', 'unit'])
                ->where('number', $number)
                ->first();

            if ($loan === null) {
                return response()->json(['message' => 'Préstamo no encontrado.'], 404);
            }

            if (!$this->canRequesterCorrectObserved($request, $loan)) {
                return response()->json([
                    'message' => 'Solo la unidad solicitante puede corregir este prestamo.',
                ], 403);
            }

            if ($loan->authorizationStatus?->code !== 'loan_auth_authorized'
                || $loan->workflowStatus?->code !== 'loan_status_observed') {
                return response()->json([
                    'message' => 'Este préstamo no está en un estado observable para corrección.',
                ], 422);
            }

            $activeUnit = $user->units()
                ->wherePivot('is_active', true)
                ->where('units.id', $loan->unit_id)
                ->first()
                ?? $user->units()->where('units.id', $loan->unit_id)->first();

            if ($activeUnit === null) {
                return response()->json([
                    'message' => 'El usuario no tiene asignada la unidad solicitante de este prestamo.',
                ], 403);
            }

            $systemDocuments = collect($request->input('system_documents', []))
                ->filter(fn ($document) => filled($document['id'] ?? null))
                ->values();
            $selectedIds = $systemDocuments->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $systemDocumentTypes = $systemDocuments->mapWithKeys(fn ($document) => [(int) $document['id'] => $document['document_type'] ?? 'Copia']);
            $additionalDocuments = collect($request->input('additional_documents', []))
                ->filter(fn ($document) => filled($document['name'] ?? null))
                ->values();

            if ($selectedIds->isEmpty() && $additionalDocuments->isEmpty()) {
                return response()->json(['message' => 'Debe seleccionar o agregar al menos un documento.'], 422);
            }

            $additionalDocuments = $additionalDocuments
                ->map(fn (array $document) => array_merge($document, ['unit' => $activeUnit->name]))
                ->values();

            $transferDocuments = TransferDocument::query()
                ->with(['box.transfer.unit'])
                ->whereIn('id', $selectedIds)
                ->where('is_reserved', false)
                ->whereHas('box.transfer', function ($query) use ($loan) {
                    $query->where('user_id', $loan->user_id)
                        ->whereHas('workflowStatus', fn ($statusQuery) => $statusQuery->where('code', 'transfer_status_transferred'));
                })
                ->get();

            if ($transferDocuments->count() !== $selectedIds->count()) {
                return response()->json(['message' => 'Uno o mas documentos seleccionados no estan disponibles para prestamo.'], 422);
            }

            $loanedOriginalDocumentIds = $this->loanedOriginalDocumentIds();
            if ($transferDocuments->contains(fn (TransferDocument $document) => isset($loanedOriginalDocumentIds[$document->id]))) {
                return response()->json([
                    'message' => 'Los documentos prestados como Original no están disponibles para una nueva solicitud.',
                ], 422);
            }

            DB::transaction(function () use ($request, $loan, $transferDocuments, $systemDocumentTypes, $additionalDocuments) {
                $statusPending = $this->statusId('loan_status_pending');
                $requestedAt = now();

                $loan->update([
                    'requested_at' => $requestedAt,
                    'workflow_status_id' => $statusPending,
                    'search_status_id' => null,
                    'search_started_by_user_id' => null,
                    'search_started_at' => null,
                    'search_completed_by_user_id' => null,
                    'search_completed_at' => null,
                    'search_comments' => null,
                    'view_mode' => 'manage',
                ]);

                $loan->documents()->delete();
                $documentMap = [];

                foreach ($transferDocuments->values() as $index => $document) {
                    $documentType = $systemDocumentTypes->get($document->id, 'Copia');
                    $loanDocument = $loan->documents()->create([
                        'document_kind' => 'system',
                        'group_title' => 'Documentos del Sistema (' . $transferDocuments->count() . ')',
                        'title' => $document->name,
                        'series_label' => $document->series_label,
                        'box_code' => $document->box?->boxCode($document->box?->transfer?->code),
                        'year_label' => $document->year_label,
                        'unit_name_snapshot' => $document->box?->transfer?->unit?->name,
                        'document_type_label' => $documentType,
                        'document_type_tone' => $documentType === 'Original' ? 'warning' : 'info',
                        'sort_order' => $index + 1,
                    ]);

                    $documentMap[] = [
                        'loan_document_id' => $loanDocument->id,
                        'transfer_document_id' => $document->id,
                    ];
                }

                foreach ($additionalDocuments as $index => $document) {
                    $documentType = $document['document_type'] ?? 'Copia';
                    $loan->documents()->create([
                        'document_kind' => 'additional',
                        'group_title' => 'Documentos Adicionales (' . $additionalDocuments->count() . ')',
                        'title' => $document['name'],
                        'year_label' => $document['year'] ?? null,
                        'unit_name_snapshot' => $document['unit'] ?? null,
                        'document_type_label' => $documentType,
                        'document_type_tone' => $documentType === 'Original' ? 'warning' : 'info',
                        'quantity_label' => $document['quantity'] ?? null,
                        'note' => $document['observation'] ?? null,
                        'sort_order' => $transferDocuments->count() + $index + 1,
                    ]);
                }

                $correctionNotes = trim($request->string('correction_notes')->toString());
                $loan->events()->create([
                    'status_catalog_id' => $statusPending,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Corregido y reenviado',
                    'description' => 'Solicitud corregida por la unidad solicitante y reenviada a UGDA para revisión.'
                        . ($correctionNotes !== '' ? ' Aclaraciones: ' . $correctionNotes : ''),
                    'context' => [
                        'previous_status' => 'loan_status_observed',
                        'correction_notes' => $correctionNotes !== '' ? $correctionNotes : null,
                        'system_documents' => $transferDocuments->count(),
                        'additional_documents' => $additionalDocuments->count(),
                        'document_map' => $documentMap,
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->loanCorrectionSubmitted($loan->fresh(['unit']), $request->user());

            return response()->json([
                'message' => 'La solicitud de préstamo fue corregida y reenviada a UGDA para revisión.',
                'number' => $loan->number,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'No se pudo guardar la corrección del préstamo.'], 500);
        }
    }

    public function authorizeLoan(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de autorización no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $comments = trim($request->string('comments')->toString());

        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_authorized',
            eventTitle: 'Autorizado UGDA',
            description: $comments !== ''
                ? 'Solicitud autorizada por UGDA para iniciar la búsqueda de documentos. Comentario: ' . $comments
                : 'Solicitud autorizada por UGDA para iniciar la búsqueda de documentos.',
            updater: fn (Loan $loan) => [
                'ugda_authorized_by_user_id' => $request->user()->id,
                'ugda_authorized_at' => now(),
            ],
            context: $comments !== '' ? ['comments' => $comments] : null,
            successMessage: 'El préstamo #' . $number . ' fue autorizado por UGDA para iniciar la búsqueda de documentos.',
        );
    }

    public function pendingAuthorization(Request $request): JsonResponse
    {
        if (!$this->canUnitDirectorAuthorize($request)) {
            return response()->json([
                'message' => 'Solo el Director/Jefe de Unidad puede revisar estas solicitudes.',
            ], 403);
        }

        $unitIds = $this->userUnitIds($request);

        $loans = Loan::query()
            ->with(['requester.person', 'unit', 'authorizationStatus', 'workflowStatus', 'documents'])
            ->whereIn('unit_id', $unitIds)
            ->whereHas('authorizationStatus', fn ($query) => $query->where('code', 'loan_auth_pending'))
            ->whereHas('workflowStatus', fn ($query) => $query->where('code', 'loan_status_pending'))
            ->orderByDesc('requested_at')
            ->get();

        $today = now()->toDateString();

        return response()->json([
            'summary' => [
                'pending' => $loans->count(),
                'today' => $loans->filter(fn (Loan $loan) => $loan->requested_at?->toDateString() === $today)->count(),
                'units' => $loans->pluck('unit_id')->filter()->unique()->count(),
            ],
            'items' => $loans->map(fn (Loan $loan) => [
                'number' => $loan->number,
                'unit' => $loan->unit?->name,
                'responsible' => $this->userName($loan->requester) ?: 'Sin responsable',
                'requested_at' => $loan->requested_at?->format('d/m/Y H:i'),
                'auth' => $loan->authorizationStatus?->label ?? 'Pendiente',
                'status' => $loan->workflowStatus?->label ?? 'Pendiente',
                'documents_count' => $loan->documents->count(),
            ])->values(),
        ]);
    }

    public function authorizeByUnit(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de autorización no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->unitStateChange(
            request: $request,
            number: $number,
            authCode: 'loan_auth_authorized',
            workflowCode: 'loan_status_pending',
            eventTitle: 'Autorizado por jefatura',
            description: trim($request->string('comments')->toString()) !== ''
                ? 'Solicitud autorizada por jefatura de unidad. Comentario: ' . trim($request->string('comments')->toString())
                : 'Solicitud autorizada por jefatura de unidad.',
            updater: fn () => [
                'authorized_by_user_id' => $request->user()->id,
                'authorized_at' => now(),
                'view_mode' => 'manage',
            ],
            context: trim($request->string('comments')->toString()) !== ''
                ? ['comments' => trim($request->string('comments')->toString()), 'decision_scope' => 'unit']
                : ['decision_scope' => 'unit'],
            successMessage: 'El prestamo #' . $number . ' fue autorizado por Director de unidad y enviado a UGDA para su gestión.',
        );
    }

    public function denyByUnit(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe ingresar el motivo de rechazo.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->unitStateChange(
            request: $request,
            number: $number,
            authCode: 'loan_auth_denied',
            workflowCode: 'loan_status_denied',
            eventTitle: 'Denegado por jefatura',
            description: 'Solicitud denegada por jefatura de unidad. Motivo: ' . $request->string('reason')->toString(),
            eventType: 'decision',
            context: [
                'reason_label' => 'Motivo de denegación:',
                'reason' => $request->string('reason')->toString(),
                'decision_scope' => 'unit',
            ],
            successMessage: 'El prestamo #' . $number . ' fue denegado por Director de unidad y devuelto a la Unidad solicitante.',
        );
    }

    public function observe(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe ingresar la observación de la solicitud.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_observed',
            eventTitle: 'Observación UGDA',
            description: $request->string('message')->toString(),
            eventType: 'observation',
            successMessage: 'El prestamo #' . $number . ' fue observado por UGDA y devuelto a la Unidad solicitante para correcciones.',
        );
    }

    public function deny(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe ingresar el motivo de rechazo.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_denied',
            eventTitle: 'Denegado por UGDA',
            description: 'Solicitud denegada por UGDA. Motivo: ' . $request->string('reason')->toString(),
            eventType: 'decision',
            context: [
                'reason_label' => 'Motivo de rechazo:',
                'reason' => $request->string('reason')->toString(),
                'decision_scope' => 'ugda',
            ],
            successMessage: 'El prestamo #' . $number . ' fue denegado por UGDA y devuelto a la Unidad solicitante.',
        );
    }

    public function startSearch(Request $request, string $number): JsonResponse
    {
        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_authorized',
            eventTitle: 'Búsqueda en proceso',
            description: 'UGDA ha iniciado el proceso de búsqueda y localización física de los documentos solicitados.',
            updater: fn (Loan $loan) => [
                'search_status_id' => $this->statusId('loan_search_in_progress'),
                'search_started_by_user_id' => $request->user()->id,
                'search_started_at' => now(),
            ],
            successMessage: 'La búsqueda del préstamo #' . $number . ' fue iniciada por UGDA.',
        );
    }

    public function finishSearch(Request $request, string $number): JsonResponse
    {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la búsqueda no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()->with('documents')->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Préstamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan) {
                $selectedIds = collect($request->input('document_ids', []))->map(fn ($id) => (int) $id)->all();
                $foundDocuments = $selectedIds !== [];
                $searchStatusCode = $foundDocuments ? 'loan_search_completed' : 'loan_search_not_found';
                $eventTitle = $foundDocuments ? 'Búsqueda finalizada' : 'Búsqueda sin resultados';
                $eventDescription = $foundDocuments
                    ? 'La búsqueda de los documentos ha sido completada. Documentos listos para retiro.'
                    : 'Los documentos solicitados no fueron encontrados en el archivo.';

                $loan->documents()->update(['found_in_search' => false]);

                if ($foundDocuments) {
                    $loan->documents()->whereIn('id', $selectedIds)->update(['found_in_search' => true]);
                }

                $loan->update([
                    'search_status_id' => $this->statusId($searchStatusCode),
                    'search_completed_by_user_id' => $request->user()->id,
                    'search_completed_at' => now(),
                    'search_comments' => $request->input('comments'),
                ]);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($searchStatusCode),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => $eventTitle,
                    'description' => $eventDescription,
                    'context' => [
                        'comments' => $request->input('comments'),
                        'found_documents' => count($selectedIds),
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->loanSearchFinished($loan->fresh(), $request->user());

            $foundCount = (int) collect($request->input('document_ids', []))->count();
            $message = $foundCount > 0
                ? 'La búsqueda del préstamo #' . $number . ' fue finalizada por UGDA. Documentos encontrados: ' . $foundCount . '.'
                : 'La búsqueda del préstamo #' . $number . ' fue finalizada por UGDA. No se encontraron documentos disponibles.';

            return response()->json(['message' => $message]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo finalizar la búsqueda.',
            ], 500);
        }
    }

    public function registerLoan(Request $request, string $number): JsonResponse
    {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer'],
            'loan_date' => ['required', 'date_format:d/m/Y'],
            'due_date' => ['required', 'date_format:d/m/Y'],
            'received_by' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $loanDate = Carbon::createFromFormat('d/m/Y', $request->string('loan_date')->toString());
            $dueDate = Carbon::createFromFormat('d/m/Y', $request->string('due_date')->toString());

            if ($dueDate->lt($loanDate)) {
                $validator->errors()->add(
                    'due_date',
                    'La fecha de devolución programada debe ser igual o posterior a la fecha de préstamo.'
                );
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del préstamo no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()->with('documents')->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Préstamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan) {
                $selectedIds = collect($request->input('selected_ids'))->map(fn ($id) => (int) $id)->all();
                $dispatch = $loan->dispatches()->create([
                    'loan_date' => Carbon::createFromFormat('d/m/Y', $request->string('loan_date')->toString())->toDateString(),
                    'due_date' => Carbon::createFromFormat('d/m/Y', $request->string('due_date')->toString())->toDateString(),
                    'received_by_name' => $request->string('received_by')->toString(),
                    'delivered_by_user_id' => $request->user()->id,
                    'observations' => $request->input('observations'),
                ]);

                foreach ($selectedIds as $documentId) {
                    $dispatch->items()->create(['loan_document_id' => $documentId]);
                }

                $loan->documents()->whereIn('id', $selectedIds)->update([
                    'selected_for_loan' => true,
                ]);

                $loan->update([
                    'workflow_status_id' => $this->statusId('loan_status_loaned'),
                ]);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId('loan_status_loaned'),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => 'Prestado',
                    'description' => 'Documentos entregados a la Unidad solicitante. Fecha de devolución: ' . $request->string('due_date')->toString(),
                    'context' => null,
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->loanRegistered($loan->fresh(), $request->user());

            return response()->json([
                'message' => 'El prestamo #' . $number . ' fue registrado por UGDA y entregado a la Unidad solicitante. Fecha de devolución: ' . $request->string('due_date')->toString() . '.',
                'summarySheetUrl' => \App\Support\SignedPdfUrl::loanSummarySheet($number),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo registrar el prestamo.',
            ], 500);
        }
    }

    public function registerDocumentModifications(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'modifications' => ['required', 'array', 'min:1'],
            'modifications.*.loan_document_id' => ['required', 'integer'],
            'modifications.*.description' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe indicar al menos una modificacion realizada a los documentos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()
                ->with(['authorizationStatus', 'workflowStatus', 'searchStatus', 'documents'])
                ->where('number', $number)
                ->first();

            if ($loan === null) {
                return response()->json(['message' => 'Préstamo no encontrado.'], 404);
            }

            if (!$this->canRegisterDocumentModifications($request, $loan)) {
                return response()->json([
                    'message' => 'Solo la unidad solicitante puede registrar modificaciones a los documentos.',
                ], 403);
            }

            if (
                $loan->authorizationStatus?->code !== 'loan_auth_authorized'
                || $loan->workflowStatus?->code !== 'loan_status_loaned'
                || $loan->searchStatus?->code !== 'loan_search_completed'
            ) {
                return response()->json([
                    'message' => 'Las modificaciones solo pueden registrarse cuando el préstamo está autorizado, prestado y con búsqueda finalizada.',
                ], 422);
            }

            DB::transaction(function () use ($request, $loan) {
                $modifications = collect($request->input('modifications'))
                    ->map(fn ($item) => [
                        'loan_document_id' => (int) $item['loan_document_id'],
                        'description' => trim((string) $item['description']),
                    ])
                    ->filter(fn ($item) => $item['loan_document_id'] > 0 && $item['description'] !== '')
                    ->values();

                $validDocumentIds = $loan->documents
                    ->where('selected_for_loan', true)
                    ->where('returned', false)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $invalidDocument = $modifications
                    ->pluck('loan_document_id')
                    ->contains(fn ($id) => !in_array((int) $id, $validDocumentIds, true));

                if ($modifications->isEmpty() || $invalidDocument) {
                    throw new \RuntimeException('Seleccione documentos prestados válidos para registrar modificaciones.', 422);
                }

                $loan->documentModifications()->delete();

                foreach ($modifications as $modification) {
                    $loan->documentModifications()->create([
                        'loan_document_id' => $modification['loan_document_id'],
                        'registered_by_user_id' => $request->user()->id,
                        'description' => $modification['description'],
                    ]);
                }

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId('loan_status_loaned'),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'modification',
                    'title' => 'Modificaciones a documentos',
                    'description' => 'Se registraron modificaciones realizadas a documentos prestados.',
                    'context' => [
                        'modifications_count' => $modifications->count(),
                    ],
                    'occurred_at' => now(),
                ]);
            });

            return response()->json([
                'message' => 'Las modificaciones del prestamo #' . $number . ' fueron registradas correctamente.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getCode() === 422
                    ? $exception->getMessage()
                    : 'No se pudieron registrar las modificaciones.',
            ], $exception->getCode() === 422 ? 422 : 500);
        }
    }

    public function registerReturn(Request $request, string $number): JsonResponse
    {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer'],
            'return_date' => ['required', 'date_format:d/m/Y'],
            'condition' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la devolución no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()->with('documents')->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Préstamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan) {
                $selectedIds = collect($request->input('selected_ids'))->map(fn ($id) => (int) $id)->all();
                $return = $loan->returns()->create([
                    'return_date' => Carbon::createFromFormat('d/m/Y', $request->string('return_date')->toString())->toDateString(),
                    'received_by_user_id' => $request->user()->id,
                    'condition_label' => $request->string('condition')->toString(),
                    'observations' => $request->input('observations'),
                ]);

                foreach ($selectedIds as $documentId) {
                    $return->items()->create(['loan_document_id' => $documentId]);
                }

                $loan->documents()->whereIn('id', $selectedIds)->update([
                    'returned' => true,
                ]);

                $hasPending = $loan->documents()->where('selected_for_loan', true)->where('returned', false)->exists();
                $loan->update([
                    'workflow_status_id' => $this->statusId($hasPending ? 'loan_status_loaned' : 'loan_status_returned'),
                ]);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($hasPending ? 'loan_status_loaned' : 'loan_status_returned'),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => 'Devuelto',
                    'description' => 'Documentos recibidos por UGDA. ' . $request->string('condition')->toString(),
                    'context' => null,
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->loanReturned($loan->fresh(), $request->user());

            return response()->json([
                'message' => 'La devolución del prestamo #' . $number . ' fue registrada por UGDA. Documentos recibidos por UGDA.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo registrar la devolución.',
            ], 500);
        }
    }

    private function loanedOriginalDocumentIds(): array
    {
        return LoanDocument::query()
            ->with('loan.events')
            ->where('selected_for_loan', true)
            ->where('returned', false)
            ->where('document_type_label', 'Original')
            ->get(['id', 'loan_id'])
            ->reduce(function (array $documentIds, LoanDocument $document): array {
                $requestEvent = $document->loan?->events
                    ->first(fn ($event) => is_array($event->context) && isset($event->context['document_map']));

                foreach ($requestEvent?->context['document_map'] ?? [] as $mapping) {
                    if ((int) ($mapping['loan_document_id'] ?? 0) === (int) $document->id) {
                        $documentIds[(int) ($mapping['transfer_document_id'] ?? 0)] = true;
                        break;
                    }
                }

                return $documentIds;
            }, []);
    }

    private function simpleStateChange(
        Request $request,
        string $number,
        string $workflowCode,
        string $eventTitle,
        string $description,
        ?callable $updater = null,
        string $eventType = 'status',
        ?array $context = null,
        ?string $successMessage = null
    ): JsonResponse {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        try {
            $loan = Loan::query()->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Préstamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan, $workflowCode, $eventTitle, $description, $updater, $eventType, $context) {
                $attributes = ['workflow_status_id' => $this->statusId($workflowCode)];

                if ($updater !== null) {
                    $attributes = array_merge($attributes, $updater($loan));
                }

                $loan->update($attributes);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($workflowCode),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => $eventType,
                    'title' => $eventTitle,
                    'description' => $description,
                    'context' => $context,
                    'occurred_at' => now(),
                ]);
            });

            $notificationService = app(SystemNotificationService::class);
            $freshLoan = $loan->fresh();

            if ($eventTitle === 'Búsqueda en proceso') {
                $notificationService->loanSearchStarted($freshLoan, $request->user());
            } else {
                $notificationService->loanStatusChanged($freshLoan, $request->user(), $workflowCode, $description);
            }

            return response()->json(['message' => $successMessage ?? 'Estado del prestamo actualizado correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo actualizar el prestamo.',
            ], 500);
        }
    }

    private function unitStateChange(
        Request $request,
        string $number,
        string $authCode,
        string $workflowCode,
        string $eventTitle,
        string $description,
        ?callable $updater = null,
        string $eventType = 'status',
        ?array $context = null,
        ?string $successMessage = null
    ): JsonResponse {
        if (!$this->canUnitDirectorAuthorize($request)) {
            return response()->json([
                'message' => 'Solo el Director/Jefe de Unidad puede realizar esta accion.',
            ], 403);
        }

        try {
            $loan = Loan::query()
                ->with(['authorizationStatus', 'workflowStatus'])
                ->where('number', $number)
                ->first();

            if ($loan === null) {
                return response()->json(['message' => 'Préstamo no encontrado.'], 404);
            }

            if (!in_array($loan->unit_id, $this->userUnitIds($request), true)) {
                return response()->json([
                    'message' => 'La solicitud no pertenece a su unidad.',
                ], 403);
            }

            if ($loan->authorizationStatus?->code !== 'loan_auth_pending' || $loan->workflowStatus?->code !== 'loan_status_pending') {
                return response()->json([
                    'message' => 'La solicitud ya no se encuentra pendiente de autorización.',
                ], 422);
            }

            DB::transaction(function () use ($request, $loan, $authCode, $workflowCode, $eventTitle, $description, $updater, $eventType, $context) {
                $attributes = [
                    'authorization_status_id' => $this->statusId($authCode),
                    'workflow_status_id' => $this->statusId($workflowCode),
                ];

                if ($updater !== null) {
                    $attributes = array_merge($attributes, $updater($loan));
                }

                $loan->update($attributes);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($workflowCode),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => $eventType,
                    'title' => $eventTitle,
                    'description' => $description,
                    'context' => $context,
                    'occurred_at' => now(),
                ]);
            });

            $notificationService = app(SystemNotificationService::class);
            $freshLoan = $loan->fresh(['unit']);

            if ($authCode === 'loan_auth_authorized') {
                $notificationService->loanAuthorizedByUnit($freshLoan, $request->user());
            } else {
                $notificationService->loanStatusChanged($freshLoan, $request->user(), $workflowCode, $description);
            }

            return response()->json(['message' => $successMessage ?? 'Solicitud de prestamo actualizada correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo actualizar la solicitud de prestamo.',
            ], 500);
        }
    }

    private function statusId(string $code): ?int
    {
        return RequestStatusCatalog::query()->where('code', $code)->value('id');
    }

    private function canRequesterCreate(Request $request): bool
    {
        return $request->user()?->activeProfile()?->name === 'Unidad Solicitante';
    }

    private function canRequesterCorrectCatalog(Request $request): bool
    {
        return $request->user()?->activeProfile()?->name === 'Unidad Solicitante';
    }

    private function canRequesterCorrectObserved(Request $request, Loan $loan): bool
    {
        if (!$this->canRequesterCorrectCatalog($request)) {
            return false;
        }

        return in_array((int) $loan->unit_id, $this->userUnitIds($request), true);
    }

    private function canUnitDirectorAuthorize(Request $request): bool
    {
        return $request->user()?->activeProfile()?->name === 'Director/Jefe de Unidad';
    }

    private function canUgdaManage(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $profileName = $user->activeProfile()?->name;

        if (in_array($profileName, ['Administrador', 'Usuario UGDA'], true)) {
            return true;
        }

        return $user->units()
            ->where('units.code', 'UGDA')
            ->exists();
    }

    private function canAccessLoan(Request $request, Loan $loan): bool
    {
        if ($this->canUgdaManage($request)) {
            return true;
        }

        return in_array((int) $loan->unit_id, $this->userUnitIds($request), true);
    }

    private function canRegisterDocumentModifications(Request $request, Loan $loan): bool
    {
        if ($this->canUgdaManage($request)) {
            return false;
        }

        if ($request->user()?->activeProfile()?->name !== 'Unidad Solicitante') {
            return false;
        }

        return in_array((int) $loan->unit_id, $this->userUnitIds($request), true);
    }

    private function userUnitIds(Request $request): array
    {
        return $request->user()
            ? $request->user()->units()->pluck('units.id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    private function userName($user): string
    {
        $parts = array_filter([
            $user->person?->first_name,
            $user->person?->second_name,
            $user->person?->first_last_name,
            $user->person?->second_last_name,
        ]);

        return implode(' ', $parts);
    }
}
