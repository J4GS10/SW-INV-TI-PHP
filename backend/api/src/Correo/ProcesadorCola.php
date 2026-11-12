<?php

declare(strict_types=1);

namespace App\Correo;

use App\Correo\Infrastructure\ColaCorreos;
use App\Config\Ajustes;
use App\Integracion\FuenteIntegracion;
use App\Notificaciones\Destinatario;
use App\Notificaciones\ResolutorDestinatarios;
use Psr\Log\LoggerInterface;

/**
 * Worker de la cola de correos (mismo comportamiento que el backend anterior):
 *
 *   reclamo (lote de 20, arriendo de 5 min) → por cada correo:
 *     · en el primer intento resuelve los destinatarios (reglas, jefes, grupos AD) y los fija;
 *     · un envío: OK → ENVIADO;
 *     · credenciales rechazadas → no consume intento, espera la pausa del cortacircuitos;
 *     · otro error → PENDIENTE con espera creciente (1 min … 6 h); agotados los intentos → FALLIDO.
 *
 * Entrega «al menos una vez»: solo si el proceso muere entre la aceptación del
 * servidor de correo y el UPDATE a ENVIADO, otro worker lo reenviará al vencer el arriendo.
 */
final readonly class ProcesadorCola
{
    public const int LOTE = 20;
    public const int ARRIENDO_SEGUNDOS = 300;
    public const array ESPERAS_SEGUNDOS = [60, 300, 900, 3600, 3 * 3600, 6 * 3600];

    public function __construct(
        private ColaCorreos $cola,
        private EntregaCorreo $entrega,
        private FuenteIntegracion $fuente,
        private Cortacircuitos $cortacircuitos,
        private ResolutorDestinatarios $resolutor,
        private Ajustes $ajustes,
        private LoggerInterface $logger,
    ) {
    }

    /** Drena la cola mientras haya lotes completos y el correo esté configurado. Devuelve cuántos intentó. */
    public function drenar(): int
    {
        $total = 0;
        do {
            if (!$this->fuente->obtener()->correo->lista()) {
                break;
            }
            $lote = $this->procesarVencidos();
            $total += $lote;
        } while (self::LOTE === $lote);

        return $total;
    }

    /** Un lote de correos vencidos. Devuelve cuántos reclamó. */
    public function procesarVencidos(): int
    {
        if (null !== $this->cortacircuitos->pausadoHasta('smtp')) {
            return 0; // autenticación en pausa: los correos esperan en la cola
        }
        $ids = $this->cola->reclamar(self::LOTE, self::ARRIENDO_SEGUNDOS, $this->ajustes->outboxMaxIntentos);
        foreach ($ids as $id) {
            $correo = $this->cola->cargar($id);
            if (null !== $correo && CorreoEnCola::PENDIENTE === $correo->estado) {
                $this->entregar($correo);
            }
        }

        return \count($ids);
    }

    private function entregar(CorreoEnCola $c): void
    {
        try {
            if ($c->resolver) {
                $c->fijarDestinatarios(array_map(
                    static fn (Destinatario $d): string => $d->correo,
                    $this->resolutor->resolver($c->plantilla, $c->para, $c->afectados, $c->copiarAdmins),
                ));
            }
            if ([] === $c->para) {
                $c->marcarEnviado('SIN_DESTINATARIOS');
                $this->cola->guardar($c);

                return;
            }
            $this->entrega->entregar($this->fuente->obtener()->correo, $c->para, $c->asunto, $c->html, $c->responderA);
        } catch (ErrorEnvioCorreo $e) {
            if ($e->esDeAutenticacion()) {
                $c->retener("{$e->codigo}: ".mb_substr($e->detalle, 0, 300), Cortacircuitos::MINUTOS);
                $this->cola->guardar($c);

                return;
            }
            $this->fallar($c, $e);

            return;
        } catch (\Throwable $e) {
            $this->fallar($c, $e);

            return;
        }
        $c->marcarEnviado();
        $this->cola->guardar($c);
    }

    private function fallar(CorreoEnCola $c, \Throwable $e): void
    {
        $error = (new \ReflectionClass($e))->getShortName().': '.mb_substr($e->getMessage(), 0, 400);
        if ($c->resolver) {
            // Falló la resolución (base o AD): se reintenta con los destinatarios históricos para no perder el aviso.
            $c->fijarDestinatarios(array_values(array_unique(array_filter(
                [...$c->para, ...($c->copiarAdmins ? $this->ajustes->correosAdmin : [])],
                static fn (string $x): bool => '' !== $x,
            ))));
        }
        if ($c->fallar($error, $this->ajustes->outboxMaxIntentos, self::ESPERAS_SEGUNDOS)) {
            $this->logger->error('email_outbox.failed', ['id' => $c->id, 'plantilla' => $c->plantilla, 'error' => $error]);
        } else {
            $this->logger->warning('email_outbox.retry', ['id' => $c->id, 'intento' => $c->intentos, 'error' => $error]);
        }
        $this->cola->guardar($c);
    }
}
