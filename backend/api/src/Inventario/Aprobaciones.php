<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Http\Entrada;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Resolución de las solicitudes (App\Inventario\Solicitudes):
 *   PENDIENTE → aprobar (ejecuta la operación tal como se pidió, revalidando las
 *   reglas) → APROBADA | rechazar (con motivo) → RECHAZADA | anular (solo quien
 *   la pidió) → ANULADA.
 * Nadie aprueba su propia solicitud (aquí y con un CHECK en la base). Si al
 * aprobar la operación ya no es válida, no se ejecuta nada y sigue pendiente.
 */
final readonly class Aprobaciones
{
    public function __construct(
        private Connection $conexion,
        private Alcance $alcance,
        private GobiernoRepositorio $gobierno,
        private Solicitudes $solicitudes,
        private Activos $activos,
        private Consumibles $consumibles,
        private Reclasificacion $reclasificacion,
        private Faltantes $faltantes,
        private \App\GoodsReceipt\Application\GoodsReceiptService $goodsReceipts,
        private Transaccion $transaccion,
    ) {
    }

    /**
     * Atajo usado por /consumibles/{id}/ajuste.
     *
     * @return array<string, mixed>
     */
    public function solicitarAjuste(int $consumible, int $real, string $motivo, string $usuario, ?string $ip): array
    {
        return $this->solicitudes->solicitarAjuste($consumible, $real, $motivo, $usuario, $ip);
    }

    /** @return array<string, mixed> bloqueada y PENDIENTE */
    private function pendiente(int $id): array
    {
        $a = $this->solicitudes->fila($id, true);
        if (null === $a || !$this->visible($a)) {
            throw ErrorDominio::noEncontrado('APPROVAL_NOT_FOUND');
        }
        if ('PENDIENTE' !== $a['APR_Estado']) {
            throw ErrorDominio::conflicto("APPROVAL_ALREADY_RESOLVED:{$a['APR_Estado']}");
        }

        return $a;
    }

    /** @param array<string, mixed> $a */
    private function visible(array $a): bool
    {
        $alcance = $this->alcance->actual();

        return null === $alcance || $alcance->global || (string) $a['APR_Solicitante'] === $alcance->usuarioId
            || $alcance->permite(null === $a['SED_Sede'] ? null : (int) $a['SED_Sede']);
    }

    /**
     * Pendiente, de otra persona y de una sede del alcance del aprobador.
     *
     * @return array<string, mixed>
     */
    private function resoluble(int $id, string $aprobador): array
    {
        $a = $this->pendiente($id);
        if ((string) $a['APR_Solicitante'] === $aprobador) {
            throw ErrorDominio::prohibido('CANNOT_APPROVE_OWN_REQUEST');
        }
        $this->alcance->exigirPropio(null === $a['SED_Sede'] ? null : (int) $a['SED_Sede']);

        return $a;
    }

    /** @return array<string, mixed> */
    public function aprobar(int $id, string $aprobador, ?string $comentario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $aprobador, $comentario, $ip): array {
            $a = $this->resoluble($id, $aprobador);
            $solicitante = (string) $a['APR_Solicitante'];
            $traza = ['aprobacion' => (int) $a['APR_Aprobacion'], 'solicitado_por' => Ident::aTexto($solicitante), 'aprobado_por' => Ident::aTexto($aprobador)];
            $payload = json_decode((string) $a['APR_Payload'], true) ?: [];
            switch ($a['APR_Tipo']) {
                case 'BAJA':
                    $this->activos->darDeBaja((string) $a['ACT_Activo'], Esquemas::baja(Entrada::de($payload)), $solicitante, $traza);
                    $this->faltantes->alResolverBaja((int) $a['APR_Aprobacion'], true);
                    break;
                case 'AJUSTE_CONSUMIBLE':
                    $this->consumibles->ajustar((int) $a['CON_Consumible'], (int) $payload['CON_Stock_Real'], (string) $payload['MOC_Motivo'], $solicitante, $ip,
                        $traza, isset($payload['stock_antes']) ? (int) $payload['stock_antes'] : null);
                    break;
                case 'RECEPCION_DIFERENCIA':
                    $this->goodsReceipts->approveDifference((int) $payload['goods_receipt'], $solicitante, $ip, (int) $a['APR_Aprobacion']);
                    break;
                case 'RECLASIFICACION':
                    $traza['resultado'] = $this->reclasificacion->ejecutar($payload, $solicitante, $ip, $traza);
                    break;
            }
            $this->conexion->executeStatement("UPDATE \"INV_APROBACION\" SET \"APR_Estado\" = 'APROBADA', \"APR_Aprobador\" = :ap, \"APR_Fecha_Resolucion\" = :f, "
                .'"APR_Comentario" = :c WHERE "APR_Aprobacion" = :id', ['ap' => $aprobador, 'f' => Reloj::aBd(Reloj::ahora()),
                    'c' => null === $comentario || '' === $comentario ? null : $comentario, 'id' => $id]);
            $this->gobierno->registrarAuditoria('APPROVAL_APPROVE', 'INV_APROBACION', ['id' => $id, 'tipo' => $a['APR_Tipo'], 'resumen' => $a['APR_Resumen'],
                'solicitante' => Ident::aTexto($solicitante), 'comentario' => $comentario], $aprobador, $ip,
                sedeId: null === $a['SED_Sede'] ? null : (int) $a['SED_Sede']);

            return $this->solicitudes->aApi($this->solicitudes->fila($id) ?? $a);
        });
    }

    /** @return array<string, mixed> */
    public function rechazar(int $id, string $aprobador, string $comentario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $aprobador, $comentario, $ip): array {
            $a = $this->resoluble($id, $aprobador);
            $this->conexion->executeStatement("UPDATE \"INV_APROBACION\" SET \"APR_Estado\" = 'RECHAZADA', \"APR_Aprobador\" = :ap, \"APR_Fecha_Resolucion\" = :f, "
                .'"APR_Comentario" = :c WHERE "APR_Aprobacion" = :id', ['ap' => $aprobador, 'f' => Reloj::aBd(Reloj::ahora()), 'c' => $comentario, 'id' => $id]);
            if ('BAJA' === $a['APR_Tipo']) {
                $this->faltantes->alResolverBaja($id, false);
            }
            if ('RECEPCION_DIFERENCIA' === $a['APR_Tipo']) {
                $this->goodsReceipts->rejectDifference((int) (json_decode((string) $a['APR_Payload'], true)['goods_receipt'] ?? 0), $aprobador, $comentario);
            }
            $this->gobierno->registrarAuditoria('APPROVAL_REJECT', 'INV_APROBACION', ['id' => $id, 'tipo' => $a['APR_Tipo'], 'resumen' => $a['APR_Resumen'],
                'comentario' => $comentario], $aprobador, $ip, sedeId: null === $a['SED_Sede'] ? null : (int) $a['SED_Sede']);

            return $this->solicitudes->aApi($this->solicitudes->fila($id) ?? $a);
        });
    }

    /** @return array<string, mixed> */
    public function anular(int $id, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): array {
            $a = $this->pendiente($id);
            if ((string) $a['APR_Solicitante'] !== $usuario) {
                throw ErrorDominio::prohibido('ONLY_REQUESTER_CAN_CANCEL');
            }
            $this->conexion->executeStatement("UPDATE \"INV_APROBACION\" SET \"APR_Estado\" = 'ANULADA', \"APR_Fecha_Resolucion\" = :f WHERE \"APR_Aprobacion\" = :id",
                ['f' => Reloj::aBd(Reloj::ahora()), 'id' => $id]);
            if ('BAJA' === $a['APR_Tipo']) {
                $this->faltantes->alResolverBaja($id, false);
            }
            $this->gobierno->registrarAuditoria('APPROVAL_CANCEL', 'INV_APROBACION', ['id' => $id, 'tipo' => $a['APR_Tipo']], $usuario, $ip,
                sedeId: null === $a['SED_Sede'] ? null : (int) $a['SED_Sede']);

            return $this->solicitudes->aApi($this->solicitudes->fila($id) ?? $a);
        });
    }
}
