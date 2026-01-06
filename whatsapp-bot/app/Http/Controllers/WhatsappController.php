<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ConversacionUsuario;
use App\Models\Tienda;
use App\Models\Interacciones;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;


class WhatsappController extends Controller
{
    private $verifyToken;
    private $whatsappToken;
    private $phoneId;

    public function __construct()
    {
        //$this->verifyToken = env('');
        //$this->whatsappToken = env('');
        //$this->phoneId = env('');
    }
    // ============================================================
    //  Obteniendo el token
    // ============================================================
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
                'usuario'     => env('WALMART_API_USER'),
                'contrasena'  => env('WALMART_API_PASSWORD'),
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


    // ============================================================
    //  VERIFICACIÓN DEL WEBHOOK (GET)
    // ============================================================
    public function verify(Request $request)
    {
        $mode = $request->hub_mode;
        $token = $request->hub_verify_token;
        $challenge = $request->hub_challenge;

        if ($mode === 'subscribe' && $token === $this->verifyToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Error de verificación', 403);
    }

    // ============================================================
    //  RECEPCIÓN DE MENSAJES (POST)
    // ============================================================
    public function receive(Request $request)
    {
        Log::info("WEBHOOK RECIBIDO: " . json_encode($request->all()));

        $value = $request->input("entry.0.changes.0.value") ?? null;
        $messages = $value["messages"][0] ?? null;

        if (!$messages) {
            return response("EVENT_RECEIVED", 200);
        }

        $phone = $messages["from"];
        $texto = $messages["text"]["body"] ?? null;

        //Guardar interaccion usuario
        try {
            $conv = ConversacionUsuario::where('phone', $phone)->first();

            Interacciones::create([
                'phone' => $phone,
                'estado' => $conv?->estado,
                'mensaje_usuario' => $texto
            ]);
        } catch (\Throwable $e) {
            Log::error('Error guardando mensaje usuario: ' . $e->getMessage());
        }

        if (!$texto) {
            $this->sendMessage($phone, "Puedo procesar solo mensajes de texto por ahora.");
            return response("EVENT_RECEIVED", 200);
        }

        return $this->procesarConversacion($phone, trim($texto));
    }

    // ============================================================
    //  LÓGICA DEL FLUJO CONVERSACIONAL
    // ============================================================
    private function procesarConversacion($phone, $texto)
    {
        $ahora = Carbon::now();

        // ============================================================
        // FILTRO 1 → Ignorar mensajes vacíos (WhatsApp envía eventos sin texto)
        // ============================================================
        if (!$texto || trim($texto) === '') {
            return response("EVENT_RECEIVED", 200);
        }

        // Normalizar texto
        $texto = trim($texto);
        $textoLimpio = strtolower($texto);

        // ============================================================
        // OBTENER CONVERSACIÓN
        // ============================================================
        $conv = ConversacionUsuario::firstOrCreate(
            ['phone' => $phone],
            [
                'estado' => 'INICIO',
                'intentos' => 0,
                'datos' => [],
                'last_interaction' => $ahora
            ]
        );


        $palabrasSalida = [
            'salir',
            'adios',
            'adiós',
            'chao',
            'chau',
            'no mas',
            'no más',
            'suficiente',
            'terminar',
            'cancelar',
            'fin'
        ];

        foreach ($palabrasSalida as $palabra) {
            if (str_contains($textoLimpio, $palabra)) {

                // Reset completo de la conversación
                $conv->estado = 'CERRADA';
                $conv->intentos = 0;
                $conv->datos = [];
                $conv->formulario = null;
                $conv->last_interaction = now();
                $conv->save();

                // Mensaje final
                $this->sendMessage(
                    $phone,
                    "👋 Gracias por contactarnos.\nLa conversación ha sido finalizada.\nSi necesitas ayuda nuevamente, no dudes en contactarnos."
                );

                return response("EVENT_RECEIVED", 200);
            }
        }
        // ============================================================
        // FILTRO 2 → Reiniciar conversación por inactividad (10 minutos)
        // ============================================================
        if ($conv->last_interaction) {

            $ultimoDia = Carbon::parse($conv->last_interaction)->toDateString();
            $hoy = Carbon::now()->toDateString();

            if ($ultimoDia !== $hoy) {

                // Reiniciar conversación
                $conv->estado = 'INICIO';
                $conv->intentos = 0;
                $conv->datos = [];
                $conv->formulario = null;
                $conv->last_interaction = $ahora;
                $conv->save();

                // Mensaje inicial

                $this->sendMessage($phone, "👋 ¡Hola! Bienvenido a tu asistente virtual.");

                $this->sendMessage(
                    $phone,
                    "Seleccione una opción. Recuerde que para EMERGENCIAS debe llamar al XXXXXXXX.\n\n" .
                        "1️⃣ Consultar caso existente\n" .
                        "2️⃣ Ingresar nuevo caso\n " .
                        "Debe ingresar el número de la opcion"
                );

                return response("EVENT_RECEIVED", 200);
            }
        }
        // ============================
        //          SWITCH
        // ============================
        switch ($conv->estado) {
            // ============================================================
            //  INICIO tras cierre
            // ============================================================

            case 'CERRADA':

                if (
                    str_contains($textoLimpio, 'hola') ||
                    str_contains($textoLimpio, 'hi') ||
                    str_contains($textoLimpio, 'buenas') ||
                    str_contains($textoLimpio, 'hello') ||
                    str_contains($textoLimpio, 'holi')
                ) {

                    // 🔄 Reset completo
                    $conv->estado = 'INICIO';
                    $conv->intentos = 0;
                    $conv->datos = [];
                    $conv->formulario = null;

                    $this->sendMessage($phone, "👋 ¡Hola! Bienvenido nuevamente a tu asistente virtual.");

                    $this->sendMessage(
                        $phone,
                        "Seleccione una opción. Recuerde que para EMERGENCIAS debe llamar al XXXXXXXX.\n\n" .
                            "1️⃣ Consultar caso existente\n" .
                            "2️⃣ Ingresar nuevo caso\n" .
                            "Debe ingresar el número de la opción"
                    );

                    $conv->estado = 'ESPERANDO_OPCION_MENU';
                }

                // cualquier otra cosa → silencio
                break;

            // ============================================================
            //  INICIO
            // ============================================================
            case 'INICIO':

                if (
                    str_contains($textoLimpio, 'hola') ||
                    str_contains($textoLimpio, 'buenas') ||
                    str_contains($textoLimpio, 'holi') ||
                    str_contains($textoLimpio, 'hello') ||
                    str_contains($textoLimpio, 'aloha') ||
                    str_contains($textoLimpio, 'aloja') ||
                    str_contains($textoLimpio, 'hi')
                ) {

                    $this->sendMessage($phone, "👋 ¡Hola! Bienvenido a tu asistente virtual.");

                    $this->sendMessage(
                        $phone,
                        "Seleccione una opción. Recuerde que para EMERGENCIAS debe llamar al XXXXXXXX.\n\n" .
                            "1️⃣ Consultar caso existente\n" .
                            "2️⃣ Ingresar nuevo caso\n " .
                            "Debe ingresar el número de la opcion"
                    );

                    $conv->estado = 'ESPERANDO_OPCION_MENU';
                } else {
                    $this->sendMessage($phone, "👋 Para comenzar por favor escriba *hola*.");
                    $this->sendMessage(
                        $phone,
                        "Seleccione una opción. Recuerde que para EMERGENCIAS debe llamar al XXXXXXXX.\n\n" .
                            "1️⃣ Consultar caso existente\n" .
                            "2️⃣ Ingresar nuevo caso\n " .
                            "Debe ingresar el número de la opcion"
                    );
                }

                break;

            // ============================================================
            // MENÚ PRINCIPAL
            // ============================================================
            case 'MENU_PRINCIPAL':

                $this->sendMessage(
                    $phone,
                    "👋 Bienvenido nuevamente.\nSeleccione una opción:\n Recuerde que para EMERGENCIAS debe llamar al XXXXXXXX.\n\n" .
                        "1️⃣ Consultar caso existente\n" .
                        "2️⃣ Ingresar nuevo caso\n" .
                        "Debe ingresar el número de la opcion"
                );

                $conv->estado = 'ESPERANDO_OPCION_MENU';
                break;

            // ============================================================
            // ESPERANDO OPCIÓN MENÚ
            // ============================================================
            case 'ESPERANDO_OPCION_MENU':

                if ($texto === "1") {

                    $this->sendMessage(
                        $phone,
                        "Por favor seleccione identificador:\n1️⃣ Número de Ticket\n2️⃣ Número de Tririga\n3️⃣ Número de Local\nDebe ingresar el número de la opcion"
                    );

                    $conv->estado = 'CONSULTA_SELECCION_IDENTIFICADOR';
                } elseif ($texto === "2") {

                    $this->sendMessage($phone, "Seleccione tipo de caso:\n1️⃣ Casos normales\nDebe ingresar el número de la opcion");
                    $conv->estado = 'INGRESO_TIPO_CASO';
                } else {
                    $this->sendMessage($phone, "❌ Debe seleccionar una de las opciones disponibles en menú.");
                    $this->sendMessage(
                        $phone,
                        "Por favor seleccione identificador:\n1️⃣ Número de Ticket\n2️⃣ Número de Tririga\n3️⃣ Número de Local\nDebe ingresar el número de la opcion"
                    );
                }

                break;

            // ============================================================
            // SELECTOR DE IDENTIFICADOR
            // ============================================================
            case 'CONSULTA_SELECCION_IDENTIFICADOR':

                if ($texto === "1") {
                    $this->sendMessage($phone, "Ingrese el número de ticket:");
                    $conv->estado = 'CONSULTA_INGRESAR_TICKET';
                } elseif ($texto === "2") {
                    $this->sendMessage($phone, "Ingrese el N° ID:");
                    $conv->estado = 'CONSULTA_INGRESAR_ID';
                } elseif ($texto === "3") {
                    $this->sendMessage($phone, "Ingrese el N° local:");
                    $conv->estado = 'CONSULTA_INGRESAR_LOCAL';
                } else {
                    $this->sendMessage($phone, "❌ Debe seleccionar una de las opciones disponibles en menú.");
                    $this->sendMessage(
                        $phone,
                        "Por favor seleccione identificador:\n1️⃣ Número de Ticket\n2️⃣ Número de Tririga\n3️⃣ Número de Local\nDebe ingresar el número de la opcion"
                    );
                }

                break;

            // ============================================================
            // CONSULTAS (Ticket / ID / Local)
            // ============================================================
            case 'CONSULTA_INGRESAR_TICKET':

                // Normalizamos texto
                $ticket = trim($texto);

                // Validación: nro_TIRRIGA es solo numérico
                if (!ctype_digit($ticket)) {
                    $this->sendMessage(
                        $phone,
                        "❌ Formato inválido.\nEl número de ticket debe contener solo números.\nEjemplo: 11563839\nPor favor ingrese un número de ticket válido:"
                    );
                    break; // Mantiene CONSULTA_INGRESAR_TICKET
                }

                $this->sendMessage($phone, "⏳ Consultando sistema CRM...");

                // 🔗 LLAMADA REAL A LA API
                $resultado = $this->consultarTicketsCRM([
                    'idTicket' => $ticket // id_atencion
                ]);

                // 📭 Sin resultados
                if (
                    !$resultado ||
                    empty($resultado['result']['ticket'])
                ) {
                    $this->sendMessage(
                        $phone,
                        "ℹ️ No se encontraron casos asociados al ticket $ticket.\n\n" . "📞 Si tiene dudas por favor comuníquese con la mesa de ayuda 220305515."
                    );
                }
                // 📄 Con resultados
                else {
                    foreach ($resultado['result']['ticket'] as $t) {
                        $this->sendMessage(
                            $phone,
                            $this->formatearTicketCRM($t) . "📞 Si tiene dudas comuníquese con la mesa de ayuda 220305515."
                        );
                    }
                }


                $this->sendMessage($phone, "¿Necesita otra consulta?");
                $conv->estado = 'NECESITA_OTRA_CONSULTA';
                break;



            case 'CONSULTA_INGRESAR_ID':

                // Normalizamos texto
                $idAtencion = trim($texto);

                // Validación: ID de atención debe ser numérico
                if (!ctype_digit($idAtencion)) {
                    $this->sendMessage(
                        $phone,
                        "❌ Formato inválido.\nEl ID debe contener solo números.\nEjemplo: 3721\nPor favor ingrese un ID válido:"
                    );
                    break; // Mantiene CONSULTA_INGRESAR_ID
                }

                $this->sendMessage($phone, "⏳ Consultando sistema CRM...");

                // 🔗 LLAMADA REAL A LA API
                $resultado = $this->consultarTicketsCRM([
                    'nroTririga' => $idAtencion
                ]);

                // 📭 Sin resultados
                if (
                    !$resultado ||
                    empty($resultado['result']['ticket'])
                ) {
                    $this->sendMessage(
                        $phone,
                        "ℹ️ No se encontraron casos asociados al ID $idAtencion.\n\n" . "📞 Si tiene dudas por favor comuníquese con la mesa de ayuda 220305515."
                    );
                }
                // 📄 Con resultados
                else {
                    foreach ($resultado['result']['ticket'] as $t) {
                        $this->sendMessage(
                            $phone,
                            $this->formatearTicketCRM($t) . "📞 Si tiene dudas comuníquese con la mesa de ayuda 220305515."
                        );
                    }
                }


                $this->sendMessage($phone, "¿Necesita otra consulta?");
                $conv->estado = 'NECESITA_OTRA_CONSULTA';
                break;




            case 'CONSULTA_INGRESAR_LOCAL':

                $idLocal = trim($texto);

                if (!ctype_digit($idLocal)) {
                    $this->sendMessage(
                        $phone,
                        "❌ Formato inválido.\nEl número de local debe contener solo números.\nEjemplo: 120"
                    );
                    break;
                }

                $this->sendMessage($phone, "⏳ Consultando sistema CRM...");

                // 🔗 LLAMADA REAL A LA API
                $resultado = $this->consultarTicketsCRM([
                    'idLocal' => $idLocal
                ]);

                if (
                    !$resultado ||
                    empty($resultado['result']['ticket'])
                ) {
                    $this->sendMessage(
                        $phone,
                        "ℹ️ El local $idLocal no registra casos asociados.\n\n" . "📞 Si tiene dudas comuníquese con la mesa de ayuda 220305515."
                    );
                } else {
                    foreach ($resultado['result']['ticket'] as $t) {
                        $this->sendMessage(
                            $phone,
                            $this->formatearTicketCRM($t) . "📞 Si tiene dudas comuníquese con la mesa de ayuda 220305515."
                        );
                    }
                }


                $conv->estado = 'FIN';
                break;


            // ============================================================
            // NECESITA OTRA CONSULTA
            // ============================================================
            case 'NECESITA_OTRA_CONSULTA':

                // Normalizar texto
                $texto = $textoLimpio;

                // ===============================
                // INTENCIÓN: CONSULTAR OTRO TICKET
                // ===============================
                if (
                    str_contains($texto, 'consultar') ||
                    str_contains($texto, 'ver') ||
                    str_contains($texto, 'buscar')
                ) {
                    $this->sendMessage(
                        $phone,
                        "Por favor seleccione identificador:\n1️⃣ Número de Ticket\n2️⃣ Número de Tririga\n3️⃣ Número de Local\nDebe ingresar el número de la opcion"
                    );

                    $conv->estado = 'CONSULTA_SELECCION_IDENTIFICADOR';
                    break;
                }

                // ===============================
                // INTENCIÓN: CREAR OTRO TICKET
                // ===============================
                if (
                    str_contains($texto, 'crear') ||
                    str_contains($texto, 'ingresar') ||
                    str_contains($texto, 'nuevo')
                ) {
                    $this->sendMessage(
                        $phone,
                        "📄 Ingresar nuevo caso\n\nSeleccione tipo de caso:\n1️⃣ Casos normales\nDebe ingresar el número de la opción"
                    );

                    $conv->estado = 'INGRESO_TIPO_CASO';
                    break;
                }

                // ===============================
                // RESPUESTAS CLÁSICAS (SI / NO)
                // ===============================
                if ($texto === "si") {

                    $this->sendMessage(
                        $phone,
                        "👋 Seleccione una opción:\n\n" .
                            "1️⃣ Consultar caso existente\n" .
                            "2️⃣ Ingresar nuevo caso\n" .
                            "Debe ingresar el número de la opción"
                    );

                    $conv->estado = 'ESPERANDO_OPCION_MENU';
                } elseif ($texto === "no") {

                    $this->sendMessage($phone, "🙏 Gracias por usar nuestro servicio.");
                    $conv->estado = 'FIN';
                } else {

                    // ===============================
                    // INPUT NO RECONOCIDO
                    // ===============================
                    $this->sendMessage(
                        $phone,
                        "❌ ¿Que? Perdon no entendi por favor ingresa una entrada valida\n" .
                            "Por ejemplo:\n" .
                            "• si\n" .
                            "• no\n" .
                            "• consultar otro ticket\n" .
                            "• crear otro ticket"
                    );
                }

                break;

            // ============================================================
            // INGRESO TIPO DE CASO
            // ============================================================
            case 'INGRESO_TIPO_CASO':

                if ($texto === "1") {
                    $this->sendMessage($phone, "Ingrese número de local:");
                    $conv->estado = 'INGRESO_NUMERO_LOCAL';
                } else {
                    $this->sendMessage($phone, "❌ Debe seleccionar una de las opciones disponibles en menú.");
                    $this->sendMessage($phone, "Seleccione tipo de caso:\n1️⃣ Casos normales\nDebe ingresar el número de la opcion");
                }

                break;

            // ============================================================
            // INGRESO N° LOCAL
            // ============================================================
            case 'INGRESO_NUMERO_LOCAL':

                // Validar que el input sea numérico
                if (!ctype_digit($texto)) {
                    $this->sendMessage(
                        $phone,
                        "❌ El número de local debe contener solo números.\nIngrese nuevamente el número de local:"
                    );
                    break;
                }

                // 🔹 Normalizar el número de local (quita ceros a la izquierda)
                $localIngresado = (int) ltrim($texto, '0');

                // Caso especial: si era "0", "00", etc.
                if ($localIngresado === 0) {
                    $this->sendMessage(
                        $phone,
                        "❌ El número de local ingresado no es válido.\nIngrese nuevamente el número de local:"
                    );
                    break;
                }
                // Buscar el local en la tabla tiendas
                $tienda = Tienda::where('local', (int) $texto)->first();

                // Si no existe, pedir nuevamente el número
                if (!$tienda) {
                    $this->sendMessage(
                        $phone,
                        "❌ No se encontró una tienda asociada al local ingresado.\nIngrese nuevamente el número de local:"
                    );
                    break;
                }

                // Guardar datos en la conversación
                $datos = $conv->datos ?? [];
                $datos['local'] = $tienda->local;
                $conv->datos = $datos;
                $conv->save();

                // Continuar flujo SIN mensajes adicionales
                $this->sendMessage($phone, "Ingrese su código de autorización:");
                $conv->estado = 'INGRESO_VALIDAR_CODIGO';

                break;

            // ============================================================
            // VALIDAR CÓDIGO
            // ============================================================
            case 'INGRESO_VALIDAR_CODIGO':

                $codigo = strtoupper(trim($texto));

                // Validar que exista el local en la conversación
                $local = $conv->datos['local'] ?? null;

                if (!$local) {
                    $this->sendMessage($phone, "❌ Error interno. Intente nuevamente.");
                    $conv->estado = 'MENU_PRINCIPAL';
                    break;
                }

                // Buscar coincidencia local + código en la tabla tiendas
                $tienda = Tienda::where('local', $local)
                    ->where('codigo', $codigo)
                    ->first();

                // ❌ Código no válido
                if (!$tienda) {
                    $conv->intentos++;

                    if ($conv->intentos < 2) {
                        $this->sendMessage(
                            $phone,
                            "❌ Código inválido. 1 de 2 Intente nuevamente:"
                        );
                    } else {
                        $this->sendMessage(
                            $phone,
                            "❌ Código inválido. 2 de 2. Interacción finalizada por seguridad."
                        );
                        $this->sendMessage($phone, "¿Necesita otra consulta?");
                        $conv->estado = 'NECESITA_OTRA_CONSULTA';
                    }

                    break;
                }

                // ✅ Código válido
                $datos = $conv->datos ?? [];
                $datos['codigo_tienda'] = $tienda->codigo;
                $conv->datos = $datos;

                $conv->intentos = 0;
                $conv->save();

                $this->sendMessage($phone, "✔ Código autorizado.");

                $this->sendMessage($phone, "Para crear un caso haga clic en el siguiente link:\n\n https://crm2new.upcom.cl/FormMantWalmartQA/Formulario");


                $this->sendMessage(
                    $phone,
                    "📩 Si completó el formulario, dentro de los próximos 10 minutos recibirá un mail con la información asociada a su solicitud. \n\n🙏 Gracias por usar nuestro servicio."
                );


                // Registrar envío del formulario
                $datos = $conv->datos ?? [];
                $datos['formulario_enviado_en'] = now()->toDateTimeString();
                $conv->datos = $datos;

                $conv->estado = 'ESPERANDO_FORMULARIO';

                break;


            // ============================================================
            // ESPERANDO FORMULARIO (evitar mensajes automáticos)
            // ============================================================
            case 'ESPERANDO_FORMULARIO':

                $datos = $conv->datos;

                // Seguridad: si no existe hora de envío, volvemos al menú
                if (!isset($datos['formulario_enviado_en'])) {
                    $conv->estado = 'MENU_PRINCIPAL';
                    break;
                }

                $enviadoEn = Carbon::parse($datos['formulario_enviado_en']);
                $minutos = $enviadoEn->diffInMinutes($ahora);

                // ============================================================
                // ⏱ MENSAJE INFORMATIVO A LOS 2 MINUTOS (solo una vez)
                // ============================================================

                if (
                    $minutos >= 2 &&
                    !isset($datos['mensaje_info_formulario_enviado'])
                ) {
                    $this->sendMessage(
                        $phone,
                        "📩 Si completó el formulario, dentro de los próximos 10 minutos recibirá un mail con la información asociada a su solicitud."
                    );
                    $this->sendMessage($phone, "¿Necesita otra consulta?");
                    $conv->estado = 'NECESITA_OTRA_CONSULTA';

                    $datos['mensaje_info_formulario_enviado'] = true;
                    $conv->datos = $datos;
                }
                // ============================================================
                // 1️⃣ PERMITIR SALIDAS EXPLÍCITAS (hola / cancelar)
                // ============================================================

                if (
                    str_contains($textoLimpio, 'hola') ||
                    str_contains($textoLimpio, 'buenas') ||
                    str_contains($textoLimpio, 'holi') ||
                    str_contains($textoLimpio, 'hello') ||
                    str_contains($textoLimpio, 'aloha') ||
                    str_contains($textoLimpio, 'aloja') ||
                    str_contains($textoLimpio, 'hi')
                ) {

                    $this->sendMessage($phone, "👋 ¡Hola nuevamente! Continuemos con su atención.");

                    $this->sendMessage(
                        $phone,
                        "Seleccione una opción. Recuerde que para EMERGENCIAS debe llamar al XXXXXXXX.\n\n" .
                            "1️⃣ Consultar caso existente\n" .
                            "2️⃣ Ingresar nuevo caso\n" .
                            "Debe ingresar el número de la opción"
                    );

                    $conv->estado = 'ESPERANDO_OPCION_MENU';
                    break;
                }

                if ($textoLimpio === 'cancelar') {

                    $this->sendMessage($phone, "✅ Se ha cancelado el ingreso del ticket.");
                    $this->sendMessage($phone, "¿Necesita otra consulta?");
                    $conv->estado = 'NECESITA_OTRA_CONSULTA';
                    break;
                }

                // ============================================================
                // 2️⃣ ANTES DE 5 MINUTOS → SILENCIO TOTAL
                // ============================================================

                if ($minutos < 5) {
                    // No respondemos nada
                    break;
                }

                // ============================================================
                // 3️⃣ DESPUÉS DE 5 MINUTOS → PREGUNTAR SOLO UNA VEZ
                // ============================================================

                if (!isset($datos['pregunta_post_formulario'])) {

                    $this->sendMessage(
                        $phone,
                        "⏳ Han pasado algunos minutos.\n¿Pudo completar el formulario?\n\nResponda *SI* o *NO*."
                    );

                    $datos['pregunta_post_formulario'] = true;
                    $conv->datos = $datos;
                    $conv->estado = 'CONFIRMAR_FORMULARIO';
                }

                break;

            // ============================================================
            // Confirmar Formulario
            // ============================================================
            case 'CONFIRMAR_FORMULARIO':

                if ($textoLimpio === 'si') {

                    $this->sendMessage(
                        $phone,
                        "✔ Gracias. Su solicitud fue registrada correctamente.\nUn ejecutivo revisará su caso."
                    );

                    $this->sendMessage($phone, "¿Necesita otra consulta?");
                    $conv->estado = 'NECESITA_OTRA_CONSULTA';
                } elseif ($textoLimpio === 'no') {

                    $this->sendMessage(
                        $phone,
                        "❌ Entendido.\nPuede intentar nuevamente cuando lo desee."
                    );

                    $this->sendMessage(
                        $phone,
                        "1️⃣ Volver a enviar formulario\n2️⃣ Volver al menú principal"
                    );

                    $conv->estado = 'REINTENTO_FORMULARIO';
                } else {
                    $this->sendMessage($phone, "❌ Disculpe, no entiendo esta respuesta. Por favor reintente");
                }

                break;


            // ============================================================
            // ESTADO FINAL DE CONVERSACIÓN
            // ============================================================
            case 'FIN':

                // Solo renacemos si dice hola (nuevo inicio real)
                if (
                    str_contains($textoLimpio, 'hola') ||
                    str_contains($textoLimpio, 'hi') ||
                    str_contains($textoLimpio, 'buenas') ||
                    str_contains($textoLimpio, 'hello') ||
                    str_contains($textoLimpio, 'holi')
                ) {

                    $this->sendMessage($phone, "👋 ¡Bienvenido a tu asistente virtual! \nRecuerde que para EMERGENCIAS debe llamar al XXXXXXXX.\n\n");

                    $this->sendMessage(
                        $phone,
                        "👋 Seleccione una opción:\n\n" .
                            "1️⃣ Consultar caso existente\n" .
                            "2️⃣ Ingresar nuevo caso\n" .
                            "Debe ingresar el número de la opcion"
                    );

                    $conv->estado = 'ESPERANDO_OPCION_MENU';
                }

                // Si escribe cualquier otra cosa → no respondemos nada.
                break;
        }

        // Actualizar última interacción
        $conv->last_interaction = $ahora;

        $conv->save();
        return response("EVENT_RECEIVED", 200);
    }





    // ============================================================
    //  ENVÍO DE MENSAJES
    // ============================================================
    private function sendMessage($to, $message)
    {
        $url = "https://graph.facebook.com/v22.0/{$this->phoneId}/messages";

        $response = Http::withToken($this->whatsappToken)->post($url, [
            "messaging_product" => "whatsapp",
            "to" => $to,
            "type" => "text",
            "text" => ["body" => $message]
        ]);

        Log::info("RESPUESTA WHATSAPP: " . $response->body());

        try {
            $conv = ConversacionUsuario::where('phone', $to)->first();

            Interacciones::create([
                'phone' => $to,
                'estado' => $conv?->estado,
                'mensaje_bot' => $message
            ]);
        } catch (\Throwable $e) {
            // ⚠️ Nunca romper el flujo del bot por un log
            Log::error('Error guardando historial bot: ' . $e->getMessage());
        }
    }

    private function consultarTicketsCRM(array $params)
    {
        $token = $this->obtenerTokenCRM();

        if (!$token) {
            return null;
        }

        Log::info('QUERY CRM', $params);

        $response = Http::withToken($token)
            ->get(
                'https://crm2new.upcom.cl/MantWalmartAPIQA/api/Ticket/listar',
                $params
            );

        // 🔄 Si el token expiró, lo limpiamos para forzar nuevo login
        if ($response->status() === 401) {
            Cache::forget('walmart_token');
            return null;
        }

        if (!$response->successful()) {
            Log::error('Error consulta CRM', [
                'params' => $params,
                'status' => $response->status(),
                'body'   => $response->body()
            ]);
            return null;
        }

        return $response->json();
    }


    private function formatearTicketCRM(array $ticket): string
    {
        // 🔹 Obtener ID del ticket desde el CRM (nombres reales + fallback)
        $idTicket = $ticket['iD_ATENCION']
            ?? $ticket['idTicket']
            ?? $ticket['ticketId']
            ?? $ticket['id']
            ?? null;

        if (!$idTicket) {
            Log::error('Respuesta CRM sin ID de atención', ['ticket' => $ticket]);
            return "⚠️ No se pudo obtener la información del ticket.";
        }

        // 🔹 Obtener estado del ticket
        $estado = $ticket['estadO_ATENCION']
            ?? $ticket['estado']
            ?? $ticket['estado_atencion']
            ?? 'Desconocido';

        return "📄 El caso *{$idTicket}* se encuentra en estado: *{$estado}*";
    }
}
