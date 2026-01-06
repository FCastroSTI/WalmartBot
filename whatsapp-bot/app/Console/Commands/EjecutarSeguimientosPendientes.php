<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Jobs\ProcesarSeguimientoProveedor;
use App\Models\Seguimiento;
use App\Models\SeguimientoReprogramado;
use App\Services\SeguimientoFlowService;

class EjecutarSeguimientosPendientes extends Command
{
    protected $signature = 'seguimiento:ejecutar-pendientes';
    protected $description = 'Ejecuta reagendamientos pendientes desde tabla seguimientos_reagendamientos';

    public function handle(SeguimientoFlowService $flow)
    {
        Log::info('🧪 COMMAND seguimiento:ejecutar-pendientes ENTRÓ');

        // 1) Traer IDs listos (en lote)
        $ids = SeguimientoReprogramado::query()
            ->where('estado', 'PENDIENTE')
            ->where('ejecutar_desde_at', '<=', now())
            ->orderBy('ejecutar_desde_at')
            ->limit(20)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return Command::SUCCESS;
        }

        foreach ($ids as $id) {

            // 2) Tomar el registro de forma atómica para evitar doble ejecución
            $claimed = SeguimientoReprogramado::where('id', $id)
                ->where('estado', 'PENDIENTE')
                ->update([
                    'estado' => 'PROCESANDO',
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                continue; // otro proceso lo tomó
            }

            $r = SeguimientoReprogramado::find($id);
            if (!$r) {
                continue;
            }

            // 3) Validación mínima (WhatsApp peta si envías parámetros vacíos)
            if (empty($r->telefono_proveedor)) {
                SeguimientoReprogramado::where('id', $id)->update([
                    'estado' => 'FALLIDO',
                    'intentos' => DB::raw('COALESCE(intentos,0) + 1'),
                    'ultimo_error' => 'telefono_proveedor vacío',
                    'updated_at' => now(),
                ]);
                continue;
            }

            try {
                // 4) Crear nuevo seguimiento (conversación limpia)
                //    OJO: estos campos deben existir en tu tabla seguimientos_proveedor y estar en $fillable.
                $nuevo = Seguimiento::create([
                    'id_atencion'        => $r->id_atencion,
                    'nro_tririga'        => $r->nro_tririga,
                    'id_local'           => $r->id_local,
                    'criticidad'         => $r->criticidad,

                    'nombre_proveedor'   => $r->nombre_proveedor,
                    'rut_proveedor'      => $r->rut_proveedor,
                    'telefono_proveedor' => $r->telefono_proveedor,

                    'camino'             => 1,
                    'estado_seguimiento' => Seguimiento::ESTADO_PENDIENTE_FLUJO,

                    // lo ejecutamos ahora, así que no dejamos ejecutar_desde_at
                    'ejecutar_desde_at'  => null,

                    // si existe en tu DB/modelo:
                    'payload_ticket'     => $r->payload_ticket,
                ]);

                Log::info('▶ Reagendamiento ejecutado: nuevo seguimiento creado', [
                    'reagendamiento_id' => $r->id,
                    'nuevo_seguimiento_id' => $nuevo->id,
                ]);

                // 5) Iniciar flujo (por ahora lo hacemos directo acá)
                //    Luego lo conectamos con tu Job ProcesarSeguimientoProveedor.
                ProcesarSeguimientoProveedor::dispatch(
                    $nuevo->id,
                    $nuevo->nombre_proveedor ?? '-',
                    $nuevo->rut_proveedor ?? '-'
                );

                // 6) Marcar reagendamiento como ejecutado
                SeguimientoReprogramado::where('id', $r->id)->update([
                    'estado' => 'EJECUTADO',
                    'ejecutado_at' => now(),
                    'ultimo_error' => null,
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {

                Log::error('❌ Error ejecutando reagendamiento', [
                    'reagendamiento_id' => $r->id,
                    'error' => $e->getMessage(),
                ]);

                // Reintento simple: lo marcamos FALLIDO y lo reagendamos 1 minuto después
                SeguimientoReprogramado::where('id', $r->id)->update([
                    'estado' => 'FALLIDO',
                    'intentos' => DB::raw('COALESCE(intentos,0) + 1'),
                    'ultimo_error' => $e->getMessage(),
                    'ejecutar_desde_at' => now()->addMinute(),
                    'updated_at' => now(),
                ]);
            }
        }

        return Command::SUCCESS;
    }
}
