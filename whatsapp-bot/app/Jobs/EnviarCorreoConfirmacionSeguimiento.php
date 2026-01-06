<?php

namespace App\Jobs;

use App\Mail\SeguimientoConfirmacionMail;
use App\Models\Seguimiento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EnviarCorreoConfirmacionSeguimiento implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $seguimientoId,
        public string $mensajeWhatsapp
    ) {}

    public function handle(): void
    {
        $s = Seguimiento::find($this->seguimientoId);

        if (!$s) {
            Log::warning("Seguimiento no encontrado para correo. ID {$this->seguimientoId}");
            return;
        }

        $to = env('SEGUIMIENTO_MAIL_TO');
        if (!$to) {
            Log::error('SEGUIMIENTO_MAIL_TO no configurado en .env');
            return;
        }

        $ccRaw = env('SEGUIMIENTO_MAIL_CC', '');
        $cc = collect(explode(',', $ccRaw))
            ->map(fn($x) => trim($x))
            ->filter()
            ->values()
            ->all();

        $mail = new SeguimientoConfirmacionMail($s, $this->mensajeWhatsapp);

        $mailer = Mail::to($to);
        if (!empty($cc)) {
            $mailer->cc($cc);
        }

        $mailer->queue($mail);

        Log::info('✅ Correo de confirmación enviado', [
            'seguimiento_id' => $s->id,
            'to' => $to,
            'cc' => $cc,
        ]);
    }
}
