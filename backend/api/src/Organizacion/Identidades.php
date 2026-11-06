<?php

declare(strict_types=1);

namespace App\Organizacion;

use App\Autenticacion\CambioContrasena;
use App\Autenticacion\Sesiones;
use App\Config\Ajustes;
use App\Correo\Notificador;
use App\Crud\Esquema;
use App\Db\ContextoRls;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Dominio\Usuario;
use App\Http\ErrorApi;
use App\Repositorio\CredencialesRepositorio;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\SesionRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\Alcance;
use App\Seguridad\BloqueoCuenta;
use App\Seguridad\Contrasenas;
use App\Seguridad\PoliticaContrasenas;
use App\Seguridad\PoliticaSesion;
use Doctrine\DBAL\Connection;

/**
 * Gestión de identidades (SUPER_ADMIN y ADMIN_SEGURIDAD), con las reglas del
 * backend anterior (app/core/roles.py):
 * - SUPER_ADMIN gestiona cualquier cuenta; ADMIN_SEGURIDAD solo consulta y cierra sesiones de las operativas.
 * - Los roles protegidos y el alcance global solo los otorga un SUPER_ADMIN.
 * - Nadie gestiona su propia cuenta desde IAM (para eso está /me).
 * - Nunca se desactiva ni se degrada al último SUPER_ADMIN activo.
 * - Las columnas de identidad (rol, alcance, estado, sedes) se escriben en modo
 *   elevado: el rol restringido de la base no puede tocarlas.
 */
final readonly class Identidades
{
    public function __construct(
        private Connection $conexion,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
        private CredencialesRepositorio $credenciales,
        private SesionRepositorio $sesiones,
        private Sesiones $cierre,
        private PoliticaContrasenas $politica,
        private PoliticaSesion $politicaSesion,
        private CambioContrasena $cambio,
        private Notificador $notificador,
        private Alcance $alcance,
        private ContextoRls $rls,
        private Ajustes $ajustes,
        private BloqueoCuenta $bloqueo,
    ) {
    }

    // ---- Lectura -----------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return array_map(fn (string $id): array => (array) $this->respuesta($id),
            array_map('strval', $this->conexion->fetchFirstColumn('SELECT "USU_Usuario" FROM "INV_USUARIO" ORDER BY "USU_Creado_En", "USU_Username"')));
    }

    /** @return array<string, mixed>|null representación de la API (UsuarioResponse) */
    public function respuesta(string $idHex): ?array
    {
        $u = $this->conexion->fetchAssociative('SELECT * FROM "INV_USUARIO" WHERE "USU_Usuario" = :id', ['id' => $idHex]);
        if (false === $u) {
            return null;
        }
        $persona = $this->conexion->fetchAssociative('SELECT '.Entidades::persona()->columnasSelect().' FROM "INV_PERSONA" t WHERE t."PER_Persona" = :p', ['p' => $u['PER_Persona']]);
        $sedes = $this->conexion->fetchAllAssociative(
            'SELECT s."SED_Sede", s."SED_Nombre" FROM "INV_SEDE" s JOIN "INV_USUARIO_SEDE" us ON us."SED_Sede" = s."SED_Sede" '
            .'WHERE us."USU_Usuario" = :id ORDER BY s."SED_Nombre"', ['id' => $idHex]);
        $bool = static fn (mixed $v): bool => 1 === (int) $v;
        $fecha = static fn (mixed $v): ?string => Reloj::iso(Reloj::desdeBd(null === $v ? null : (string) $v));
        $rol = (string) $u['USU_Rol'];
        $sso = $bool($u['USU_SSO_Habilitado']);

        return [
            'USU_Usuario' => Ident::aTexto((string) $u['USU_Usuario']),
            'USU_Username' => $u['USU_Username'],
            'USU_Rol' => $rol,
            'USU_Estado' => $bool($u['USU_Estado']),
            'USU_SSO_Habilitado' => $sso,
            'USU_SSO_Provider' => $u['USU_SSO_Provider'],
            'USU_2FA_Habilitado' => $bool($u['USU_2FA_Habilitado']),
            'USU_2FA_Metodo' => $u['USU_2FA_Metodo'],
            'USU_Ultimo_Login' => $fecha($u['USU_Ultimo_Login']),
            'USU_Debe_Cambiar_Password' => $bool($u['USU_Debe_Cambiar_Password']),
            'USU_Bloqueado_Hasta' => $fecha($u['USU_Bloqueado_Hasta']),
            'USU_Intentos_Fallidos' => (int) $u['USU_Intentos_Fallidos'],
            'USU_Password_Cambiada_En' => $fecha($u['USU_Password_Cambiada_En']),
            'mfa_requerido' => $this->mfaRequerido($rol, $sso),
            'USU_Alcance_Global' => $bool($u['USU_Alcance_Global']),
            'alcance_global_efectivo' => $bool($u['USU_Alcance_Global']) || \in_array($rol, Roles::SIEMPRE_GLOBALES, true),
            'sedes' => array_map(static fn (array $s): array => ['SED_Sede' => (int) $s['SED_Sede'], 'SED_Nombre' => $s['SED_Nombre']], $sedes),
            'USU_Creado_En' => $fecha($u['USU_Creado_En']),
            'persona' => false === $persona ? null : Esquema::aApi(Entidades::persona(), $persona),
        ];
    }

    /** El rol exige MFA (las cuentas SSO lo delegan en el proveedor solo si SSO_MFA_LOCAL=false). */
    public function mfaRequerido(string $rol, bool $sso): bool
    {
        return $this->politicaSesion->rolExigeMfa($rol) && !($sso && !$this->ajustes->ssoMfaLocal);
    }

    // ---- Alta y edición ----------------------------------------------------

    /**
     * @param array{USU_Username: string, USU_Password: ?string, USU_Rol: string, USU_SSO_Habilitado: bool,
     *     USU_SSO_Provider: ?string, PER_Persona: string, USU_Alcance_Global: bool, sedes: list<int>} $d
     *
     * @return array<string, mixed>
     */
    public function crear(array $d, Usuario $solicitante, callable $auditar): array
    {
        if (!Roles::puedeAdministrar($solicitante->rol, $d['USU_Rol'])) {
            throw ErrorApi::prohibido('ONLY_SUPER_ADMIN_CAN_CREATE_ADMINS');
        }
        if (null === $d['USU_Password'] && !$d['USU_SSO_Habilitado']) {
            throw new ErrorApi(400, 'AUTH_METHOD_REQUIRED');
        }
        if (null !== $d['USU_SSO_Provider'] && !$d['USU_SSO_Habilitado']) {
            throw new ErrorApi(400, 'SSO_PROVIDER_REQUIRES_SSO_ENABLED');
        }
        $personaHex = Ident::aHex($d['PER_Persona']);
        $persona = $this->conexion->fetchAssociative('SELECT * FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $personaHex]);
        if (false === $persona) {
            throw new ErrorApi(404, 'PERSONA_NOT_FOUND');
        }
        if (null !== $d['USU_Password']) {
            $this->politica->validar($d['USU_Password'], $d['USU_Username'], \App\Dominio\Persona::desdeFila($persona)->datosPersonales());
        }
        if (null !== $this->usuarios->porUsername($d['USU_Username'])) {
            throw new ErrorApi(400, 'USERNAME_ALREADY_EXISTS');
        }
        if (false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_USUARIO" WHERE "PER_Persona" = :p', ['p' => $personaHex])) {
            throw new ErrorApi(400, 'PERSON_ALREADY_HAS_USER');
        }
        $alcance = $this->alcanceNuevo(['global' => false, 'sedes' => []], $d['USU_Rol'], $d['USU_Alcance_Global'], $d['sedes'], $solicitante->rol);
        $id = Ident::nuevo();

        $this->conexion->transactional(function () use ($d, $id, $personaHex, $alcance, $auditar): void {
            $this->alcance->elevado(function () use ($d, $id, $personaHex, $alcance): void {
                $this->conexion->executeStatement(
                    'INSERT INTO "INV_USUARIO" ("USU_Usuario", "USU_Username", "USU_Password_Hash", "USU_Rol", "USU_Estado", '
                    .'"USU_SSO_Habilitado", "USU_SSO_Provider", "USU_Intentos_Fallidos", "USU_Bloqueos_Consecutivos", '
                    .'"USU_2FA_Habilitado", "USU_Alcance_Global", "USU_Debe_Cambiar_Password", "PER_Persona", "USU_Creado_En") '
                    .'VALUES (:id, :u, :h, :r, 1, :sso, :prov, 0, 0, 0, :g, :debe, :p, :ahora)',
                    ['id' => $id, 'u' => $d['USU_Username'], 'h' => null === $d['USU_Password'] ? null : Contrasenas::hashear($d['USU_Password']),
                        'r' => $d['USU_Rol'], 'sso' => $d['USU_SSO_Habilitado'] ? 1 : 0, 'prov' => $d['USU_SSO_Provider'],
                        'g' => $alcance['global'] ? 1 : 0,
                        // Contraseña elegida por un administrador: temporal, se cambia en el primer inicio.
                        'debe' => null !== $d['USU_Password'] ? 1 : 0, 'p' => $personaHex, 'ahora' => Reloj::aBd(Reloj::ahora())],
                );
                $this->guardarSedes($id, $alcance['sedes']);
            });
            $auditar('CREATE', 'INV_USUARIO', [
                'username' => $d['USU_Username'], 'rol' => $d['USU_Rol'], 'sso_habilitado' => $d['USU_SSO_Habilitado'],
                'sso_provider' => $d['USU_SSO_Provider'], 'password_interna' => null !== $d['USU_Password'],
                'alcance' => ['global' => $alcance['global'] || \in_array($d['USU_Rol'], Roles::SIEMPRE_GLOBALES, true), 'sedes' => $alcance['sedes']],
            ]);
        });

        return (array) $this->respuesta($id);
    }

    /**
     * @param array<string, mixed> $c campos enviados (USU_Password, USU_Rol, USU_Estado, USU_SSO_Habilitado,
     *                                USU_SSO_Provider, USU_Alcance_Global, sedes)
     *
     * @return array<string, mixed>
     */
    public function actualizar(string $objetivoHex, array $c, Usuario $solicitante, callable $auditar, ?string $ip): array
    {
        $objetivo = $this->usuarios->porId($objetivoHex) ?? throw new ErrorApi(404, 'USER_NOT_FOUND');
        if ($objetivo->id === $solicitante->id) {
            throw ErrorApi::prohibido('CANNOT_MODIFY_OWN_ACCOUNT');
        }
        $fila = (array) $this->conexion->fetchAssociative('SELECT * FROM "INV_USUARIO" WHERE "USU_Usuario" = :id', ['id' => $objetivoHex]);
        $rolAntes = $objetivo->rol;
        $estadoAntes = $objetivo->activo;
        $ssoAntes = 1 === (int) $fila['USU_SSO_Habilitado'];
        $proveedorAntes = $fila['USU_SSO_Provider'];

        if (Roles::SUPER_ADMIN === $objetivo->rol && Roles::SUPER_ADMIN !== $solicitante->rol) {
            throw ErrorApi::prohibido('CANNOT_MODIFY_SUPER_ADMIN');
        }
        if (!Roles::puedeAdministrar($solicitante->rol, $objetivo->rol)) {
            throw ErrorApi::prohibido('INSUFFICIENT_PERMISSIONS');
        }
        $rolNuevo = $c['USU_Rol'] ?? null;
        // Anti-escalada: los roles protegidos solo los otorga un SUPER_ADMIN.
        if (null !== $rolNuevo && !Roles::puedeAdministrar($solicitante->rol, $rolNuevo)) {
            throw ErrorApi::prohibido('ONLY_SUPER_ADMIN_CAN_GRANT_ADMIN_ROLES');
        }
        // Una cuenta global solo cambia de rol por un SUPER_ADMIN (salvo que se le quite el global).
        if (null !== $rolNuevo && $rolNuevo !== $objetivo->rol && $objetivo->alcanceGlobal
            && false !== ($c['USU_Alcance_Global'] ?? null) && !Roles::puedeOtorgarAlcanceGlobal($solicitante->rol)) {
            throw ErrorApi::prohibido('ONLY_SUPER_ADMIN_CAN_CHANGE_ROLE_OF_GLOBAL_ACCOUNT');
        }
        $desactiva = false === ($c['USU_Estado'] ?? null);
        $degrada = null !== $rolNuevo && Roles::SUPER_ADMIN !== $rolNuevo;
        if (Roles::SUPER_ADMIN === $objetivo->rol && ($desactiva || $degrada)
            && (int) $this->conexion->fetchOne("SELECT COUNT(*) FROM \"INV_USUARIO\" WHERE \"USU_Rol\" = 'SUPER_ADMIN' AND \"USU_Estado\" = 1") <= 1) {
            throw new ErrorApi(400, 'CANNOT_DISABLE_LAST_SUPER_ADMIN');
        }
        $password = $c['USU_Password'] ?? null;
        if (null !== $password) {
            $this->cambio->validar($objetivo, $password);
        }
        $ssoDespues = $c['USU_SSO_Habilitado'] ?? $ssoAntes;
        if (null !== ($c['USU_SSO_Provider'] ?? null) && !$ssoDespues) {
            throw new ErrorApi(400, 'SSO_PROVIDER_REQUIRES_SSO_ENABLED');
        }
        if (!$ssoDespues && null === $objetivo->passwordHash && null === $password) {
            throw new ErrorApi(400, 'PASSWORD_REQUIRED_WHEN_SSO_DISABLED');
        }
        $rolFinal = $rolNuevo ?? $objetivo->rol;
        $alcanceAntes = ['global' => $objetivo->alcanceGlobal, 'sedes' => $objetivo->idsSedes()];
        sort($alcanceAntes['sedes']);
        $alcance = $this->alcanceNuevo($alcanceAntes, $rolFinal,
            \array_key_exists('USU_Alcance_Global', $c) ? $c['USU_Alcance_Global'] : null,
            \array_key_exists('sedes', $c) ? $c['sedes'] : null, $solicitante->rol);
        $accesoRevocado = $estadoAntes && $desactiva;

        $this->conexion->transactional(function () use ($objetivo, $objetivoHex, $c, $password, $alcance, $alcanceAntes, $accesoRevocado, $rolAntes, $estadoAntes, $ssoAntes, $proveedorAntes, $auditar): void {
            if (null !== $password) {
                $this->cambio->aplicar($objetivo, $password, false);
                // Fijada por un administrador → temporal (cambio obligatorio).
                $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET "USU_Debe_Cambiar_Password" = 1 WHERE "USU_Usuario" = :id', ['id' => $objetivoHex]);
            }
            $this->alcance->elevado(function () use ($objetivoHex, $c, $alcance, $alcanceAntes): void {
                $sets = [];
                $parametros = ['id' => $objetivoHex];
                foreach (['USU_Rol', 'USU_Estado', 'USU_SSO_Habilitado', 'USU_SSO_Provider'] as $columna) {
                    if (\array_key_exists($columna, $c)) {
                        $sets[] = "\"{$columna}\" = :{$columna}";
                        $parametros[$columna] = \is_bool($c[$columna]) ? ($c[$columna] ? 1 : 0) : $c[$columna];
                    }
                }
                $sets[] = '"USU_Alcance_Global" = :g';
                $parametros['g'] = $alcance['global'] ? 1 : 0;
                $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET '.implode(', ', $sets).' WHERE "USU_Usuario" = :id', $parametros);
                if ($alcance['sedes'] !== $alcanceAntes['sedes']) {
                    $this->guardarSedes($objetivoHex, $alcance['sedes']);
                }
            });
            // Desactivar corta el acceso ya: sin esto, al reactivar la cuenta sus sesiones volvían a valer.
            if ($accesoRevocado) {
                $this->revocarAcceso($objetivoHex);
            }
            $diff = [];
            foreach (['USU_Rol' => $rolAntes, 'USU_Estado' => $estadoAntes, 'USU_SSO_Habilitado' => $ssoAntes, 'USU_SSO_Provider' => $proveedorAntes] as $campo => $antes) {
                if (null !== ($c[$campo] ?? null) && $c[$campo] !== $antes) {
                    $diff[$campo] = ['antes' => $antes, 'despues' => $c[$campo]];
                }
            }
            if ($alcance !== $alcanceAntes) {
                $diff['alcance'] = ['antes' => $alcanceAntes, 'despues' => $alcance];
            }
            $cambios = $c;
            unset($cambios['USU_Password']);
            $auditar('UPDATE', 'INV_USUARIO', [
                'target_id' => Ident::aTexto($objetivoHex), 'target_username' => $objetivo->username, 'cambios' => (object) $cambios,
                'diff' => (object) $diff, 'password_cambiada' => null !== $password, 'acceso_revocado' => $accesoRevocado,
            ]);
        });
        if (null !== $password) {
            $persona = $this->usuarios->persona($objetivo);
            $this->notificador->contrasenaCambiada($persona?->nombreCorto() ?? $objetivo->username, $objetivo->username, $persona?->correo, 'Cambio por administrador', $ip);
        }

        return (array) $this->respuesta($objetivoHex);
    }

    // ---- Acciones administrativas -----------------------------------------

    /** Cuenta objetivo de una acción administrativa, validando la jerarquía. */
    private function conSesionesGestionables(string $objetivoHex, Usuario $solicitante): Usuario
    {
        $objetivo = $this->usuarios->porId($objetivoHex) ?? throw new ErrorApi(404, 'USER_NOT_FOUND');
        if ($objetivo->id === $solicitante->id) {
            throw ErrorApi::prohibido('CANNOT_MODIFY_OWN_ACCOUNT');
        }
        if (!Roles::puedeCerrarSesiones($solicitante->rol, $objetivo->rol)) {
            throw ErrorApi::prohibido('INSUFFICIENT_PERMISSIONS');
        }

        return $objetivo;
    }

    public function gestionable(string $objetivoHex, Usuario $solicitante): Usuario
    {
        $objetivo = $this->usuarios->porId($objetivoHex) ?? throw new ErrorApi(404, 'USER_NOT_FOUND');
        if ($objetivo->id === $solicitante->id) {
            throw ErrorApi::prohibido('CANNOT_MODIFY_OWN_ACCOUNT');
        }
        if (!Roles::puedeAdministrar($solicitante->rol, $objetivo->rol)) {
            throw ErrorApi::prohibido('INSUFFICIENT_PERMISSIONS');
        }

        return $objetivo;
    }

    /**
     * Contraseña TEMPORAL aleatoria (se devuelve una sola vez): cambio obligatorio
     * en el próximo inicio, cuenta desbloqueada y todas sus sesiones cerradas.
     */
    public function restablecerContrasena(string $objetivoHex, Usuario $solicitante, callable $auditar, ?string $ip): string
    {
        $objetivo = $this->gestionable($objetivoHex, $solicitante);
        $fila = (array) $this->conexion->fetchAssociative('SELECT "USU_SSO_Habilitado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :id', ['id' => $objetivoHex]);
        if (1 === (int) $fila['USU_SSO_Habilitado'] && null === $objetivo->passwordHash) {
            throw new ErrorApi(400, 'SSO_ONLY_ACCOUNT');
        }
        $temporal = $this->politica->generarTemporal($objetivo->username);
        $this->conexion->transactional(function () use ($objetivo, $objetivoHex, $temporal, $auditar): void {
            $this->cambio->aplicar($objetivo, $temporal, true);
            $this->conexion->executeStatement('UPDATE "INV_USUARIO" SET "USU_Debe_Cambiar_Password" = 1 WHERE "USU_Usuario" = :id', ['id' => $objetivoHex]);
            $auditar('PASSWORD_RESET_BY_ADMIN', 'INV_USUARIO', ['target_id' => Ident::aTexto($objetivoHex), 'target_username' => $objetivo->username]);
        });
        $persona = $this->usuarios->persona($objetivo);
        $this->notificador->contrasenaCambiada($persona?->nombreCorto() ?? $objetivo->username, $objetivo->username, $persona?->correo, 'admin', $ip);

        return $temporal;
    }

    /** Reset del 2FA (p. ej. teléfono perdido): cierra sus sesiones; si su rol lo exige, re-enrola. */
    public function restablecer2fa(string $objetivoHex, Usuario $solicitante, callable $auditar): void
    {
        $objetivo = $this->gestionable($objetivoHex, $solicitante);
        $this->conexion->transactional(function () use ($objetivo, $objetivoHex, $auditar): void {
            $estaba = $objetivo->dosFactoresHabilitado;
            $objetivo->dosFactoresHabilitado = false;
            $objetivo->dosFactoresMetodo = null;
            $objetivo->dosFactoresSecreto = null;
            $this->usuarios->guardarDosFactores($objetivo);
            $this->credenciales->borrarRecuperacion($objetivoHex);
            $this->credenciales->invalidarOtps($objetivoHex);
            $this->gobierno->revocarTodo($objetivoHex, Reloj::ahora()->modify('+1 year'));
            $auditar('2FA_RESET_BY_ADMIN', 'INV_USUARIO', ['target_id' => Ident::aTexto($objetivoHex), 'target_username' => $objetivo->username, 'estaba_habilitado' => $estaba]);
        });
    }

    public function desbloquear(string $objetivoHex, Usuario $solicitante, callable $auditar): void
    {
        $objetivo = $this->gestionable($objetivoHex, $solicitante);
        $this->conexion->transactional(function () use ($objetivo, $objetivoHex, $auditar): void {
            $estaba = null !== $objetivo->bloqueadoHasta;
            $previos = $objetivo->bloqueosConsecutivos;
            // También las IP bloqueadas por fallos desde ellas (fase 8).
            $this->bloqueo->limpiar($objetivo);
            $this->usuarios->guardarEstadoAcceso($objetivo);
            $auditar('ACCOUNT_UNLOCKED_BY_ADMIN', 'INV_USUARIO', ['target_id' => Ident::aTexto($objetivoHex), 'target_username' => $objetivo->username,
                'estaba_bloqueado' => $estaba, 'bloqueos_consecutivos' => $previos]);
        });
    }

    public function revocarSesiones(string $objetivoHex, Usuario $solicitante, callable $auditar): void
    {
        $objetivo = $this->conSesionesGestionables($objetivoHex, $solicitante);
        $this->conexion->transactional(function () use ($objetivo, $objetivoHex, $auditar): void {
            $this->gobierno->revocarTodo($objetivoHex, Reloj::ahora()->modify('+1 year'));
            $auditar('SESSIONS_REVOKED_BY_ADMIN', 'INV_USUARIO', ['target_id' => Ident::aTexto($objetivoHex), 'target_username' => $objetivo->username]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function sesionesDe(string $objetivoHex, Usuario $solicitante): array
    {
        $objetivo = $this->usuarios->porId($objetivoHex) ?? throw new ErrorApi(404, 'USER_NOT_FOUND');
        if ($objetivo->id !== $solicitante->id && !Roles::puedeCerrarSesiones($solicitante->rol, $objetivo->rol)) {
            throw ErrorApi::prohibido('INSUFFICIENT_PERMISSIONS');
        }

        return array_map(static fn (array $s): array => SesionRepositorio::serializar($s), $this->sesiones->activas($objetivoHex));
    }

    public function cerrarSesion(string $objetivoHex, string $sesionId, Usuario $solicitante, callable $auditar): void
    {
        $objetivo = $this->conSesionesGestionables($objetivoHex, $solicitante);
        $sesion = $this->sesiones->obtener($sesionId);
        if (null === $sesion || $sesion['usuario'] !== $objetivoHex) {
            throw new ErrorApi(404, 'SESSION_NOT_FOUND');
        }
        $this->conexion->transactional(function () use ($objetivo, $objetivoHex, $sesion, $sesionId, $auditar): void {
            if ($this->cierre->cerrar($sesion['id'], $objetivoHex, 'cerrada_por_administrador')) {
                $auditar('SESSION_CLOSED', 'SYS_SESION', ['target_id' => Ident::aTexto($objetivoHex), 'target_username' => $objetivo->username,
                    'sesion' => $sesionId, 'dispositivo' => $sesion['dispositivo'], 'por' => 'administrador']);
            }
        });
    }

    /**

     * Sesiones activas de todas las cuentas, con su titular (panel de seguridad).

     *

     * @return list<array<string, mixed>>

     */
    public function todasLasSesiones(Usuario $solicitante): array
    {
        $filas = $this->conexion->fetchAllAssociative(
            'SELECT s."SES_Sesion", u."USU_Username", u."USU_Rol", p."PER_Primer_Nombre", p."PER_Primer_Apellido" FROM "SYS_SESION" s '
            .'JOIN "INV_USUARIO" u ON u."USU_Usuario" = s."USU_Usuario" LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = u."PER_Persona" '
            .'WHERE s."SES_Cerrada_En" IS NULL AND s."SES_Expira" > :ahora ORDER BY s."SES_Ultima_Actividad" DESC',
            ['ahora' => Reloj::aBd(Reloj::ahora())],
        );

        return array_map(function (array $f) use ($solicitante): array {
            $sesion = (array) $this->sesiones->obtener((string) $f['SES_Sesion']);

            return [...SesionRepositorio::serializar($sesion), 'username' => $f['USU_Username'], 'rol' => $f['USU_Rol'],
                'nombre' => null === $f['PER_Primer_Nombre'] ? $f['USU_Username'] : "{$f['PER_Primer_Nombre']} {$f['PER_Primer_Apellido']}",
                'gestionable' => Roles::puedeCerrarSesiones($solicitante->rol, (string) $f['USU_Rol'])];
        }, $filas);
    }

    /**

     * Indicadores del panel de seguridad y la política vigente.

     *

     * @return array<string, mixed>

     */
    public function resumenSeguridad(): array
    {
        $ahora = Reloj::ahora();
        $usuarios = $this->conexion->fetchAllAssociative(
            'SELECT u."USU_Usuario", u."USU_Rol", u."USU_Estado", u."USU_2FA_Habilitado", u."USU_SSO_Habilitado", u."USU_Alcance_Global", '
            .'u."USU_Bloqueado_Hasta", u."USU_Debe_Cambiar_Password", (SELECT COUNT(*) FROM "INV_USUARIO_SEDE" us WHERE us."USU_Usuario" = u."USU_Usuario") AS "SEDES" '
            .'FROM "INV_USUARIO" u',
        );
        $activos = array_values(array_filter($usuarios, static fn (array $u): bool => 1 === (int) $u['USU_Estado']));
        $global = static fn (array $u): bool => 1 === (int) $u['USU_Alcance_Global'] || \in_array($u['USU_Rol'], Roles::SIEMPRE_GLOBALES, true);
        $conMfa = \count(array_filter($activos, static fn (array $u): bool => 1 === (int) $u['USU_2FA_Habilitado']));
        $a = $this->ajustes;

        return [
            'sesiones_activas' => (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "SYS_SESION" WHERE "SES_Cerrada_En" IS NULL AND "SES_Expira" > :a', ['a' => Reloj::aBd($ahora)]),
            'usuarios_alcance_global' => \count(array_filter($activos, static fn (array $u): bool => \in_array($u['USU_Rol'], Roles::CON_ALCANCE, true) && $global($u))),
            'usuarios_sin_alcance' => \count(array_filter($activos, static fn (array $u): bool => \in_array($u['USU_Rol'], Roles::CON_ALCANCE, true) && !$global($u) && 0 === (int) $u['SEDES'])),
            'rls_base_datos' => $this->rls->capaActiva(),
            'usuarios_activos' => \count($activos),
            'usuarios_inactivos' => \count($usuarios) - \count($activos),
            'con_mfa' => $conMfa,
            'cobertura_mfa' => [] === $activos ? 0 : (int) round(100 * $conMfa / \count($activos)),
            'mfa_pendiente_obligatorio' => \count(array_filter($activos, fn (array $u): bool => $this->mfaRequerido((string) $u['USU_Rol'], 1 === (int) $u['USU_SSO_Habilitado']) && 1 !== (int) $u['USU_2FA_Habilitado'])),
            'bloqueados' => \count(array_filter($activos, static fn (array $u): bool => null !== $u['USU_Bloqueado_Hasta'] && Reloj::desdeBd((string) $u['USU_Bloqueado_Hasta']) > $ahora)),
            'cambio_password_pendiente' => \count(array_filter($activos, static fn (array $u): bool => 1 === (int) $u['USU_Debe_Cambiar_Password'])),
            'politica' => [
                'mfa_roles_obligatorios' => (static function (array $r): array { sort($r); return $r; })(array_values(array_filter($a->rolesConMfa, $this->politicaSesion->rolExigeMfa(...)))),
                'password_longitud_minima' => $a->passwordLongitudMin,
                'password_mayuscula' => $a->passwordMayuscula,
                'password_minuscula' => $a->passwordMinuscula,
                'password_digito' => $a->passwordDigito,
                'password_simbolo' => $a->passwordSimbolo,
                'bloqueo_intentos' => $a->bloqueoUmbral,
                'bloqueo_minutos' => $a->bloqueoMinutos,
                'bloqueo_progresivo_tope_minutos' => $a->bloqueoMaxMinutos,
                'sesion_inactividad_minutos' => $a->sesionInactividadMinutos,
                'sesion_maxima_horas' => $a->sesionMaxHoras,
                'password_historial' => $a->passwordHistorial,
                'password_bloquea_comunes' => true,
                'aviso_dispositivo_nuevo' => $a->avisoDispositivoNuevo,
                'cuentas_inactivas_dias' => $a->cuentasInactivasDias,
                'auditoria_retencion_dias' => $a->auditoriaRetencionDias,
            ],
        ];
    }

    /**
     * Cuenta desactivada: revoca sus tokens y cierra sesiones, y anula los enlaces
     * de restablecimiento y códigos por correo pendientes.
     */
    public function revocarAcceso(string $usuarioHex): void
    {
        $this->gobierno->revocarTodo($usuarioHex, Reloj::ahora()->modify('+'.($this->ajustes->refreshDias + 1).' days'));
        $this->credenciales->invalidarResets($usuarioHex);
        $this->credenciales->invalidarOtps($usuarioHex);
    }

    /**
     * Alcance resultante. Reglas: SUPER_ADMIN y ADMIN_SEGURIDAD son globales por rol
     * (se ignora lo enviado); solo un SUPER_ADMIN otorga el global; un rol de
     * inventario debe quedar con alcance global o al menos una sede.
     *
     * @param array{global: bool, sedes: list<int>} $antes
     * @param list<int>|null                        $sedes
     *
     * @return array{global: bool, sedes: list<int>}
     */
    private function alcanceNuevo(array $antes, string $rol, ?bool $global, ?array $sedes, string $rolSolicitante): array
    {
        if (\in_array($rol, Roles::SIEMPRE_GLOBALES, true)) {
            [$global, $sedes] = [false, []];
        }
        $nuevoGlobal = $global ?? $antes['global'];
        if ($nuevoGlobal && !$antes['global'] && !Roles::puedeOtorgarAlcanceGlobal($rolSolicitante)) {
            throw ErrorApi::prohibido('ONLY_SUPER_ADMIN_CAN_GRANT_GLOBAL_SCOPE');
        }
        $nuevasSedes = null === $sedes ? $antes['sedes'] : $this->sedesValidas($sedes);
        if ($nuevoGlobal) {
            $nuevasSedes = [];
        }
        if (\in_array($rol, Roles::CON_ALCANCE, true) && !$nuevoGlobal && [] === $nuevasSedes) {
            throw new ErrorApi(400, 'SCOPE_REQUIRED');
        }

        return ['global' => $nuevoGlobal, 'sedes' => $nuevasSedes];
    }

    /**

     * @param list<int> $sedes

     *

     * @return list<int>

     */
    private function sedesValidas(array $sedes): array
    {
        $ids = array_values(array_unique($sedes));
        sort($ids);
        foreach ($ids as $id) {
            if (false === $this->conexion->fetchOne('SELECT 1 FROM "INV_SEDE" WHERE "SED_Sede" = :s', ['s' => $id])) {
                throw new ErrorApi(400, 'SEDE_NOT_FOUND');
            }
        }

        return $ids;
    }

    /** @param list<int> $sedes */
    private function guardarSedes(string $usuarioHex, array $sedes): void
    {
        $this->conexion->executeStatement('DELETE FROM "INV_USUARIO_SEDE" WHERE "USU_Usuario" = :u', ['u' => $usuarioHex]);
        foreach ($sedes as $sede) {
            $this->conexion->executeStatement('INSERT INTO "INV_USUARIO_SEDE" ("USU_Usuario", "SED_Sede") VALUES (:u, :s)', ['u' => $usuarioHex, 's' => $sede]);
        }
    }
}
