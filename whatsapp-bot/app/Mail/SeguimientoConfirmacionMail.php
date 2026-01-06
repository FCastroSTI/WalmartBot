<?php

namespace App\Mail;

use App\Models\Seguimiento;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SeguimientoConfirmacionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Seguimiento $seguimiento,
        public string $mensajeWhatsapp
    ) {}

    public function build()
    {
        return $this->subject('Seguimiento reagendado para el ticket ' . $this->seguimiento->id_atencion)
            ->view('email.seguimiento_confirmacion')
            ->with([
                'id_atencion'      => $this->seguimiento->id_atencion,
                'nombre_proveedor' => $this->seguimiento->nombre_proveedor,
                'rut_proveedor'    => $this->seguimiento->rut_proveedor,
                'fecha_hora'       => $this->seguimiento->ejecutar_desde_at
                    ? $this->seguimiento->ejecutar_desde_at->format('d-m-Y H:i')
                    : 'Fecha no definida',
                'mensaje'          => $this->mensajeWhatsapp,
            ]);
    }
}
