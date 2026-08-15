<?php

namespace Database\Seeders;

use App\Models\Loan;
use App\Models\LoanDispatch;
use App\Models\LoanDocument;
use App\Models\LoanEvent;
use App\Models\LoanReturn;
use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use App\Models\TransferBox;
use App\Models\TransferDocument;
use App\Models\TransferEvent;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RequestWorkflowDemoSeeder extends Seeder
{
    private array $statusIds = [];

    private array $units = [];

    private array $users = [];

    public function run(): void
    {
        $this->seedUnits();
        $this->seedUsers();
        $this->loadStatuses();
        $this->seedTransfers();
        $this->seedLoans();
    }

    private function seedUnits(): void
    {
        $catalog = [
            'UGDA' => 'Unidad de Gestion Documental y Archivos',
            'DEC-FIA' => 'Decanato',
            'EIC' => 'Escuela de Ingenieria Civil',
            'EII' => 'Escuela de Ingenieria Industrial',
            'EIM' => 'Escuela de Ingenieria Mecanica',
            'EIE' => 'Escuela de Ingenieria Electrica',
            'ARQ' => 'Escuela de Arquitectura',
            'EIQ' => 'Escuela de Ingenieria Quimica',
            'EIS' => 'Escuela de Ingenieria de Sistemas',
        ];

        foreach ($catalog as $code => $name) {
            $this->units[$code] = Unit::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_active' => true]
            );
        }
    }

    private function seedUsers(): void
    {
        $catalog = [
            'carlos_rodriguez_ugda' => [
                'email' => 'usuario.ugda@yopmail.com',
                'first_name' => 'Carlos',
                'second_name' => null,
                'first_last_name' => 'Rodriguez',
                'second_last_name' => null,
                'unit' => 'UGDA',
            ],
            'francisco_alarcon' => [
                'email' => 'francisco.alarcon@yopmail.com',
                'first_name' => 'Francisco',
                'second_name' => 'Armando',
                'first_last_name' => 'Alarcon',
                'second_last_name' => 'Sandoval',
                'unit' => 'DEC-FIA',
            ],
            'maria_elena_guardado' => [
                'email' => 'maria.guardado@yopmail.com',
                'first_name' => 'Maria',
                'second_name' => 'Elena',
                'first_last_name' => 'Guardado',
                'second_last_name' => null,
                'unit' => 'UGDA',
            ],
            'ana_maria_hernandez' => [
                'email' => 'ana.hernandez@yopmail.com',
                'first_name' => 'Ana',
                'second_name' => 'Maria',
                'first_last_name' => 'Hernandez',
                'second_last_name' => null,
                'unit' => 'ARQ',
            ],
            'jose_antonio_mendez' => [
                'email' => 'jose.mendez@yopmail.com',
                'first_name' => 'Jose',
                'second_name' => 'Antonio',
                'first_last_name' => 'Mendez',
                'second_last_name' => null,
                'unit' => 'EII',
            ],
            'luis_barrera' => ['email' => 'luis.barrera@yopmail.com', 'first_name' => 'Luis', 'second_name' => 'Salvador', 'first_last_name' => 'Barrera', 'second_last_name' => 'Mancia', 'unit' => 'DEC-FIA'],
            'karla_baires' => ['email' => 'karla.baires@yopmail.com', 'first_name' => 'Karla', 'second_name' => 'Beatriz', 'first_last_name' => 'Baires', 'second_last_name' => 'de Rivas', 'unit' => 'EII'],
            'patricia_morales' => ['email' => 'patricia.morales@yopmail.com', 'first_name' => 'Patricia', 'second_name' => null, 'first_last_name' => 'Morales', 'second_last_name' => null, 'unit' => 'EII'],
            'fernando_garcia' => ['email' => 'fernando.garcia@yopmail.com', 'first_name' => 'Fernando', 'second_name' => null, 'first_last_name' => 'Garcia', 'second_last_name' => null, 'unit' => 'DEC-FIA'],
            'sandra_lopez' => ['email' => 'sandra.lopez@yopmail.com', 'first_name' => 'Sandra', 'second_name' => null, 'first_last_name' => 'Lopez', 'second_last_name' => null, 'unit' => 'EII'],
            'mario_flores' => ['email' => 'mario.flores@yopmail.com', 'first_name' => 'Mario', 'second_name' => 'Alberto', 'first_last_name' => 'Flores', 'second_last_name' => null, 'unit' => 'EIM'],
            'carmen_martinez' => ['email' => 'carmen.martinez@yopmail.com', 'first_name' => 'Carmen', 'second_name' => 'Julia', 'first_last_name' => 'Martinez', 'second_last_name' => null, 'unit' => 'DEC-FIA'],
            'roberto_martinez' => ['email' => 'roberto.martinez@yopmail.com', 'first_name' => 'Roberto', 'second_name' => 'Carlos', 'first_last_name' => 'Martinez', 'second_last_name' => 'Lopez', 'unit' => 'EIC'],
            'maria_rodriguez' => ['email' => 'maria.rodriguez@yopmail.com', 'first_name' => 'Maria', 'second_name' => 'Elena', 'first_last_name' => 'Rodriguez', 'second_last_name' => null, 'unit' => 'EIC'],
            'carlos_martinez' => ['email' => 'carlos.martinez@yopmail.com', 'first_name' => 'Carlos', 'second_name' => 'Alberto', 'first_last_name' => 'Martinez', 'second_last_name' => null, 'unit' => 'EIE'],
            'ana_gomez' => ['email' => 'ana.gomez@yopmail.com', 'first_name' => 'Ana', 'second_name' => 'Patricia', 'first_last_name' => 'Gomez', 'second_last_name' => null, 'unit' => 'ARQ'],
            'roberto_flores' => ['email' => 'roberto.flores@yopmail.com', 'first_name' => 'Roberto', 'second_name' => 'Antonio', 'first_last_name' => 'Flores', 'second_last_name' => null, 'unit' => 'EIM'],
            'patricia_ramirez' => ['email' => 'patricia.ramirez@yopmail.com', 'first_name' => 'Patricia', 'second_name' => 'del Carmen', 'first_last_name' => 'Ramirez', 'second_last_name' => null, 'unit' => 'DEC-FIA'],
            'jose_hernandez' => ['email' => 'jose.hernandez@yopmail.com', 'first_name' => 'Jose', 'second_name' => 'Antonio', 'first_last_name' => 'Hernandez', 'second_last_name' => null, 'unit' => 'EII'],
            'luis_ramirez' => ['email' => 'luis.ramirez@yopmail.com', 'first_name' => 'Luis', 'second_name' => 'Fernando', 'first_last_name' => 'Ramirez', 'second_last_name' => null, 'unit' => 'ARQ'],
            'sandra_beatriz' => ['email' => 'sandra.beatriz@yopmail.com', 'first_name' => 'Sandra', 'second_name' => 'Beatriz', 'first_last_name' => 'Morales', 'second_last_name' => null, 'unit' => 'EIQ'],
            'miguel_torres' => ['email' => 'miguel.torres@yopmail.com', 'first_name' => 'Miguel', 'second_name' => 'Angel', 'first_last_name' => 'Torres', 'second_last_name' => null, 'unit' => 'EIS'],
            'carlos_eduardo' => ['email' => 'carlos.eduardo@yopmail.com', 'first_name' => 'Carlos', 'second_name' => 'Eduardo', 'first_last_name' => 'Flores', 'second_last_name' => null, 'unit' => 'EIC'],
        ];

        foreach ($catalog as $key => $data) {
            $this->users[$key] = $this->createUser($data);
        }
    }

    private function loadStatuses(): void
    {
        $this->statusIds = RequestStatusCatalog::query()
            ->pluck('id', 'code')
            ->all();
    }

    private function seedTransfers(): void
    {
        $transfers = [
            [
                'code' => '000964',
                'requester' => 'luis_barrera',
                'unit' => 'DEC-FIA',
                'requested_at' => '19/04/2025 08:30',
                'authorization_status' => 'transfer_auth_authorized',
                'workflow_status' => 'transfer_status_transferred',
                'authorized_by' => 'francisco_alarcon',
                'authorized_at' => '19/04/2025 14:20',
                'completed_by' => 'carlos_rodriguez_ugda',
                'completed_at' => '19/04/2025 15:45',
                'view_mode' => 'detail',
                'box_display_state' => 'collapsed',
                'show_print_card' => true,
                'description' => 'Transferencia documental correspondiente a expedientes administrativos y academicos del periodo 2024-2025.',
                'boxes' => [
                    $this->transferBoxPayload('001', 'Caja #001', '01/01/2024 - 31/12/2024', 'A-03-2-05', 'carlos_rodriguez_ugda', '19/04/2025 15:30', 'Actas y Resoluciones', 2024, 2024, [
                        $this->transferDocPayload('EXP-GRAD-2024', 'Expedientes de estudiantes graduados 2024', 'Expedientes Academicos', 'Fisico', '2024', '350 pags', 'EXP-GRAD-2024.pdf'),
                        $this->transferDocPayload('ACTA-GRAD-2024', 'Actas de examen de grado', 'Actas y Reuniones', 'Fisico', '2024', '120 pags', 'ACTA-GRAD-2024.pdf'),
                    ]),
                    $this->transferBoxPayload('002', 'Caja #002', '01/01/2024 - 30/06/2024', 'A-03-2-06', 'carlos_rodriguez_ugda', '19/04/2025 15:32', 'Correspondencia Oficial', 2024, 2024, [
                        $this->transferDocPayload('CORR-INT-2024', 'Correspondencia interna', 'Correspondencia Institucional', 'Fisico', '2024', '210 pags', 'CORR-INT-2024.pdf'),
                    ]),
                    $this->transferBoxPayload('003', 'Caja #003', '01/01/2024 - 31/12/2024', 'A-03-2-07', 'carlos_rodriguez_ugda', '19/04/2025 15:34', 'Actas y Reuniones', 2024, 2024, [
                        $this->transferDocPayload('ACTAS-2024', 'Actas de junta directiva', 'Actas y Reuniones', 'Fisico', '2024', '140 pags', null),
                    ]),
                ],
                'events' => [
                    $this->eventPayload('status', 'Transferido', 'Transferencia completada exitosamente', '19/04/2025 15:45', 'carlos_rodriguez_ugda', 'transfer_status_transferred'),
                    $this->eventPayload('status', 'Agendado', 'Transferencia agendada para entrega fisica', '19/04/2025 14:30', 'carlos_rodriguez_ugda', 'transfer_status_scheduled'),
                    $this->eventPayload('status', 'Autorizado', 'Solicitud autorizada por jefatura de unidad', '19/04/2025 14:20', 'francisco_alarcon', 'transfer_auth_authorized'),
                    $this->eventPayload('status', 'Creado', 'Solicitud creada y enviada por la unidad productora', '19/04/2025 08:30', 'luis_barrera', null),
                ],
            ],
            [
                'code' => '000965',
                'requester' => 'karla_baires',
                'unit' => 'EII',
                'requested_at' => '19/04/2025 08:30',
                'authorization_status' => 'transfer_auth_authorized',
                'workflow_status' => 'transfer_status_scheduled',
                'authorized_by' => 'francisco_alarcon',
                'authorized_at' => '19/04/2025 08:30',
                'view_mode' => 'detail',
                'box_display_state' => 'first',
                'show_print_card' => true,
                'description' => 'Detalle de transferencia documental en proceso de gestion dentro del sistema UGDA FIA UES.',
                'boxes' => [
                    $this->transferBoxPayload('001', 'Caja #001', '01/01/2024 - 31/12/2024', 'A-03-2-01', 'carlos_rodriguez_ugda', '19/04/2025 08:35', 'Expedientes Academicos', 2024, 2024, [
                        $this->transferDocPayload('EXP-GRAD-2024', 'Expedientes de estudiantes graduados 2024', 'Expedientes Academicos', 'Fisico', '2024', '350 pags', 'EXP-GRAD-2024.pdf'),
                        $this->transferDocPayload('ACTA-GRAD-2024', 'Actas de examen de grado', 'Actas y Reuniones', 'Fisico', '2024', '120 pags', 'ACTA-GRAD-2024.pdf'),
                    ]),
                ],
                'events' => [
                    $this->eventPayload('status', 'Agendado', 'Estado actual registrado para la solicitud.', '19/04/2025 08:30', 'carlos_rodriguez_ugda', 'transfer_status_scheduled'),
                    $this->eventPayload('status', 'Creado', 'Solicitud creada por la unidad productora.', '19/04/2025 08:30', 'karla_baires', null),
                ],
            ],
            [
                'code' => '000967',
                'requester' => 'karla_baires',
                'unit' => 'EII',
                'requested_at' => '17/04/2025 11:45',
                'authorization_status' => 'transfer_auth_pending',
                'workflow_status' => 'transfer_status_pending',
                'view_mode' => 'detail',
                'box_display_state' => 'collapsed',
                'show_print_card' => false,
                'description' => 'Transferencia documental correspondiente a expedientes administrativos y academicos del periodo 2024-2025.',
                'boxes' => [
                    $this->transferBoxPayload('001', 'Caja #001 -', '01/01/2024 - 31/12/2024', null, null, null, 'Documentación administrativa', 2024, 2024, [
                        $this->transferDocPayload('DOC-001', 'Documentación administrativa', 'Administracion', 'Fisico', '2024', '45 pags', null),
                    ]),
                    $this->transferBoxPayload('002', 'Caja #002 -', '01/01/2024 - 30/06/2024', null, null, null, 'Correspondencia institucional', 2024, 2024, [
                        $this->transferDocPayload('DOC-002', 'Correspondencia institucional', 'Correspondencia', 'Fisico', '2024', '38 pags', null),
                    ]),
                ],
                'events' => [
                    $this->eventPayload('status', 'Creado', 'Solicitud de transferencia creada', '17/04/2025 11:45', 'karla_baires', null),
                ],
            ],
            [
                'code' => '000973',
                'requester' => 'patricia_morales',
                'unit' => 'EII',
                'requested_at' => '10/04/2025 15:30',
                'authorization_status' => 'transfer_auth_denied',
                'workflow_status' => 'transfer_status_cancelled',
                'view_mode' => 'detail',
                'box_display_state' => 'collapsed',
                'show_print_card' => false,
                'description' => 'Transferencia documental correspondiente a expedientes administrativos y academicos del periodo 2024-2025.',
                'boxes' => [
                    $this->transferBoxPayload('001', 'Caja #001 -', '01/01/2024 - 31/12/2024', null, null, null, 'Documentación administrativa', 2024, 2024, [
                        $this->transferDocPayload('DOC-003', 'Documentación administrativa', 'Administracion', 'Fisico', '2024', '28 pags', null),
                    ]),
                ],
                'events' => [
                    $this->eventPayload('decision', 'Denegado', 'Solicitud denegada. Motivo: La documentación presentada no cumple con los requisitos minimos establecidos en el reglamento de archivo institucional.', '11/04/2025 09:00', 'jose_antonio_mendez', 'transfer_auth_denied', [
                        'reason_label' => 'Motivo de denegación:',
                        'reason' => 'La documentación presentada no cumple con los requisitos minimos establecidos en el reglamento de archivo institucional.',
                        'decision_scope' => 'unit',
                    ]),
                    $this->eventPayload('status', 'Creado', 'Solicitud de transferencia creada', '10/04/2025 15:30', 'patricia_morales', null),
                ],
            ],
            [
                'code' => '000975',
                'requester' => 'fernando_garcia',
                'unit' => 'DEC-FIA',
                'requested_at' => '12/04/2025 16:00',
                'authorization_status' => 'transfer_auth_authorized',
                'workflow_status' => 'transfer_status_observed',
                'authorized_by' => 'francisco_alarcon',
                'authorized_at' => '13/04/2025 10:00',
                'view_mode' => 'review',
                'box_display_state' => 'collapsed',
                'show_print_card' => false,
                'description' => null,
                'boxes' => [
                    $this->transferBoxPayload('001', 'Caja #001 - Expedientes Academicos', '01/01/2024 - 31/12/2024', null, null, null, 'Correspondencia interna', 2020, 2024, [
                        $this->transferDocPayload('EXP-2024', 'Expedientes de estudiantes graduados 2024', 'Expedientes Academicos', 'Fisico', '2024', '350 pags', null),
                        $this->transferDocPayload('ACTA-2024', 'Actas de examen de grado', 'Actas y Reuniones', 'Fisico', '2024', '120 pags', null),
                        $this->transferDocPayload('CERT-2024', 'Certificaciones academicas', 'Certificaciones', 'Fisico', '2024', '85 pags', null),
                    ]),
                    $this->transferBoxPayload('002', 'Caja #002 - Correspondencia Oficial', '01/01/2024 - 30/06/2024', null, null, null, 'Informes Anuales', 2021, 2025, [
                        $this->transferDocPayload('CORR-2024', 'Correspondencia interna', 'Correspondencia Institucional', 'Fisico', '2024', '75 pags', null),
                        $this->transferDocPayload('MEMO-2024', 'Memorandos administrativos', 'Comunicaciones Internas', 'Mixto', '2024', '90 pags', null),
                        $this->transferDocPayload('CIRC-2024', 'Circulares', 'Circulares', 'Digital', '2024', '48 pags', null),
                    ]),
                    $this->transferBoxPayload('003', 'Caja #003 - Actas y Reuniones', '01/01/2024 - 31/12/2024', null, null, null, 'Actas y Reuniones', 2024, 2024, [
                        $this->transferDocPayload('ACTA-JD-2024', 'Actas de junta directiva', 'Actas y Reuniones', 'Fisico', '2024', '110 pags', null),
                        $this->transferDocPayload('ACTA-COM-2024', 'Actas de comisiones especiales', 'Actas y Reuniones', 'Fisico', '2024', '60 pags', null),
                    ]),
                ],
                'events' => [
                    $this->eventPayload('observation', 'Observación UGDA', 'Se requiere completar el inventario de documentos. Faltan las fichas descriptivas de las series documentales y el formato de transferencia no esta firmado por el jefe de la unidad productora.', '05/02/2026 14:20', 'carlos_rodriguez_ugda'),
                    $this->eventPayload('observation', 'Observación UGDA', 'Actualizacion: Se necesita adjuntar la resolucion de eliminacion de documentos segun normativa vigente. Los documentos marcados como "Para eliminar" deben contar con la aprobacion correspondiente.', '08/02/2026 09:15', 'carlos_rodriguez_ugda'),
                    $this->eventPayload('status', 'Autorizado', 'Solicitud autorizada por director de unidad.', '13/04/2025 10:00', 'francisco_alarcon', 'transfer_auth_authorized'),
                    $this->eventPayload('status', 'Creado', 'Solicitud creada y enviada por la unidad productora.', '12/04/2025 16:00', 'fernando_garcia', null),
                ],
            ],
            [
                'code' => '000976',
                'requester' => 'sandra_lopez',
                'unit' => 'EII',
                'requested_at' => '08/04/2025 13:20',
                'authorization_status' => 'transfer_auth_authorized',
                'workflow_status' => 'transfer_status_denied',
                'authorized_by' => 'jose_antonio_mendez',
                'authorized_at' => '09/04/2025 08:00',
                'view_mode' => 'detail',
                'box_display_state' => 'collapsed',
                'show_print_card' => false,
                'description' => 'Transferencia documental correspondiente a expedientes administrativos y academicos del periodo 2024-2025.',
                'boxes' => [
                    $this->transferBoxPayload('001', 'Caja #001 -', '01/01/2024 - 31/12/2024', null, null, null, 'Documentación administrativa', 2024, 2024, [
                        $this->transferDocPayload('DOC-004', 'Documentación administrativa', 'Administracion', 'Fisico', '2024', '32 pags', null),
                    ]),
                ],
                'events' => [
                    $this->eventPayload('decision', 'Denegado', 'Solicitud denegada por UGDA. Motivo: Los documentos no cumplen con los criterios de valoracion documental establecidos. Se recomienda revision interna antes de solicitar nuevamente la transferencia.', '10/04/2025 11:15', 'carlos_rodriguez_ugda', 'transfer_status_denied', [
                        'reason_label' => 'Motivo de rechazo:',
                        'reason' => 'Los documentos no cumplen con los criterios de valoracion documental establecidos. Se recomienda revision interna antes de solicitar nuevamente la transferencia.',
                        'decision_scope' => 'ugda',
                    ]),
                    $this->eventPayload('status', 'Autorizado', 'Solicitud autorizada por coordinacion', '09/04/2025 08:00', 'jose_antonio_mendez', 'transfer_auth_authorized'),
                    $this->eventPayload('status', 'Creado', 'Solicitud de transferencia creada', '08/04/2025 13:20', 'sandra_lopez', null),
                ],
            ],
            [
                'code' => '000977',
                'requester' => 'mario_flores',
                'unit' => 'EIM',
                'requested_at' => '10/02/2026 09:30',
                'authorization_status' => 'transfer_auth_authorized',
                'workflow_status' => 'transfer_status_pending',
                'authorized_by' => 'maria_elena_guardado',
                'authorized_at' => '11/02/2026 08:15',
                'view_mode' => 'review',
                'box_display_state' => 'collapsed',
                'show_print_card' => false,
                'description' => null,
                'boxes' => [
                    $this->transferBoxPayload('001', 'Caja #001 - Expedientes Academicos', '01/01/2024 - 31/12/2024', null, null, null, 'Correspondencia interna', 2020, 2024, [
                        $this->transferDocPayload('EXP-EST-2024', 'Expedientes Academicos', 'Expedientes Academicos', 'Fisico', '2024', '15 doc(s)', null),
                    ]),
                    $this->transferBoxPayload('002', 'Caja #002 - Correspondencia Oficial', '01/01/2024 - 30/06/2024', null, null, null, 'Informes Anuales', 2021, 2025, [
                        $this->transferDocPayload('CORR-OF-2024', 'Correspondencia Oficial', 'Correspondencia Oficial', 'Fisico', '2024', '8 doc(s)', null),
                    ]),
                    $this->transferBoxPayload('003', 'Caja #003 - Actas y Reuniones', '01/01/2024 - 31/12/2024', null, null, null, 'Actas y Reuniones', 2024, 2024, [
                        $this->transferDocPayload('ACTAS-REUN-2024', 'Actas y Reuniones', 'Actas y Reuniones', 'Fisico', '2024', '2 doc(s)', null),
                    ]),
                ],
                'events' => [
                    $this->eventPayload('status', 'Autorizado', 'Solicitud autorizada por director de unidad.', '11/02/2026 08:15', 'maria_elena_guardado', 'transfer_auth_authorized'),
                    $this->eventPayload('status', 'Creado', 'Solicitud de transferencia creada', '10/02/2026 09:30', 'mario_flores', null),
                ],
            ],
            [
                'code' => '000978',
                'requester' => 'carmen_martinez',
                'unit' => 'DEC-FIA',
                'requested_at' => '09/02/2026 14:20',
                'authorization_status' => 'transfer_auth_authorized',
                'workflow_status' => 'transfer_status_pending',
                'authorized_by' => 'maria_elena_guardado',
                'authorized_at' => '10/02/2026 08:15',
                'view_mode' => 'review',
                'box_display_state' => 'collapsed',
                'show_print_card' => false,
                'description' => null,
                'boxes' => [
                    $this->transferBoxPayload('001', 'Caja #001 - Correspondencia Oficial', '01/01/2024 - 31/12/2024', null, null, null, 'Correspondencia oficial', 2024, 2024, [
                        $this->transferDocPayload('CORR-2024', 'Correspondencia oficial', 'Correspondencia', 'Fisico', '2024', '6 doc(s)', null),
                    ]),
                ],
                'events' => [
                    $this->eventPayload('status', 'Autorizado', 'Solicitud autorizada por director de unidad.', '10/02/2026 08:15', 'maria_elena_guardado', 'transfer_auth_authorized'),
                    $this->eventPayload('status', 'Creado', 'Solicitud de transferencia creada', '09/02/2026 14:20', 'carmen_martinez', null),
                ],
            ],
            [
                'code' => '000970',
                'requester' => 'roberto_martinez',
                'unit' => 'EIC',
                'requested_at' => '15/03/2025 10:15',
                'authorization_status' => 'transfer_auth_authorized',
                'workflow_status' => 'transfer_status_transferred',
                'authorized_by' => 'francisco_alarcon',
                'authorized_at' => '15/03/2025 11:00',
                'completed_by' => 'carlos_rodriguez_ugda',
                'completed_at' => '15/03/2025 16:45',
                'view_mode' => 'detail',
                'box_display_state' => 'collapsed',
                'show_print_card' => true,
                'description' => 'Transferencia documental correspondiente a expedientes administrativos y academicos del periodo 2024-2025.',
                'boxes' => [
                    $this->transferBoxPayload('001', 'Caja #001', '01/01/2024 - 31/12/2024', 'A-03-2-001', 'carlos_rodriguez_ugda', '15/03/2025 16:40', 'Expedientes Academicos', 2024, 2024, [
                        $this->transferDocPayload('EXP-2024-CIV', 'Expedientes academicos', 'Expedientes Academicos', 'Mixto', '2024', '415 pags', 'EXP-ACA-2024.pdf'),
                    ]),
                ],
                'events' => [
                    $this->eventPayload('status', 'Transferido', 'Transferencia completada exitosamente', '15/03/2025 16:45', 'carlos_rodriguez_ugda', 'transfer_status_transferred'),
                    $this->eventPayload('status', 'Autorizado', 'Solicitud autorizada por jefatura de unidad', '15/03/2025 11:00', 'francisco_alarcon', 'transfer_auth_authorized'),
                    $this->eventPayload('status', 'Creado', 'Solicitud creada y enviada por la unidad productora', '15/03/2025 10:15', 'roberto_martinez', null),
                ],
            ],
        ];

        foreach ($transfers as $transfer) {
            $this->syncTransfer($transfer);
        }
    }

    private function seedLoans(): void
    {
        $loans = [
            [
                'number' => 'PREST-001',
                'requester' => 'maria_rodriguez',
                'unit' => 'EIC',
                'requested_at' => '16/02/2026 09:15',
                'authorization_status' => 'loan_auth_pending',
                'workflow_status' => 'loan_status_pending',
                'view_mode' => 'detail',
                'description' => null,
                'documents' => [
                    $this->loanDocPayload('system', 'Documentos del Sistema (2)', 'Actas de Junta Directiva 2023', 'Actas y Resoluciones', 'CAJA-001', '2023', null, 'Copia', 'info', null, null),
                    $this->loanDocPayload('system', 'Documentos del Sistema (2)', 'Expedientes Estudiantiles 2022', 'Expedientes Academicos', 'CAJA-015', '2022', null, 'Original', 'warning', null, null),
                ],
                'events' => [
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Lic. Maria Elena Rodriguez', '16/02/2026 09:15', 'maria_rodriguez', null),
                ],
            ],
            [
                'number' => 'PREST-002',
                'requester' => 'carlos_martinez',
                'unit' => 'EIE',
                'requested_at' => '15/02/2026 14:30',
                'authorization_status' => 'loan_auth_authorized',
                'workflow_status' => 'loan_status_pending',
                'authorized_by' => 'francisco_alarcon',
                'authorized_at' => '16/02/2026 10:00',
                'view_mode' => 'manage',
                'documents' => [
                    $this->loanDocPayload('system', 'Documentos del Sistema (1)', 'Contratos y Convenios 2021-2023', 'Documentación Administrativa', 'CAJA-028', '2021-2023', null, 'Copia', 'info', null, null),
                    $this->loanDocPayload('additional', 'Documentos Adicionales (1)', 'Expedientes de Graduacion 2015', null, null, '2015', 'Escuela de Ingenieria Electrica', 'Original', 'warning', '3 cajas', 'Se transfirieron el 10 de marzo de 2022'),
                ],
                'events' => [
                    $this->loanEventPayload('status', 'Autorizado Jefe/Director', 'Solicitud autorizada por Ing. Maria Elena Guardado', '16/02/2026 10:00', 'francisco_alarcon', 'loan_auth_authorized'),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Ing. Carlos Alberto Martinez', '15/02/2026 14:30', 'carlos_martinez', null),
                ],
            ],
            [
                'number' => 'PREST-003',
                'requester' => 'ana_gomez',
                'unit' => 'ARQ',
                'requested_at' => '14/02/2026 11:00',
                'authorization_status' => 'loan_auth_denied',
                'workflow_status' => 'loan_status_cancelled',
                'view_mode' => 'detail',
                'documents' => [
                    $this->loanDocPayload('additional', 'Documentos Adicionales (1)', 'Planos arquitectonicos historicos', null, null, '2010-2012', 'Escuela de Arquitectura', 'Original', 'warning', '2 carpetas', 'Documentos historicos de proyectos emblematicos'),
                ],
                'events' => [
                    $this->loanEventPayload('decision', 'Denegado por Jefe/Director', 'Solicitud denegada por Arq. Ana Maria Hernandez. Motivo: Los documentos solicitados estan en proceso de revision legal y no pueden ser prestados en este momento.', '15/02/2026 09:30', 'ana_maria_hernandez', 'loan_auth_denied', [
                        'reason_label' => 'Motivo de denegación:',
                        'reason' => 'Los documentos solicitados estan en proceso de revision legal y no pueden ser prestados en este momento.',
                        'decision_scope' => 'unit',
                    ]),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Arq. Ana Patricia Gomez', '14/02/2026 11:00', 'ana_gomez', null),
                ],
            ],
            [
                'number' => 'PREST-004',
                'requester' => 'roberto_flores',
                'unit' => 'EIM',
                'requested_at' => '13/02/2026 16:45',
                'authorization_status' => 'loan_auth_authorized',
                'workflow_status' => 'loan_status_observed',
                'authorized_by' => 'maria_elena_guardado',
                'authorized_at' => '14/02/2026 08:00',
                'view_mode' => 'manage',
                'documents' => [
                    $this->loanDocPayload('system', 'Documentos del Sistema (1)', 'Proyectos de Investigacion 2020', 'Investigacion y Desarrollo', 'CAJA-042', '2020', null, 'Copia', 'info', null, null),
                ],
                'events' => [
                    $this->loanEventPayload('observation', 'Observación UGDA', 'Se requiere especificar con mayor precision el ano de los documentos solicitados. Algunos expedientes abarcan multiples anos.', '15/02/2026 10:30', 'carlos_rodriguez_ugda'),
                    $this->loanEventPayload('status', 'Autorizado Jefe/Director', 'Solicitud autorizada por Ing. Maria Elena Guardado', '14/02/2026 08:00', 'maria_elena_guardado', 'loan_auth_authorized'),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Ing. Roberto Antonio Flores', '13/02/2026 16:45', 'roberto_flores', null),
                ],
            ],
            [
                'number' => 'PREST-010',
                'requester' => 'carlos_eduardo',
                'unit' => 'EIC',
                'requested_at' => '05/04/2025 11:30',
                'authorization_status' => 'loan_auth_authorized',
                'workflow_status' => 'loan_status_authorized',
                'authorized_by' => 'maria_elena_guardado',
                'authorized_at' => '06/04/2025 09:20',
                'view_mode' => 'manage',
                'documents' => [
                    $this->loanDocPayload('system', 'Documentos del Sistema (2)', 'Informes de Auditoria 2022', 'Auditoria y Control', 'CAJA-033', '2022', null, 'Original', 'warning', null, null),
                    $this->loanDocPayload('system', 'Documentos del Sistema (2)', 'Actas de Junta Directiva 2023', 'Actas y Resoluciones', 'CAJA-001', '2023', null, 'Copia', 'info', null, null),
                ],
                'events' => [
                    $this->loanEventPayload('status', 'Autorizado UGDA', 'Solicitud autorizada por UGDA para iniciar busqueda de documentos.', '06/04/2025 09:20', 'maria_elena_guardado', 'loan_status_authorized'),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Ing. Carlos Eduardo Flores', '05/04/2025 11:30', 'carlos_eduardo', null),
                ],
            ],
            [
                'number' => 'PREST-005',
                'requester' => 'patricia_ramirez',
                'unit' => 'DEC-FIA',
                'requested_at' => '12/02/2026 10:00',
                'authorization_status' => 'loan_auth_authorized',
                'workflow_status' => 'loan_status_authorized',
                'search_status' => 'loan_search_in_progress',
                'authorized_by' => 'francisco_alarcon',
                'authorized_at' => '13/02/2026 09:00',
                'ugda_authorized_by' => 'carlos_rodriguez_ugda',
                'ugda_authorized_at' => '13/02/2026 09:30',
                'search_started_by' => 'carlos_rodriguez_ugda',
                'search_started_at' => '13/02/2026 09:45',
                'view_mode' => 'manage',
                'documents' => [
                    $this->loanDocPayload('system', 'Documentos del Sistema (2)', 'Informes de Auditoria 2022', 'Auditoria y Control', 'CAJA-033', '2022', null, 'Original', 'warning', null, null),
                    $this->loanDocPayload('system', 'Documentos del Sistema (2)', 'Actas de Junta Directiva 2023', 'Actas y Resoluciones', 'CAJA-001', '2023', null, 'Copia', 'info', null, null),
                ],
                'events' => [
                    $this->loanEventPayload('status', 'Busqueda en proceso', 'UGDA ha iniciado el proceso de busqueda y localizacion fisica de los documentos solicitados.', '13/02/2026 09:45', 'carlos_rodriguez_ugda', 'loan_search_in_progress'),
                    $this->loanEventPayload('status', 'Autorizado UGDA', 'Solicitud autorizada por UGDA para iniciar busqueda de documentos.', '13/02/2026 09:30', 'carlos_rodriguez_ugda', 'loan_status_authorized'),
                    $this->loanEventPayload('status', 'Autorizado Jefe/Director', 'Solicitud autorizada por Dr. Francisco Armando Alarcon Sandoval', '13/02/2026 09:00', 'francisco_alarcon', 'loan_auth_authorized'),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Lic. Patricia del Carmen Ramirez', '12/02/2026 10:00', 'patricia_ramirez', null),
                ],
            ],
            [
                'number' => 'PREST-006',
                'requester' => 'jose_hernandez',
                'unit' => 'EII',
                'requested_at' => '10/02/2026 08:30',
                'authorization_status' => 'loan_auth_authorized',
                'workflow_status' => 'loan_status_authorized',
                'search_status' => 'loan_search_completed',
                'authorized_by' => 'maria_elena_guardado',
                'authorized_at' => '11/02/2026 10:00',
                'ugda_authorized_by' => 'carlos_rodriguez_ugda',
                'ugda_authorized_at' => '11/02/2026 09:30',
                'search_started_by' => 'carlos_rodriguez_ugda',
                'search_started_at' => '11/02/2026 10:00',
                'search_completed_by' => 'carlos_rodriguez_ugda',
                'search_completed_at' => '12/02/2026 14:30',
                'search_comments' => 'Se encontro el expediente de estudiantes, pero las tesis de grado 2018 no estan disponibles en este momento.',
                'view_mode' => 'manage',
                'documents' => [
                    $this->loanDocPayload('system', 'Documentos del Sistema (1)', 'Expedientes Estudiantiles 2022', 'Expedientes Academicos', 'CAJA-015', '2022', null, 'Copia', 'info', null, null, true),
                    $this->loanDocPayload('additional', 'Documentos Adicionales (1)', 'Tesis de grado 2018', null, null, '2018', 'Escuela de Ingenieria Industrial', 'Copia', 'info', '5 expedientes', 'Tesis relacionadas con optimizacion de procesos', false),
                ],
                'events' => [
                    $this->loanEventPayload('status', 'Busqueda finalizada', 'La busqueda de los documentos ha sido completada. Documentos listos para retiro.', '12/02/2026 14:30', 'carlos_rodriguez_ugda', 'loan_search_completed'),
                    $this->loanEventPayload('status', 'Busqueda en proceso', 'UGDA ha iniciado el proceso de busqueda y localizacion fisica de los documentos solicitados.', '11/02/2026 10:00', 'carlos_rodriguez_ugda', 'loan_search_in_progress'),
                    $this->loanEventPayload('status', 'Autorizado UGDA', 'Solicitud autorizada por UGDA para iniciar busqueda de documentos.', '11/02/2026 09:30', 'carlos_rodriguez_ugda', 'loan_status_authorized'),
                    $this->loanEventPayload('status', 'Autorizado Jefe/Director', 'Solicitud autorizada por Ing. Maria Elena Guardado', '11/02/2026 10:00', 'maria_elena_guardado', 'loan_auth_authorized'),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Ing. Jose Antonio Hernandez', '10/02/2026 08:30', 'jose_hernandez', null),
                ],
                'dispatch' => null,
                'return' => null,
            ],
            [
                'number' => 'PREST-007',
                'requester' => 'luis_ramirez',
                'unit' => 'ARQ',
                'requested_at' => '08/02/2026 09:00',
                'authorization_status' => 'loan_auth_authorized',
                'workflow_status' => 'loan_status_loaned',
                'search_status' => 'loan_search_completed',
                'authorized_by' => 'ana_maria_hernandez',
                'authorized_at' => '09/02/2026 08:30',
                'ugda_authorized_by' => 'carlos_rodriguez_ugda',
                'ugda_authorized_at' => '11/02/2026 09:30',
                'search_started_by' => 'carlos_rodriguez_ugda',
                'search_started_at' => '11/02/2026 10:00',
                'search_completed_by' => 'carlos_rodriguez_ugda',
                'search_completed_at' => '12/02/2026 14:30',
                'search_comments' => 'Todos los documentos solicitados fueron encontrados.',
                'view_mode' => 'manage',
                'documents' => [
                    $this->loanDocPayload('system', 'Documentos del Sistema (1)', 'Contratos y Convenios 2021-2023', 'Documentación Administrativa', 'CAJA-028', '2021-2023', null, 'Original', 'warning', null, null, true, true),
                ],
                'events' => [
                    $this->loanEventPayload('status', 'Prestado', 'Documentos entregados a Arq. Luis Fernando Ramirez. Fecha de devolución: 15/02/2026', '12/02/2026 11:15', 'carlos_rodriguez_ugda', 'loan_status_loaned'),
                    $this->loanEventPayload('status', 'Busqueda finalizada', 'La busqueda de los documentos ha sido completada. Documentos listos para retiro.', '12/02/2026 14:30', 'carlos_rodriguez_ugda', 'loan_search_completed'),
                    $this->loanEventPayload('status', 'Busqueda en proceso', 'UGDA ha iniciado el proceso de busqueda y localizacion fisica de los documentos solicitados.', '11/02/2026 10:00', 'carlos_rodriguez_ugda', 'loan_search_in_progress'),
                    $this->loanEventPayload('status', 'Autorizado UGDA', 'Solicitud autorizada por UGDA para iniciar busqueda de documentos.', '11/02/2026 09:30', 'carlos_rodriguez_ugda', 'loan_status_authorized'),
                    $this->loanEventPayload('status', 'Autorizado Jefe/Director', 'Solicitud autorizada por Arq. Ana Maria Hernandez', '09/02/2026 08:30', 'ana_maria_hernandez', 'loan_auth_authorized'),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Arq. Luis Fernando Ramirez', '08/02/2026 09:00', 'luis_ramirez', null),
                ],
                'dispatch' => [
                    'loan_date' => '12/02/2026',
                    'due_date' => '26/02/2026',
                    'received_by_name' => 'Arq. Luis Fernando Ramirez',
                    'delivered_by' => 'carlos_rodriguez_ugda',
                    'observations' => 'Prestamo de 14 dias. Renovacion automatica si no hay otros solicitantes.',
                    'document_titles' => ['Contratos y Convenios 2021-2023'],
                ],
            ],
            [
                'number' => 'PREST-008',
                'requester' => 'sandra_beatriz',
                'unit' => 'EIQ',
                'requested_at' => '05/02/2026 11:30',
                'authorization_status' => 'loan_auth_authorized',
                'workflow_status' => 'loan_status_returned',
                'search_status' => 'loan_search_completed',
                'authorized_by' => 'francisco_alarcon',
                'authorized_at' => '06/02/2026 09:00',
                'ugda_authorized_by' => 'carlos_rodriguez_ugda',
                'ugda_authorized_at' => '11/02/2026 09:30',
                'search_started_by' => 'carlos_rodriguez_ugda',
                'search_started_at' => '11/02/2026 10:00',
                'search_completed_by' => 'carlos_rodriguez_ugda',
                'search_completed_at' => '12/02/2026 14:30',
                'search_comments' => 'Documentos listos para entrega.',
                'view_mode' => 'detail',
                'documents' => [
                    $this->loanDocPayload('system', 'Documentos del Sistema (1)', 'Proyectos de Investigacion 2020', 'Investigacion y Desarrollo', 'CAJA-042', '2020', null, 'Copia', 'info', null, null, true, true, true),
                    $this->loanDocPayload('additional', 'Documentos Adicionales (1)', 'Informes de laboratorio 2019', null, null, '2019', 'Escuela de Ingenieria Quimica', 'Copia', 'info', '1 caja', 'Informes de practicas de laboratorio', false),
                ],
                'events' => [
                    $this->loanEventPayload('status', 'Devuelto', 'Documentos devueltos por Carlos Rodriguez (UGDA). Documentos devueltos en buen estado', '15/02/2026 10:45', 'carlos_rodriguez_ugda', 'loan_status_returned'),
                    $this->loanEventPayload('status', 'Prestado', 'Documentos entregados a Lic. Sandra Beatriz Morales. Fecha de devolución: 15/02/2026', '12/02/2026 11:15', 'carlos_rodriguez_ugda', 'loan_status_loaned'),
                    $this->loanEventPayload('status', 'Busqueda finalizada', 'La busqueda de los documentos ha sido completada. Documentos listos para retiro.', '12/02/2026 14:30', 'carlos_rodriguez_ugda', 'loan_search_completed'),
                    $this->loanEventPayload('status', 'Busqueda en proceso', 'UGDA ha iniciado el proceso de busqueda y localizacion fisica de los documentos solicitados.', '11/02/2026 10:00', 'carlos_rodriguez_ugda', 'loan_search_in_progress'),
                    $this->loanEventPayload('status', 'Autorizado UGDA', 'Solicitud autorizada por UGDA para iniciar busqueda de documentos.', '11/02/2026 09:30', 'carlos_rodriguez_ugda', 'loan_status_authorized'),
                    $this->loanEventPayload('status', 'Autorizado Jefe/Director', 'Solicitud autorizada por Dr. Francisco Armando Alarcon Sandoval', '06/02/2026 09:00', 'francisco_alarcon', 'loan_auth_authorized'),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Lic. Sandra Beatriz Morales', '05/02/2026 11:30', 'sandra_beatriz', null),
                ],
                'dispatch' => [
                    'loan_date' => '08/02/2026',
                    'due_date' => '15/02/2026',
                    'received_by_name' => 'Lic. Sandra Beatriz Morales',
                    'delivered_by' => 'carlos_rodriguez_ugda',
                    'observations' => 'Prestamo gestionado por 7 dias.',
                    'document_titles' => ['Proyectos de Investigacion 2020'],
                ],
                'return' => [
                    'return_date' => '15/02/2026',
                    'received_by' => 'carlos_rodriguez_ugda',
                    'condition_label' => 'Documentos devueltos en buen estado',
                    'observations' => 'Devolución realizada antes de la hora establecida. Documentos verificados y en perfectas condiciones.',
                    'document_titles' => ['Proyectos de Investigacion 2020'],
                ],
            ],
            [
                'number' => 'PREST-009',
                'requester' => 'miguel_torres',
                'unit' => 'EIS',
                'requested_at' => '03/02/2026 15:00',
                'authorization_status' => 'loan_auth_authorized',
                'workflow_status' => 'loan_status_denied',
                'authorized_by' => 'maria_elena_guardado',
                'authorized_at' => '04/02/2026 08:30',
                'view_mode' => 'detail',
                'documents' => [
                    $this->loanDocPayload('additional', 'Documentos Adicionales (1)', 'Documentos historicos de sistemas', null, null, '2005-2010', 'Escuela de Ingenieria de Sistemas', 'Original', 'warning', '4 cajas', 'Documentación historica del desarrollo de sistemas institucionales'),
                ],
                'events' => [
                    $this->loanEventPayload('status', 'Autorizado Jefe/Director', 'Solicitud autorizada por Ing. Maria Elena Guardado', '04/02/2026 08:30', 'maria_elena_guardado', 'loan_auth_authorized'),
                    $this->loanEventPayload('decision', 'Denegado por UGDA', 'Solicitud denegada por UGDA. Motivo: Los documentos solicitados se encuentran en proceso de digitalizacion y no estan disponibles para prestamo fisico en este momento. Se sugiere esperar 2 semanas para acceder a las versiones digitales.', '05/02/2026 10:00', 'carlos_rodriguez_ugda', 'loan_status_denied', [
                        'reason_label' => 'Motivo de rechazo:',
                        'reason' => 'Los documentos solicitados se encuentran en proceso de digitalizacion y no estan disponibles para prestamo fisico en este momento. Se sugiere esperar 2 semanas para acceder a las versiones digitales.',
                        'decision_scope' => 'ugda',
                    ]),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Ing. Miguel Angel Torres', '03/02/2026 15:00', 'miguel_torres', null),
                ],
            ],
            [
                'number' => '000974',
                'requester' => 'carlos_eduardo',
                'unit' => 'EIC',
                'requested_at' => '05/04/2025 11:30',
                'authorization_status' => 'loan_auth_authorized',
                'workflow_status' => 'loan_status_authorized',
                'authorized_by' => 'maria_elena_guardado',
                'authorized_at' => '06/04/2025 09:20',
                'view_mode' => 'manage',
                'documents' => [
                    $this->loanDocPayload('system', 'Documentos del Sistema (2)', 'Informes de Auditoria 2022', 'Auditoria y Control', 'CAJA-033', '2022', null, 'Original', 'warning', null, null),
                    $this->loanDocPayload('system', 'Documentos del Sistema (2)', 'Actas de Junta Directiva 2023', 'Actas y Resoluciones', 'CAJA-001', '2023', null, 'Copia', 'info', null, null),
                ],
                'events' => [
                    $this->loanEventPayload('status', 'Autorizado UGDA', 'Solicitud autorizada por UGDA.', '06/04/2025 09:20', 'maria_elena_guardado', 'loan_status_authorized'),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud creada.', '05/04/2025 11:30', 'carlos_eduardo', null),
                ],
            ],
            [
                'number' => '000966',
                'requester' => 'luis_barrera',
                'unit' => 'DEC-FIA',
                'requested_at' => '18/04/2025 10:15',
                'authorization_status' => 'loan_auth_denied',
                'workflow_status' => 'loan_status_cancelled',
                'view_mode' => 'detail',
                'documents' => [
                    $this->loanDocPayload('additional', 'Documentos Adicionales (2)', 'Contratos de servicios profesionales', null, null, '2020', 'Decanato', 'Original', 'warning', '180 paginas', 'Documentos legales en revision'),
                    $this->loanDocPayload('additional', 'Documentos Adicionales (2)', 'Convenios interinstitucionales', null, null, '2020', 'Decanato', 'Copia', 'info', '95 paginas', 'Convenios activos'),
                ],
                'events' => [
                    $this->loanEventPayload('decision', 'Denegado por Jefe/Director', 'Solicitud denegada por Dr. Francisco Armando Alarcon Sandoval. Motivo: Los documentos solicitados estan en proceso de revision legal y no pueden ser prestados en este momento.', '18/04/2025 16:30', 'francisco_alarcon', 'loan_auth_denied', [
                        'reason_label' => 'Motivo de denegación:',
                        'reason' => 'Los documentos solicitados estan en proceso de revision legal y no pueden ser prestados en este momento.',
                        'decision_scope' => 'unit',
                    ]),
                    $this->loanEventPayload('status', 'Creado', 'Solicitud de prestamo creada por Ing. Luis Salvador Barrera Mancia', '18/04/2025 10:15', 'luis_barrera', null),
                ],
            ],
        ];

        foreach ($loans as $loan) {
            $this->syncLoan($loan);
        }
    }

    private function syncTransfer(array $payload): void
    {
        $transfer = Transfer::updateOrCreate(
            ['code' => $payload['code']],
            [
                'user_id' => $this->users[$payload['requester']]->id,
                'unit_id' => $this->units[$payload['unit']]->id,
                'request_date' => $this->parseDateTime($payload['requested_at'])->toDateString(),
                'requested_at' => $this->parseDateTime($payload['requested_at']),
                'status' => $payload['authorization_status'] === 'transfer_auth_denied' ? 'Rechazada' : 'Aprobada',
                'authorization_status_id' => $this->statusIds[$payload['authorization_status']] ?? null,
                'workflow_status_id' => $this->statusIds[$payload['workflow_status']] ?? null,
                'authorized_by_user_id' => isset($payload['authorized_by']) ? $this->users[$payload['authorized_by']]->id : null,
                'authorized_at' => !empty($payload['authorized_at']) ? $this->parseDateTime($payload['authorized_at']) : null,
                'completed_by_user_id' => isset($payload['completed_by']) ? $this->users[$payload['completed_by']]->id : null,
                'completed_at' => !empty($payload['completed_at']) ? $this->parseDateTime($payload['completed_at']) : null,
                'view_mode' => $payload['view_mode'],
                'box_display_state' => $payload['box_display_state'],
                'show_print_card' => (bool) $payload['show_print_card'],
                'description' => $payload['description'],
                'observation' => null,
            ]
        );

        $transfer->events()->delete();
        $transfer->boxes()->delete();

        foreach ($payload['boxes'] as $boxPayload) {
            $box = $transfer->boxes()->create([
                'series_name' => $boxPayload['series_name'],
                'start_year' => $boxPayload['start_year'],
                'end_year' => $boxPayload['end_year'],
                'box_number' => (int) $boxPayload['number'],
                'title' => $boxPayload['title'],
                'period_label' => $boxPayload['period_label'],
                'location_code' => $boxPayload['location_code'],
                'assigned_by_user_id' => isset($boxPayload['assigned_by']) ? $this->users[$boxPayload['assigned_by']]->id : null,
                'assigned_at' => !empty($boxPayload['assigned_at']) ? $this->parseDateTime($boxPayload['assigned_at']) : null,
                'content_description' => $boxPayload['content_description'],
            ]);

            foreach ($boxPayload['documents'] as $index => $documentPayload) {
                $box->documents()->create($documentPayload + ['sort_order' => $index + 1]);
            }
        }

        foreach ($payload['events'] as $eventPayload) {
            $transfer->events()->create([
                'status_catalog_id' => $eventPayload['status_code'] ? ($this->statusIds[$eventPayload['status_code']] ?? null) : null,
                'actor_user_id' => $eventPayload['actor'] ? $this->users[$eventPayload['actor']]->id : null,
                'event_type' => $eventPayload['event_type'],
                'title' => $eventPayload['title'],
                'description' => $eventPayload['description'],
                'context' => $eventPayload['context'],
                'occurred_at' => $this->parseDateTime($eventPayload['occurred_at']),
            ]);
        }
    }

    private function syncLoan(array $payload): void
    {
        $loan = Loan::updateOrCreate(
            ['number' => $payload['number']],
            [
                'user_id' => $this->users[$payload['requester']]->id,
                'unit_id' => $this->units[$payload['unit']]->id,
                'requested_at' => $this->parseDateTime($payload['requested_at']),
                'authorization_status_id' => $this->statusIds[$payload['authorization_status']] ?? null,
                'workflow_status_id' => $this->statusIds[$payload['workflow_status']] ?? null,
                'search_status_id' => !empty($payload['search_status']) ? ($this->statusIds[$payload['search_status']] ?? null) : null,
                'authorized_by_user_id' => isset($payload['authorized_by']) ? $this->users[$payload['authorized_by']]->id : null,
                'authorized_at' => !empty($payload['authorized_at']) ? $this->parseDateTime($payload['authorized_at']) : null,
                'ugda_authorized_by_user_id' => isset($payload['ugda_authorized_by']) ? $this->users[$payload['ugda_authorized_by']]->id : null,
                'ugda_authorized_at' => !empty($payload['ugda_authorized_at']) ? $this->parseDateTime($payload['ugda_authorized_at']) : null,
                'search_started_by_user_id' => isset($payload['search_started_by']) ? $this->users[$payload['search_started_by']]->id : null,
                'search_started_at' => !empty($payload['search_started_at']) ? $this->parseDateTime($payload['search_started_at']) : null,
                'search_completed_by_user_id' => isset($payload['search_completed_by']) ? $this->users[$payload['search_completed_by']]->id : null,
                'search_completed_at' => !empty($payload['search_completed_at']) ? $this->parseDateTime($payload['search_completed_at']) : null,
                'search_comments' => $payload['search_comments'] ?? null,
                'view_mode' => $payload['view_mode'],
                'description' => $payload['description'] ?? null,
            ]
        );

        $loan->dispatches()->delete();
        $loan->returns()->delete();
        $loan->events()->delete();
        $loan->documents()->delete();

        $documents = [];
        foreach ($payload['documents'] as $index => $documentPayload) {
            $documents[$documentPayload['title']] = $loan->documents()->create($documentPayload + ['sort_order' => $index + 1]);
        }

        foreach ($payload['events'] as $eventPayload) {
            $loan->events()->create([
                'status_catalog_id' => $eventPayload['status_code'] ? ($this->statusIds[$eventPayload['status_code']] ?? null) : null,
                'actor_user_id' => $eventPayload['actor'] ? $this->users[$eventPayload['actor']]->id : null,
                'actor_name_snapshot' => $eventPayload['actor'] ? $this->userDisplayName($this->users[$eventPayload['actor']]) : null,
                'event_type' => $eventPayload['event_type'],
                'title' => $eventPayload['title'],
                'description' => $eventPayload['description'],
                'context' => $eventPayload['context'],
                'occurred_at' => $this->parseDateTime($eventPayload['occurred_at']),
            ]);
        }

        if (!empty($payload['dispatch'])) {
            $dispatch = $loan->dispatches()->create([
                'loan_date' => $this->parseDate($payload['dispatch']['loan_date']),
                'due_date' => $this->parseDate($payload['dispatch']['due_date']),
                'received_by_name' => $payload['dispatch']['received_by_name'],
                'delivered_by_user_id' => $this->users[$payload['dispatch']['delivered_by']]->id,
                'observations' => $payload['dispatch']['observations'] ?? null,
            ]);

            foreach ($payload['dispatch']['document_titles'] as $title) {
                if (isset($documents[$title])) {
                    $dispatch->items()->create(['loan_document_id' => $documents[$title]->id]);
                }
            }
        }

        if (!empty($payload['return'])) {
            $return = $loan->returns()->create([
                'return_date' => $this->parseDate($payload['return']['return_date']),
                'received_by_user_id' => $this->users[$payload['return']['received_by']]->id,
                'condition_label' => $payload['return']['condition_label'],
                'observations' => $payload['return']['observations'] ?? null,
            ]);

            foreach ($payload['return']['document_titles'] as $title) {
                if (isset($documents[$title])) {
                    $return->items()->create(['loan_document_id' => $documents[$title]->id]);
                }
            }
        }
    }

    private function createUser(array $data): User
    {
        $user = User::updateOrCreate(
            ['email' => $data['email']],
            ['password' => 'password', 'is_active' => true]
        );

        DB::table('person')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'first_name' => $data['first_name'],
                'second_name' => $data['second_name'],
                'first_last_name' => $data['first_last_name'],
                'second_last_name' => $data['second_last_name'],
                'carnet' => strtoupper(Str::random(8)),
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('user_unit')->updateOrInsert(
            ['user_id' => $user->id, 'unit_id' => $this->units[$data['unit']]->id],
            ['is_active' => true, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null]
        );

        return $user->fresh('person');
    }

    private function transferBoxPayload(string $number, string $title, string $period, ?string $locationCode, ?string $assignedBy, ?string $assignedAt, string $seriesName, int $startYear, int $endYear, array $documents): array
    {
        return [
            'number' => $number,
            'title' => $title,
            'period_label' => $period,
            'location_code' => $locationCode,
            'assigned_by' => $assignedBy,
            'assigned_at' => $assignedAt,
            'series_name' => $seriesName,
            'start_year' => $startYear,
            'end_year' => $endYear,
            'content_description' => $title,
            'documents' => $documents,
        ];
    }

    private function transferDocPayload(string $code, string $name, ?string $series, ?string $support, ?string $year, ?string $pages, ?string $digitalFile): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'series_label' => $series,
            'support_type' => $support,
            'year_label' => $year,
            'pages_label' => $pages,
            'digital_file_name' => $digitalFile,
            'digital_file_path' => $digitalFile ? 'mock/' . $digitalFile : null,
        ];
    }

    private function loanDocPayload(string $kind, string $groupTitle, string $title, ?string $series, ?string $box, ?string $year, ?string $unitName, ?string $documentType, string $tone, ?string $quantityLabel, ?string $note, bool $foundInSearch = false, bool $selectedForLoan = false, bool $returned = false): array
    {
        return [
            'document_kind' => $kind,
            'group_title' => $groupTitle,
            'title' => $title,
            'series_label' => $series,
            'box_code' => $box,
            'year_label' => $year,
            'unit_name_snapshot' => $unitName,
            'document_type_label' => $documentType,
            'document_type_tone' => $tone,
            'quantity_label' => $quantityLabel,
            'note' => $note,
            'found_in_search' => $foundInSearch,
            'selected_for_loan' => $selectedForLoan,
            'returned' => $returned,
        ];
    }

    private function eventPayload(string $type, string $title, string $description, string $occurredAt, ?string $actor = null, ?string $statusCode = null, array $context = []): array
    {
        return [
            'event_type' => $type,
            'title' => $title,
            'description' => $description,
            'occurred_at' => $occurredAt,
            'actor' => $actor,
            'status_code' => $statusCode,
            'context' => $context,
        ];
    }

    private function loanEventPayload(string $type, string $title, string $description, string $occurredAt, ?string $actor = null, ?string $statusCode = null, array $context = []): array
    {
        return $this->eventPayload($type, $title, $description, $occurredAt, $actor, $statusCode, $context);
    }

    private function parseDateTime(string $value): Carbon
    {
        return Carbon::createFromFormat('d/m/Y H:i', $value);
    }

    private function parseDate(string $value): Carbon
    {
        return Carbon::createFromFormat('d/m/Y', $value);
    }

    private function userDisplayName(User $user): string
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
