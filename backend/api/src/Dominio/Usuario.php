<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Cuenta de INV_USUARIO. Los campos de acceso (bloqueo, último login,
 * contraseña y segundo factor) son mutables: los modifican los flujos de
 * autenticación y se persisten con UsuarioRepositorio.
 */
final class Usuario
{
    /** @param list<array{id: int, nombre: string}> $sedes */
    public function __construct(
        public readonly string $id,
        public readonly string $username,
        public ?string $passwordHash,
        public readonly string $rol,
        public readonly bool $activo,
        public readonly bool $alcanceGlobal,
        public readonly string $personaId,
        public bool $debeCambiarPassword,
        public bool $dosFactoresHabilitado,
        public ?string $dosFactoresMetodo,
        public int $intentosFallidos,
        public ?\DateTimeImmutable $bloqueadoHasta,
        public int $bloqueosConsecutivos,
        public ?\DateTimeImmutable $ultimoBloqueo,
        public ?\DateTimeImmutable $ultimoLogin,
        public array $sedes = [],
        /** Secreto TOTP cifrado con Fernet (null en método EMAIL). */
        public ?string $dosFactoresSecreto = null,
        /** Último paso TOTP aceptado (anti-reutilización). */
        public ?int $dosFactoresUltimoPaso = null,
        public readonly bool $ssoHabilitado = false,
        /** Proveedor SSO fijado para la cuenta ('microsoft' | 'google') o null = cualquiera. */
        public readonly ?string $ssoProveedor = null,
        /** Excepción temporal del MFA concedida por el administrador del sistema. */
        public readonly ?\DateTimeImmutable $mfaExentoHasta = null,
        /** Cuándo omitió la persona configurar el MFA (solo se puede una vez, en su primer ingreso). */
        public readonly ?\DateTimeImmutable $mfaOmitidoEn = null,
        /** Bloqueo puesto por el administrador del sistema: motivo (null = sin bloqueo) y fin (null = indefinido). */
        public readonly ?string $bloqueoAdminMotivo = null,
        public readonly ?\DateTimeImmutable $bloqueoAdminHasta = null,
    ) {
    }

    /**
     * ¿La bloqueó el administrador del sistema y sigue vigente? Aparte del bloqueo por intentos:
     * no lo levanta un inicio de sesión ni un cambio de contraseña, solo el administrador o su fecha.
     */
    public function bloqueoAdministrativoVigente(\DateTimeImmutable $ahora): bool
    {
        return null !== $this->bloqueoAdminMotivo && (null === $this->bloqueoAdminHasta || $this->bloqueoAdminHasta > $ahora);
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (string) $fila['USU_Usuario'],
            username: (string) $fila['USU_Username'],
            passwordHash: $fila['USU_Password_Hash'] ?? null,
            rol: (string) $fila['USU_Rol'],
            activo: 1 === (int) $fila['USU_Estado'],
            alcanceGlobal: 1 === (int) ($fila['USU_Alcance_Global'] ?? 0),
            personaId: (string) $fila['PER_Persona'],
            debeCambiarPassword: 1 === (int) ($fila['USU_Debe_Cambiar_Password'] ?? 0),
            dosFactoresHabilitado: 1 === (int) ($fila['USU_2FA_Habilitado'] ?? 0),
            dosFactoresMetodo: $fila['USU_2FA_Metodo'] ?? null,
            intentosFallidos: (int) ($fila['USU_Intentos_Fallidos'] ?? 0),
            bloqueadoHasta: Reloj::desdeBd($fila['USU_Bloqueado_Hasta'] ?? null),
            bloqueosConsecutivos: (int) ($fila['USU_Bloqueos_Consecutivos'] ?? 0),
            ultimoBloqueo: Reloj::desdeBd($fila['USU_Ultimo_Bloqueo'] ?? null),
            ultimoLogin: Reloj::desdeBd($fila['USU_Ultimo_Login'] ?? null),
            dosFactoresSecreto: $fila['USU_2FA_Secret'] ?? null,
            dosFactoresUltimoPaso: isset($fila['USU_2FA_Ultimo_Paso']) ? (int) $fila['USU_2FA_Ultimo_Paso'] : null,
            ssoHabilitado: 1 === (int) ($fila['USU_SSO_Habilitado'] ?? 0),
            ssoProveedor: isset($fila['USU_SSO_Provider']) && '' !== $fila['USU_SSO_Provider'] ? (string) $fila['USU_SSO_Provider'] : null,
            mfaExentoHasta: Reloj::desdeBd($fila['USU_MFA_Exento_Hasta'] ?? null),
            mfaOmitidoEn: Reloj::desdeBd($fila['USU_MFA_Omitido_En'] ?? null),
            bloqueoAdminMotivo: isset($fila['USU_Bloqueo_Admin_Motivo']) && '' !== $fila['USU_Bloqueo_Admin_Motivo'] ? (string) $fila['USU_Bloqueo_Admin_Motivo'] : null,
            bloqueoAdminHasta: Reloj::desdeBd($fila['USU_Bloqueo_Admin_Hasta'] ?? null),
        );
    }

    /** Alcance global efectivo (los roles de gobierno siempre lo son). */
    public function esGlobal(): bool
    {
        return $this->alcanceGlobal || \in_array($this->rol, Roles::SIEMPRE_GLOBALES, true);
    }

    /** @return list<int> */
    public function idsSedes(): array
    {
        return array_column($this->sedes, 'id');
    }
}
