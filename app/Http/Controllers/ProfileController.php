<?php

namespace App\Http\Controllers;

use App\Models\Profile;

class ProfileController extends Controller
{
    public function index()
    {
        $profiles = Profile::query()
            ->where('is_active', true)
            ->orderByRaw("
                CASE name
                    WHEN 'Administrador' THEN 1
                    WHEN 'Unidad Solicitante' THEN 2
                    WHEN 'Director/Jefe de Unidad' THEN 3
                    ELSE 99
                END
            ")
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'is_active']);

        return response()->json($profiles);
    }
}
