<?php

namespace Database\Seeders;

use App\Models\RequestStatusCatalog;
use Illuminate\Database\Seeder;

class RequestStatusCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['request_type' => 'transfer', 'category' => 'authorization', 'code' => 'transfer_auth_pending', 'label' => 'Pendiente', 'tone' => 'neutral', 'sort_order' => 10],
            ['request_type' => 'transfer', 'category' => 'authorization', 'code' => 'transfer_auth_authorized', 'label' => 'Autorizado', 'tone' => 'success', 'sort_order' => 20],
            ['request_type' => 'transfer', 'category' => 'authorization', 'code' => 'transfer_auth_denied', 'label' => 'Denegado', 'tone' => 'danger', 'sort_order' => 30],
            ['request_type' => 'transfer', 'category' => 'workflow', 'code' => 'transfer_status_pending', 'label' => 'Pendiente', 'tone' => 'warning', 'sort_order' => 10],
            ['request_type' => 'transfer', 'category' => 'workflow', 'code' => 'transfer_status_observed', 'label' => 'Observado', 'tone' => 'warning', 'sort_order' => 20],
            ['request_type' => 'transfer', 'category' => 'workflow', 'code' => 'transfer_status_scheduled', 'label' => 'Agendado', 'tone' => 'info', 'sort_order' => 30],
            ['request_type' => 'transfer', 'category' => 'workflow', 'code' => 'transfer_status_physical_review', 'label' => 'En Revisión', 'tone' => 'warning', 'sort_order' => 35],
            ['request_type' => 'transfer', 'category' => 'workflow', 'code' => 'transfer_status_physical_observed', 'label' => 'Obs. en Revisión', 'tone' => 'warning', 'sort_order' => 36],
            ['request_type' => 'transfer', 'category' => 'workflow', 'code' => 'transfer_status_subsanated', 'label' => 'Subsanada', 'tone' => 'info', 'sort_order' => 37],
            ['request_type' => 'transfer', 'category' => 'workflow', 'code' => 'transfer_status_transferred', 'label' => 'Transferido', 'tone' => 'success', 'sort_order' => 40],
            ['request_type' => 'transfer', 'category' => 'workflow', 'code' => 'transfer_status_denied', 'label' => 'Denegado', 'tone' => 'danger', 'sort_order' => 50],
            ['request_type' => 'transfer', 'category' => 'workflow', 'code' => 'transfer_status_cancelled', 'label' => 'Cancelada', 'tone' => 'warning', 'sort_order' => 60],

            ['request_type' => 'loan', 'category' => 'authorization', 'code' => 'loan_auth_pending', 'label' => 'Pendiente', 'tone' => 'neutral', 'sort_order' => 10],
            ['request_type' => 'loan', 'category' => 'authorization', 'code' => 'loan_auth_authorized', 'label' => 'Autorizado', 'tone' => 'success', 'sort_order' => 20],
            ['request_type' => 'loan', 'category' => 'authorization', 'code' => 'loan_auth_denied', 'label' => 'Denegado', 'tone' => 'danger', 'sort_order' => 30],
            ['request_type' => 'loan', 'category' => 'workflow', 'code' => 'loan_status_pending', 'label' => 'Pendiente', 'tone' => 'warning', 'sort_order' => 10],
            ['request_type' => 'loan', 'category' => 'workflow', 'code' => 'loan_status_authorized', 'label' => 'Autorizado', 'tone' => 'success', 'sort_order' => 20],
            ['request_type' => 'loan', 'category' => 'workflow', 'code' => 'loan_status_observed', 'label' => 'Observado', 'tone' => 'warning', 'sort_order' => 30],
            ['request_type' => 'loan', 'category' => 'workflow', 'code' => 'loan_status_loaned', 'label' => 'Prestado', 'tone' => 'info', 'sort_order' => 40],
            ['request_type' => 'loan', 'category' => 'workflow', 'code' => 'loan_status_returned', 'label' => 'Devuelto', 'tone' => 'success', 'sort_order' => 50],
            ['request_type' => 'loan', 'category' => 'workflow', 'code' => 'loan_status_denied', 'label' => 'Denegado', 'tone' => 'danger', 'sort_order' => 60],
            ['request_type' => 'loan', 'category' => 'workflow', 'code' => 'loan_status_cancelled', 'label' => 'Cancelada', 'tone' => 'warning', 'sort_order' => 70],
            ['request_type' => 'loan', 'category' => 'search', 'code' => 'loan_search_in_progress', 'label' => 'Busqueda: En proceso', 'tone' => 'info', 'sort_order' => 10],
            ['request_type' => 'loan', 'category' => 'search', 'code' => 'loan_search_completed', 'label' => 'Busqueda: Finalizada', 'tone' => 'success', 'sort_order' => 20],
            ['request_type' => 'loan', 'category' => 'search', 'code' => 'loan_search_not_found', 'label' => 'Busqueda: No encontrados', 'tone' => 'danger', 'sort_order' => 30],
        ];

        foreach ($statuses as $status) {
            RequestStatusCatalog::updateOrCreate(
                ['code' => $status['code']],
                $status + ['is_active' => true]
            );
        }
    }
}
