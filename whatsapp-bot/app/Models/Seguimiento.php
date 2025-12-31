<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Seguimiento extends Model
{
    use HasFactory;

    protected $table = 'seguimientos_proveedor';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /* ============================================================
       ESTADOS (estado_seguimiento) — DEBEN CALZAR CON CHECK DB
    ============================================================ */
    public const ESTADO_PENDIENTE_FLUJO       = 'PENDIENTE_FLUJO';
    public const ESTADO_MENSAJE_ENVIADO       = 'MENSAJE_ENVIADO';
    public const ESTADO_ESPERANDO_RESPUESTA   = 'ESPERANDO_RESPUESTA';

    public const ESTADO_REAGENDADO            = 'REAGENDADO';

    public const ESTADO_CERRADO_CONFIRMADO    = 'CERRADO_CONFIRMADO';
    public const ESTADO_CERRADO_REPROGRAMADO  = 'CERRADO_REPROGRAMADO';
    public const ESTADO_CERRADO_NO_CONFIRMADO = 'CERRADO_NO_CONFIRMADO';

    /* ============================================================
       SUBESTADOS (subestado_conversacion) — DEBEN CALZAR CON CHECK DB
    ============================================================ */
    public const SUB_PREGUNTA_LLEGADA                   = 'PREGUNTA_LLEGADA';
    public const SUB_ESPERANDO_FECHA_HORA_LLEGADA       = 'ESPERANDO_FECHA_HORA_LLEGADA';       // técnico NO llegó → pide reagendar
    public const SUB_ESPERANDO_FECHA_REAGENDADA         = 'ESPERANDO_FECHA_REAGENDADA';         // ingreso fecha reagendada
    public const SUB_PREGUNTA_HORA_COMPROMETIDA         = 'PREGUNTA_HORA_COMPROMETIDA';         // camino crítico
    public const SUB_ESPERANDO_FECHA_HORA_LLEGADA_REAL  = 'ESPERANDO_FECHA_HORA_LLEGADA_REAL';  // técnico SI llegó → pide hora/fecha exacta

    /* ============================================================
       MASS ASSIGNMENT
    ============================================================ */
    protected $fillable = [
        'id_atencion',
        'nro_tririga',
        'id_local',
        'criticidad',
        'telefono_proveedor',

        'estado_seguimiento',
        'subestado_conversacion',

        'mensaje_enviado_at',
        'espera_hasta_at',
        'proximo_seguimiento_at',
        'ejecutar_desde_at',

        'respuesta',
        'hora_llegada',
        'nueva_fecha_hora',

        'camino',
    ];

    /* ============================================================
       CASTS
    ============================================================ */
    protected $casts = [
        'mensaje_enviado_at'      => 'datetime',
        'espera_hasta_at'         => 'datetime',
        'proximo_seguimiento_at'  => 'datetime',
        'ejecutar_desde_at'       => 'datetime',
        'nueva_fecha_hora'        => 'datetime',

        // TIME en PostgreSQL suele venir como string "HH:MM:SS"
        'hora_llegada'            => 'string',
    ];

    /* ============================================================
       SCOPES
    ============================================================ */

    /** Seguimientos “en curso” (útil para buscar el que debe responder) */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->whereIn('estado_seguimiento', [
            self::ESTADO_MENSAJE_ENVIADO,
            self::ESTADO_ESPERANDO_RESPUESTA,
        ]);
    }

    /** Listos para ejecutar flujo (el cron/command los toma) */
    public function scopeListosParaEjecutar(Builder $query): Builder
    {
        return $query
            ->where('estado_seguimiento', self::ESTADO_PENDIENTE_FLUJO)
            ->where(function ($q) {
                $q->whereNull('ejecutar_desde_at')
                    ->orWhere('ejecutar_desde_at', '<=', now());
            });
    }

    /* ============================================================
       RELACIONES (si las usas)
       - Si NO tienes esta tabla/modelo, comenta esta sección
    ============================================================ */
    // public function reprogramaciones(): HasMany
    // {
    //     return $this->hasMany(SeguimientoReprogramado::class, 'seguimiento_id');
    // }

    /* ============================================================
       MÉTODOS DE NEGOCIO (seguros con checks)
    ============================================================ */

    public function marcarEsperandoRespuesta(string $subestado, int $minutos = 10): void
    {
        // Ojo: $subestado debe ser uno de los permitidos por el CHECK.
        $this->update([
            'estado_seguimiento'     => self::ESTADO_ESPERANDO_RESPUESTA,
            'subestado_conversacion' => $subestado,
            'mensaje_enviado_at'     => now(),
            'espera_hasta_at'        => now()->addMinutes($minutos),
        ]);
    }

    public function limpiarVentanaYSubestado(): void
    {
        $this->update([
            'subestado_conversacion' => null,
            'espera_hasta_at'        => null,
        ]);
    }

    public function registrarRespuesta(string $respuesta): void
    {
        // DB permite SI/NO. Si te llega "si" o "no", normalizamos:
        $this->update([
            'respuesta' => strtoupper(trim($respuesta)),
        ]);
    }

    /**
     * Cuando el técnico SI llegó y el proveedor entrega “hora/fecha exacta”.
     * - Si guardas solo H:i, usa registrarHoraLlegada().
     * - Si guardas d-m-Y H:i, puedes guardar la hora en hora_llegada (TIME)
     *   y si quieres además dejar trazabilidad, puedes usar nueva_fecha_hora.
     */
    public function registrarLlegada(Carbon $fechaHora): void
    {
        $this->update([
            'hora_llegada'           => $fechaHora->format('H:i:s'),
            'nueva_fecha_hora'       => $fechaHora, // opcional como evidencia completa (timestamp)
            'estado_seguimiento'     => self::ESTADO_CERRADO_CONFIRMADO,
            'subestado_conversacion' => null,
            'espera_hasta_at'        => null,
        ]);
    }

    public function registrarHoraLlegada(string $horaHHMM): void
    {
        // acepta "HH:MM" y lo guardamos como "HH:MM:00"
        $horaHHMM = trim($horaHHMM);

        $this->update([
            'hora_llegada'           => $horaHHMM . ':00',
            'estado_seguimiento'     => self::ESTADO_CERRADO_CONFIRMADO,
            'subestado_conversacion' => null,
            'espera_hasta_at'        => null,
        ]);
    }

    /**
     * Reagendamiento: crea “nuevo seguimiento” afuera (controller/job),
     * y este registro queda cerrado como REAGENDADO o CERRADO_REPROGRAMADO según tu criterio.
     */
    public function marcarReagendado(Carbon $fechaHora): void
    {
        $this->update([
            'estado_seguimiento'     => self::ESTADO_REAGENDADO,
            'nueva_fecha_hora'       => $fechaHora,
            'ejecutar_desde_at'      => $fechaHora,
            'subestado_conversacion' => null,
            'espera_hasta_at'        => null,
        ]);
    }

    public function cerrarNoConfirmado(): void
    {
        $this->update([
            'estado_seguimiento'     => self::ESTADO_CERRADO_NO_CONFIRMADO,
            'subestado_conversacion' => null,
            'espera_hasta_at'        => null,
        ]);
    }

    public function cerrarReprogramado(): void
    {
        $this->update([
            'estado_seguimiento'     => self::ESTADO_CERRADO_REPROGRAMADO,
            'subestado_conversacion' => null,
            'espera_hasta_at'        => null,
        ]);
    }
}
