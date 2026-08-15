<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Services\SystemNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendLoanDueDateNotifications extends Command
{
    protected $signature = 'loans:notify-due-dates {--date= : Fecha de ejecucion en formato Y-m-d}';

    protected $description = 'Envía recordatorios de devolución para préstamos pendientes';

    public function handle(SystemNotificationService $notificationService): int
    {
        $today = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('date'))->startOfDay()
            : Carbon::today();

        $sent = 0;
        $processed = 0;

        Loan::query()
            ->with(['latestDispatch', 'documents'])
            ->whereHas('workflowStatus', fn ($query) => $query->where('code', 'loan_status_loaned'))
            ->whereHas('documents', fn ($query) => $query
                ->where('selected_for_loan', true)
                ->where('returned', false))
            ->chunkById(100, function ($loans) use ($notificationService, $today, &$sent, &$processed) {
                foreach ($loans as $loan) {
                    $dispatch = $loan->latestDispatch;

                    if ($dispatch === null) {
                        continue;
                    }

                    $processed++;

                    if ($notificationService->loanDueDateReminder($loan, $dispatch, $today)) {
                        $sent++;
                    }
                }
            });

        $this->info("Préstamos revisados: {$processed}. Notificaciones enviadas: {$sent}.");

        return self::SUCCESS;
    }
}
