<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversacionUsuario extends Model
{
    protected $table = 'conversaciones_usuarios';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'phone',
        'estado',
        'datos',
        'intentos',
        'formulario',
        'last_interaction'
    ];

    // Indicar que "datos" es un JSON que Laravel convierte automáticamente en array
    protected $casts = [
        'datos' => 'array',
        'formulario' => 'array'
    ];

    // Laravel gestionará created_at y updated_at automáticamente
    public $timestamps = true;
}
