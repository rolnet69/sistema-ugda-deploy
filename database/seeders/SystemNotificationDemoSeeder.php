<?php

namespace Database\Seeders;

use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Database\Seeder;

class SystemNotificationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $recipients = User::query()
            ->whereIn('email', [
                'admin-ugda@yopmail.com',
                'usuario.ugda@yopmail.com',
            ])
            ->get();

        $actor = User::query()
            ->where('email', 'unidad.solicitante@yopmail.com')
            ->first();

        foreach ($recipients as $recipient) {
            SystemNotification::query()
                ->where('user_id', $recipient->id)
                ->where('metadata->demo_batch', 'notifications-preview')
                ->delete();

            foreach ($this->demoNotifications() as $index => $notification) {
                SystemNotification::query()->create(array_merge($notification, [
                    'user_id' => $recipient->id,
                    'actor_user_id' => $actor?->id,
                    'metadata' => [
                        'demo_batch' => 'notifications-preview',
                        'demo_index' => $index + 1,
                    ],
                    'created_at' => now()->subMinutes(($index + 1) * 9),
                    'updated_at' => now()->subMinutes(($index + 1) * 9),
                    'read_at' => $index >= 9 ? now()->subMinutes(($index + 1) * 5) : null,
                ]));
            }
        }
    }

    private function demoNotifications(): array
    {
        return [
            $this->notification('transfer_pending_ugda_review', 'Transferencia autorizada', 'Transferencia #000981 lista para revision UGDA.', 'La jefatura autorizo la transferencia documental de Decanato FIA. UGDA debe revisar el detalle y programar la entrega fisica.', 'info', '/solicitudes/transferencias/000981', 'transfer', 981),
            $this->notification('transfer_observed', 'Transferencia observada', 'Transferencia #000975 necesita correcciones.', 'UGDA registro observaciones sobre inventario y firmas requeridas. La unidad solicitante debe corregir y reenviar.', 'warning', '/solicitudes/transferencias/000975', 'transfer', 975),
            $this->notification('loan_pending_search', 'Prestamo autorizado', 'Prestamo #PREST-004 listo para busqueda.', 'La solicitud fue autorizada. UGDA debe iniciar la busqueda fisica de los documentos solicitados.', 'info', '/solicitudes/prestamos/PREST-004', 'loan', 4),
            $this->notification('transfer_pending_unit_authorization', 'Pendiente de jefatura', 'Transferencia #000967 requiere autorización.', 'La unidad productora envio una transferencia documental pendiente de revision por jefatura.', 'warning', '/solicitudes/transferencias/000967', 'transfer', 967),
            $this->notification('loan_pending_delivery', 'Busqueda finalizada', 'Prestamo #PREST-006 listo para entrega.', 'Los documentos ya fueron localizados. Registre el prestamo cuando se entreguen al solicitante.', 'success', '/solicitudes/prestamos/PREST-006', 'loan', 6),
            $this->notification('transfer_scheduled', 'Transferencia agendada', 'Transferencia #000965 fue agendada.', 'UGDA programo la entrega fisica. Revise fecha y prepare las cajas indicadas.', 'info', '/solicitudes/transferencias/000965', 'transfer', 965),
            $this->notification('loan_observed', 'Prestamo observado', 'Prestamo #PREST-002 necesita revision.', 'UGDA solicito aclarar el alcance de los documentos requeridos antes de continuar.', 'warning', '/solicitudes/prestamos/PREST-002', 'loan', 2),
            $this->notification('transfer_denied_by_ugda', 'Transferencia denegada', 'Transferencia #000976 fue denegada.', 'UGDA denego la transferencia porque la documentación no cumple los requisitos minimos.', 'danger', '/solicitudes/transferencias/000976', 'transfer', 976),
            $this->notification('loan_search_started', 'Busqueda iniciada', 'Prestamo #PREST-003 esta en busqueda.', 'UGDA inicio la localizacion fisica de los expedientes solicitados.', 'info', '/solicitudes/prestamos/PREST-003', 'loan', 3),
            $this->notification('transfer_completed', 'Transferencia completada', 'Transferencia #000964 fue completada.', 'Las ubicaciones fisicas fueron asignadas y el proceso de transferencia quedo cerrado.', 'success', '/solicitudes/transferencias/000964', 'transfer', 964),
            $this->notification('loan_registered', 'Prestamo registrado', 'Prestamo #PREST-007 fue entregado.', 'UGDA registro la entrega de documentos con fecha de devolución programada.', 'success', '/solicitudes/prestamos/PREST-007', 'loan', 7),
            $this->notification('transfer_denied_by_unit', 'Transferencia denegada', 'Transferencia #000973 fue denegada por jefatura.', 'La jefatura de unidad denego la solicitud por documentación incompleta.', 'danger', '/solicitudes/transferencias/000973', 'transfer', 973),
            $this->notification('loan_returned', 'Devolución registrada', 'Prestamo #PREST-008 tiene devolución registrada.', 'Los documentos del prestamo fueron devueltos y verificados por UGDA.', 'success', '/solicitudes/prestamos/PREST-008', 'loan', 8),
            $this->notification('transfer_pending_ugda_review', 'Revision pendiente', 'Transferencia #000982 espera gestion UGDA.', 'La solicitud ya fue autorizada por jefatura y requiere continuar el flujo interno.', 'info', '/solicitudes/transferencias/000982', 'transfer', 982),
            $this->notification('loan_denied', 'Prestamo denegado', 'Prestamo #PREST-009 fue denegado.', 'UGDA denego la solicitud porque los documentos estan en proceso de digitalizacion.', 'danger', '/solicitudes/prestamos/PREST-009', 'loan', 9),
            $this->notification('transfer_pending_unit_authorization', 'Nueva transferencia', 'Transferencia #000983 pendiente de autorización.', 'Una nueva solicitud de transferencia fue registrada y espera decision de jefatura.', 'warning', '/solicitudes/transferencias/000983', 'transfer', 983),
        ];
    }

    private function notification(
        string $type,
        string $title,
        string $shortMessage,
        string $body,
        string $tone,
        string $actionUrl,
        string $referenceType,
        int $referenceNumber
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'short_message' => $shortMessage,
            'body' => $body,
            'tone' => $tone,
            'action_url' => $actionUrl,
            'reference_type' => $referenceType,
            'reference_id' => $referenceNumber,
            'reference_number' => $referenceType === 'loan'
                ? 'PREST-' . str_pad((string) $referenceNumber, 3, '0', STR_PAD_LEFT)
                : str_pad((string) $referenceNumber, 6, '0', STR_PAD_LEFT),
        ];
    }
}
