<?php

namespace App\Http\Controllers;

use App\Mail\UserCredentialsMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Profile;
use App\Models\User;
use App\Services\UserActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly UserActivityLogger $activityLogger)
    {
    }

    public function index()
    {
        $users = User::with([
            'units' => function ($query) {
                $query->whereNull('user_unit.deleted_at');
            },
            'person',
            'profiles' => function ($query) {
                $query->whereNull('user_profile.deleted_at')
                    ->where('profiles.is_active', true);
            },
        ])->orderBy('id', 'desc')->get();

        return response()->json(
            $users->map(fn (User $user) => $this->mapUserForResponse($user))
        );
    }

    public function store(Request $request)
    {
        $this->normalizeCarnetInput($request);
        $this->normalizeUserUnitInput($request);

        $request->validate([
            'first_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'first_last_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_last_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'carnet' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/', Rule::unique('person', 'carnet')],
            'email' => ['required', 'email', 'unique:users,email'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['required', 'integer', 'distinct', 'exists:units,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'role' => $this->roleValidationRules(),
        ], [
            'first_name.regex' => 'El nombre solo puede contener letras.',
            'carnet.regex' => 'El carnet solo puede contener letras mayúsculas y números.',
            'carnet.unique' => 'El carnet ya esta registrado.',
            'unit_ids.required' => 'Seleccione al menos una unidad para el usuario.',
            'unit_ids.min' => 'Seleccione al menos una unidad para el usuario.',
            'unit_ids.*.exists' => 'Seleccione una unidad valida.',
            'role.required' => 'Seleccione un rol para el usuario.',
            'role.exists' => 'Seleccione un rol válido.',
            'max' => 'El campo no debe exceder los :max caracteres.',
        ], [
            'role.required' => 'Seleccione un rol para el usuario.',
            'role.exists' => 'Seleccione un rol válido.',
        ]);

        $randomPassword = Str::random(8);
        $carnetUpper = $request->carnet ? strtoupper($request->carnet) : null;
        $profileName = trim((string) $request->role);
        $unitIds = $this->normalizeUnitIds($request);

        $user = DB::transaction(function () use ($request, $randomPassword, $carnetUpper, $profileName, $unitIds) {
            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($randomPassword),
                'is_active' => true,
                'two_factor_method' => 'email',
                'must_change_password' => true,
                'temporary_password_expires_at' => Carbon::now()->addDays(7),
            ]);

            $user->person()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => $request->first_name,
                    'second_name' => $request->second_name,
                    'first_last_name' => $request->first_last_name,
                    'second_last_name' => $request->second_last_name,
                    'carnet' => $carnetUpper,
                ]
            );

            $this->assignProfileToUser($user, $profileName);
            $this->assignUnitsToUser($user, $unitIds);

            return $user->load(['units', 'person', 'profiles']);
        });

        try {
            $data = [
                'name' => trim(($request->first_name ?? '') . ' ' . ($request->first_last_name ?? '')),
                'email' => $user->email,
                'password' => $randomPassword,
            ];
            Mail::to($user->email)->send(new UserCredentialsMail($data));
        } catch (\Throwable $e) {
            //
        }

        return response()->json([
            'message' => 'Usuario creado y credenciales enviadas',
            'user' => $this->mapUserForResponse($user),
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $this->normalizeCarnetInput($request);
        $this->normalizeUserUnitInput($request);

        $request->validate([
            'first_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'first_last_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_last_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'carnet' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/', Rule::unique('person', 'carnet')->ignore($user->id, 'user_id')],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['required', 'integer', 'distinct', 'exists:units,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'role' => $this->roleValidationRules(),
            'is_active' => ['required', 'boolean'],
        ], [
            'carnet.unique' => 'El carnet ya esta registrado.',
            'unit_ids.required' => 'Seleccione al menos una unidad para el usuario.',
            'unit_ids.min' => 'Seleccione al menos una unidad para el usuario.',
            'unit_ids.*.exists' => 'Seleccione una unidad valida.',
        ]);

        $carnetUpper = $request->carnet ? strtoupper($request->carnet) : null;
        $profileName = trim((string) $request->role);
        $unitIds = $this->normalizeUnitIds($request);

        DB::transaction(function () use ($request, $user, $carnetUpper, $profileName, $unitIds) {
            $user->update([
                'email' => $request->email,
                'is_active' => $request->is_active,
            ]);

            $user->person()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => $request->first_name,
                    'second_name' => $request->second_name,
                    'first_last_name' => $request->first_last_name,
                    'second_last_name' => $request->second_last_name,
                    'carnet' => $carnetUpper,
                ]
            );

            $this->assignProfileToUser($user, $profileName);
            $this->assignUnitsToUser($user, $unitIds);
        });

        return response()->json(['message' => 'Usuario actualizado correctamente']);
    }

    public function resetPassword(Request $request, $id)
    {
        $request->validate([
            'admin_password' => ['required'],
        ], [
            'admin_password.required' => 'Ingrese su contrasena para confirmar el restablecimiento.',
        ]);

        $user = User::with('person')->findOrFail($id);

        if (!$user->is_active) {
            return response()->json(['message' => 'El usuario no se encuentra activo'], 422);
        }

        if (!Hash::check($request->admin_password, $request->user()->password)) {
            return response()->json(['message' => 'La contrasena del administrador no es correcta.'], 422);
        }

        $temporaryPassword = Str::password(12, true, true, false, false);
        $expiresAt = Carbon::now()->addDays(2);

        $user->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'temporary_password_expires_at' => $expiresAt,
            'password_changed_at' => null,
            'two_factor_method' => 'email',
        ]);

        $data = [
            'name' => $this->userDisplayName($user),
            'email' => $user->email,
            'password' => $temporaryPassword,
            'expires_at' => $expiresAt->format('d/m/Y H:i'),
        ];

        try {
            Mail::to($user->email)->send(new TemporaryPasswordMail($data));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'La contrasena fue restablecida, pero no se pudo enviar el correo: ' . $e->getMessage(),
            ], 500);
        }

        $this->activityLogger->log(
            $user,
            $request->user(),
            'temporary_password_reset',
            'Contraseña temporal generada',
            'Un administrador restablecio la contrasena y envio una temporal por correo.',
            $request
        );

        return response()->json(['message' => 'Contraseña temporal enviada al correo del usuario.']);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        DB::transaction(function () use ($user) {
            DB::table('person')->where('user_id', $user->id)->delete();
            DB::table('user_profile')->where('user_id', $user->id)->delete();
            DB::table('user_unit')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return response()->json(['message' => 'Usuario eliminado correctamente']);
    }

    private function assignProfileToUser(User $user, string $profileName): void
    {
        $profile = Profile::withTrashed()->firstOrCreate(
            ['name' => $profileName],
            [
                'description' => null,
                'is_active' => true,
            ]
        );

        if ($profile->trashed()) {
            $profile->restore();
        }

        if (!$profile->is_active) {
            $profile->is_active = true;
            $profile->save();
        }

        $now = now();

        DB::table('user_profile')
            ->where('user_id', $user->id)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);

        DB::table('user_profile')->updateOrInsert(
            [
                'user_id' => $user->id,
                'profile_id' => $profile->id,
            ],
            [
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
                'deleted_at' => null,
            ]
        );
    }

    private function roleValidationRules(): array
    {
        return [
            'required',
            'string',
            'max:100',
            Rule::exists('profiles', 'name')
                ->where('is_active', true)
                ->whereNull('deleted_at'),
        ];
    }

    private function userDisplayName(User $user): string
    {
        $person = $user->person;

        return trim(($person?->first_name ?? '') . ' ' . ($person?->first_last_name ?? '')) ?: $user->email;
    }

    private function mapUserForResponse(User $user): array
    {
        $person = $user->person;
        $activeProfile = $user->profiles
            ->first(fn ($profile) => (bool) ($profile->pivot->is_active ?? false))
            ?? $user->profiles->first();
        $activeUnit = $user->units
            ->first(fn ($unit) => (bool) ($unit->pivot->is_active ?? false))
            ?? $user->units->first();

        return [
            'id' => $user->id,
            'first_name' => $person?->first_name,
            'second_name' => $person?->second_name,
            'first_last_name' => $person?->first_last_name,
            'second_last_name' => $person?->second_last_name,
            'carnet' => $person?->carnet,
            'email' => $user->email,
            'unit_id' => $activeUnit?->id,
            'unit_ids' => $user->units->pluck('id')->values()->all(),
            'role' => $activeProfile?->name,
            'is_active' => (bool) $user->is_active,
            'unit' => $activeUnit,
            'units' => $user->units->values(),
        ];
    }

    private function normalizeUnitIds(Request $request): array
    {
        $unitIds = $request->input('unit_ids', []);

        if ((!is_array($unitIds) || $unitIds === []) && $request->filled('unit_id')) {
            $unitIds = [$request->integer('unit_id')];
        }

        return collect($unitIds)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeUserUnitInput(Request $request): void
    {
        $request->merge([
            'unit_ids' => $this->normalizeUnitIds($request),
        ]);
    }

    private function normalizeCarnetInput(Request $request): void
    {
        if (!$request->has('carnet')) {
            return;
        }

        $carnet = trim((string) $request->input('carnet'));

        $request->merge([
            'carnet' => $carnet === '' ? null : strtoupper($carnet),
        ]);
    }

    private function assignUnitsToUser(User $user, array $unitIds): void
    {
        $now = now();

        DB::table('user_unit')
            ->where('user_id', $user->id)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
                'deleted_at' => $now,
            ]);

        foreach ($unitIds as $index => $unitId) {
            DB::table('user_unit')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'unit_id' => $unitId,
                ],
                [
                    'is_active' => $index === 0,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
