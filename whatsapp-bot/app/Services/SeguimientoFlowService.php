<?php

namespace App\Services;

use App\Models\Seguimiento;
use App\Jobs\CerrarSeguimientoXSilencio;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeguimientoFlowService
{
    /**
     * Camino 1:
     * - Seguimiento nuevo
     * - Seguimiento reagendado
     */
    public function iniciarFlujo(Seguimiento $s): void
    {
        $hasta = now()->addMinutes(10);

        $s->update([
            'estado_seguimiento' => Seguimiento::ESTADO_ESPERANDO_RESPUESTA,
            'subestado_conversacion' => 'PREGUNTA_LLEGADA',
            'espera_hasta_at' => $hasta,
        ]);
        /**
         * Fecha que se muestra en WhatsApp:
         * - si viene de reagendamiento → ejecutar_desde_at
         * - si no → ahora
         */
        $fechaReferencia = $s->nueva_fecha_hora
            ?? $s->ejecutar_desde_at
            ?? now();

        Log::info('📩 Enviando seguimiento (Camino 1)', [
            'seguimiento_id' => $s->id,
            'fecha_referencia' => (string) $fechaReferencia,
        ]);

        //normalizar datos
        $nombreProveedor = trim((string) $s->nombre_proveedor) ?: '-';
        $rutProveedor    = trim((string) $s->rut_proveedor) ?: '-';
        $idAtencion      = (string) ($s->id_atencion ?? '-');
        $idLocal         = (string) ($s->id_local ?? '-');

        $fechaTexto = Carbon::parse($fechaReferencia)
            ->setTimezone('America/Santiago')
            ->format('d-m-Y H:i');
        /**
         * Mensaje inicial estándar (nuevo y reagendado)
         */
        $response = Http::withToken(env('WHATSAPP_SEGUIMIENTO_TOKEN'))->post(
            'https://graph.facebook.com/v22.0/' . env('WHATSAPP_SEGUIMIENTO_PHONE_ID') . '/messages',
            [
                'messaging_product' => 'whatsapp',
                'to' => $s->telefono_proveedor,
                'type' => 'template',
                'template' => [
                    'name' => 'seguimiento_saludo1',
                    'language' => [
                        'code' => 'es_CL',
                    ],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $nombreProveedor],
                                ['type' => 'text', 'text' => $rutProveedor],
                                ['type' => 'text', 'text' => $idAtencion],
                                ['type' => 'text', 'text' => $idLocal],
                                ['type' => 'text', 'text' => $fechaTexto],
                            ],
                        ],
                    ],
                ],
            ]
        );

        // 🔎 LOG CRÍTICO (este es el que necesitamos)
        Log::info('📨 RESPUESTA WHATSAPP', [
            'seguimiento_id' => $s->id,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        // 🚨 Si WhatsApp falló → NO continuar
        if (!$response->successful()) {
            Log::error('❌ Error enviando WhatsApp', [
                'seguimiento_id' => $s->id,
            ]);
            return;
        }

        /**
         * ⏳ Cierre automático por silencio
         */
        CerrarSeguimientoXSilencio::dispatch($s->id)
            ->delay($hasta);
    }
}
