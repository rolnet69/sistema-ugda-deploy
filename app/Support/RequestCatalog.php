<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use App\Models\TransferDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RequestCatalog
{
    public static function listings(?User $viewer = null): array
    {
        $transferQuery = Transfer::query()
            ->with(['requester.person', 'unit', 'authorizationStatus', 'workflowStatus'])
            ->where(fn ($query) => $query->whereNull('view_mode')->orWhere('view_mode', '<>', 'archive'))
            ->orderByDesc('requested_at');

        self::scopeToVisibleUnits($transferQuery, $viewer);

        $transfers = $transferQuery
            ->get()
            ->map(fn (Transfer $transfer) => self::mapTransferListing($transfer))
            ->values()
            ->all();

        $loanQuery = Loan::query()
            ->with(['requester.person', 'unit', 'authorizationStatus', 'workflowStatus', 'searchStatus'])
            ->orderByDesc('requested_at');

        self::scopeToVisibleUnits($loanQuery, $viewer);

        $loans = $loanQuery
            ->get()
            ->map(fn (Loan $loan) => self::mapLoanListing($loan))
            ->values()
            ->all();

        $units = collect(array_merge(
            array_column($transfers, 'unit'),
            array_column($loans, 'unit')
        ))->filter()->unique()->values()->all();

        return [
            'transfers' => $transfers,
            'loans' => $loans,
            'units' => $units,
            'filters' => self::filterOptions($transfers, $loans),
        ];
    }

    private static function filterOptions(array $transfers, array $loans): array
    {
        return [
            'transfers' => [
                'auth' => self::statusOptions('transfer', 'authorization', array_column($transfers, 'auth')),
                'status' => self::statusOptions('transfer', 'workflow', array_column($transfers, 'status')),
            ],
            'loans' => [
                'auth' => self::statusOptions('loan', 'authorization', array_column($loans, 'auth')),
                'status' => self::statusOptions('loan', 'workflow', array_column($loans, 'status')),
                'search' => self::statusOptions('loan', 'search', array_column($loans, 'search'), 'Búsqueda: '),
            ],
        ];
    }

    private static function statusOptions(string $requestType, string $category, array $visibleValues = [], string $labelPrefix = ''): array
    {
        $catalogValues = RequestStatusCatalog::query()
            ->where('request_type', $requestType)
            ->where('category', $category)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->pluck('label')
            ->map(fn ($label) => self::normalizeFilterLabel((string) $label, $labelPrefix))
            ->filter()
            ->values()
            ->all();

        $dataValues = collect($visibleValues)
            ->map(fn ($label) => self::normalizeFilterLabel((string) $label, $labelPrefix))
            ->filter(fn ($label) => $label !== '' && $label !== '-')
            ->values()
            ->all();

        return collect($catalogValues)
            ->merge($dataValues)
            ->unique()
            ->values()
            ->all();
    }

    private static function normalizeFilterLabel(string $label, string $prefix = ''): string
    {
        $label = trim($label);

        if ($prefix !== '' && str_starts_with($label, $prefix)) {
            return trim(substr($label, strlen($prefix)));
        }

        return $label;
    }

    public static function transferDetail(string $number, ?User $viewer = null): ?array
    {
        $transfer = Transfer::query()
            ->with([
                'requester.person',
                'unit',
                'authorizationStatus',
                'workflowStatus',
                'authorizedBy.person',
                'completedBy.person',
                'boxes.documents',
                'boxes.assignedBy.person',
                'events.actor.person',
                'events.status',
            ])
            ->where('code', $number)
            ->first();

        if ($transfer === null) {
            return null;
        }

        if (!self::viewerCanAccessRequestUnit($viewer, $transfer->unit_id)) {
            return null;
        }

        $observations = $transfer->events
            ->where('event_type', 'observation')
            ->sortBy('occurred_at')
            ->values();
        $physicalReviewObservations = $observations
            ->filter(fn ($event) => ($event->context['observation_scope'] ?? null) === 'physical_review')
            ->values();
        $initialReviewObservations = $observations
            ->reject(fn ($event) => ($event->context['observation_scope'] ?? null) === 'physical_review')
            ->values();

        $latestDecision = $transfer->events
            ->where('event_type', 'decision')
            ->sortByDesc('occurred_at')
            ->first();
        $latestSchedule = $transfer->events
            ->filter(fn ($event) => $event->status?->code === 'transfer_status_scheduled')
            ->sortByDesc('occurred_at')
            ->first();
        $latestObservation = $initialReviewObservations->sortByDesc('occurred_at')->first();
        $latestPhysicalObservation = $physicalReviewObservations->sortByDesc('occurred_at')->first();
        $latestCorrection = $transfer->events
            ->filter(fn ($event) => $event->title === 'Corregido y reenviado')
            ->sortByDesc('occurred_at')
            ->first();

        $workflowCode = $transfer->workflowStatus?->code;
        $authCode = $transfer->authorizationStatus?->code;
        $viewerProfile = $viewer?->activeProfile()?->name;
        $viewerUnitIds = $viewer
            ? $viewer->units()->pluck('units.id')->all()
            : [];
        $isUnitDirectorReview = $authCode === 'transfer_auth_pending'
            && $workflowCode === 'transfer_status_pending'
            && $viewerProfile === 'Director/Jefe de Unidad'
            && in_array($transfer->unit_id, $viewerUnitIds, true);
        $physicalReviewStatuses = [
            'transfer_status_physical_review',
            'transfer_status_physical_observed',
            'transfer_status_subsanated',
        ];
        $isPhysicalReviewFlow = in_array($workflowCode, $physicalReviewStatuses, true);
        $latestSubsanation = $transfer->events
            ->filter(fn ($event) => $event->status?->code === 'transfer_status_subsanated' || $event->title === 'Subsanada')
            ->sortByDesc('occurred_at')
            ->first();
        $hasSubsanationAfterObservation = $latestPhysicalObservation
            && $latestSubsanation
            && $latestSubsanation->occurred_at?->greaterThan($latestPhysicalObservation->occurred_at);
        $latestApplicantClarification = $transfer->events
            ->filter(fn ($event) => in_array($event->title, ['Subsanada', 'Corregido y reenviado'], true)
                || $event->status?->code === 'transfer_status_subsanated')
            ->sortByDesc('occurred_at')
            ->first();
        $isPhysicalClarification = $latestApplicantClarification
            && ($latestApplicantClarification->status?->code === 'transfer_status_subsanated'
                || $latestApplicantClarification->title === 'Subsanada');
        $clarificationObservation = $isPhysicalClarification ? $latestPhysicalObservation : $latestObservation;
        $hasApplicantClarificationAfterObservation = $clarificationObservation
            && $latestApplicantClarification
            && $latestApplicantClarification->occurred_at?->greaterThan($clarificationObservation->occurred_at);

        $isUgdaReview = $authCode === 'transfer_auth_authorized'
            && $workflowCode === 'transfer_status_pending'
            && self::viewerCanManageUgda($viewer);
        $canCorrectObserved = in_array($workflowCode, ['transfer_status_observed', 'transfer_status_physical_observed'], true)
            && self::viewerCanCorrectObservedTransfer($viewer, $transfer);
        $isReview = $isUnitDirectorReview || $isUgdaReview || ($isPhysicalReviewFlow && self::viewerCanManageUgda($viewer));
        $isScheduled = $workflowCode === 'transfer_status_scheduled';
        $mode = $isUnitDirectorReview ? 'unitAuthorization' : (($isUgdaReview || ($isPhysicalReviewFlow && self::viewerCanManageUgda($viewer))) ? 'review' : 'detail');

        return [
            'number' => $transfer->code,
            'unit' => $transfer->unit?->name,
            'responsible' => self::userDisplayName($transfer->requester),
            'receivedAt' => self::formatDateTime($transfer->requested_at),
            'auth' => $transfer->authorizationStatus?->label ?? 'Pendiente',
            'status' => $transfer->workflowStatus?->label ?? 'Pendiente',
            'pageTitle' => ($isReview || $isScheduled) ? 'Revisar Transferencia Documental' : 'Transferencia #' . $transfer->code,
            'pageSubtitle' => $isReview
                ? 'Solicitud #' . $transfer->code . ' - ' . ($transfer->unit?->name ?? 'Sin unidad')
                : ($isScheduled
                    ? 'Solicitud #' . $transfer->code . ' - ' . ($transfer->unit?->name ?? 'Sin unidad')
                    : 'Detalle de la solicitud'),
            'mode' => $mode,
            'showPrintCard' => (bool) $transfer->show_print_card && self::viewerCanViewBoxLocationLabels($viewer),
            'canViewBoxLocationLabels' => self::viewerCanViewBoxLocationLabels($viewer),
            'boxDisplayState' => $transfer->box_display_state ?: 'collapsed',
            'authorizedBy' => self::userDisplayName($transfer->authorizedBy),
            'authorizedAt' => self::formatDateTime($transfer->authorized_at),
            'description' => $transfer->description,
            'completion' => $workflowCode === 'transfer_status_transferred'
                ? [
                    'date' => self::formatDate($transfer->completed_at),
                    'time' => self::formatTime($transfer->completed_at),
                    'completedBy' => self::userDisplayName($transfer->completedBy),
                    'message' => 'El proceso de transferencia fisica ha sido completado exitosamente',
                ]
                : null,
            'scheduledSummary' => $isScheduled && $latestSchedule
                ? [
                    'title' => 'Fecha de Entrega Programada',
                    'description' => 'La transferencia esta agendada para su entrega fisica',
                    'deliveryDate' => self::formatDate($transfer->scheduled_for),
                    'deliveryTime' => self::formatTime($transfer->scheduled_for),
                    'comments' => $latestSchedule->context['comments'] ?? 'Sin comentarios adicionales.',
                    'scheduledBy' => self::eventActorName($latestSchedule),
                    'scheduledAt' => self::formatDateTime($latestSchedule->occurred_at),
                ]
                : null,
            'summarySheetCard' => self::viewerCanViewTransferSummarySheet($viewer, $transfer)
                ? [
                    'title' => 'Hojas resumen obligatorias',
                    'description' => 'Para que toda la documentacion sea recibida es obligatorio llevar todas las hojas resumen de la transferencia firmadas y selladas por el jefe de la unidad.',
                    'buttonLabel' => 'Visualizar PDF',
                    'url' => SignedPdfUrl::transferSummarySheet($transfer->code),
                ]
                : null,
            'processCard' => ($isScheduled || $workflowCode === 'transfer_status_subsanated') && self::viewerCanManageUgda($viewer)
                ? [
                    'action' => 'startPhysicalReview',
                    'icon' => 'pi pi-eye',
                    'title' => $workflowCode === 'transfer_status_subsanated' ? 'Validar Correcciones' : 'Revisión Física de Documentos',
                    'description' => $workflowCode === 'transfer_status_subsanated'
                        ? 'Inicie una nueva revisión física para validar las correcciones realizadas por el solicitante'
                        : 'Inicie el proceso de revisión física de la documentación antes de asignar ubicaciones',
                    'buttonLabel' => $workflowCode === 'transfer_status_subsanated' ? 'Iniciar nueva revisión' : 'Iniciar revisión física',
                ]
                : null,
            'physicalReviewCard' => $workflowCode === 'transfer_status_physical_review' && self::viewerCanManageUgda($viewer)
                ? [
                    'title' => 'Revisión Física en Proceso',
                    'description' => 'La documentación está siendo revisada. Complete la revisión registrando observaciones o proceda con la asignación de ubicaciones.',
                    'observeLabel' => 'Observar revisión',
                    'transferLabel' => 'Iniciar proceso de transferencia',
                    'canStartTransfer' => true,
                ]
                : ($workflowCode === 'transfer_status_physical_observed' && self::viewerCanManageUgda($viewer)
                    ? [
                        'title' => 'Revisión Física en Proceso',
                        'description' => 'Puede seguir registrando observaciones mientras la solicitud se encuentre en observación de revisión.',
                        'observeLabel' => 'Observar revisión',
                        'transferLabel' => 'Iniciar proceso de transferencia',
                        'canStartTransfer' => false,
                    ]
                    : null),
            'workflowNotice' => $isUnitDirectorReview
                ? [
                    'title' => 'Solicitud pendiente de autorización',
                    'description' => 'Esta transferencia debe ser autorizada por la jefatura de la unidad productora antes de pasar a gestión UGDA.',
                ]
                : ($isUgdaReview && $workflowCode === 'transfer_status_pending'
                ? [
                    'title' => 'Solicitud pendiente de gestión',
                    'description' => 'Esta solicitud ha sido autorizada por la jefatura de unidad y requiere gestión de la UGDA para continuar el proceso.',
                ]
                : null),
            'physicalObservations' => $isPhysicalReviewFlow && $physicalReviewObservations->isNotEmpty()
                ? self::buildPhysicalObservationPanel(
                    $physicalReviewObservations,
                    $workflowCode,
                    $hasSubsanationAfterObservation,
                    $workflowCode === 'transfer_status_physical_observed'
                        && self::viewerCanCorrectObservedTransfer($viewer, $transfer)
                )
                : null,
            'subsanationSummary' => $hasApplicantClarificationAfterObservation
                && in_array($workflowCode, ['transfer_status_pending', 'transfer_status_subsanated', 'transfer_status_physical_review'], true)
                ? [
                    'title' => $isPhysicalClarification ? 'Observaciones Subsanadas' : 'Aclaraciones del solicitante',
                    'notesLabel' => 'Aclaraciones del solicitante:',
                    'notes' => $latestApplicantClarification->context['correction_notes'] ?? 'Sin aclaraciones registradas.',
                    'description' => $isPhysicalClarification
                        ? 'El solicitante ha corregido las observaciones y reenviado la solicitud para nueva validación'
                        : 'El solicitante ha atendido las observaciones de UGDA y reenviado la solicitud para nueva validación',
                    'message' => $isPhysicalClarification
                        ? 'Se ha recibido la documentación corregida. Revise las aclaraciones del solicitante e inicie una nueva revisión física para validar los cambios realizados.'
                        : 'Se han recibido las aclaraciones del solicitante. Revise la respuesta antes de continuar con la autorización o gestión de la solicitud.',
                ]
                : null,
            'observationsRegistered' => $workflowCode === 'transfer_status_observed' && $initialReviewObservations->isNotEmpty()
                ? [
                    'count' => $initialReviewObservations->count(),
                    'title' => 'Observaciones Registradas',
                    'description' => 'Esta solicitud tiene ' . $observations->count() . ' observación(es) registrada(s)',
                    'canCorrect' => $canCorrectObserved,
                    'actionLabel' => 'Editar solicitud y corregir observaciones',
                    'items' => $initialReviewObservations->map(fn ($event) => [
                        'actor' => self::eventActorName($event),
                        'dateTime' => self::formatDateTime($event->occurred_at),
                        'message' => $event->description,
                    ])->values()->all(),
                ]
                : null,
            'resolvedObservation' => $workflowCode === 'transfer_status_pending'
                && $latestObservation
                && $latestCorrection
                && $latestCorrection->occurred_at?->greaterThan($latestObservation->occurred_at)
                ? [
                    'title' => 'Observación resuelta',
                    'description' => 'La unidad solicitante corrigio la solicitud y la reenvió a jefatura para autorización.',
                    'observedBy' => self::eventActorName($latestObservation),
                    'observedAt' => self::formatDateTime($latestObservation->occurred_at),
                    'observation' => $latestObservation->description,
                    'resolvedBy' => self::eventActorName($latestCorrection),
                    'resolvedAt' => self::formatDateTime($latestCorrection->occurred_at),
                ]
                : null,
            'decisionCard' => $workflowCode === 'transfer_status_denied' && $latestDecision
                ? self::mapTransferDecisionCard($latestDecision)
                : null,
            'documentSummary' => $isReview ? self::buildTransferDocumentSummary($transfer) : null,
            'boxes' => $transfer->boxes->map(fn ($box) => self::mapTransferBox($transfer, $box, $viewer))->values()->all(),
            'actions' => $isUnitDirectorReview
                ? self::transferUnitAuthorizationActions()
                : ($isUgdaReview ? self::transferReviewActions($workflowCode) : []),
            'history' => self::mapHistory($transfer->events),
        ];
    }

    public static function transferSummarySheet(string $number, ?User $viewer = null): ?array
    {
        $transfer = Transfer::query()
            ->with([
                'requester.person',
                'unit',
                'authorizationStatus',
                'workflowStatus',
                'authorizedBy.person',
                'boxes.documents',
            ])
            ->where('code', $number)
            ->first();

        if ($transfer === null || !self::viewerCanViewTransferSummarySheet($viewer, $transfer)) {
            return null;
        }

        return [
            'number' => $transfer->code,
            'unit' => $transfer->unit?->name ?: 'N/D',
            'responsible' => self::userDisplayName($transfer->requester) ?: 'N/D',
            'authorizedBy' => self::userDisplayName($transfer->authorizedBy) ?: 'N/D',
            'authorizedAt' => self::formatDateTime($transfer->authorized_at) ?: 'N/D',
            'requestedAt' => self::formatDateTime($transfer->requested_at) ?: 'N/D',
            'scheduledFor' => self::formatDateTime($transfer->scheduled_for) ?: 'N/D',
            'totalBoxes' => $transfer->boxes->count(),
            'totalDocuments' => $transfer->boxes->sum(fn ($box) => $box->documents->count()),
            'boxes' => $transfer->boxes
                ->sortBy('box_number')
                ->map(function ($box) use ($transfer) {
                    return [
                        'number' => str_pad((string) $box->box_number, 3, '0', STR_PAD_LEFT),
                        'code' => $box->boxCode($transfer->code),
                        'title' => $box->title ?: 'Caja ' . $box->boxCode($transfer->code),
                        'period' => $box->period_label ?: self::buildPeriodLabel($box->start_year, $box->end_year),
                        'series' => $box->series_name,
                        'documents' => $box->documents->map(fn ($document) => [
                            'code' => $document->code,
                            'name' => $document->name,
                            'series' => $document->series_label ?: 'N/D',
                            'year' => $document->year_label ?: 'N/D',
                            'pages' => $document->pages_label ?: 'N/D',
                            'support' => $document->support_type ?: 'N/D',
                        ])->values()->all(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    public static function loanDetail(string $number, ?User $viewer = null): ?array
    {
        $loan = Loan::query()
            ->with([
                'requester.person',
                'unit',
                'authorizationStatus',
                'workflowStatus',
                'searchStatus',
                'authorizedBy.person',
                'ugdaAuthorizedBy.person',
                'searchStartedBy.person',
                'searchCompletedBy.person',
                'documents',
                'documentModifications.document',
                'documentModifications.registeredBy.person',
                'events.actor.person',
                'events.status',
                'latestDispatch.deliveredBy.person',
                'latestDispatch.items.document',
                'latestReturn.receivedBy.person',
                'latestReturn.items.document',
            ])
            ->where('number', $number)
            ->first();

        if ($loan === null) {
            return null;
        }

        if (!self::viewerCanAccessRequestUnit($viewer, $loan->unit_id)) {
            return null;
        }

        $observations = $loan->events->where('event_type', 'observation')->sortBy('occurred_at')->values();
        $latestDecision = $loan->events->where('event_type', 'decision')->sortByDesc('occurred_at')->first();
        $latestObservation = $observations->sortByDesc('occurred_at')->first();
        $latestApplicantClarification = $loan->events
            ->filter(fn ($event) => $event->title === 'Corregido y reenviado')
            ->sortByDesc('occurred_at')
            ->first();
        $hasApplicantClarificationAfterObservation = $latestObservation
            && $latestApplicantClarification
            && $latestApplicantClarification->occurred_at?->greaterThan($latestObservation->occurred_at);
        $workflowCode = $loan->workflowStatus?->code;
        $authCode = $loan->authorizationStatus?->code;
        $searchCode = $loan->searchStatus?->code;
        $canManageUgda = self::viewerCanManageUgda($viewer);
        $isManage = $loan->view_mode === 'manage'
            && $canManageUgda
            && $authCode === 'loan_auth_authorized';
        $viewerProfile = $viewer?->activeProfile()?->name;
        $viewerUnitIds = $viewer
            ? $viewer->units()->pluck('units.id')->all()
            : [];
        $isUnitDirectorReview = $authCode === 'loan_auth_pending'
            && $workflowCode === 'loan_status_pending'
            && $viewerProfile === 'Director/Jefe de Unidad'
            && in_array($loan->unit_id, $viewerUnitIds, true);
        $canCorrectObserved = $workflowCode === 'loan_status_observed'
            && $authCode === 'loan_auth_authorized'
            && self::viewerCanCorrectObservedLoan($viewer, $loan);

        return [
            'number' => $loan->number,
            'unit' => $loan->unit?->name,
            'applicant' => self::userDisplayName($loan->requester),
            'requestedBy' => self::userDisplayName($loan->requester),
            'requestedAt' => self::formatDateTime($loan->requested_at),
            'auth' => $loan->authorizationStatus?->label ?? 'Pendiente',
            'status' => $loan->workflowStatus?->label ?? 'Pendiente',
            'search' => $loan->searchStatus?->label ? preg_replace('/^(?:Búsqueda|Busqueda):\s*/u', '', $loan->searchStatus->label) : '-',
            'pageTitle' => $isUnitDirectorReview ? 'Revisar solicitud de préstamo' : ($isManage ? 'Gestionar préstamo documental' : 'Préstamo #' . $loan->number),
            'pageSubtitle' => $isUnitDirectorReview
                ? 'Solicitud #' . $loan->number . ' - ' . ($loan->unit?->name ?? 'Sin unidad')
                : ($isManage
                ? 'Solicitud #' . $loan->number . ' - ' . ($loan->unit?->name ?? 'Sin unidad')
                : 'Detalle de la solicitud'),
            'mode' => $isUnitDirectorReview ? 'unitAuthorization' : ($isManage ? 'manage' : 'detail'),
            'generalSectionTitle' => ($isManage || $isUnitDirectorReview) ? 'Datos generales' : 'Información general',
            'documentsSectionTitle' => $workflowCode === 'loan_status_loaned' ? 'Documentos prestados' : 'Documentos solicitados',
            'authorizedBy' => self::userDisplayName($loan->authorizedBy),
            'authorizedAt' => self::formatDateTime($loan->authorized_at),
            'chips' => self::loanChips($loan),
            'workflowNotice' => $isUnitDirectorReview
                ? [
                    'title' => 'Solicitud de prestamo pendiente de autorización',
                    'description' => 'Revise los documentos solicitados antes de tomar una decision.',
                ]
                : ($isManage && $workflowCode === 'loan_status_pending'
                ? [
                    'title' => 'Solicitud de prestamo pendiente de gestión',
                    'description' => 'Esta solicitud ha sido autorizada por el Director y requiere su gestión.',
                ]
                : null),
            'observationsRegistered' => $workflowCode === 'loan_status_observed' && $observations->isNotEmpty()
                ? [
                    'count' => $observations->count(),
                    'title' => 'Observaciones Registradas',
                    'description' => 'Esta solicitud tiene ' . $observations->count() . ' observación(es) registrada(s)',
                    'canCorrect' => $canCorrectObserved,
                    'actionLabel' => 'Editar solicitud y corregir observaciones',
                    'items' => $observations->map(fn ($event) => [
                        'actor' => self::eventActorName($event),
                        'dateTime' => self::formatDateTime($event->occurred_at),
                        'message' => $event->description,
                    ])->values()->all(),
                ]
                : null,
            'subsanationSummary' => $hasApplicantClarificationAfterObservation
                && $workflowCode === 'loan_status_pending'
                ? [
                    'title' => 'Aclaraciones del solicitante',
                    'notesLabel' => 'Aclaraciones del solicitante:',
                    'notes' => $latestApplicantClarification->context['correction_notes'] ?? 'Sin aclaraciones registradas.',
                    'description' => 'El solicitante ha atendido las observaciones de UGDA y reenviado la solicitud para nueva validación',
                    'message' => 'Se han recibido las aclaraciones del solicitante. Revise la respuesta antes de continuar con la autorización o gestión de la solicitud.',
                ]
                : null,
            'searchProgress' => $searchCode === 'loan_search_in_progress'
                ? [
                    'title' => 'Búsqueda en proceso',
                    'description' => 'Los documentos están siendo localizados en el archivo.',
                ]
                : null,
            'searchCompleted' => in_array($searchCode, ['loan_search_completed', 'loan_search_not_found'], true)
                && $workflowCode === 'loan_status_authorized'
                ? self::buildLoanSearchCompleted($loan)
                : null,
            'readyForPickup' => self::viewerCanViewLoanReadyNotice($viewer, $loan)
                && $searchCode === 'loan_search_completed'
                && $workflowCode === 'loan_status_authorized'
                ? self::buildLoanReadyForPickup($loan)
                : null,
            'loanSummary' => $workflowCode === 'loan_status_loaned'
                ? self::buildLoanSummary($loan)
                : null,
            'loanSummarySheetCard' => self::viewerCanViewLoanSummarySheet($viewer, $loan)
                ? [
                    'title' => 'Hoja de préstamo',
                    'description' => 'Imprima esta hoja desde UGDA para obtener la firma de la persona que recibe los documentos.',
                    'buttonLabel' => 'Visualizar PDF',
                    'url' => SignedPdfUrl::loanSummarySheet($loan->number),
                ]
                : null,
            'returnSummary' => $workflowCode === 'loan_status_returned'
                ? self::buildLoanReturnSummary($loan)
                : null,
            'decisionCard' => $latestDecision ? self::mapLoanDecisionCard($latestDecision) : null,
            'documentGroups' => $searchCode === 'loan_search_not_found' ? [] : self::mapLoanDocumentGroups($loan),
            'actions' => $isUnitDirectorReview ? self::loanUnitAuthorizationActions() : ($isManage ? self::loanActions($loan) : []),
            'loanRegistration' => $isManage ? self::buildLoanRegistrationConfig($loan) : null,
            'documentModifications' => self::buildLoanDocumentModificationsConfig($loan, $viewer),
            'returnRegistration' => $isManage ? self::buildReturnRegistrationConfig($loan) : null,
            'history' => self::mapHistory($loan->events),
        ];
    }

    public static function loanSummarySheet(string $number, ?User $viewer = null): ?array
    {
        $loan = Loan::query()
            ->with([
                'requester.person',
                'unit',
                'latestDispatch.deliveredBy.person',
                'latestDispatch.items.document',
            ])
            ->where('number', $number)
            ->first();

        if ($loan === null || !self::viewerCanViewLoanSummarySheet($viewer, $loan)) {
            return null;
        }

        $dispatch = $loan->latestDispatch;

        return [
            'number' => $loan->number,
            'requestedAt' => self::formatDateTime($loan->requested_at) ?: 'N/D',
            'unit' => $loan->unit?->name ?: 'N/D',
            'applicant' => self::userDisplayName($loan->requester) ?: 'N/D',
            'loanDate' => self::formatDate($dispatch->loan_date) ?: 'N/D',
            'dueDate' => self::formatDate($dispatch->due_date) ?: 'N/D',
            'receivedBy' => $dispatch->received_by_name ?: 'N/D',
            'deliveredBy' => self::userDisplayName($dispatch->deliveredBy) ?: 'N/D',
            'observations' => $dispatch->observations ?: 'Sin observaciones.',
            'totalDocuments' => $dispatch->items->count(),
            'documents' => $dispatch->items
                ->map(fn ($item) => [
                    'title' => $item->document?->title ?: 'N/D',
                    'series' => $item->document?->series_label ?: 'N/D',
                    'box' => $item->document?->box_code ?: 'N/D',
                    'year' => $item->document?->year_label ?: 'N/D',
                    'documentType' => $item->document?->document_type_label ?: 'N/D',
                ])
                ->values()
                ->all(),
        ];
    }

    public static function transferDocumentDownload(string $number, string $reference, ?User $viewer = null): ?array
    {
        $documentQuery = TransferDocument::query()
            ->whereHas('box.transfer', fn ($query) => $query->where('code', $number))
            ->with(['box.transfer.workflowStatus']);

        if (preg_match('/^document-(\d+)$/', $reference, $matches) === 1) {
            $documentQuery->whereKey((int) $matches[1]);
        } else {
            $documentQuery->where('code', $reference);
        }

        $document = $documentQuery->first();

        if ($document === null || empty($document->digital_file_name)) {
            return null;
        }

        $transfer = $document->box?->transfer;

        if (!self::viewerCanAccessRequestUnit($viewer, $transfer?->unit_id)) {
            return null;
        }

        if (self::shouldHideCompletedDigitalDocument($transfer, $document, $viewer)) {
            return null;
        }

        return [
            'code' => $document->code,
            'name' => $document->name,
            'series' => $document->series_label,
            'support' => $document->support_type,
            'year' => $document->year_label,
            'pages' => $document->pages_label,
            'digitalFile' => $document->digital_file_name,
            'digitalPath' => $document->digital_file_path,
            'boxNumber' => str_pad((string) $document->box?->box_number, 3, '0', STR_PAD_LEFT),
            'boxCode' => $document->box?->transfer
                ? $document->box->boxCode($document->box->transfer->code)
                : null,
        ];
    }

    public static function dashboard(User $user): array
    {
        $profile = $user->activeProfile()?->name;
        $activeUnit = $user->units()->wherePivot('is_active', true)->first() ?? $user->units()->first();
        $activeUnitName = $activeUnit?->name;

        $listings = self::listings($user);
        $transfers = collect($listings['transfers'])->map(fn ($item) => ['type' => 'Transferencia'] + $item);
        $loans = collect($listings['loans'])->map(fn ($item) => ['type' => 'Prestamo'] + $item);
        $all = $transfers->concat($loans)->values();

        $alerts = match ($profile) {
            'Director/Jefe de Unidad' => $all
                ->filter(fn ($item) => $item['auth'] === 'Pendiente' && ($activeUnitName === null || $item['unit'] === $activeUnitName))
                ->values(),
            'Unidad Solicitante' => $all
                ->filter(fn ($item) => in_array($item['status'], ['Pendiente', 'Observado'], true) && ($activeUnitName === null || $item['unit'] === $activeUnitName))
                ->values(),
            default => $all
                ->filter(fn ($item) => ($item['type'] === 'Transferencia' && $item['auth'] === 'Autorizado' && in_array($item['status'], ['Agendado', 'Pendiente', 'Observado'], true))
                    || ($item['type'] === 'Prestamo' && ($item['search'] === 'En proceso' || in_array($item['status'], ['Pendiente', 'Observado'], true))))
                ->values(),
        };

        $stats = [
            [
                'title' => 'Total de Solicitudes',
                'value' => (string) $all->count(),
                'icon' => 'pi pi-file',
                'color' => 'text-blue-500',
                'bg' => 'bg-blue-50',
            ],
            [
                'title' => 'Autorizadas',
                'value' => (string) $all->where('auth', 'Autorizado')->count(),
                'icon' => 'pi pi-check-circle',
                'color' => 'text-green-500',
                'bg' => 'bg-green-50',
            ],
            [
                'title' => 'Pendientes',
                'value' => (string) $alerts->count(),
                'icon' => 'pi pi-clock',
                'color' => 'text-gray-500',
                'bg' => 'bg-gray-100',
            ],
            [
                'title' => 'Denegadas',
                'value' => (string) $all->where('auth', 'Denegado')->count(),
                'icon' => 'pi pi-times-circle',
                'color' => 'text-red-500',
                'bg' => 'bg-red-50',
            ],
        ];

        $activeUnits = $all
            ->groupBy('unit')
            ->map(function (Collection $items, string $unit) use ($all) {
                $count = $items->count();
                $percent = max(15, (int) round(($count / max(1, $all->count())) * 100));

                return [
                    'name' => $unit,
                    'count' => $count,
                    'percent' => min($percent, 100),
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->all();

        $recentRequests = $all
            ->map(function ($item) {
                $rawDate = $item['receivedAt'] ?? $item['requestedAt'];
                $date = Carbon::createFromFormat('d/m/Y H:i', $rawDate);

                return [
                    'id' => '#' . $item['number'],
                    'user' => $item['responsible'] ?? $item['applicant'],
                    'date' => $rawDate,
                    'status' => $item['status'],
                    'type' => $item['type'],
                    'sort_date' => $date,
                ];
            })
            ->sortByDesc('sort_date')
            ->take(4)
            ->map(fn ($item) => collect($item)->except('sort_date')->all())
            ->values()
            ->all();

        return [
            'stats' => $stats,
            'alerts' => [
                'count' => $alerts->count(),
                'title' => match ($profile) {
                    'Director/Jefe de Unidad' => 'solicitudes pendientes de autorización',
                    'Unidad Solicitante' => 'solicitudes pendientes de seguimiento',
                    default => 'solicitudes pendientes de gestión',
                },
                'description' => match ($profile) {
                    'Director/Jefe de Unidad' => 'Las siguientes solicitudes requieren tu revisión y autorización:',
                    'Unidad Solicitante' => 'Las siguientes solicitudes requieren tu atención o seguimiento:',
                    default => 'Las siguientes solicitudes requieren gestión desde UGDA:',
                },
                'items' => $alerts->map(fn ($item) => [
                    'type' => $item['type'],
                    'number' => $item['number'],
                    'title' => $item['type'] . ' #' . $item['number'],
                    'subtitle' => $item['unit'] . ' · ' . ($item['responsible'] ?? $item['applicant']),
                    'route' => $item['type'] === 'Transferencia'
                        ? '/solicitudes/transferencias/' . $item['number']
                        : '/solicitudes/prestamos/' . $item['number'],
                ])->values()->all(),
            ],
            'active_units' => $activeUnits,
            'recent_requests' => $recentRequests,
        ];
    }

    private static function mapTransferListing(Transfer $transfer): array
    {
        return [
            'number' => $transfer->code,
            'responsible' => self::userDisplayName($transfer->requester),
            'unit' => $transfer->unit?->name,
            'receivedAt' => self::formatDateTime($transfer->requested_at),
            'auth' => $transfer->authorizationStatus?->label ?? 'Pendiente',
            'status' => $transfer->workflowStatus?->label ?? 'Pendiente',
        ];
    }

    private static function mapLoanListing(Loan $loan): array
    {
        return [
            'number' => $loan->number,
            'applicant' => self::userDisplayName($loan->requester),
            'unit' => $loan->unit?->name,
            'requestedAt' => self::formatDateTime($loan->requested_at),
            'auth' => $loan->authorizationStatus?->label ?? 'Pendiente',
            'status' => $loan->workflowStatus?->label ?? 'Pendiente',
            'search' => $loan->searchStatus?->label ? preg_replace('/^(?:Búsqueda|Busqueda):\s*/u', '', $loan->searchStatus->label) : '-',
        ];
    }

    private static function buildTransferDocumentSummary(Transfer $transfer): array
    {
        $rows = $transfer->boxes->map(function ($box, $index) use ($transfer) {
            $seriesList = self::splitSeriesLabels($box->series_name);

            if ($seriesList->isEmpty()) {
                $seriesList = $box->documents
                    ->pluck('series_label')
                    ->filter()
                    ->unique()
                    ->values();
            }

            return [
                'index' => $index + 1,
                'series' => $box->series_name,
                'seriesList' => $seriesList->values()->all(),
                'startYear' => (string) $box->start_year,
                'endYear' => (string) $box->end_year,
                'boxNumber' => (string) $box->box_number,
                'boxCode' => $box->boxCode($transfer->code),
                'documentsLabel' => $box->documents->count() . ' doc(s)',
            ];
        })->values()->all();

        $totalDocuments = $transfer->boxes->sum(fn ($box) => $box->documents->count());

        return [
            'rows' => $rows,
            'totalBoxes' => $transfer->boxes->count(),
            'totalDocuments' => $totalDocuments,
            'summaryText' => $totalDocuments . ' documentos distribuidos en ' . $transfer->boxes->count() . ' cajas',
            'boxesTitle' => 'Documentos por Caja',
        ];
    }

    private static function buildPhysicalObservationPanel(Collection $observations, ?string $workflowCode, bool $hasSubsanationAfterObservation, bool $canCorrect): array
    {
        $count = $observations->count();
        $items = $observations->map(fn ($event) => [
            'actor' => self::eventActorName($event),
            'dateTime' => self::formatDateTime($event->occurred_at),
            'message' => $event->description,
        ])->values()->all();

        if ($workflowCode === 'transfer_status_physical_review' && $hasSubsanationAfterObservation) {
            return [
                'count' => $count,
                'title' => 'Observaciones Previas de Revisión Física',
                'description' => 'Estas son las observaciones previas que se registraron. Valide que hayan sido corregidas durante esta nueva revisión:',
                'instruction' => 'Si las observaciones fueron corregidas, proceda con el proceso de transferencia. Si encuentra nuevas observaciones, regístrelas usando el botón "Observar revisión"',
                'canCorrect' => false,
                'actionLabel' => null,
                'items' => $items,
            ];
        }

        if ($workflowCode === 'transfer_status_subsanated') {
            return [
                'count' => $count,
                'title' => 'Observaciones de Revisión Física',
                'description' => 'La solicitud ha sido subsanada. Revise las siguientes ' . $count . ' observaciones que se hicieron para validar las correcciones:',
                'instruction' => 'Inicie una nueva revisión física para validar que las observaciones han sido corregidas',
                'canCorrect' => false,
                'actionLabel' => null,
                'items' => $items,
            ];
        }

        return [
            'count' => $count,
            'title' => 'Observaciones de Revisión Física',
            'description' => $workflowCode === 'transfer_status_physical_observed'
                ? 'La revisión física encontró ' . $count . ' observaciones que debe corregir'
                : 'La revisión física encontró ' . $count . ' observaciones registrada(s)',
            'instruction' => $canCorrect ? 'Puede editar la solicitud para corregir las observaciones y volver a enviarla' : null,
            'canCorrect' => $canCorrect,
            'actionLabel' => 'Editar solicitud y corregir observaciones',
            'items' => $items,
        ];
    }

    private static function mapTransferBox(Transfer $transfer, $box, ?User $viewer = null): array
    {
        $number = str_pad((string) $box->box_number, 3, '0', STR_PAD_LEFT);
        $boxCode = $box->boxCode($transfer->code);
        $seriesList = self::splitSeriesLabels($box->series_name);

        if ($seriesList->isEmpty()) {
            $seriesList = $box->documents
                ->pluck('series_label')
                ->filter()
                ->unique()
                ->values();
        }

        return [
            'number' => $number,
            'code' => $boxCode,
            'boxCode' => $boxCode,
            'title' => $box->title ?: 'Caja ' . $boxCode,
            'series' => $box->series_name,
            'seriesList' => $seriesList->values()->all(),
            'contentDescription' => $box->content_description,
            'period' => $box->period_label ?: self::buildPeriodLabel($box->start_year, $box->end_year),
            'documentsCount' => $box->documents->count(),
            'physicalLocation' => $box->location_code,
            'isLocated' => !empty($box->location_code),
            'documents' => $box->documents->map(function ($document) use ($transfer, $viewer) {
                $hideDigitalSupport = self::shouldHideCompletedDigitalDocument($transfer, $document, $viewer);
                $hasDigitalFile = self::supportsDigitalFile($document->support_type)
                    && !empty($document->digital_file_name);

                return [
                    'code' => $document->code,
                    'name' => $document->name,
                    'series' => $document->series_label,
                    'support' => $hideDigitalSupport ? null : $document->support_type,
                    'supportRestricted' => $hideDigitalSupport,
                    'year' => $document->year_label,
                    'pages' => $document->pages_label,
                    'digitalFile' => $hideDigitalSupport || !$hasDigitalFile ? null : $document->digital_file_name,
                    'digitalAvailable' => !$hideDigitalSupport && $hasDigitalFile,
                    'digitalRestricted' => $hideDigitalSupport && $hasDigitalFile,
                    'digitalUrl' => !$hideDigitalSupport && $hasDigitalFile
                        ? SignedPdfUrl::transferDocument($transfer->code, SignedPdfUrl::transferDocumentReference($document->id))
                        : null,
                ];
            })->values()->all(),
        ];
    }

    private static function shouldHideCompletedDigitalDocument(?Transfer $transfer, TransferDocument $document, ?User $viewer): bool
    {
        if ($transfer?->workflowStatus?->code !== 'transfer_status_transferred') {
            return false;
        }

        if (self::viewerCanManageUgda($viewer)) {
            return false;
        }

        return self::isDigitalSupport($document->support_type) || !empty($document->digital_file_name);
    }

    private static function isDigitalSupport(?string $supportType): bool
    {
        return self::supportsDigitalFile($supportType);
    }

    private static function supportsDigitalFile(?string $supportType): bool
    {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim((string) $supportType));
        $normalized = strtolower($normalized !== false ? $normalized : trim((string) $supportType));

        return in_array($normalized, ['digital', 'mixto'], true);
    }

    private static function splitSeriesLabels(?string $value): Collection
    {
        return collect(explode(';', (string) $value))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->unique()
            ->values();
    }

    private static function mapTransferDecisionCard($event): array
    {
        $context = $event->context ?? [];
        $scope = $context['decision_scope'] ?? 'ugda';

        return [
            'variant' => 'danger',
            'title' => $scope === 'unit' ? 'Solicitud Denegada' : 'Solicitud Rechazada',
            'subtitle' => $scope === 'unit'
                ? 'La solicitud fue denegada por el director/jefe de la unidad productora'
                : 'La solicitud fue rechazada por UGDA',
            'actor' => self::eventActorName($event),
            'dateTime' => self::formatDateTime($event->occurred_at),
            'reasonLabel' => $context['reason_label'] ?? 'Motivo:',
            'reason' => $context['reason'] ?? $event->description,
            'icon' => 'pi pi-times-circle',
        ];
    }

    private static function buildLoanSearchCompleted(Loan $loan): array
    {
        $groups = $loan->documents
            ->where('found_in_search', true)
            ->groupBy('group_title')
            ->map(fn ($documents, $title) => [
                'title' => $title,
                'items' => $documents->map(fn ($document) => [
                    'title' => $document->title,
                    'meta' => collect([$document->series_label, $document->box_code ? 'Caja ' . $document->box_code : null])->filter()->implode(' • '),
                ])->values()->all(),
            ])
            ->values()
            ->all();
        $isNotFound = $loan->searchStatus?->code === 'loan_search_not_found';

        return [
            'title' => $isNotFound ? 'Búsqueda sin resultados' : 'Búsqueda finalizada',
            'description' => $isNotFound
                ? 'Los documentos solicitados no fueron encontrados.'
                : ($groups ? 'Algunos documentos fueron encontrados.' : 'No se encontraron documentos disponibles.'),
            'completedBy' => self::userDisplayName($loan->searchCompletedBy),
            'completedAt' => self::formatDateTime($loan->search_completed_at),
            'comments' => $isNotFound ? $loan->search_comments : ($loan->search_comments ?: 'Sin comentarios adicionales.'),
            'groups' => $groups,
            'result' => $isNotFound ? 'not_found' : 'completed',
            'tone' => $isNotFound ? 'danger' : 'warning',
        ];
    }

    private static function buildLoanReadyForPickup(Loan $loan): array
    {
        return [
            'title' => 'Documentos Listos para Retiro',
            'description' => 'El proceso de búsqueda ha finalizado. Los documentos solicitados están disponibles para su retiro.',
            'instructions' => [
                'Presente su identificación en el archivo de la UGDA',
                'Mencione el número de préstamo: ' . $loan->number,
                'Firme el registro de préstamo de documentos',
                'Horario de atención: Lunes a Viernes, 8:00 AM - 4:00 PM',
            ],
        ];
    }

    private static function buildLoanSummary(Loan $loan): ?array
    {
        $dispatch = $loan->latestDispatch;

        if ($dispatch === null) {
            return null;
        }

        $groups = $dispatch->items
            ->map(fn ($item) => $item->document)
            ->filter()
            ->groupBy('group_title')
            ->map(fn ($documents, $title) => [
                'title' => $title,
                'items' => $documents->map(fn ($document) => [
                    'title' => $document->title,
                    'meta' => collect([$document->series_label, $document->box_code ? 'Caja ' . $document->box_code : null, $document->year_label])->filter()->implode(' • '),
                    'documentType' => $document->document_type_label,
                    'documentTypeColor' => $document->document_type_tone,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'title' => 'Documentos en préstamo',
            'description' => 'Los documentos fueron entregados y deben ser devueltos en la fecha indicada.',
            'loanDate' => self::formatDate($dispatch->loan_date),
            'dueDate' => self::formatDate($dispatch->due_date),
            'responsible' => self::userDisplayName($loan->requester),
            'receivedBy' => $dispatch->received_by_name,
            'deliveredBy' => self::userDisplayName($dispatch->deliveredBy),
            'groups' => $groups,
            'observations' => $dispatch->observations,
        ];
    }

    private static function buildLoanReturnSummary(Loan $loan): ?array
    {
        $return = $loan->latestReturn;

        if ($return === null) {
            return null;
        }

        return [
            'title' => 'Documentos Devueltos',
            'description' => 'El prestamo ha sido completado exitosamente',
            'returnDate' => self::formatDate($return->return_date),
            'returnTime' => self::formatTime($return->created_at),
            'receivedBy' => self::userDisplayName($return->receivedBy),
            'condition' => $return->condition_label,
            'observations' => $return->observations,
        ];
    }

    private static function buildLoanDocumentModificationsConfig(Loan $loan, ?User $viewer): ?array
    {
        $workflowCode = $loan->workflowStatus?->code;
        $viewerCanEdit = $workflowCode === 'loan_status_loaned'
            && !self::viewerCanManageUgda($viewer)
            && $viewer?->activeProfile()?->name === 'Unidad Solicitante'
            && self::viewerCanAccessRequestUnit($viewer, $loan->unit_id);
        $modifications = $loan->documentModifications
            ->sortBy('created_at')
            ->values();

        if (
            $loan->authorizationStatus?->code !== 'loan_auth_authorized'
            || !in_array($workflowCode, ['loan_status_loaned', 'loan_status_returned'], true)
            || $loan->searchStatus?->code !== 'loan_search_completed'
            || !self::viewerCanAccessRequestUnit($viewer, $loan->unit_id)
            || (!$viewerCanEdit && $modifications->isEmpty())
        ) {
            return null;
        }

        $documents = $loan->documents
            ->where('selected_for_loan', true)
            ->values();

        if ($documents->isEmpty()) {
            return null;
        }

        return [
            'title' => !$viewerCanEdit
                ? 'Modificaciones Reportadas por el Solicitante'
                : 'Modificaciones a Documentos',
            'returnTitle' => 'Modificaciones reportadas por el solicitante',
            'description' => !$viewerCanEdit
                ? 'El solicitante registro ' . $modifications->count() . ' modificacion(es) sobre los documentos durante el periodo del prestamo.'
                : ($modifications->isEmpty()
                ? 'Si ha realizado alguna modificacion a los documentos que tiene en prestamo, registrela aqui. La UGDA vera esta información al recibir la devolución.'
                : 'Ha registrado modificaciones en documentos prestados. Puede actualizar esta información cuando sea necesario.'),
            'returnDescription' => 'El solicitante ha reportado las siguientes modificaciones realizadas a los documentos durante el periodo del prestamo:',
            'buttonLabel' => $modifications->isEmpty() ? 'Registrar modificaciones' : 'Actualizar modificaciones',
            'canEdit' => $viewerCanEdit,
            'showInDetail' => $viewerCanEdit || $workflowCode === 'loan_status_returned',
            'count' => $modifications->count(),
            'documents' => $documents->map(fn ($document) => [
                'id' => $document->id,
                'title' => $document->title,
                'series' => $document->series_label,
                'box' => $document->box_code,
                'year' => $document->year_label,
                'documentType' => $document->document_type_label,
                'documentTypeColor' => $document->document_type_tone,
            ])->values()->all(),
            'items' => $modifications->map(fn ($modification) => [
                'id' => $modification->id,
                'loanDocumentId' => $modification->loan_document_id,
                'documentTitle' => $modification->document?->title ?? 'Documento',
                'description' => $modification->description,
                'registeredBy' => self::userDisplayName($modification->registeredBy),
                'registeredAt' => self::formatDateTime($modification->created_at),
            ])->values()->all(),
        ];
    }

    private static function mapLoanDecisionCard($event): array
    {
        $context = $event->context ?? [];
        $scope = $context['decision_scope'] ?? 'ugda';

        return [
            'title' => $scope === 'unit' ? 'Solicitud Denegada' : 'Solicitud Rechazada',
            'subtitle' => $scope === 'unit'
                ? 'La solicitud fue denegada por el jefe/director de la unidad solicitante'
                : 'La solicitud fue rechazada por UGDA',
            'actor' => self::eventActorName($event),
            'dateTime' => self::formatDateTime($event->occurred_at),
            'reasonLabel' => $context['reason_label'] ?? 'Motivo:',
            'reason' => $context['reason'] ?? $event->description,
            'icon' => 'pi pi-times-circle',
        ];
    }

    private static function mapLoanDocumentGroups(Loan $loan): array
    {
        return $loan->documents
            ->groupBy('group_title')
            ->map(function ($documents, $title) {
                $kind = $documents->first()->document_kind;

                return [
                    'kind' => $kind,
                    'title' => $title,
                    'items' => $documents->map(fn ($document) => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'series' => $document->series_label,
                        'box' => $document->box_code,
                        'year' => $document->year_label,
                        'unit' => $document->unit_name_snapshot,
                        'documentType' => $document->document_type_label,
                        'documentTypeColor' => $document->document_type_tone,
                        'quantity' => $document->quantity_label,
                        'note' => $document->note,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private static function loanActions(Loan $loan): array
    {
        $workflowCode = $loan->workflowStatus?->code;
        $searchCode = $loan->searchStatus?->code;

        if ($loan->view_mode !== 'manage') {
            return [];
        }

        if ($workflowCode === 'loan_status_pending') {
            return [
                ['key' => 'authorize', 'label' => 'Autorizar préstamo', 'icon' => 'pi pi-check-circle', 'variant' => 'success'],
                ['key' => 'observe', 'label' => 'Observar solicitud', 'icon' => 'pi pi-eye', 'variant' => 'warning'],
                ['key' => 'deny', 'label' => 'Denegar préstamo', 'icon' => 'pi pi-times-circle', 'variant' => 'danger'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($workflowCode === 'loan_status_authorized' && $searchCode === null) {
            return [
                ['key' => 'startSearch', 'label' => 'Iniciar búsqueda de documentos', 'icon' => 'pi pi-search', 'variant' => 'primary'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($searchCode === 'loan_search_in_progress') {
            return [
                ['key' => 'finishSearch', 'label' => 'Finalizar búsqueda', 'icon' => 'pi pi-check-circle', 'variant' => 'primary'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($searchCode === 'loan_search_not_found') {
            return [
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($workflowCode === 'loan_status_authorized' && $searchCode === 'loan_search_completed') {
            return [
                ['key' => 'registerLoan', 'label' => 'Registrar préstamo', 'icon' => 'pi pi-box', 'variant' => 'success'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        if ($workflowCode === 'loan_status_loaned') {
            return [
                ['key' => 'registerReturn', 'label' => 'Registrar devolución', 'icon' => 'pi pi-check-circle', 'variant' => 'muted'],
                ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
            ];
        }

        return [
            ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
        ];
    }

    private static function loanUnitAuthorizationActions(): array
    {
        return [
            ['key' => 'authorizeUnit', 'label' => 'Autorizar préstamo', 'icon' => 'pi pi-check-circle', 'variant' => 'success'],
            ['key' => 'denyUnit', 'label' => 'Denegar préstamo', 'icon' => 'pi pi-times-circle', 'variant' => 'danger'],
            ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
        ];
    }

    private static function buildLoanRegistrationConfig(Loan $loan): ?array
    {
        if ($loan->workflowStatus?->code !== 'loan_status_authorized' || $loan->searchStatus?->code !== 'loan_search_completed') {
            return null;
        }

        $documents = $loan->documents
            ->where('found_in_search', true)
            ->where('selected_for_loan', false)
            ->values();

        return [
            'title' => 'Registrar préstamo de documentos',
            'subtitle' => 'Seleccione los documentos que se están prestando e ingrese los detalles.',
            'helperTitle' => 'Seleccione los documentos a prestar',
            'helperText' => 'Solo se muestran los documentos que fueron encontrados en la búsqueda.',
            'selectAllLabel' => 'Seleccionar todos',
            'documentsTitle' => $documents->first()->group_title ?? 'Documentos encontrados',
            'selectionSummary' => 'Documentos seleccionados para prestamo: {count} de {total}',
            'documents' => $documents->map(fn ($document) => [
                'id' => $document->id,
                'title' => $document->title,
                'series' => $document->series_label,
                'box' => $document->box_code,
                'year' => $document->year_label,
                'documentType' => $document->document_type_label,
                'documentTypeColor' => $document->document_type_tone,
            ])->values()->all(),
        ];
    }

    private static function buildReturnRegistrationConfig(Loan $loan): ?array
    {
        if ($loan->workflowStatus?->code !== 'loan_status_loaned') {
            return null;
        }

        $documents = $loan->documents
            ->where('selected_for_loan', true)
            ->where('returned', false)
            ->values();

        return [
            'title' => 'Registrar Devolución de Documentos',
            'subtitle' => 'Seleccione los documentos que se estan devolviendo e ingrese los detalles',
            'helperTitle' => 'Seleccione los documentos que se devuelven',
            'helperText' => 'Marque los documentos que el usuario esta devolviendo en este momento',
            'selectAllLabel' => 'Seleccionar todos',
            'documentsTitle' => $documents->first()->group_title ?? 'Documentos prestados',
            'selectionSummary' => 'Documentos seleccionados para devolución: {count} de {total}',
            'defaultCondition' => 'Documentos devueltos en buen estado',
            'documents' => $documents->map(fn ($document) => [
                'id' => $document->id,
                'title' => $document->title,
                'series' => $document->series_label,
                'box' => $document->box_code,
                'year' => $document->year_label,
                'documentType' => $document->document_type_label,
                'documentTypeColor' => $document->document_type_tone,
            ])->values()->all(),
        ];
    }

    private static function mapHistory(Collection $events): array
    {
        return $events
            ->sortByDesc('occurred_at')
            ->values()
            ->map(fn ($event) => [
                'status' => $event->title,
                'dateTime' => self::formatDateTime($event->occurred_at),
                'description' => $event->description,
                'icon' => self::historyIcon($event->event_type),
            ])
            ->all();
    }

    private static function historyIcon(string $eventType): string
    {
        return match ($eventType) {
            'decision' => 'pi pi-times-circle',
            'observation' => 'pi pi-eye',
            default => 'pi pi-check-circle',
        };
    }

    private static function transferReviewActions(?string $workflowCode): array
    {
        if ($workflowCode !== 'transfer_status_pending') {
            return [];
        }

        return [
            ['key' => 'schedule', 'label' => 'Programar entrega', 'icon' => 'pi pi-calendar', 'variant' => 'primary'],
            ['key' => 'observe', 'label' => 'Observar transferencia', 'icon' => 'pi pi-eye', 'variant' => 'warning'],
            ['key' => 'deny', 'label' => 'Denegar transferencia', 'icon' => 'pi pi-times-circle', 'variant' => 'danger'],
            ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
        ];
    }

    private static function transferUnitAuthorizationActions(): array
    {
        return [
            ['key' => 'authorizeUnit', 'label' => 'Autorizar transferencia', 'icon' => 'pi pi-check-circle', 'variant' => 'success'],
            ['key' => 'denyUnit', 'label' => 'Denegar transferencia', 'icon' => 'pi pi-times-circle', 'variant' => 'danger'],
            ['key' => 'back', 'label' => 'Volver', 'icon' => null, 'variant' => 'secondary'],
        ];
    }

    private static function loanChips(Loan $loan): array
    {
        $chips = [
            ['label' => $loan->authorizationStatus?->label ?? 'Pendiente', 'tone' => $loan->authorizationStatus?->tone ?? 'neutral'],
            ['label' => $loan->workflowStatus?->label ?? 'Pendiente', 'tone' => $loan->workflowStatus?->tone ?? 'warning'],
        ];

        if ($loan->searchStatus) {
            $chips[] = ['label' => $loan->searchStatus->label, 'tone' => $loan->searchStatus->tone];
        }

        return $chips;
    }

    private static function viewerCanManageUgda(?User $viewer): bool
    {
        return in_array($viewer?->activeProfile()?->name, ['Administrador', 'Usuario UGDA'], true);
    }

    private static function viewerCanViewBoxLocationLabels(?User $viewer): bool
    {
        return self::viewerCanManageUgda($viewer);
    }

    private static function viewerCanViewTransferSummarySheet(?User $viewer, ?Transfer $transfer): bool
    {
        if ($viewer === null || $transfer === null) {
            return false;
        }

        if ($transfer->authorizationStatus?->code !== 'transfer_auth_authorized'
            || $transfer->workflowStatus?->code !== 'transfer_status_scheduled') {
            return false;
        }

        if (!self::viewerCanAccessRequestUnit($viewer, $transfer->unit_id)) {
            return false;
        }

        return in_array($viewer->activeProfile()?->name, ['Unidad Solicitante', 'Director/Jefe de Unidad'], true);
    }

    private static function viewerCanViewLoanSummarySheet(?User $viewer, ?Loan $loan): bool
    {
        return $loan !== null
            && self::viewerCanManageUgda($viewer)
            && $loan->workflowStatus?->code === 'loan_status_loaned'
            && $loan->latestDispatch !== null;
    }

    private static function viewerCanViewLoanReadyNotice(?User $viewer, Loan $loan): bool
    {
        if (!in_array($viewer?->activeProfile()?->name, ['Unidad Solicitante', 'Director/Jefe de Unidad'], true)) {
            return false;
        }

        if (!self::viewerCanAccessRequestUnit($viewer, $loan->unit_id)) {
            return false;
        }

        return $loan->documents->contains(fn ($document) => (bool) $document->found_in_search);
    }

    private static function viewerUnitIds(?User $viewer): array
    {
        return $viewer
            ? $viewer->units()->pluck('units.id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    private static function scopeToVisibleUnits($query, ?User $viewer): void
    {
        if (self::viewerCanManageUgda($viewer)) {
            return;
        }

        $unitIds = self::viewerUnitIds($viewer);

        if ($unitIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn('unit_id', $unitIds);
    }

    private static function viewerCanAccessRequestUnit(?User $viewer, ?int $unitId): bool
    {
        if (self::viewerCanManageUgda($viewer)) {
            return true;
        }

        if ($unitId === null) {
            return false;
        }

        return in_array((int) $unitId, self::viewerUnitIds($viewer), true);
    }

    private static function viewerCanCorrectObservedTransfer(?User $viewer, Transfer $transfer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->activeProfile()?->name !== 'Unidad Solicitante') {
            return false;
        }

        return $viewer->units()
            ->where('units.id', $transfer->unit_id)
            ->exists();
    }

    private static function viewerCanCorrectObservedLoan(?User $viewer, Loan $loan): bool
    {
        if ($viewer?->activeProfile()?->name !== 'Unidad Solicitante') {
            return false;
        }

        return self::viewerCanAccessRequestUnit($viewer, $loan->unit_id);
    }

    private static function userDisplayName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $parts = array_filter([
            $user->person?->first_name,
            $user->person?->second_name,
            $user->person?->first_last_name,
            $user->person?->second_last_name,
        ]);

        return implode(' ', $parts);
    }

    private static function eventActorName($event): ?string
    {
        return self::userDisplayName($event->actor) ?: ($event->actor_name_snapshot ?? null);
    }

    private static function formatDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->format('d/m/Y H:i');
    }

    private static function formatDate($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->format('d/m/Y');
    }

    private static function formatTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->format('H:i');
    }

    private static function buildPeriodLabel(?int $startYear, ?int $endYear): ?string
    {
        if ($startYear === null && $endYear === null) {
            return null;
        }

        if ($startYear === $endYear) {
            return '01/01/' . $startYear . ' - 31/12/' . $endYear;
        }

        return '01/01/' . $startYear . ' - 31/12/' . $endYear;
    }
}
