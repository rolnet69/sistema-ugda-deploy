<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index()
    {
        return response()->json(Unit::with('parents')->orderBy('id', 'desc')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            // CAMBIO: max:100
            'name' => 'required|string|max:100|unique:units,name', 
            'is_active' => 'required|boolean',
            'parents' => 'nullable|array'
        ]);

        $unit = Unit::create([
            'name' => $request->name,
            'is_active' => $request->is_active
        ]);

        if ($request->has('parents')) {
            $unit->parents()->sync($request->parents);
        }

        return response()->json(['message' => 'Unidad creada con éxito']);
    }

    public function update(Request $request, $id)
    {
        $unit = Unit::findOrFail($id);
        
        $request->validate([
            'name' => [
                'required', 
                'string', 
                // CAMBIO: max:100
                'max:100', 
                Rule::unique('units', 'name')->ignore($unit->id)
            ],
            'is_active' => 'required|boolean',
            'parents' => 'nullable|array'
        ]);

        $unit->update([
            'name' => $request->name,
            'is_active' => $request->is_active
        ]);

        if ($request->has('parents')) {
            $unit->parents()->sync($request->parents);
        }

        return response()->json(['message' => 'Unidad actualizada']);
    }

    public function destroy($id)
    {
        $unit = Unit::findOrFail($id);
        $unit->delete();
        return response()->json(['message' => 'Unidad eliminada']);
    }
}