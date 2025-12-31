<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeguimientoReprogramado extends Model
{
    /**
     * Nombre explícito de la tabla
     */
    protected $table = 'seguimiento_reprogramaciones';

    /**
     * Campos asignables masivamente
     */
    protected $fillable = [
        'seguimiento_id',
        'fecha_hora_comprometida',
        'motivo',
    ];

    /**
     * Relación: una reprogramación pertenece a un seguimiento
     */
    public function seguimiento(): BelongsTo
    {
        return $this->belongsTo(
            Seguimiento::class,
            'seguimiento_id'
        );
    }
}
