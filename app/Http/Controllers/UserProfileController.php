<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AuthenticatorCodeService;
use App\Services\UserActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserProfileController extends Controller
{
    public function __construct(
        private readonly AuthenticatorCodeService $authenticatorCodes,
        private readonly UserActivityLogger $activityLogger
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user()->load([
            'person',
            'units' => fn ($query) => $query->whereNull('user_unit.deleted_at'),
            'profiles' => fn ($query) => $query->whereNull('user_profile.deleted_at'),
            'activityLogs.actor',
            'systemNotifications' => fn ($query) => $query->latest()->limit(5),
        ]);

        $activeProfile = $user->activeProfile();
        $activeUnit = $user->units
            ->first(fn ($unit) => (bool) ($unit->pivot->is_active ?? false))
            ?? $user->units->first();

        return response()->json([
            'profile' => [
                'id' => $user->id,
                'full_name' => $this->fullName($user),
                'email' => $user->email,
                'carnet' => $user->person?->carnet,
                'role' => $activeProfile?->name,
                'unit' => $activeUnit?->name,
                'units' => $user->units->map(fn ($unit) => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'is_active' => (bool) ($unit->pivot->is_active ?? false),
                ])->values(),
                'is_active' => (bool) $user->is_active,
                'created_at' => $this->formatDate($user->created_at),
                'last_login_at' => $this->formatDate($user->last_login_at),
                'password_changed_at' => $this->formatDate($user->password_changed_at),
                'two_factor_method' => $user->two_factor_method ?: 'email',
                'two_factor_confirmed_at' => $this->formatDate($user->two_factor_confirmed_at),
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'activity' => $user->activityLogs->take(8)->map(fn ($log) => [
                'id' => $log->id,
                'title' => $log->title,
                'description' => $log->description,
                'event_type' => $log->event_type,
                'actor' => $log->actor ? $this->fullName($log->actor) : null,
                'created_at' => $this->formatDate($log->created_at),
            ])->values(),
            'requests' => $this->recentRequests($user),
            'notifications' => $user->systemNotifications->map(fn ($notification) => [
                'id' => $notification->id,
                'title' => $notification->title,
                'description' => $notification->description,
                'is_read' => (bool) $notification->read_at,
                'created_at' => $this->formatDate($notification->created_at),
                'action_url' => $notification->action_url,
            ])->values(),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'password' => [
                'required',
                'string',
                Password::min(10)->mixedCase()->numbers()->symbols(),
                'confirmed',
            ],
        ], [
            'current_password.required' => 'Ingrese su contraseña actual.',
            'password.required' => 'Ingrese la nueva contraseña.',
            'password.confirmed' => 'La confirmación no coincide.',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña actual no es correcta.'], 422);
        }

        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
            'temporary_password_expires_at' => null,
            'password_changed_at' => Carbon::now(),
        ]);

        $this->activityLogger->log(
            $user,
            $user,
            'password_changed',
            'Cambio de contraseña',
            'Actualizó su contraseña desde Mi perfil.',
            $request
        );

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    public function updateTwoFactor(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'method' => ['required', Rule::in(['email', 'disabled'])],
        ], [
            'current_password.required' => 'Ingrese su contraseña para confirmar el cambio.',
            'method.in' => 'Seleccione un método válido.',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña no es correcta.'], 422);
        }

        $user->forceFill([
            'two_factor_method' => $request->method,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $label = $request->method === 'email' ? 'Código por correo' : 'Sin segundo factor';

        $this->activityLogger->log(
            $user,
            $user,
            'two_factor_changed',
            'Método de seguridad actualizado',
            "Seleccionó: {$label}.",
            $request
        );

        return response()->json([
            'message' => 'Método de seguridad actualizado.',
            'two_factor_method' => $user->two_factor_method,
        ]);
    }

    public function prepareAuthenticator(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
        ], [
            'current_password.required' => 'Ingrese su contraseña para configurar el autenticador.',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña no es correcta.'], 422);
        }

        $secret = $this->authenticatorCodes->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $this->authenticatorCodes->otpauthUrl($user, $secret),
        ]);
    }

    public function confirmAuthenticator(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'code' => ['required', 'string', 'size:6'],
        ], [
            'current_password.required' => 'Ingrese su contraseña para confirmar el cambio.',
            'code.required' => 'Ingrese el código del autenticador.',
            'code.size' => 'El código debe tener 6 dígitos.',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña no es correcta.'], 422);
        }

        if (!$this->authenticatorCodes->verify($user->two_factor_secret, $request->code)) {
            return response()->json(['message' => 'El código del autenticador no es válido.'], 422);
        }

        $user->forceFill([
            'two_factor_method' => 'authenticator',
            'two_factor_confirmed_at' => Carbon::now(),
        ])->save();

        $this->activityLogger->log(
            $user,
            $user,
            'two_factor_changed',
            'Google Authenticator activado',
            'Configuró la verificación con aplicación autenticadora.',
            $request
        );

        return response()->json([
            'message' => 'Google Authenticator activado correctamente.',
            'two_factor_method' => 'authenticator',
        ]);
    }

    private function recentRequests(User $user)
    {
        $transfers = Transfer::with(['workflowStatus'])
            ->where('user_id', $user->id)
            ->latest('requested_at')
            ->limit(5)
            ->get()
            ->map(fn ($transfer) => [
                'type' => 'Transferencia',
                'number' => $transfer->code,
                'status' => $transfer->workflowStatus?->label ?? 'En proceso',
                'created_at' => $transfer->requested_at,
                'url' => "/solicitudes/transferencias/{$transfer->code}",
            ]);

        $loans = Loan::with(['workflowStatus'])
            ->where('user_id', $user->id)
            ->latest('requested_at')
            ->limit(5)
            ->get()
            ->map(fn ($loan) => [
                'type' => 'Prestamo',
                'number' => $loan->number,
                'status' => $loan->workflowStatus?->label ?? 'En proceso',
                'created_at' => $loan->requested_at,
                'url' => "/solicitudes/prestamos/{$loan->number}",
            ]);

        return $transfers
            ->concat($loans)
            ->sortByDesc('created_at')
            ->take(6)
            ->map(fn ($item) => [
                ...$item,
                'created_at' => $this->formatDate($item['created_at']),
            ])
            ->values();
    }

    private function fullName(User $user): string
    {
        $person = $user->relationLoaded('person') ? $user->person : $user->person()->first();

        return trim(collect([
            $person?->first_name,
            $person?->second_name,
            $person?->first_last_name,
            $person?->second_last_name,
        ])->filter()->join(' ')) ?: $user->email;
    }

    private function formatDate($date): ?string
    {
        if (!$date) {
            return null;
        }

        return Carbon::parse($date)->format('d/m/Y H:i');
    }
}
