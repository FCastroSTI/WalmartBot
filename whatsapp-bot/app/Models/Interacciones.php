<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Interacciones extends Model
{
    protected $table = 'historial_interacciones';

    public $timestamps = false;

    protected $fillable = [
        'phone',
        'estado',
        'mensaje_usuario',
        'mensaje_bot'
    ];
}
