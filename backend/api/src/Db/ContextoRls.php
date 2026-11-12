<?php

declare(strict_types=1);

namespace App\Db;

use App\Config\Ajustes;
use App\Dominio\ErrorDominio;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Segunda capa del alcance por sede: Virtual Private Database de Oracle.
 *
 * Tras autenticar, se fija el usuario en el contexto de aplicación
 * (`INV_CTX.fijar_usuario`) y las políticas DBMS_RLS filtran en la base todas
 * las consultas de la petición. Sin usuario fijado (login, tareas internas)
 * el contexto está vacío: modo sistema, sin filtros.
 *
 * El contexto dura toda la sesión de Oracle, no la petición. Con FrankenPHP en
 * modo worker la conexión sobrevive entre peticiones, así que se limpia al
 * terminar cada una (reset()) y, por si una petición anterior abortó, también
 * al empezar la siguiente (limpiarSiQuedo()).
 */
final class ContextoRls implements ResetInterface
{
    private ?bool $disponible = null;
    private bool $fijado = false;

    public function __construct(
        private readonly Connection $conexion,
        private readonly Ajustes $ajustes,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Con DB_RLS_ENABLED, una petición autenticada nunca sigue sin la segunda capa: si el VPD no se
     * puede verificar, falla cerrada (503) en lugar de quedar en modo sistema (fase 7, hallazgo F-01).
     */
    public function fijarUsuario(string $usuarioHex): void
    {
        if (!$this->ajustes->rlsHabilitado) {
            return;
        }
        if (!$this->disponible()) {
            throw ErrorDominio::noDisponible('DATABASE_ERROR');
        }
        $this->conexion->executeStatement('BEGIN INV_CTX.fijar_usuario(:usuario); END;', ['usuario' => $usuarioHex]);
        $this->fijado = true;
    }

    public function limpiar(): void
    {
        if (!$this->conexion->isConnected()) {
            $this->fijado = false;

            return;
        }
        $this->conexion->executeStatement('BEGIN INV_CTX.limpiar; END;');
        $this->fijado = false;
    }

    /** Al empezar una petición: la conexión reutilizada no debe traer un usuario anterior. */
    public function limpiarSiQuedo(): void
    {
        if ($this->fijado) {
            $this->limpiar();
        }
    }

    public function reset(): void
    {
        try {
            $this->limpiarSiQuedo();
        } catch (\Throwable $e) {
            // Sin limpiar no se puede reutilizar la conexión con seguridad.
            $this->logger->error('rls.limpieza_fallida', ['error' => $e->getMessage()]);
            $this->conexion->close();
            $this->fijado = false;
        }
    }

    public function capaActiva(): bool
    {
        return $this->ajustes->rlsHabilitado && $this->disponible();
    }

    /**
     * Paquete de contexto válido y una política activa en cada tabla con
     * predicado (INV_VPD_PREDICADO). Solo se recuerda el resultado positivo: un
     * fallo (base reiniciándose, políticas que el migrador aún no repuso) se
     * vuelve a verificar en la petición siguiente, para no apagar la capa para
     * siempre en ese proceso.
     */
    public function disponible(): bool
    {
        if (true === $this->disponible) {
            return true;
        }
        [$ok, $motivo] = $this->verificar();
        if ($ok) {
            $this->disponible = true;
            $this->logger->info('rls.capa_bd_activa', ['esquema' => $this->ajustes->esquema()]);
        } else {
            $this->logger->error('rls.capa_bd_no_disponible', ['motivo' => $motivo]);
        }

        return $ok;
    }

    /** @return array{0: bool, 1: string} */
    public function verificar(): array
    {
        $esquema = "SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA')";
        try {
            $paquete = $this->conexion->fetchOne(
                "SELECT status FROM all_objects WHERE owner = {$esquema} AND object_name = 'INV_CTX' AND object_type = 'PACKAGE'",
            );
            if ('VALID' !== $paquete) {
                return [false, 'context_package_missing'];
            }
            $faltantes = $this->conexion->fetchFirstColumn(
                'SELECT DISTINCT p."VPD_Tabla" FROM "INV_VPD_PREDICADO" p '
                ."WHERE NOT EXISTS (SELECT 1 FROM all_policies a WHERE a.object_owner = {$esquema} "
                ."AND a.object_name = p.\"VPD_Tabla\" AND a.policy_name LIKE 'INV_RLS_%' AND a.enable = 'YES') "
                .'ORDER BY 1',
            );
        } catch (\Throwable $e) {
            return [false, 'check_failed:'.$e::class];
        }

        return [] === $faltantes ? [true, 'ok'] : [false, 'policies_missing:'.implode(',', $faltantes)];
    }
}
