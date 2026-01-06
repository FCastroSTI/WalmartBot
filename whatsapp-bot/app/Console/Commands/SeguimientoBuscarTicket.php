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

    private function obtenerTokenCRM(): ?string
    {
        // 1️⃣ Token en cache
        if (Cache::has('walmart_token')) {
            return Cache::get('walmart_token');
        }

        // 2️⃣ LOGIN CORRECTO (GET + query params)
        $response = Http::get(
            'https://crm2new.upcom.cl/MantWalmartAPIQA/api/Login/Token',
            [
                'usuario'    => env('WALMART_API_USER'),
                'contrasena' => env('WALMART_API_PASSWORD'),
            ]
        );

        // 🔍 Log útil
        Log::info('LOGIN CRM RESPONSE', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if (!$response->successful()) {
            Log::error('Error login CRM', [
                'status' => $response->status(),
                'body'   => $response->body()
            ]);
            return null;
        }

        // 3️⃣ Token viene como string plano
        $token = trim($response->body(), "\" \n\r\t");

        // 4️⃣ Cachear token (50 min)
        Cache::put('walmart_token', $token, now()->addMinutes(50));

        return $token;
    }


    public function handle()
    {
        // ⚠️ AJUSTA la clave si el token viene con otro nombre
        $token = $this->obtenerTokenCRM();

        if (!$token) {
            $this->error('❌ No se pudo obtener token CRM');
            return;
        }

        $this->info('✅ Autenticación exitosa con API Walmart');


        Log::info('🚀 COMMAND seguimiento:buscar-tickets EJECUTADO');
        $this->info('🔍 Analizando tickets para seguimiento...');

        // 1️⃣ Obtener tickets desde API CRM
        $this->info('🌐 Consultando API CRM...');

        try {
            $response = Http::withToken($token)
                ->get(
                    'https://crm2new.upcom.cl/MantWalmartAPIQA/api/Ticket/listarDia'
                );
        } catch (\Throwable $e) {
            Log::error('❌ Error conectando con API CRM', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (!$response->successful()) {
            Log::error('❌ API CRM respondió error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return;
        }

        $data = $response->json();

        // ⚠️ Ajusta esta ruta si tu API responde distinto
        $tickets = $data['result']['ticket'] ?? [];

        $this->line('📦 Tickets encontrados desde API: ' . count($tickets));


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

            if ($crit === 'CRITICO' && $min >= 70) {
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
            $telefonoPrueba = '+56949098167';
            // 5️⃣ Normalizar teléfono
            /*$telefonoProveedor = $this->normalizarTelefono(
                $ticket['celular_1_PROVEEDOR']
                    ?? $ticket['celular_2_PROVEEDOR']
                    ?? null
            );*ESTO ES TEMPORAL PARA PRUEBA CUANDO SE TERMINE SE DESBLOQUEA ESTE BLOQUE
            

            if (!$telefonoProveedor) {
                $this->warn('⛔ Descartado: teléfono inválido');
                continue;
            }*/

            // 📞 TELÉFONO PROVEEDOR
            if (app()->environment('local', 'testing')) {
                // 🧪 Modo prueba: usar teléfono fijo
                $telefonoProveedor = $telefonoPrueba;
                $this->warn("🧪 Usando teléfono de prueba: {$telefonoProveedor}");
            } else {
                // 🔴 Producción: usar teléfono desde API
                $telefonoProveedor = $this->normalizarTelefono(
                    $ticket['celular_1_PROVEEDOR']
                        ?? $ticket['celular_2_PROVEEDOR']
                        ?? null
                );

                if (!$telefonoProveedor) {
                    $this->warn('⛔ Descartado: teléfono inválido');
                    continue;
                }
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

            // 🔒 Lock conversacional temporal (evita doble inicio en la misma corrida)
            $lockKey = "lock_conversacion_{$telefonoProveedor}";

            if (Cache::has($lockKey)) {
                $this->warn("🔒 Lock activo para {$telefonoProveedor}, se omite inicio");
                continue;
            }

            Cache::put($lockKey, true, now()->addMinutes(15));

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
        $nombreProveedor = $ticket['nombrE_PROVEEDOR'] ?? null;
        $rutProveedor    = $ticket['ruT_PROVEEDOR'] ?? null;
        $criticidad      = $ticket['criticidad'] ?? null;

        return [
            // 🏢 Nombre proveedor: solo forzar si viene vacío
            'nombre_proveedor' => (!is_string($nombreProveedor) || trim($nombreProveedor) === '')
                ? 'Proveedor Desconocido'
                : $nombreProveedor,

            // 🆔 RUT proveedor: solo forzar si viene vacío
            'rut_proveedor' => (!is_string($rutProveedor) || trim($rutProveedor) === '')
                ? '11.111.111-1'
                : $rutProveedor,

            // ⚠️ Criticidad: solo forzar si viene vacío
            'criticidad' => (!is_string($criticidad) || trim($criticidad) === '')
                ? 'NORMAL'
                : strtoupper(trim($criticidad)),
            'id_ATENCION'      => $ticket['iD_ATENCION']
                ?? $ticket['ID_ATENCION']
                ?? $ticket['id_ATENCION']
                ?? null,
            'nro_TRIRIGA'      => $ticket['nrO_TRIRIGA'] ?? null,
            'id_LOCAL'         => $ticket['iD_LOCAL'] ?? null,
            //'fecha'            => $ticket['fecha'] ?? null,
            'fecha'               => '2026-01-06T07:10:00',
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
