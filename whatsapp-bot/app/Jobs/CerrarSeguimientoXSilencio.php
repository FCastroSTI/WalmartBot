<?php

namespace App\Jobs;

use App\Models\Seguimiento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CerrarSeguimientoXSilencio implements ShouldQueue
{
    use Queueable;

    protected int $seguimientoId;

    public function __construct(int $seguimientoId)
    {
        $this->seguimientoId = $seguimientoId;
    }

    public function handle(): void
    {
        $seguimiento = Seguimiento::find($this->seguimientoId);

        if (!$seguimiento) {
            Log::warning("Seguimiento no encontrado ID {$this->seguimientoId}");
            return;
        }


        // 🔒 Si ya fue respondido o cerrado, no hacemos nada
        if (in_array($seguimiento->estado_seguimiento, [
            'CERRADO_CONFIRMADO',
            'CERRADO_REPROGRAMADO',
            'CERRADO_NO_CONFIRMADO',
            'CERRADO_SIN_RESPUESTA',
        ])) {
            return;
        }

        if (!$seguimiento->espera_hasta_at || now()->lessThan($seguimiento->espera_hasta_at)) {
            return;
        }

        // 📲 Enviar TEMPLATE de cierre por silencio
        $this->enviarTemplateCierreWhatsapp($seguimiento);

        // 🔁 Cerrar seguimiento
        $seguimiento->update([
            'estado_seguimiento'     => 'CERRADO_SIN_RESPUESTA',
            'subestado_conversacion' => null,
            'cerrado_at'             => now(),
        ]);

        Log::info("Seguimiento {$seguimiento->id} cerrado por silencio.");
    }

    /**
     * 📤 Envío de TEMPLATE WhatsApp (OBLIGATORIO por política Meta)
     */
    private function enviarTemplateCierreWhatsapp(Seguimiento $seguimiento): void
    {
        $token   = env('WHATSAPP_SEGUIMIENTO_TOKEN');
        $phoneId = env('WHATSAPP_SEGUIMIENTO_PHONE_ID');

        if (!$token || !$phoneId) {
            Log::error('Credenciales WhatsApp seguimiento no configuradas');
            return;
        }

        try {
            $response = Http::withToken($token)->post(
                "https://graph.facebook.com/v22.0/{$phoneId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $seguimiento->telefono_proveedor,
                    'type' => 'template',
                    'template' => [
                        'name' => 'seguimiento_cierre',
                        'language' => [
                            'code' => 'es_CL',
                        ],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    [
                                        'type' => 'text',
                                        'text' => (string) $seguimiento->id_atencion,
                                    ],
                                    [
                                        'type' => 'text',
                                        'text' => (string) $seguimiento->id_local,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]
            );

            Log::info('📥 Template cierre silencio enviado', [
                'seguimiento_id' => $seguimiento->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error(
                "Error enviando TEMPLATE cierre seguimiento {$seguimiento->id}: " .
                    $e->getMessage()
            );
        }
    }
}
