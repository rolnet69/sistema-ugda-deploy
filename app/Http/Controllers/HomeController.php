<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        // Esta es la ruta raíz (/). 
        // Por ahora puedes dejarla retornando 'welcome' o redirigir al login si prefieres.
        return view('welcome');
    }

    // Agrega esta función nueva
    public function showLogin()
    {
        return view('login'); // Retorna el archivo login.blade.php que acabamos de crear
    }
}