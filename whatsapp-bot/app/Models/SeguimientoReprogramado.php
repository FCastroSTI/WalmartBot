<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeguimientoReprogramado extends Model
{
    use HasFactory;

    protected $table = 'seguimientos_reagendamientos';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /* ============================================================
       ESTADOS (estado)
    ============================================================ */
    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_EJECUTADO = 'EJECUTADO';
    public const ESTADO_CANCELADO = 'CANCELADO';
    public const ESTADO_FALLIDO   = 'FALLIDO';

    /* ============================================================
       MASS ASSIGNMENT
    ============================================================ */
    protected $fillable = [
        'seguimiento_origen_id',

        'id_atencion',
        'nro_tririga',
        'id_local',
        'criticidad',

        'nombre_proveedor',
        'rut_proveedor',
        'telefono_proveedor',

        'camino',

        'ejecutar_desde_at',

        'estado',
        'motivo',
        'payload_ticket',

        'intentos',
        'ultimo_error',
        'ejecutado_at',
    ];

    /* ============================================================
       CASTS
       - En Postgres JSONB -> array
       - TIMESTAMPTZ -> datetime (Carbon)
    ============================================================ */
    protected $casts = [
        'payload_ticket'   => 'array',
        'ejecutar_desde_at' => 'datetime',
        'ejecutado_at'     => 'datetime',
    ];

    /* ============================================================
       RELACIONES
    ============================================================ */
    public function seguimientoOrigen(): BelongsTo
    {
        // Seguimiento usa la tabla seguimientos_proveedor
        return $this->belongsTo(Seguimiento::class, 'seguimiento_origen_id', 'id');
    }

    /* ============================================================
       SCOPES
    ============================================================ */

    /** Reagendamientos listos para ejecutarse */
    public function scopeListos(Builder $query): Builder
    {
        return $query
            ->where('estado', self::ESTADO_PENDIENTE)
            ->where('ejecutar_desde_at', '<=', now());
    }

    /** Reagendamientos pendientes (sin filtrar por fecha) */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    /* ============================================================
       MÉTODOS DE NEGOCIO
    ============================================================ */

    public function marcarEjecutado(): void
    {
        $this->update([
            'estado'      => self::ESTADO_EJECUTADO,
            'ejecutado_at' => now(),
            'ultimo_error' => null,
        ]);
    }

    public function marcarFallido(string $error): void
    {
        $this->update([
            'estado'       => self::ESTADO_FALLIDO,
            'intentos'     => ($this->intentos ?? 0) + 1,
            'ultimo_error' => $error,
        ]);
    }

    public function cancelar(string $motivo = null): void
    {
        $this->update([
            'estado' => self::ESTADO_CANCELADO,
            'motivo' => $motivo ?? $this->motivo,
        ]);
    }
}
