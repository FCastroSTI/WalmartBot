<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

use App\Jobs\CerrarSeguimientoXSilencio;
use App\Jobs\EnviarCorreoConfirmacionSeguimiento;
use App\Models\Seguimiento;

class WhatsappSeguimientoController extends Controller
{
    private string $token;
    private string $phoneId;

    public function __construct()
    {
        $this->token   = env('WHATSAPP_SEGUIMIENTO_TOKEN');
        $this->phoneId = env('WHATSAPP_SEGUIMIENTO_PHONE_ID');
    }

    // ==========================
    // VERIFY WEBHOOK
    // ==========================
    public function verify(Request $request)
    {
        if (
            $request->hub_mode === 'subscribe' &&
            $request->hub_verify_token === env('WHATSAPP_SEGUIMIENTO_VERIFY_TOKEN')
        ) {
            return response($request->hub_challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    // ==========================
    // RECEIVE MENSAJES
    // ==========================
    public function receive(Request $request)
    {
        Log::info('📩 WEBHOOK SEGUIMIENTO RECIBIDO', $request->all());

        $message = $request->input('entry.0.changes.0.value.messages.0');

        if (!$message) {
            return response('EVENT_RECEIVED', 200);
        }

        // =====================================================
        // 🔑 IDEMPOTENCIA WHATSAPP (EVITAR EVENTOS DUPLICADOS)
        // =====================================================
        $messageId = $message['id'] ?? null;

        if (!$messageId) {
            return response('EVENT_RECEIVED', 200);
        }

        $cacheKey = 'whatsapp_msg_' . $messageId;

        if (Cache::has($cacheKey)) {
            Log::warning('🔁 Mensaje duplicado ignorado', [
                'message_id' => $messageId
            ]);
            return response('EVENT_RECEIVED', 200);
        }

        // Marcar mensaje como procesado (5 minutos)
        Cache::put($cacheKey, true, now()->addMinutes(5));

        // =====================================================
        // DATOS DEL MENSAJE
        // =====================================================
        $from = '+' . preg_replace('/\D/', '', $message['from']);
        $text = '';

        // Extraer texto de forma segura
        if (
            isset($message['text']) &&
            isset($message['text']['body']) &&
            is_string($message['text']['body'])
        ) {
            $text = trim($message['text']['body']);
        }

        // Validar que haya texto usable
        if ($text === '') {
            Log::warning('⚠️ Mensaje sin texto válido', [
                'message_id' => $messageId,
                'raw_message' => $message
            ]);
            return response('EVENT_RECEIVED', 200);
        }

        $seguimiento = Seguimiento::where('telefono_proveedor', $from)
            ->whereIn('estado_seguimiento', ['MENSAJE_ENVIADO', 'ESPERANDO_RESPUESTA'])
            ->first();

        if (!$seguimiento) {
            Log::warning('❌ Mensaje sin seguimiento activo', ['from' => $from]);
            return response('EVENT_RECEIVED', 200);
        }

        // =====================================================
        // ⏰ VALIDAR VENTANA DE RESPUESTA
        // =====================================================
        if ($seguimiento->espera_hasta_at && now()->greaterThan($seguimiento->espera_hasta_at)) {
            Log::warning('⏰ Mensaje fuera de ventana', [
                'seguimiento_id' => $seguimiento->id
            ]);
            return response('EVENT_RECEIVED', 200);
        }

        // =====================================================
        // 🔀 FLUJO SEGÚN SUBESTADO
        // =====================================================
        switch ($seguimiento->subestado_conversacion) {



            case 'PREGUNTA_LLEGADA':
                $this->procesarLlegada($seguimiento, strtolower($text));
                break;

            case 'PREGUNTA_HORA_COMPROMETIDA':
                $this->procesarConfirmacionHoraComprometida($seguimiento, $text);
                break;

            case 'ESPERANDO_HORA_LLEGADA':
                $this->guardarHoraLlegada($seguimiento, $text);
                break;

            case 'ESPERANDO_REPROGRAMACION':
                $this->guardarReprogramacion($seguimiento, $text);
                break;

            case 'ESPERANDO_FECHA_HORA_LLEGADA':
                $this->procesarReagendamiento($seguimiento, $text);
                break;

            case 'ESPERANDO_FECHA_REAGENDADA':
                $this->procesarReagendamiento($seguimiento, $text);
                break;

            case 'ESPERANDO_FECHA_HORA_LLEGADA_REAL':
                $this->guardarFechaHoraLlegada($seguimiento, $text);
                break;

            default:
                Log::warning('⚠️ Subestado no manejado', [
                    'subestado' => $seguimiento->subestado_conversacion,
                    'seguimiento_id' => $seguimiento->id
                ]);
                break;
        }

        return response('EVENT_RECEIVED', 200);
    }


    // =====================================================
    // SUBFLUJOS
    // =====================================================

    private function procesarLlegada(Seguimiento $s, string $text): void
    {
        if ($text === 'si') {
            $this->sendText(
                $s->telefono_proveedor,
                'Por favor ingrese la hora exacta de llegada:' . "\n"
            );


            $s->update([
                'subestado_conversacion' => "ESPERANDO_FECHA_HORA_LLEGADA_REAL",
                'estado_seguimiento'     => 'ESPERANDO_RESPUESTA',
                'espera_hasta_at'        => now()->addMinutes(10),
            ]);


            $conv = true;

            CerrarSeguimientoXSilencio::dispatch($s->id)
                ->delay(now()->addMinutes(10));
        } elseif ($text === 'no') {

            // 🔑 MENSAJE DIFERENCIADO POR CAMINO
            $mensaje = match ((int) $s->camino) {
                3 =>
                "Para reprogramar el servicio,\n" .
                    "por favor ingrese:\n" .
                    "Nueva fecha y hora\n" .
                    "comprometida.",

                default =>
                "Te recordamos que te encuentras fuera del plazo establecido de 2 horas.\n\n" .
                    "Favor indicar nueva fecha y hora de llegada.\n\n" .
                    "Formato requerido:\n" .
                    "dd-mm-yyyy hh:mm",
            };

            $this->sendText($s->telefono_proveedor, $mensaje);

            $s->update([
                'subestado_conversacion' => 'ESPERANDO_FECHA_REAGENDADA',
                'estado_seguimiento'     => 'ESPERANDO_RESPUESTA',
                'espera_hasta_at'        => now()->addMinutes(10),
            ]);

            // ⏳ Job de cierre por silencio
            CerrarSeguimientoXSilencio::dispatch($s->id)
                ->delay(now()->addMinutes(10));
        }
    }

    private function guardarFechaHoraLlegada(Seguimiento $s, string $texto): void
    {
        // Validar formato hh:mm
        if (!preg_match('/^\d{2}:\d{2}$/', $texto)) {
            $this->sendText(
                $s->telefono_proveedor,
                "❌ Formato inválido.\nUse el formato:\n" .
                    "hh:mm\nEjemplo:\n10:30"
            );
            return;
        }

        try {
            // Combinar HOY + hora ingresada
            $fechaHoraLlegada = now()
                ->setTimezone('America/Santiago')
                ->setTimeFromTimeString($texto);
        } catch (\Throwable $e) {
            $this->sendText(
                $s->telefono_proveedor,
                "❌ Hora inválida. Intente nuevamente."
            );
            return;
        }

        // Guardar
        $s->update([
            'fecha_hora_confirmada' => $fechaHoraLlegada,
            'estado_seguimiento'    => 'CERRADO_CONFIRMADO',
            'subestado_conversacion' => null,
            'espera_hasta_at'       => null,
        ]);

        // Mensaje final
        $mensaje =
            "Estimado proveedor {$s->nombre_proveedor} {$s->rut_proveedor}\n\n" .
            "Agradecemos su confirmación, se procede a finalizar el seguimiento del " .
            "ID N° {$s->id_atencion} Local {$s->id_local}.\n\n" .
            "Atte.\nWalmart Mantención tiendas";

        $this->sendText($s->telefono_proveedor, $mensaje);
    }

    private function procesarHoraComprometida(Seguimiento $s, string $text): void
    {
        // similar a procesarLlegada pero específico crítico
    }

    private function guardarHoraLlegada(Seguimiento $s, string $hora): void
    {
        $s->update([
            'estado_seguimiento' => 'CERRADO_CONFIRMADO',
            'subestado_conversacion' => null,
        ]);

        $this->sendText(
            $s->telefono_proveedor,
            '✔ Gracias. Confirmación registrada.'
        );
    }

    private function guardarReprogramacion(Seguimiento $s, string $texto): void
    {
        $s->update([
            'estado_seguimiento' => 'CERRADO_REPROGRAMADO',
            'subestado_conversacion' => null,
        ]);

        $this->sendText(
            $s->telefono_proveedor,
            '✔ Reprogramación registrada. Gracias.'
        );
    }

    private function procesarReagendamiento(Seguimiento $s, string $texto): void
    {
        // Validar formato dd-mm-yyyy hh:mm
        if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}$/', $texto)) {
            $this->sendText(
                $s->telefono_proveedor,
                "❌ Formato inválido.\nUse el formato:\n" .
                    "dd-mm-yyyy hh:mm"
            );
            return;
        }

        // Convertir a Carbon (recomendado)
        try {
            $fechaReagendada = \Carbon\Carbon::createFromFormat(
                'd-m-Y H:i',
                $texto,
                'America/Santiago'
            );
        } catch (\Throwable $e) {
            $this->sendText(
                $s->telefono_proveedor,
                "❌ Fecha u hora inválida. Intente nuevamente."
            );
            return;
        }

        // Mensaje de confirmación
        $mensaje =
            "Estimado proveedor {$s->nombre_proveedor} {$s->rut_proveedor}\n\n" .
            "Agradecemos su confirmación.\n" .
            "Se realizará un nuevo seguimiento en la fecha y hora indicadas.\n\n" .
            "Atte.\nWalmart Mantención tiendas";

        $this->sendText($s->telefono_proveedor, $mensaje);

        EnviarCorreoConfirmacionSeguimiento::dispatch($s->id, $mensaje);

        // 🧠 Crear NUEVO seguimiento reagendado
        Seguimiento::create([
            'id_atencion'        => $s->id_atencion,
            'nro_tririga'        => $s->nro_tririga,
            'id_local'           => $s->id_local,
            'criticidad'         => $s->criticidad,
            'nombre_proveedor'   => $s->nombre_proveedor,
            'rut_proveedor'      => $s->rut_proveedor,
            'telefono_proveedor' => $s->telefono_proveedor,
            'camino'             => $s->camino,
            'estado_seguimiento' => 'PENDIENTE_FLUJO',
            'payload_ticket'     => $s->payload_ticket,
            'ejecutar_desde_at'  => $fechaReagendada, // 👈 clave
        ]);

        // 🔒 Cerrar seguimiento actual
        $s->update([
            'estado_seguimiento'     => 'REAGENDADO',
            'subestado_conversacion' => null,
        ]);
    }


    private function procesarConfirmacionHoraComprometida(Seguimiento $s, string $text): void
    {
        // Normalizar
        $text = strtolower(trim($text));

        // 🟢 CONFIRMA
        if (in_array($text, ['si', 'sí', 'ok', 'confirmo'])) {

            $mensaje =
                "Estimado proveedor {$s->nombre_proveedor} {$s->rut_proveedor}\n\n" .
                "Agradecemos su confirmación.\n" .
                "Se realizará un nuevo seguimiento\n" .
                "para corroborar su llegada al local.\n\n" .
                "Atte.\nWalmart Mantención tiendas";

            $this->sendText($s->telefono_proveedor, $mensaje);

            // 🧠 Crear nuevo seguimiento de corroboración
            Seguimiento::create([
                'id_atencion'        => $s->id_atencion,
                'nro_tririga'        => $s->nro_tririga,
                'id_local'           => $s->id_local,
                'criticidad'         => $s->criticidad,
                'nombre_proveedor'   => $s->nombre_proveedor,
                'rut_proveedor'      => $s->rut_proveedor,
                'telefono_proveedor' => $s->telefono_proveedor,
                'camino'             => 2, // sigue siendo crítico
                'estado_seguimiento' => 'PENDIENTE_FLUJO',
                'payload_ticket'     => $s->payload_ticket,
                'ejecutar_desde_at'  => now()->addMinutes(30), // o lo que definas
            ]);

            // 🔒 Cerrar seguimiento actual
            $s->update([
                'estado_seguimiento'     => 'CERRADO_CONFIRMADO',
                'subestado_conversacion' => null,
                'espera_hasta_at'        => null,
            ]);

            return;
        }

        // 🔴 NO CONFIRMA → CIERRE INMEDIATO
        if (in_array($text, ['no', 'rechazo', 'no confirmo'])) {

            $mensaje =
                "Le informamos que por falta de\n" .
                "confirmación se procede a finalizar el\n" .
                "seguimiento del ID {$s->id_atencion} Local {$s->id_local}.\n\n" .
                "Atte.\nWalmart Mantención tiendas";

            $this->sendText($s->telefono_proveedor, $mensaje);

            $s->update([
                'estado_seguimiento'     => 'CERRADO_NO_CONFIRMADO',
                'subestado_conversacion' => null,
                'espera_hasta_at'        => null,
            ]);

            return;
        }

        // ❌ Entrada inválida
        $this->sendText(
            $s->telefono_proveedor,
            "❓ Respuesta no válida.\n" .
                "Por favor responda:\n" .
                "SI o NO"
        );
    }

    // =====================================================
    // SEND TEXT
    // =====================================================
    private function sendText(string $to, string $body): void
    {
        Http::withToken($this->token)->post(
            "https://graph.facebook.com/v22.0/{$this->phoneId}/messages",
            [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $body],
            ]
        );
    }
}
