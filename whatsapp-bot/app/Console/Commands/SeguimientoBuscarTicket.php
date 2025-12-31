<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Jobs\ProcesarSeguimientoProveedor;
use App\Events\SeguimientoIniciado;
use Carbon\Carbon;
use App\Models\Seguimiento;

class SeguimientoBuscarTicket extends Command
{
    protected $signature = 'seguimiento:buscar-tickets';
    protected $description = 'Analiza tickets del CRM y crea seguimientos con ruta de flujo';

    public function handle()
    {
        Log::info('🚀 COMMAND seguimiento:buscar-tickets EJECUTADO');
        $this->info('🔍 Analizando tickets para seguimiento...');

        // 1️⃣ Obtener tickets
        if (config('app.mock_crm')) {

            $this->info('🧪 Usando JSON mock');

            $json = file_get_contents(
                storage_path('app/mock/tickets_listar_dia.json')
            );

            $data = json_decode($json, true);
            $tickets = $data['result']['ticket'] ?? [];

            $this->line('📦 Tickets encontrados: ' . count($tickets));
        } else {
            $this->warn('⚠️ Modo CRM real (no debería usarse ahora)');
            return;
        }

        if (empty($tickets)) {
            $this->warn('ℹ️ No hay tickets para procesar');
            return;
        }

        foreach ($tickets as $rawTicket) {

            $this->line('➡️ Procesando ticket raw');

            // 🔁 Normalizar ticket
            $ticket = $this->normalizarTicket($rawTicket);

            $this->line('🧾 Ticket normalizado: ' . json_encode($ticket));

            if (!$ticket['id_ATENCION'] || !$ticket['fecha']) {
                $this->warn('⛔ Descartado: falta id_ATENCION o fecha');
                continue;
            }

            // 2️⃣ Evitar duplicados
            if (Seguimiento::where('id_atencion', $ticket['id_ATENCION'])->exists()) {
                $this->warn("⛔ Descartado: ticket {$ticket['id_ATENCION']} ya tiene seguimiento");
                continue;
            }

            // 3️⃣ Calcular minutos y criticidad
            try {
                $fechaCreacion = Carbon::parse(
                    $ticket['fecha'],
                    'America/Santiago'
                );
            } catch (\Throwable $e) {
                $this->warn("⛔ Descartado: fecha inválida ({$ticket['fecha']})");
                continue;
            }

            $ahora = Carbon::now('America/Santiago');
            $min = $fechaCreacion->diffInMinutes($ahora);
            $crit = strtoupper(trim($ticket['criticidad'] ?? 'NORMAL'));

            $this->line("⏱️ Ticket {$ticket['id_ATENCION']} | Crit={$crit} | Min={$min}");

            // 4️⃣ Determinar camino
            $camino = null;

            if ($crit === 'CRITICO' && $min >= 60) {
                $camino = 2;
            } elseif ($crit === 'EXCEPCIONAL' && $min >= 120) {
                $camino = 3;
            } elseif ($crit !== 'CRITICO' && $crit !== 'EXCEPCIONAL' && $min >= 120) {
                $camino = 1;
            } else {
                $this->warn("⛔ Descartado: no cumple condiciones de tiempo");
                continue;
            }

            $this->info("🛣️ Camino asignado: {$camino}");

            // 5️⃣ Normalizar teléfono
            $telefonoProveedor = $this->normalizarTelefono(
                $ticket['celular_1_PROVEEDOR']
                    ?? $ticket['celular_2_PROVEEDOR']
                    ?? null
            );

            if (!$telefonoProveedor) {
                $this->warn('⛔ Descartado: teléfono inválido');
                continue;
            }

            // 6️⃣ Datos de proveedor (OPCIÓN 1: NO se guardan en la tabla)
            $nombreProveedor = $ticket['nombre_proveedor'];
            $rutProveedor    = $ticket['rut_proveedor'];

            if (!$nombreProveedor || !$rutProveedor) {
                Log::error('❌ Ticket sin datos completos de proveedor', [
                    'id_atencion' => $ticket['id_ATENCION'],
                    'telefono'    => $telefonoProveedor,
                ]);
                continue;
            }

            $subestadoInicial = match ($camino) {
                1 => 'PREGUNTA_LLEGADA',
                2 => 'PREGUNTA_HORA_COMPROMETIDA',
                3 => 'PREGUNTA_LLEGADA',
            };

            // 7️⃣ Crear seguimiento (solo datos de control)
            $seguimiento = Seguimiento::create([
                'id_atencion'        => $ticket['id_ATENCION'],
                'nro_tririga'        => $ticket['nro_TRIRIGA'],
                'id_local'           => $ticket['id_LOCAL'],
                'criticidad'         => $crit,
                'telefono_proveedor' => $telefonoProveedor,
                'estado_seguimiento' => 'PENDIENTE_FLUJO',
                "camino" => $camino,
                'subestado_conversacion' => $subestadoInicial,
                'nombre_proveedor' => $nombreProveedor,
                'rut_proveedor'    => $rutProveedor,
            ]);

            // 🚀 DISPARAR EVENTO (nombre y rut VIAJAN EN EL EVENTO)
            ProcesarSeguimientoProveedor::dispatch(
                $seguimiento->id,
                $nombreProveedor,
                $rutProveedor
            );



            $this->info("🚀 Evento SeguimientoIniciado disparado para ticket {$ticket['id_ATENCION']}");
        }

        $this->info('🏁 Análisis finalizado');
    }

    // =========================
    // Métodos auxiliares
    // =========================

    private function normalizarTicket(array $ticket): array
    {
        return [
            'nombre_proveedor' => $ticket['nombrE_PROVEEDOR'] ?? null,
            'rut_proveedor'    => $ticket['ruT_PROVEEDOR'] ?? null,
            'id_ATENCION'      => $ticket['iD_ATENCION']
                ?? $ticket['ID_ATENCION']
                ?? $ticket['id_ATENCION']
                ?? null,
            'nro_TRIRIGA'      => $ticket['nrO_TRIRIGA'] ?? null,
            'id_LOCAL'         => $ticket['iD_LOCAL'] ?? null,
            'fecha'            => $ticket['fecha'] ?? null,
            'criticidad'       => $ticket['criticidad'] ?? 'NORMAL',
            'celular_1_PROVEEDOR' => $ticket['celulaR_1_PROVEEDOR'] ?? null,
            'celular_2_PROVEEDOR' => $ticket['celulaR_2_PROVEEDOR'] ?? null,
        ];
    }

    private function normalizarTelefono(?string $telefono): ?string
    {
        if (!$telefono) return null;

        $telefono = preg_replace('/[^0-9]/', '', $telefono);

        if (strlen($telefono) === 9) {
            $telefono = '56' . $telefono;
        }

        if (strlen($telefono) < 11) {
            return null;
        }

        return '+' . $telefono;
    }
}
