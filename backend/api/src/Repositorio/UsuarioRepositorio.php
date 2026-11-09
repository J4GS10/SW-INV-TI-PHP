<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Dominio\Persona;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use Doctrine\DBAL\Connection;

final readonly class UsuarioRepositorio
{
    private const string COLUMNAS = 'u."USU_Usuario", u."USU_Username", u."USU_Password_Hash", u."USU_Rol", '
        .'u."USU_Estado", u."USU_Alcance_Global", u."PER_Persona", u."USU_Debe_Cambiar_Password", '
        .'u."USU_2FA_Habilitado", u."USU_2FA_Metodo", u."USU_2FA_Secret", u."USU_2FA_Ultimo_Paso", '
        .'u."USU_Intentos_Fallidos", u."USU_Bloqueado_Hasta", u."USU_Bloqueos_Consecutivos", '
        .'u."USU_Ultimo_Bloqueo", u."USU_Ultimo_Login", u."USU_SSO_Habilitado", u."USU_SSO_Provider", '
        .'u."USU_MFA_Exento_Hasta", u."USU_MFA_Omitido_En"';

    public function __construct(private Connection $conexion)
    {
    }

    public function porUsername(string $username): ?Usuario
    {
        return $this->uno('u."USU_Username" = :v', $username);
    }

    public function porId(string $idHex): ?Usuario
    {
        return $this->uno('u."USU_Usuario" = :v', $idHex);
    }

    /** Por username o, si no, por el correo corporativo de su persona (restablecimiento). */
    public function porUsernameOCorreo(string $identificador): ?Usuario
    {
        return $this->porUsername($identificador) ?? $this->uno(
            'u."PER_Persona" = (SELECT p."PER_Persona" FROM "INV_PERSONA" p '
            .'WHERE LOWER(p."PER_Email_Corporativo") = LOWER(:v) FETCH FIRST 1 ROWS ONLY)',
            $identificador,
        );
    }

    /** Cuenta cuya persona tiene ese correo corporativo (sin distinguir mayúsculas). */
    public function porCorreoPersona(string $correo): ?Usuario
    {
        return $this->uno(
            'u."PER_Persona" = (SELECT p."PER_Persona" FROM "INV_PERSONA" p '
            .'WHERE LOWER(p."PER_Email_Corporativo") = LOWER(:v) FETCH FIRST 1 ROWS ONLY)',
            trim($correo),
        );
    }

    public function persona(Usuario $usuario): ?Persona
    {
        $fila = $this->conexion->fetchAssociative(
            'SELECT p."PER_Primer_Nombre", p."PER_Segundo_Nombre", p."PER_Primer_Apellido", p."PER_Segundo_Apellido", '
            .'p."PER_Email_Corporativo", p."PER_Estado" FROM "INV_PERSONA" p WHERE p."PER_Persona" = :persona',
            ['persona' => $usuario->personaId],
        );

        return false === $fila ? null : Persona::desdeFila($fila);
    }

    /** Persiste intentos, bloqueo, último login y (si se rehízo) el hash. */
    public function guardarEstadoAcceso(Usuario $usuario): void
    {
        $this->conexion->executeStatement(
            'UPDATE "INV_USUARIO" SET "USU_Intentos_Fallidos" = :intentos, "USU_Bloqueado_Hasta" = :bloqueado, '
            .'"USU_Bloqueos_Consecutivos" = :bloqueos, "USU_Ultimo_Bloqueo" = :ultimo_bloqueo, '
            .'"USU_Ultimo_Login" = :ultimo_login, "USU_Password_Hash" = :hash WHERE "USU_Usuario" = :id',
            [
                'intentos' => $usuario->intentosFallidos,
                'bloqueado' => Reloj::aBd($usuario->bloqueadoHasta),
                'bloqueos' => $usuario->bloqueosConsecutivos,
                'ultimo_bloqueo' => Reloj::aBd($usuario->ultimoBloqueo),
                'ultimo_login' => Reloj::aBd($usuario->ultimoLogin),
                'hash' => $usuario->passwordHash,
                'id' => $usuario->id,
            ],
        );
    }

    /** Contraseña nueva: deja de ser temporal y se limpia el bloqueo. */
    public function guardarNuevaContrasena(Usuario $usuario): void
    {
        $this->conexion->executeStatement(
            'UPDATE "INV_USUARIO" SET "USU_Password_Hash" = :hash, "USU_Password_Cambiada_En" = :ahora, '
            .'"USU_Debe_Cambiar_Password" = :debe, "USU_Intentos_Fallidos" = :intentos, "USU_Bloqueado_Hasta" = :bloqueado, '
            .'"USU_Bloqueos_Consecutivos" = :bloqueos WHERE "USU_Usuario" = :id',
            [
                'hash' => $usuario->passwordHash,
                'ahora' => Reloj::aBd(Reloj::ahora()),
                'debe' => $usuario->debeCambiarPassword ? 1 : 0,
                'intentos' => $usuario->intentosFallidos,
                'bloqueado' => Reloj::aBd($usuario->bloqueadoHasta),
                'bloqueos' => $usuario->bloqueosConsecutivos,
                'id' => $usuario->id,
            ],
        );
    }

    public function guardarDosFactores(Usuario $usuario): void
    {
        $this->conexion->executeStatement(
            'UPDATE "INV_USUARIO" SET "USU_2FA_Habilitado" = :habilitado, "USU_2FA_Metodo" = :metodo, '
            .'"USU_2FA_Secret" = :secreto WHERE "USU_Usuario" = :id',
            [
                'habilitado' => $usuario->dosFactoresHabilitado ? 1 : 0,
                'metodo' => $usuario->dosFactoresMetodo,
                'secreto' => $usuario->dosFactoresSecreto,
                'id' => $usuario->id,
            ],
        );
    }

    /**
     * Consume un paso TOTP de forma ATÓMICA: solo si es posterior al último
     * aceptado. Ante verificaciones simultáneas con el mismo código, gana una.
     */
    public function aceptarPasoTotp(string $usuarioHex, int $paso): bool
    {
        return 1 === $this->conexion->executeStatement(
            'UPDATE "INV_USUARIO" SET "USU_2FA_Ultimo_Paso" = :paso WHERE "USU_Usuario" = :id '
            .'AND ("USU_2FA_Ultimo_Paso" IS NULL OR "USU_2FA_Ultimo_Paso" < :paso)',
            ['paso' => $paso, 'id' => $usuarioHex],
        );
    }

    public function preferencias(string $usuarioHex): ?string
    {
        $valor = $this->conexion->fetchOne(
            'SELECT "USU_Preferencias" FROM "INV_USUARIO" WHERE "USU_Usuario" = :id',
            ['id' => $usuarioHex],
        );

        return false === $valor || null === $valor ? null : (string) $valor;
    }

    public function guardarPreferencias(string $usuarioHex, string $json): void
    {
        $this->conexion->executeStatement(
            'UPDATE "INV_USUARIO" SET "USU_Preferencias" = :json WHERE "USU_Usuario" = :id',
            ['json' => $json, 'id' => $usuarioHex],
        );
    }

    private function uno(string $condicion, string $valor): ?Usuario
    {
        $fila = $this->conexion->fetchAssociative(
            'SELECT '.self::COLUMNAS.' FROM "INV_USUARIO" u WHERE '.$condicion,
            ['v' => $valor],
        );

        return false === $fila ? null : $this->conSedes(Usuario::desdeFila($fila));
    }

    private function conSedes(Usuario $usuario): Usuario
    {
        $filas = $this->conexion->fetchAllAssociative(
            'SELECT s."SED_Sede", s."SED_Nombre" FROM "INV_SEDE" s '
            .'JOIN "INV_USUARIO_SEDE" us ON us."SED_Sede" = s."SED_Sede" '
            .'WHERE us."USU_Usuario" = :id ORDER BY s."SED_Nombre"',
            ['id' => $usuario->id],
        );
        $usuario->sedes = array_map(
            static fn (array $f): array => ['id' => (int) $f['SED_Sede'], 'nombre' => (string) $f['SED_Nombre']],
            $filas,
        );

        return $usuario;
    }
}
