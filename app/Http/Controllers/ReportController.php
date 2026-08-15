<?php

namespace App\Http\Controllers;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use App\Models\Loan;
use App\Models\LoanDocument;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ReportController extends Controller
{
    private const TRANSFERRED_WORKFLOW_STATUS = 'transfer_status_transferred';

    private const ACCESS_HISTORY_STATUSES = [
        'pending' => 'Pendiente',
        'authorized' => 'Autorizado',
        'observed' => 'Observado',
        'returned' => 'Devuelto',
    ];

    public function summary(): JsonResponse
    {
        try {
            $documentsCount = $this->transferredDocumentsQuery()->count();
            $transfersCount = $this->transferredTransfersQuery()->count();
            $boxesCount = $this->transferredBoxesQuery()->count();
            $unitsCount = Unit::query()->count();
            $loansCount = Loan::query()->count();
            $loanedDocumentsCount = LoanDocument::query()
                ->where('selected_for_loan', true)
                ->where('returned', false)
                ->count();
            $returnedLoansCount = Loan::query()
                ->whereHas('workflowStatus', fn ($query) => $query->where('code', 'loan_status_returned'))
                ->count();
            $seriesCount = DocumentarySeries::query()->count();
            $subseriesCount = DocumentarySubseries::query()->count();
            $locatedBoxesCount = $this->transferredBoxesQuery()
                ->whereNotNull('location_code')
                ->where('location_code', '<>', '')
                ->count();

            return response()->json([
                'categories' => [
                    'entry_and_registry' => $this->availableReportsCount([
                        $transfersCount,
                        $documentsCount,
                        $boxesCount,
                        $unitsCount,
                    ]),
                    'access_and_consultation' => $this->availableReportsCount([
                        $loansCount,
                        $loanedDocumentsCount,
                        $returnedLoansCount,
                    ]),
                    'classification_and_organization' => $this->availableReportsCount([
                        $seriesCount + $subseriesCount,
                        $locatedBoxesCount,
                    ]),
                ],
                'summary' => [
                    'total_documents' => $documentsCount,
                    'total_transfers' => $transfersCount,
                    'total_boxes' => $boxesCount,
                    'reserved_documents' => $loanedDocumentsCount,
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el resumen de reportes.',
            ], 500);
        }
    }

    public function entryRegistry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ], [
            'start_date.date_format' => 'La fecha de inicio no tiene un formato válido.',
            'end_date.date_format' => 'La fecha fin no tiene un formato válido.',
            'end_date.after_or_equal' => 'La fecha fin debe ser posterior o igual a la fecha inicio.',
        ]);

        try {
            $baseQuery = $this->transferredDocumentsQuery()
                ->with(['box.transfer.unit', 'box.transfer.requester.person'])
                ->whereNotNull('created_at');

            $documents = $this->applyEntryRegistryFilters(clone $baseQuery, $validated)
                ->orderBy('created_at')
                ->orderBy('code')
                ->get();

            $availableDates = (clone $baseQuery)
                ->select('created_at')
                ->orderBy('created_at')
                ->get()
                ->map(fn (TransferDocument $document) => $document->created_at)
                ->filter();

            $mappedDocuments = $documents
                ->map(fn (TransferDocument $document) => $this->mapEntryRegistryDocument($document))
                ->values();

            $periodSeries = $documents
                ->groupBy(fn (TransferDocument $document) => $document->created_at?->toDateString() ?? 'Sin fecha')
                ->map(fn ($group, string $date) => [
                    'date' => $date,
                    'label' => $date === 'Sin fecha' ? $date : Carbon::parse($date)->format('d/m/Y'),
                    'total' => $group->count(),
                ])
                ->sortBy('date')
                ->values();

            return response()->json([
                'filters' => [
                    'years' => $availableDates
                        ->map(fn ($date) => (int) $date->format('Y'))
                        ->unique()
                        ->sort()
                        ->values(),
                    'months' => $availableDates
                        ->map(fn ($date) => (int) $date->format('n'))
                        ->unique()
                        ->sort()
                        ->values(),
                ],
                'summary' => [
                    'filtered_documents' => $mappedDocuments->count(),
                    'total_documents' => $this->transferredDocumentsQuery()->count(),
                    'units' => $mappedDocuments->pluck('unit')->filter()->unique()->count(),
                    'series' => $mappedDocuments->pluck('series')->filter()->unique()->count(),
                ],
                'charts' => [
                    'period' => $periodSeries,
                    'units' => $this->groupDocumentsForChart($mappedDocuments, 'unit', 'Sin unidad'),
                    'series' => $this->groupDocumentsForChart($mappedDocuments, 'series', 'Sin serie'),
                    'support' => $this->groupDocumentsForChart($mappedDocuments, 'support', 'Sin soporte'),
                ],
                'documents' => $mappedDocuments,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el reporte de ingreso y registro.',
            ], 500);
        }
    }

    public function accessConsultationHistory(Request $request): JsonResponse
    {
        try {
            $loans = Loan::query()
                ->with([
                    'documents',
                    'latestDispatch.items',
                    'requester.person',
                    'returns.items',
                    'unit',
                    'workflowStatus',
                ])
                ->orderBy('requested_at')
                ->orderBy('number')
                ->get();

            $mappedLoans = $loans
                ->map(fn (Loan $loan) => $this->mapAccessConsultationLoan($loan))
                ->filter()
                ->values();

            return response()->json([
                'filters' => [
                    'statuses' => collect(self::ACCESS_HISTORY_STATUSES)
                        ->map(fn (string $label, string $value) => [
                            'label' => $label,
                            'value' => $value,
                        ])
                        ->values(),
                    'units' => $mappedLoans
                        ->map(fn (array $loan) => [
                            'label' => $loan['unit'],
                            'value' => $loan['unit_id'],
                        ])
                        ->filter(fn (array $unit) => $unit['value'] !== null)
                        ->unique('value')
                        ->sortBy('label')
                        ->values(),
                    'users' => $mappedLoans
                        ->map(fn (array $loan) => [
                            'label' => $loan['user'],
                            'value' => $loan['user_id'],
                        ])
                        ->filter(fn (array $user) => $user['value'] !== null)
                        ->unique('value')
                        ->sortBy('label')
                        ->values(),
                ],
                'summary' => [
                    'filtered_loans' => $mappedLoans->count(),
                    'total_loans' => $mappedLoans->count(),
                    'users' => $mappedLoans->pluck('user_id')->filter()->unique()->count(),
                    'units' => $mappedLoans->pluck('unit_id')->filter()->unique()->count(),
                ],
                'loans' => $mappedLoans,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el historial de consultas por usuario.',
            ], 500);
        }
    }

    public function accessConsultationDocuments(Request $request): JsonResponse
    {
        try {
            $loans = Loan::query()
                ->with([
                    'documents',
                    'latestDispatch',
                    'requester.person',
                    'unit',
                    'workflowStatus',
                ])
                ->whereHas('workflowStatus', fn ($query) => $query->whereIn('code', [
                    'loan_status_authorized',
                    'loan_status_loaned',
                    'loan_status_returned',
                ]))
                ->orderBy('requested_at')
                ->orderBy('number')
                ->get();

            $documents = $loans
                ->flatMap(fn (Loan $loan) => $this->mapAccessConsultationDocumentRows($loan))
                ->values();

            return response()->json([
                'summary' => [
                    'total_documents' => $documents
                        ->pluck('document_key')
                        ->unique()
                        ->count(),
                    'total_consultations' => $documents->count(),
                ],
                'documents' => $documents,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el reporte de documentos consultados.',
            ], 500);
        }
    }

    public function accessConsultationResponseTimes(Request $request): JsonResponse
    {
        try {
            $loans = Loan::query()
                ->with([
                    'documents',
                    'unit',
                ])
                ->whereNotNull('search_started_at')
                ->whereNotNull('search_completed_at')
                ->orderBy('search_started_at')
                ->orderBy('number')
                ->get();

            $rows = $loans
                ->flatMap(fn (Loan $loan) => $this->mapAccessResponseTimeRows($loan))
                ->values();
            $times = $rows->pluck('minutes');

            return response()->json([
                'summary' => [
                    'total_rows' => $rows->count(),
                    'average_minutes' => $times->isNotEmpty() ? (int) round($times->average()) : 0,
                    'minimum_minutes' => $times->isNotEmpty() ? (int) $times->min() : 0,
                    'maximum_minutes' => $times->isNotEmpty() ? (int) $times->max() : 0,
                ],
                'rows' => $rows,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el reporte de tiempos promedio de respuesta.',
            ], 500);
        }
    }

    public function classificationSeriesSubseries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit' => ['nullable', 'integer', 'exists:units,id'],
            'series' => ['nullable', 'string', 'max:2000'],
            'support' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:120'],
            'subseries_page' => ['nullable', 'integer', 'min:1'],
            'subseries_per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'documents_page' => ['nullable', 'integer', 'min:1'],
            'documents_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $subseriesPage = (int) ($validated['subseries_page'] ?? 1);
            $subseriesPerPage = (int) ($validated['subseries_per_page'] ?? 5);
            $documentsPage = (int) ($validated['documents_page'] ?? 1);
            $documentsPerPage = (int) ($validated['documents_per_page'] ?? 50);
            $filteredQuery = $this->applyClassificationFilters(
                $this->classificationDocumentBaseQuery(),
                $validated
            );
            $filteredDocumentsCount = (clone $filteredQuery)->count();
            $totalDocumentsCount = $this->classificationDocumentBaseQuery()->count();
            $seriesRows = $this->classificationSeriesRows($filteredQuery);
            $seriesCountsData = $seriesRows
                ->forPage($subseriesPage, $subseriesPerPage)
                ->values();
            $documentsPaginator = (clone $filteredQuery)
                ->orderBy('transfer_box_documents.name')
                ->orderBy('transfer_box_documents.code')
                ->paginate($documentsPerPage, ['transfer_box_documents.*'], 'documents_page', $documentsPage);

            return response()->json([
                'filters' => $this->classificationFilterOptions(),
                'summary' => [
                    'filtered_documents' => $filteredDocumentsCount,
                    'total_documents' => $totalDocumentsCount,
                ],
                'charts' => [
                    'series' => $seriesRows,
                ],
                'series_counts' => [
                    'data' => $seriesCountsData,
                    'meta' => $this->collectionPaginationMeta(
                        $seriesRows->count(),
                        $subseriesPerPage,
                        $subseriesPage
                    ),
                ],
                'documents' => [
                    'data' => $documentsPaginator
                        ->getCollection()
                        ->map(fn (TransferDocument $document) => $this->mapEntryRegistryDocument($document))
                        ->values(),
                    'meta' => $this->paginationMeta($documentsPaginator),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el reporte de series y subseries documentales.',
            ], 500);
        }
    }

    public function unclassifiedDocuments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit' => ['nullable', 'integer', 'exists:units,id'],
            'series' => ['nullable', 'string', 'max:2000'],
            'support' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:120'],
            'documents_page' => ['nullable', 'integer', 'min:1'],
            'documents_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $documentsPage = (int) ($validated['documents_page'] ?? 1);
            $documentsPerPage = (int) ($validated['documents_per_page'] ?? 50);
            $baseQuery = $this->unclassifiedDocumentBaseQuery();
            $totalDocumentsCount = (clone $baseQuery)->count();
            $hasFilters = $this->hasClassificationFilters($validated);

            if (!$hasFilters) {
                return response()->json([
                    'filters' => $this->unclassifiedFilterOptions(),
                    'summary' => [
                        'filtered_documents' => 0,
                        'total_documents' => $totalDocumentsCount,
                        'has_filters' => false,
                    ],
                    'documents' => [
                        'data' => [],
                        'meta' => $this->emptyPaginationMeta($documentsPerPage),
                    ],
                ]);
            }

            $filteredQuery = $this->applyUnclassifiedFilters($baseQuery, $validated);
            $filteredDocumentsCount = (clone $filteredQuery)->count();
            $documentsPaginator = $filteredQuery
                ->orderBy('transfer_box_documents.name')
                ->orderBy('transfer_box_documents.code')
                ->paginate($documentsPerPage, ['transfer_box_documents.*'], 'documents_page', $documentsPage);

            return response()->json([
                'filters' => $this->unclassifiedFilterOptions(),
                'summary' => [
                    'filtered_documents' => $filteredDocumentsCount,
                    'total_documents' => $totalDocumentsCount,
                    'has_filters' => true,
                ],
                'documents' => [
                    'data' => $documentsPaginator
                        ->getCollection()
                        ->map(fn (TransferDocument $document) => $this->mapUnclassifiedDocument($document))
                        ->values(),
                    'meta' => $this->paginationMeta($documentsPaginator),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el reporte de documentos sin clasificación.',
            ], 500);
        }
    }

    private function availableReportsCount(array $metricValues): int
    {
        return collect($metricValues)
            ->filter(fn ($value) => (int) $value > 0)
            ->count();
    }

    private function classificationDocumentBaseQuery(): Builder
    {
        return $this->transferredDocumentsQuery()
            ->with(['box.transfer.unit', 'box.transfer.requester.person'])
            ->whereNotNull('transfer_box_documents.created_at');
    }

    private function transferredDocumentsQuery(): Builder
    {
        return TransferDocument::query()
            ->whereHas('box.transfer', fn (Builder $query) => $this->whereTransferredTransfer($query));
    }

    private function transferredTransfersQuery(): Builder
    {
        return Transfer::query()
            ->whereHas('workflowStatus', fn (Builder $query) =>
                $query->where('code', self::TRANSFERRED_WORKFLOW_STATUS)
            );
    }

    private function transferredBoxesQuery(): Builder
    {
        return TransferBox::query()
            ->whereHas('transfer', fn (Builder $query) => $this->whereTransferredTransfer($query));
    }

    private function whereTransferredTransfer(Builder $query): void
    {
        $query->whereHas('workflowStatus', fn (Builder $statusQuery) =>
            $statusQuery->where('code', self::TRANSFERRED_WORKFLOW_STATUS)
        );
    }

    private function unclassifiedDocumentBaseQuery(): Builder
    {
        return $this->classificationDocumentBaseQuery()
            ->where(function (Builder $query) {
                $query
                    ->whereNull('transfer_box_documents.series_label')
                    ->orWhereRaw("TRIM(transfer_box_documents.series_label) = ''");
            });
    }

    private function hasClassificationFilters(array $filters): bool
    {
        return !empty($filters['unit']) ||
            !empty($filters['series']) ||
            !empty($filters['support']) ||
            trim((string) ($filters['search'] ?? '')) !== '';
    }

    private function applyClassificationFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['unit'])) {
            $query->whereHas('box.transfer', fn (Builder $transferQuery) =>
                $transferQuery->where('unit_id', (int) $filters['unit'])
            );
        }

        if (!empty($filters['series'])) {
            $this->whereDocumentarySeriesLabelMatches($query, $filters['series']);
        }

        if (!empty($filters['support'])) {
            $this->applySupportFilter($query, $filters['support']);
        }

        $term = trim((string) ($filters['search'] ?? ''));

        if ($term !== '') {
            $query->where(function (Builder $searchQuery) use ($term) {
                $this->addReportSearchClauses($searchQuery, [
                    'transfer_box_documents.code',
                    'transfer_box_documents.name',
                    'transfer_box_documents.series_label',
                    'transfer_box_documents.support_type',
                    'transfer_box_documents.year_label',
                    'transfer_box_documents.pages_label',
                ], $term);

                $searchQuery->orWhereHas('box', function (Builder $boxQuery) use ($term) {
                    $this->addReportSearchClauses($boxQuery, [
                        'transfer_boxes.box_code',
                        'transfer_boxes.series_name',
                        'transfer_boxes.box_number',
                    ], $term);
                });

                $searchQuery->orWhereHas('box.transfer', function (Builder $transferQuery) use ($term) {
                    $this->addReportSearchClauses($transferQuery, [
                        'transfers.code',
                    ], $term);
                });

                $searchQuery->orWhereHas('box.transfer.unit', function (Builder $unitQuery) use ($term) {
                    $this->addReportSearchClauses($unitQuery, [
                        'units.code',
                        'units.name',
                    ], $term);
                });
            });
        }

        return $query;
    }

    private function applyUnclassifiedFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['unit'])) {
            $query->whereHas('box.transfer', fn (Builder $transferQuery) =>
                $transferQuery->where('unit_id', (int) $filters['unit'])
            );
        }

        if (!empty($filters['series'])) {
            $query->whereHas('box', fn (Builder $boxQuery) =>
                $this->whereDelimitedSeriesLabelMatches($boxQuery, 'transfer_boxes.series_name', $filters['series'])
            );
        }

        if (!empty($filters['support'])) {
            $this->applySupportFilter($query, $filters['support']);
        }

        $term = trim((string) ($filters['search'] ?? ''));

        if ($term !== '') {
            $query->where(function (Builder $searchQuery) use ($term) {
                $this->addReportSearchClauses($searchQuery, [
                    'transfer_box_documents.code',
                    'transfer_box_documents.name',
                    'transfer_box_documents.support_type',
                    'transfer_box_documents.year_label',
                    'transfer_box_documents.pages_label',
                ], $term);

                $searchQuery->orWhereHas('box', function (Builder $boxQuery) use ($term) {
                    $this->addReportSearchClauses($boxQuery, [
                        'transfer_boxes.box_code',
                        'transfer_boxes.series_name',
                        'transfer_boxes.box_number',
                        'transfer_boxes.title',
                        'transfer_boxes.location_code',
                    ], $term);
                });

                $searchQuery->orWhereHas('box.transfer', function (Builder $transferQuery) use ($term) {
                    $this->addReportSearchClauses($transferQuery, [
                        'transfers.code',
                    ], $term);
                });

                $searchQuery->orWhereHas('box.transfer.unit', function (Builder $unitQuery) use ($term) {
                    $this->addReportSearchClauses($unitQuery, [
                        'units.code',
                        'units.name',
                    ], $term);
                });
            });
        }

        return $query;
    }

    private function classificationFilterOptions(): array
    {
        return [
            'units' => Unit::query()
                ->whereIn('id', $this->transferredTransfersQuery()
                    ->whereHas('boxes.documents')
                    ->select('unit_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Unit $unit) => [
                    'label' => $unit->name,
                    'value' => $unit->id,
                ])
                ->values(),
            'series' => $this->documentarySeriesFilterOptions(
                $this->transferredDocumentsQuery()->pluck('series_label')
            ),
            'support' => $this->supportFilterOptions(),
        ];
    }

    private function unclassifiedFilterOptions(): array
    {
        return [
            'units' => Unit::query()
                ->whereIn('id', $this->transferredTransfersQuery()
                    ->whereHas('boxes.documents')
                    ->select('unit_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Unit $unit) => [
                    'label' => $unit->name,
                    'value' => $unit->id,
                ])
                ->values(),
            'series' => $this->documentarySeriesFilterOptions(
                $this->transferredBoxesQuery()
                    ->whereHas('documents')
                    ->pluck('series_name')
            ),
            'support' => $this->supportFilterOptions(),
        ];
    }

    private function addReportSearchClauses(Builder $query, array $columns, string $term): void
    {
        $like = '%' . mb_strtolower($term) . '%';

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}("LOWER(CAST({$column} AS TEXT)) LIKE ?", [$like]);
        }
    }

    private function supportFilterOptions(): array
    {
        return $this->transferredDocumentsQuery()
            ->pluck('support_type')
            ->map(fn ($support) => $this->normalizeSupportLabel($support))
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $label) => [
                'label' => $label,
                'value' => $label,
            ])
            ->all();
    }

    private function applySupportFilter(Builder $query, string $support): void
    {
        $normalizedSupport = $this->normalizeSupportLabel($support);

        if ($normalizedSupport === 'Sin soporte') {
            $query->where(function (Builder $supportQuery) {
                $supportQuery
                    ->whereNull('transfer_box_documents.support_type')
                    ->orWhereRaw("TRIM(transfer_box_documents.support_type) = ''");
            });

            return;
        }

        $query->whereRaw(
            $this->supportComparisonExpression('transfer_box_documents.support_type') . ' = ?',
            [mb_strtolower(Str::ascii($normalizedSupport))]
        );
    }

    private function supportComparisonExpression(string $column): string
    {
        $expression = "TRIM(COALESCE({$column}, ''))";

        foreach ([
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
        ] as $accented => $plain) {
            $expression = "REPLACE({$expression}, '{$accented}', '{$plain}')";
        }

        return "LOWER({$expression})";
    }

    private function normalizeSupportLabel(?string $support): string
    {
        $label = trim((string) $support);

        if ($label === '') {
            return 'Sin soporte';
        }

        return match (mb_strtolower(Str::ascii($label))) {
            'fisico' => 'Físico',
            'digital' => 'Digital',
            'mixto' => 'Mixto',
            default => $label,
        };
    }

    private function classificationSeriesRows(Builder $filteredQuery): \Illuminate\Support\Collection
    {
        return (clone $filteredQuery)
            ->get(['transfer_box_documents.id', 'transfer_box_documents.series_label', 'transfer_box_documents.transfer_box_id'])
            ->flatMap(function (TransferDocument $document) {
                $unitId = $document->box?->transfer?->unit_id;

                return collect($this->splitDocumentarySeriesLabels($document->series_label))
                    ->map(fn (string $label) => [
                        'label' => $label,
                        'document_id' => $document->id,
                        'unit_id' => $unitId,
                    ]);
            })
            ->groupBy('label')
            ->map(function ($rows, string $label) {
                $documentsCount = $rows->pluck('document_id')->unique()->count();
                $unitsCount = $rows->pluck('unit_id')->filter()->unique()->count();

                return [
                    'label' => $label,
                    'documents_count' => $documentsCount,
                    'units_count' => $unitsCount,
                    'documents_label' => $documentsCount === 1 ? '1 documento' : "{$documentsCount} documentos",
                    'units_label' => $unitsCount === 1 ? '1 unidad productora' : "{$unitsCount} unidades productoras",
                ];
            })
            ->sortBy([
                ['documents_count', 'desc'],
                ['label', 'asc'],
            ])
            ->values();
    }

    private function documentarySeriesFilterOptions($values): array
    {
        return collect($values)
            ->flatMap(fn ($value) => $this->splitDocumentarySeriesLabels($value))
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $label) => [
                'label' => $label,
                'value' => $label,
            ])
            ->all();
    }

    private function splitDocumentarySeriesLabels(?string $value): array
    {
        $labels = collect(explode(';', (string) $value))
            ->map(fn ($label) => trim((string) $label))
            ->filter()
            ->values();

        return $labels->isNotEmpty() ? $labels->all() : ['Sin serie'];
    }

    private function whereDocumentarySeriesLabelMatches(Builder $query, string $label): void
    {
        $this->whereDelimitedSeriesLabelMatches($query, 'transfer_box_documents.series_label', $label);
    }

    private function whereDelimitedSeriesLabelMatches(Builder $query, string $column, string $label): void
    {
        if (mb_strtolower(trim($label)) === 'sin serie') {
            $query->where(function (Builder $nullQuery) use ($column) {
                $nullQuery
                    ->whereNull($column)
                    ->orWhereRaw("TRIM({$column}) = ''");
            });

            return;
        }

        $normalizedLabel = mb_strtolower(preg_replace('/\\s+/', '', trim($label)) ?? '');
        $escapedLabel = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalizedLabel);
        $normalizedColumn = "REPLACE(REPLACE(LOWER(COALESCE({$column}, '')), ' ', ''), ';', '|')";

        $query->whereRaw(
            "('|' || {$normalizedColumn} || '|') LIKE ? ESCAPE '\\\\'",
            ["%|{$escapedLabel}|%"]
        );
    }

    private function collectionPaginationMeta(int $total, int $perPage, int $currentPage): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min(max(1, $currentPage), $lastPage);
        $from = $total === 0 ? null : (($currentPage - 1) * $perPage) + 1;
        $to = $total === 0 ? null : min($currentPage * $perPage, $total);

        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'from' => $from,
            'to' => $to,
        ];
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    private function emptyPaginationMeta(int $perPage): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];
    }

    private function applyEntryRegistryFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['year'])) {
            $query->whereYear('created_at', (int) $filters['year']);
        }

        if (!empty($filters['month'])) {
            $query->whereMonth('created_at', (int) $filters['month']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('created_at', $filters['date']);
        }

        if (!empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        return $query;
    }

    private function mapEntryRegistryDocument(TransferDocument $document): array
    {
        $date = $document->created_at;
        $box = $document->box;
        $transfer = $box?->transfer;

        return [
            'id' => $document->id,
            'code' => $document->code,
            'name' => $document->name,
            'series' => $document->series_label ?: 'Sin serie',
            'unit_id' => $transfer?->unit?->id,
            'unit' => $transfer?->unit?->name ?: 'Sin unidad',
            'entered_by_id' => $transfer?->requester?->id,
            'entered_by' => $this->formatUserName($transfer?->requester),
            'date' => $date?->toDateString(),
            'date_label' => $date?->format('d/m/Y H:i') ?: 'Sin fecha',
            'support' => $this->normalizeSupportLabel($document->support_type),
            'year' => $document->year_label ?: 'N/D',
            'pages' => $document->pages_label ?: 'N/D',
            'transfer_code' => $transfer?->code ?: 'N/D',
            'box_number' => $box?->formattedBoxNumber() ?: 'N/D',
        ];
    }

    private function mapUnclassifiedDocument(TransferDocument $document): array
    {
        $date = $document->created_at;
        $box = $document->box;
        $transfer = $box?->transfer;

        return [
            'id' => $document->id,
            'code' => $document->code,
            'name' => $document->name,
            'unit_id' => $transfer?->unit?->id,
            'unit' => $transfer?->unit?->name ?: 'Sin unidad',
            'date' => $date?->toDateString(),
            'date_label' => $date?->format('d/m/Y H:i') ?: 'Sin fecha',
            'box' => $box?->boxCode($transfer?->code) ?: 'N/D',
            'box_number' => $box?->formattedBoxNumber() ?: 'N/D',
            'box_series' => $box?->series_name ?: 'Sin serie',
            'support' => $this->normalizeSupportLabel($document->support_type),
        ];
    }

    private function groupDocumentsForChart($documents, string $key, string $fallback): array
    {
        return $documents
            ->groupBy(function (array $document) use ($key, $fallback) {
                $label = $document[$key] ?: $fallback;

                return $key === 'support' ? $this->normalizeSupportLabel($label) : $label;
            })
            ->map(fn ($group, string $label) => [
                'label' => $label,
                'total' => $group->count(),
            ])
            ->sortByDesc('total')
            ->take(12)
            ->values()
            ->all();
    }

    private function mapAccessConsultationLoan(Loan $loan): ?array
    {
        $statusCode = $this->accessHistoryStatusCode($loan);

        if ($statusCode === null) {
            return null;
        }

        $returnedDocumentIds = $loan->returns
            ->flatMap(fn ($return) => $return->items->pluck('loan_document_id'))
            ->unique()
            ->values();
        $selectedDocuments = $this->accessConsultationLoanedDocumentsForLoan($loan);

        if ($selectedDocuments->isEmpty()) {
            return null;
        }

        return [
            'id' => $loan->id,
            'number' => $loan->number,
            'user_id' => $loan->requester?->id,
            'user' => $this->formatUserName($loan->requester),
            'user_initials' => $this->initialsFromName($this->formatUserName($loan->requester)),
            'unit_id' => $loan->unit?->id,
            'unit' => $loan->unit?->name ?: 'Sin unidad',
            'status_code' => $statusCode,
            'status' => self::ACCESS_HISTORY_STATUSES[$statusCode],
            'requested_at' => $loan->requested_at?->toDateString(),
            'requested_at_label' => $loan->requested_at?->format('d/m/Y H:i') ?: 'Sin fecha',
            'documents_count' => $selectedDocuments->count(),
            'documents' => $selectedDocuments
                ->map(fn (LoanDocument $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'series' => $document->series_label ?: $document->group_title,
                    'date_label' => $loan->latestDispatch?->loan_date?->format('Y-m-d')
                        ?: $loan->requested_at?->format('Y-m-d')
                        ?: 'Sin fecha',
                    'status' => $returnedDocumentIds->contains($document->id) || $document->returned
                        ? 'Devuelta'
                        : 'Prestada',
                ])
                ->values(),
        ];
    }

    private function mapAccessConsultationDocumentRows(Loan $loan)
    {
        $consultationDate = $loan->latestDispatch?->loan_date ?: $loan->requested_at;

        return $this->accessConsultationDocumentsForLoan($loan)
            ->map(fn (LoanDocument $document) => [
                'id' => "{$loan->id}-{$document->id}",
                'document_key' => mb_strtolower(trim($document->title)),
                'title' => $document->title,
                'series' => $document->series_label ?: $document->group_title,
                'loan_number' => $loan->number,
                'user' => $this->formatUserName($loan->requester),
                'unit' => $loan->unit?->name ?: 'Sin unidad',
                'date' => $consultationDate?->toDateString(),
                'date_label' => $consultationDate?->format('d/m/Y') ?: 'Sin fecha',
            ]);
    }

    private function accessConsultationDocumentsForLoan(Loan $loan)
    {
        $archiveDocumentNames = $this->archiveDocumentNames();
        $selectedDocuments = $loan->documents
            ->filter(fn (LoanDocument $document) => $document->selected_for_loan || $document->found_in_search)
            ->filter(fn (LoanDocument $document) => $this->loanDocumentExistsInArchive($document, $archiveDocumentNames));

        if ($selectedDocuments->isNotEmpty()) {
            return $selectedDocuments;
        }

        return $loan->documents
            ->filter(fn (LoanDocument $document) => $this->loanDocumentExistsInArchive($document, $archiveDocumentNames));
    }

    private function accessConsultationLoanedDocumentsForLoan(Loan $loan)
    {
        $archiveDocumentNames = $this->archiveDocumentNames();
        $dispatchDocumentIds = $loan->latestDispatch?->items
            ->pluck('loan_document_id')
            ->unique()
            ->values();

        if ($dispatchDocumentIds === null || $dispatchDocumentIds->isEmpty()) {
            return collect();
        }

        return $loan->documents
            ->filter(fn (LoanDocument $document) => $dispatchDocumentIds->contains($document->id))
            ->filter(fn (LoanDocument $document) => $this->loanDocumentExistsInArchive($document, $archiveDocumentNames))
            ->values();
    }

    private function archiveDocumentNames()
    {
        static $names = null;

        if ($names !== null) {
            return $names;
        }

        $names = $this->transferredDocumentsQuery()
            ->pluck('name')
            ->map(fn (?string $name) => $this->normalizeDocumentTitle($name))
            ->filter()
            ->flip();

        return $names;
    }

    private function loanDocumentExistsInArchive(LoanDocument $document, $archiveDocumentNames): bool
    {
        if ($document->document_kind !== 'system') {
            return false;
        }

        return $archiveDocumentNames->has($this->normalizeDocumentTitle($document->title));
    }

    private function normalizeDocumentTitle(?string $title): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower((string) $title)));
    }

    private function mapAccessResponseTimeRows(Loan $loan)
    {
        $startedAt = $loan->search_started_at;
        $completedAt = $loan->search_completed_at;

        if ($startedAt === null || $completedAt === null) {
            return collect();
        }

        $minutes = max(0, (int) round($startedAt->diffInMinutes($completedAt)));

        return $this->accessConsultationDocumentsForLoan($loan)
            ->map(fn (LoanDocument $document) => [
                'id' => "{$loan->id}-{$document->id}",
                'request' => $loan->number,
                'document' => $document->title,
                'unit' => $loan->unit?->name ?: 'Sin unidad',
                'started_at' => $startedAt->toDateString(),
                'started_at_label' => $startedAt->format('Y-m-d'),
                'completed_at' => $completedAt->toDateString(),
                'completed_at_label' => $completedAt->format('Y-m-d'),
                'minutes' => $minutes,
            ]);
    }

    private function accessHistoryStatusCode(Loan $loan): ?string
    {
        return match ($loan->workflowStatus?->code) {
            'loan_status_pending' => 'pending',
            'loan_status_authorized', 'loan_status_loaned' => 'authorized',
            'loan_status_observed' => 'observed',
            'loan_status_returned' => 'returned',
            default => null,
        };
    }

    private function initialsFromName(string $name): string
    {
        $words = collect(explode(' ', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)));

        return $words->implode('') ?: 'SU';
    }

    private function formatUserName(?User $user): string
    {
        if ($user === null) {
            return 'Sin usuario';
        }

        $person = $user->person;
        $name = collect([
            $person?->first_name,
            $person?->second_name,
            $person?->first_last_name,
            $person?->second_last_name,
        ])
            ->filter()
            ->implode(' ');

        return $name !== '' ? $name : $user->email;
    }
}
