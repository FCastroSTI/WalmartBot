<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ConversacionUsuario;
use Illuminate\Support\Facades\Http;

class TicketWebController extends Controller
{
    /**
     * Mostrar el formulario para completar el ticket
     */
    public function formulario($phone)
    {
        return view('formulario.ticket', compact('phone'));
    }

    /**
     * Guardar datos del formulario en la conversación del usuario
     */
    public function guardar(Request $request)
    {
        // Validación de campos
        $request->validate([
            'local' => 'required',
            'nivel1' => 'required',
            'nivel2' => 'required',
            'nivel4' => 'required',
            'marca' => 'required',
            'modelo' => 'required',
            'serie' => 'required',
            'nombre' => 'required',
            'cargo' => 'required',
            'email' => 'required|email',
            'observacion' => 'required',
            'phone' => 'required'
        ]);

        // Buscar conversación por teléfono
        $conv = ConversacionUsuario::where('phone', $request->phone)->first();

        if (!$conv) {
            return back()->with('error', 'No existe una conversación activa para este número.');
        }

        // Guardar datos en JSON
        $conv->formulario = [
            'local' => $request->local,
            'nivel1' => $request->nivel1,
            'nivel2' => $request->nivel2,
            'nivel4' => $request->nivel4,
            'marca' => $request->marca,
            'modelo' => $request->modelo,
            'serie' => $request->serie,
            'nombre' => $request->nombre,
            'cargo' => $request->cargo,
            'email' => $request->email,
            'observacion' => $request->observacion,
            'fecha_envio' => now()->toDateTimeString()
        ];

        // 🔥 Dejamos la conversación en estado FINALIZADO
        $conv->estado = 'FIN';
        $conv->intentos = 0;
        $conv->save();

        // 🔥 Enviar ambos mensajes juntos al usuario
        $this->enviarMensajeWhatsApp(
            $conv->phone,
            "👍 Dentro de los próximos 10 minutos recibira un mail con la informacion asociada a su solicitud."
        );

        return back()->with('success', '✔ Ticket enviado correctamente. Gracias por completar el formulario.');
    }

    private function enviarMensajeWhatsApp($phone, $mensaje)
    {
        $url = "https://graph.facebook.com/v22.0/" . env('WHATSAPP_PHONE_ID') . "/messages";

        Http::withToken(env('WHATSAPP_TOKEN'))->post($url, [
            "messaging_product" => "whatsapp",
            "to" => $phone,
            "type" => "text",
            "text" => ["body" => $mensaje]
        ]);
    }

    /**
     * (Opcional) Enviar mensaje de confirmación al usuario por WhatsApp
     */
    private function enviarConfirmacionWhatsApp($phone)
    {
        $url = "https://graph.facebook.com/v22.0/" . env('WHATSAPP_PHONE_ID') . "/messages";

        Http::withToken(env('WHATSAPP_TOKEN'))->post($url, [
            "messaging_product" => "whatsapp",
            "to" => $phone,
            "type" => "text",
            "text" => [
                "body" => "👍 Hemos recibido su formulario correctamente. Nuestro equipo gestionará su ticket pronto."
            ]
        ]);
    }
}
