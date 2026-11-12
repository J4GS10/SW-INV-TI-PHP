<?php

declare(strict_types=1);

namespace App\Directorio;

use App\Config\Ajustes;
use App\Correo\Notificador;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Dominio\ErrorDominio;
use App\Integracion\ConfigAd;
use App\Integracion\FuenteIntegracion;
use App\Organizacion\Identidades;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Sincronización de personas desde Active Directory, fuente de verdad de las
 * personas vinculadas (PER_AD_GUID): nombres, correo, teléfono, departamento,
 * cargo y jefe se sobrescriben en cada pasada.
 *
 * - Vinculación por objectGUID; si la persona aún no está vinculada, por correo.
 * - Alta: cuentas habilitadas sin persona → persona nueva; departamento y cargo
 *   se crean si no existen (por nombre, sin distinguir mayúsculas).
 * - Baja: cuentas deshabilitadas, o vinculadas que ya no aparecen → la persona y
 *   sus cuentas se desactivan SOLO si no tiene activos asignados (si los tiene,
 *   queda pendiente y se avisa: la devolución se hace con offboarding). Nunca se
 *   deja el sistema sin un SUPER_ADMIN activo; una baja masiva (filtro mal
 *   configurado, directorio vacío) se retiene hasta confirmarla.
 * - Reactivación: cuenta habilitada de nuevo → persona activa (su usuario no).
 * - Simulación (dry_run): calcula todo y revierte.
 */
final readonly class SincronizacionDirectorio
{
    public const string ACCION_BITACORA = 'AD_SYNC';

    public function __construct(
        private Connection $conexion,
        private FuenteIntegracion $fuente,
        private FabricaDirectorio $directorios,
        private Alcance $alcance,
        private Identidades $identidades,
        private GobiernoRepositorio $gobierno,
        private Notificador $notificador,
        private Ajustes $ajustes,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> resultado público */
    public function sincronizar(bool $simulacion = false, ?string $usuarioHex = null, ?string $ip = null, bool $forzarBajas = false): array
    {
        $config = $this->fuente->obtener()->ad;
        $directorio = $this->directorios->activo() ?? throw ErrorDominio::invalido('AD_DISABLED');
        try {
            $usuarios = $directorio->usuarios();
        } catch (ErrorAutenticacionDirectorio $e) {
            $this->logger->warning('ad_sync.auth_error', ['error' => $e->getMessage()]);
            throw ErrorDominio::servicioExterno('AD_AUTH_FAILED');
        } catch (ErrorDirectorio $e) {
            $this->logger->warning('ad_sync.directory_error', ['error' => $e->getMessage()]);
            throw ErrorDominio::servicioExterno('AD_UNAVAILABLE');
        }

        $r = new ResultadoSincronizacion($simulacion, \count($usuarios));
        $this->conexion->beginTransaction();
        try {
            $this->aplicar($usuarios, $config, $r, $forzarBajas);
            if ($simulacion) {
                $this->conexion->rollBack();

                return $r->aApi();
            }
            $this->gobierno->registrarAuditoria(self::ACCION_BITACORA, 'INV_PERSONA', $r->aBitacora(), $usuarioHex, $ip);
            $this->conexion->commit();
        } catch (\Throwable $e) {
            if ($this->conexion->isTransactionActive()) {
                $this->conexion->rollBack();
            }
            throw $e;
        }
        $this->logger->info('ad_sync.done', array_filter($r->aBitacora(), 'is_int'));
        if ([] !== $r->pendientesConActivos) {
            $this->notificador->enviar('ad_sync_pendientes', ['total' => \count($r->pendientesConActivos), 'items' => $r->pendientesConActivos]);
        }

        return $r->aApi();
    }

    /**
     * Resultado de la última sincronización registrada (forma pública), o null.
     *
     * @return array<string, mixed>|null
     */
    public function ultima(): ?array
    {
        $snapshot = $this->conexion->fetchOne(
            'SELECT "AUD_Snapshot_JSON" FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Accion" = :a ORDER BY "AUD_Fecha_Hora" DESC FETCH FIRST 1 ROWS ONLY',
            ['a' => self::ACCION_BITACORA],
        );
        $datos = false === $snapshot || null === $snapshot ? null : json_decode((string) $snapshot, true);

        return \is_array($datos) ? ResultadoSincronizacion::contrato($datos) : null;
    }

    /** @param list<UsuarioDirectorio> $usuarios */
    private function aplicar(array $usuarios, ConfigAd $config, ResultadoSincronizacion $r, bool $forzarBajas): void
    {
        [$filtro, $parametros] = $this->alcance->lectura('INV_PERSONA', 'p');
        $personas = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT p.* FROM "INV_PERSONA" p WHERE '.$filtro, $parametros) as $fila) {
            $personas[(string) $fila['PER_Persona']] = $fila;
        }
        $porGuid = [];
        $porCorreo = [];
        foreach ($personas as $id => $p) {
            if (null !== $p['PER_AD_GUID']) {
                $porGuid[(string) $p['PER_AD_GUID']] = $id;
            }
            $porCorreo[strtolower((string) $p['PER_Email_Corporativo'])] = $id;
        }
        $catalogos = new CatalogosPersona($this->conexion);

        $guidsVistos = [];
        $correosVistos = [];
        $porDn = [];
        $jefes = [];
        $aDesactivar = [];
        foreach ($usuarios as $u) {
            $correo = null === $u->correo ? '' : strtolower(trim($u->correo));
            if ('' === $correo) {
                $r->error(null, 'SIN_EMAIL: '.($u->usuario ?? $u->dn));
                continue;
            }
            $u = $u->conCorreo($correo);
            if (isset($correosVistos[$correo])) {
                $r->error($correo, 'EMAIL_DUPLICADO_EN_AD');
                continue;
            }
            $correosVistos[$correo] = true;
            $guidsVistos[$u->guid] = true;

            $id = $porGuid[$u->guid] ?? null;
            if (null === $id) {
                $id = $porCorreo[$correo] ?? null;
                if (null !== $id && null !== $personas[$id]['PER_AD_GUID'] && $personas[$id]['PER_AD_GUID'] !== $u->guid) {
                    $r->error($correo, 'EMAIL_VINCULADO_A_OTRA_CUENTA_AD');
                    continue;
                }
            } elseif (isset($porCorreo[$correo]) && $porCorreo[$correo] !== $id) {
                $r->error($correo, 'EMAIL_EN_USO_POR_OTRA_PERSONA');
                continue;
            }

            if (null === $id) {
                if (!$u->habilitado) {
                    continue; // no se crean personas para cuentas deshabilitadas
                }
                $id = Ident::nuevo();
                $personas[$id] = $this->crear($id, $u, $config, $catalogos);
                ++$r->creados;
            } else {
                $vinculada = null !== $personas[$id]['PER_AD_GUID'];
                $correoAnterior = strtolower((string) $personas[$id]['PER_Email_Corporativo']);
                [$personas[$id], $cambio] = $this->actualizar($personas[$id], $u, $config, $catalogos);
                if (!$vinculada) {
                    ++$r->vinculados;
                } elseif ($cambio) {
                    ++$r->actualizados;
                }
                if ($correoAnterior !== $correo) {
                    unset($porCorreo[$correoAnterior]);
                }
                if ($u->habilitado && !$this->activa($personas[$id]) && $vinculada) {
                    $personas[$id]['PER_Estado'] = 1;
                    $this->conexion->executeStatement('UPDATE "INV_PERSONA" SET "PER_Estado" = 1 WHERE "PER_Persona" = :id', ['id' => $id]);
                    ++$r->reactivados;
                }
            }
            $porCorreo[$correo] = $id;
            $porDn[mb_strtolower($u->dn)] = $id;
            $jefes[] = [$id, $u->jefeDn];
            if (!$u->habilitado && $this->activa($personas[$id])) {
                $aDesactivar[$id] = true;
            }
        }

        if ($config->desactivar) {
            // Vinculadas que ya no aparecen en el directorio (eliminadas o fuera del filtro).
            foreach ($porGuid as $guid => $id) {
                if (!isset($guidsVistos[$guid]) && $this->activa($personas[$id])) {
                    $aDesactivar[$id] = true;
                }
            }
            $vinculadas = \count($porGuid);
            $limite = max(1, intdiv($vinculadas * $this->ajustes->adMaxBajasPorcentaje, 100));
            $masiva = $vinculadas > 0 && ([] === $usuarios || \count($aDesactivar) > $limite);
            if ($masiva && !$forzarBajas) {
                $r->bajasRetenidas = \count($aDesactivar);
                $r->advertencia = ResultadoSincronizacion::BAJAS_MASIVAS_RETENIDAS;
                $this->logger->warning('ad_sync.mass_deactivation_blocked', ['bajas' => \count($aDesactivar), 'vinculadas' => $vinculadas, 'total_ad' => \count($usuarios)]);
                $aDesactivar = [];
            }
            $this->desactivar(array_map('strval', array_keys($aDesactivar)), $personas, $r);
        }

        foreach ($jefes as [$id, $jefeDn]) {
            $jefe = null === $jefeDn ? null : ($porDn[mb_strtolower($jefeDn)] ?? null);
            $jefe = $jefe === $id ? null : $jefe;
            if ($personas[$id]['PER_Jefe'] !== $jefe) {
                $personas[$id]['PER_Jefe'] = $jefe;
                $this->conexion->executeStatement('UPDATE "INV_PERSONA" SET "PER_Jefe" = :jefe WHERE "PER_Persona" = :id', ['jefe' => $jefe, 'id' => $id]);
                ++$r->jefesAsignados;
            }
        }
    }

    /**
     * @param list<string>                        $ids
     * @param array<string, array<string, mixed>> $personas
     */
    private function desactivar(array $ids, array &$personas, ResultadoSincronizacion $r): void
    {
        // El directorio no puede dejar el sistema sin un SUPER_ADMIN activo
        // (p. ej. la cuenta de emergencia vinculada por correo y luego eliminada del AD).
        $superAdmins = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_USUARIO" WHERE "USU_Rol" = :r AND "USU_Estado" = 1', ['r' => Roles::SUPER_ADMIN]);
        foreach ($ids as $id) {
            $p = $personas[$id];
            $resumen = ['persona_id' => Ident::aTexto($id), 'nombre' => $p['PER_Primer_Nombre'].' '.$p['PER_Primer_Apellido'], 'email' => (string) $p['PER_Email_Corporativo']];
            // Decide con todas las custodias: quien sincroniza puede ser un rol sin acceso a los datos de negocio.
            $activos = (int) $this->alcance->elevado(fn (): mixed => $this->conexion->fetchOne(
                'SELECT COUNT(*) FROM "INV_MOVIMIENTO" WHERE "PER_Persona" = :p AND "MOV_Fecha_Devolucion" IS NULL', ['p' => $id]));
            if ($activos > 0) {
                $r->pendientesConActivos[] = [...$resumen, 'activos' => $activos];
                continue;
            }
            $cuentas = $this->conexion->fetchAllAssociative(
                'SELECT "USU_Usuario", "USU_Rol" FROM "INV_USUARIO" WHERE "PER_Persona" = :p AND "USU_Estado" = 1', ['p' => $id]);
            $propios = \count(array_filter($cuentas, static fn (array $c): bool => Roles::SUPER_ADMIN === $c['USU_Rol']));
            if ($propios > 0 && $superAdmins - $propios < 1) {
                $r->protegidos[] = [...$resumen, 'motivo' => 'LAST_SUPER_ADMIN'];
                $this->logger->warning('ad_sync.last_super_admin_kept', ['persona' => $resumen['persona_id']]);
                continue;
            }
            $superAdmins -= $propios;
            $personas[$id]['PER_Estado'] = 0;
            $this->conexion->executeStatement('UPDATE "INV_PERSONA" SET "PER_Estado" = 0, "UPDATED_AT" = :ahora WHERE "PER_Persona" = :id',
                ['ahora' => Reloj::aBd(Reloj::ahora()), 'id' => $id]);
            // Columna de identidad protegida: se escribe sin el filtro de alcance.
            $this->alcance->elevado(fn (): int => $this->conexion->executeStatement(
                'UPDATE "INV_USUARIO" SET "USU_Estado" = 0 WHERE "PER_Persona" = :p', ['p' => $id]));
            foreach ($cuentas as $cuenta) {
                $this->identidades->revocarAcceso((string) $cuenta['USU_Usuario']);
            }
            ++$r->desactivados;
        }
    }

    /**
     * Valores que el directorio impone a una persona.
     *
     * @return array<string, mixed>
     */
    private function valores(UsuarioDirectorio $u, ConfigAd $config, CatalogosPersona $catalogos): array
    {
        [$nombre, $segundoNombre] = NombrePersona::nombres($u->nombres ?? $u->usuario);
        [$apellido, $segundoApellido] = NombrePersona::apellidos($u->apellidos ?? '-');

        return [
            'PER_Primer_Nombre' => $nombre ?? '-',
            'PER_Segundo_Nombre' => $segundoNombre,
            'PER_Primer_Apellido' => $apellido ?? '-',
            'PER_Segundo_Apellido' => $segundoApellido,
            'PER_Email_Corporativo' => $u->correo,
            'PER_Telefono' => null === $u->telefono || '' === $u->telefono ? null : mb_substr($u->telefono, 0, 20),
            'DEP_Departamento' => $catalogos->departamento($u->departamento ?? $config->departamentoDefecto),
            'CAR_Cargo' => $catalogos->cargo($u->cargo ?? $config->cargoDefecto),
            'PER_AD_GUID' => $u->guid,
            'PER_AD_DN' => mb_substr($u->dn, 0, 500),
        ];
    }

    /** @return array<string, mixed> fila de la persona creada */
    private function crear(string $id, UsuarioDirectorio $u, ConfigAd $config, CatalogosPersona $catalogos): array
    {
        $fila = ['PER_Persona' => $id, ...$this->valores($u, $config, $catalogos), 'PER_Estado' => 1,
            'PER_AD_Sincronizado_En' => Reloj::aBd(Reloj::ahora()), 'PER_Jefe' => null];
        $columnas = array_keys($fila);
        $this->conexion->executeStatement(
            'INSERT INTO "INV_PERSONA" ("'.implode('", "', $columnas).'") VALUES (:'.implode(', :', $columnas).')', $fila);

        return $fila;
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{0: array<string, mixed>, 1: bool} [fila actualizada, ¿cambió algún dato?]
     */
    private function actualizar(array $p, UsuarioDirectorio $u, ConfigAd $config, CatalogosPersona $catalogos): array
    {
        $valores = $this->valores($u, $config, $catalogos);
        $cambio = false;
        foreach ($valores as $columna => $valor) {
            if ((string) $p[$columna] !== (string) $valor || (null === $p[$columna]) !== (null === $valor)) {
                $cambio = true;
            }
            $p[$columna] = $valor;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "\"{$c}\" = :{$c}", array_keys($valores)));
        $this->conexion->executeStatement(
            "UPDATE \"INV_PERSONA\" SET {$sets}, \"PER_AD_Sincronizado_En\" = :sincronizado, \"UPDATED_AT\" = :sincronizado WHERE \"PER_Persona\" = :id",
            [...$valores, 'sincronizado' => Reloj::aBd(Reloj::ahora()), 'id' => $p['PER_Persona']],
        );

        return [$p, $cambio];
    }

    /** @param array<string, mixed> $p */
    private function activa(array $p): bool
    {
        return 1 === (int) $p['PER_Estado'];
    }
}
