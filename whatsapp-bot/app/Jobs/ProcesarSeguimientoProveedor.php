<?php

namespace App\Jobs;

use App\Models\Seguimiento;
use App\Jobs\CerrarSeguimientoXSilencio;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcesarSeguimientoProveedor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $seguimientoId;
    protected string $nombreProveedor;
    protected string $rutProveedor;

    public function __construct(
        int $seguimientoId,
        string $nombreProveedor,
        string $rutProveedor
    ) {
        $this->seguimientoId   = $seguimientoId;
        $this->nombreProveedor = $nombreProveedor;
        $this->rutProveedor    = $rutProveedor;
    }

    public function handle(): void
    {
        $seguimiento = Seguimiento::find($this->seguimientoId);

        if (!$seguimiento) {
            Log::error('❌ Seguimiento no encontrado', [
                'seguimiento_id' => $this->seguimientoId
            ]);
            return;
        }

        // 🛑 Anti-loop
        if ($seguimiento->estado_seguimiento !== 'PENDIENTE_FLUJO') {
            Log::warning('⛔ Job abortado por estado', [
                'seguimiento_id' => $seguimiento->id,
                'estado' => $seguimiento->estado_seguimiento,
            ]);
            return;
        }

        // 1️⃣ Determinar template y subestado según camino
        switch ((int) $seguimiento->camino) {
            case 1:
            case 3:
                $templateName = 'seguimiento_llegada_tecnico';
                $subestado = 'PREGUNTA_LLEGADA';
                break;

            case 2:
                $templateName = 'mensaje_seguimiento2';
                $subestado = 'PREGUNTA_HORA_COMPROMETIDA';
                $horaComprometida = optional($seguimiento->ejecutar_desde_at)
                    ->format('H:i');
                break;

            default:
                Log::warning('⚠️ Camino desconocido', [
                    'seguimiento_id' => $seguimiento->id,
                    'camino' => $seguimiento->camino,
                ]);
                return;
        }

        Log::info("*********");
        // 2️⃣ Enviar template
        $enviado = $this->sendTemplateMessage(
            $seguimiento->telefono_proveedor,
            $templateName,
            [
                $this->nombreProveedor,              // {{1}}
                $this->rutProveedor,                 // {{2}}
                (string) $seguimiento->id_atencion,  // {{3}}
                (string) $seguimiento->id_local
            ]
        );

        if (!$enviado) {
            Log::error('❌ No se pudo enviar template WhatsApp', [
                'seguimiento_id' => $seguimiento->id
            ]);
            return;
        }

        // 3️⃣ Actualizar estado SOLO si se envió
        $seguimiento->update([
            'estado_seguimiento'     => 'MENSAJE_ENVIADO',
            'subestado_conversacion' => $subestado,
            'mensaje_enviado_at'     => now(),
            'espera_hasta_at'        => now()->addMinutes(10),
        ]);

        // 4️⃣ Programar cierre por silencio
        CerrarSeguimientoXSilencio::dispatch($seguimiento->id)
            ->delay(now()->addMinutes(10));

        Log::info('✅ Seguimiento procesado correctamente', [
            'seguimiento_id' => $seguimiento->id
        ]);
    }

    private function sendTemplateMessage(
        string $to,
        string $templateName,
        array $parameters
    ): bool {
        $token   = env('WHATSAPP_SEGUIMIENTO_TOKEN');
        $phoneId = env('WHATSAPP_SEGUIMIENTO_PHONE_ID');

        if (!$token || !$phoneId) {
            Log::error('❌ WhatsApp seguimiento: credenciales faltantes');
            return false;
        }

        $url = "https://graph.facebook.com/v22.0/{$phoneId}/messages";

        $payload = [
            "messaging_product" => "whatsapp",
            "to" => $to,
            "type" => "template",
            "template" => [
                "name" => $templateName,
                "language" => [
                    "code" => "es_CL"
                ],
                "components" => [
                    [
                        "type" => "body",
                        "parameters" => array_map(
                            fn($value) => [
                                "type" => "text",
                                "text" => (string) $value
                            ],
                            $parameters
                        )
                    ]
                ]
            ]
        ];

        Log::info(json_encode($payload));

        try {
            $response = Http::withToken($token)->post($url, $payload);

            Log::info('📤 WhatsApp TEMPLATE enviado', [
                'to' => $to,
                'template' => $templateName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }


        return true;
    }
}
