<?php

declare(strict_types=1);

namespace App\Integracion;

use App\Config\Ajustes;
use App\Correo\ConfigCorreo;

/**
 * Construye la configuración efectiva desde valores planos (fila de la base ya
 * descifrada o formulario sin guardar) o desde las variables de entorno SMTP_* /
 * AD_*, con las mismas reglas que el backend anterior.
 */
final readonly class FabricaConfigIntegracion
{
    public function __construct(private Ajustes $ajustes)
    {
    }

    /** @param array<string, mixed> $v */
    public function desdeValores(array $v, string $fuente = ConfigIntegracion::FUENTE_INTERFAZ): ConfigIntegracion
    {
        $modo = ModoIntegracion::tryFrom((string) ($v['modo'] ?? '')) ?? ModoIntegracion::DESACTIVADO;
        $cuenta = self::texto($v['cuenta_usuario'] ?? null) ?? self::texto($v['cuenta_email'] ?? null);
        $correo = new ConfigCorreo(
            habilitado: $modo->conCorreo() && $this->ajustes->correoHabilitado,
            proveedor: self::texto($v['proveedor'] ?? null) ?? 'SMTP',
            host: self::texto($v['smtp_host'] ?? null),
            puerto: (int) ($v['smtp_puerto'] ?? 0) ?: 587,
            seguridad: self::texto($v['smtp_seguridad'] ?? null) ?? 'STARTTLS',
            usuario: $cuenta,
            password: self::texto($v['cuenta_password'] ?? null),
            remitente: self::texto($v['cuenta_email'] ?? null) ?? $this->ajustes->smtpRemitente,
            nombreRemitente: self::texto($v['nombre_remitente'] ?? null) ?? $this->ajustes->smtpNombreRemitente,
            graphTenant: self::texto($v['graph_tenant_id'] ?? null),
            graphCliente: self::texto($v['graph_client_id'] ?? null),
            graphSecreto: self::texto($v['graph_client_secret'] ?? null),
            timeout: $this->ajustes->smtpTimeout,
            fuente: $fuente,
        );
        $usarCuenta = (bool) ($v['ad_usar_cuenta_servicio'] ?? true);
        $ad = $this->ad(
            habilitado: $modo->conAd(),
            servidores: self::servidores($v['ad_servidor'] ?? null),
            baseDn: self::texto($v['ad_base_dn'] ?? null) ?? '',
            grupoBaseDn: self::texto($v['ad_grupo_base_dn'] ?? null) ?? '',
            grupoAdmin: self::texto($v['ad_grupo_admin'] ?? null) ?? '',
            startTls: (bool) ($v['ad_start_tls'] ?? false),
            verificarCertificado: (bool) ($v['ad_verificar_cert'] ?? true),
            usuarioBind: $usarCuenta ? $cuenta : self::texto($v['ad_bind_usuario'] ?? null),
            passwordBind: $usarCuenta ? self::texto($v['cuenta_password'] ?? null) : self::texto($v['ad_bind_password'] ?? null),
            intervaloMinutos: (int) ($v['ad_intervalo_min'] ?? 0),
            desactivar: (bool) ($v['ad_desactivar'] ?? true),
        );

        return new ConfigIntegracion($fuente, $modo, $correo, $ad);
    }

    public function desdeEntorno(): ConfigIntegracion
    {
        $a = $this->ajustes;
        $correo = new ConfigCorreo(
            habilitado: $a->correoHabilitado && null !== $a->smtpHost,
            host: $a->smtpHost,
            puerto: $a->smtpPuerto,
            seguridad: $this->seguridadEntorno(),
            usuario: $a->smtpUsuario,
            password: $a->smtpPassword,
            remitente: $a->smtpRemitente,
            nombreRemitente: $a->smtpNombreRemitente,
            timeout: $a->smtpTimeout,
            fuente: ConfigIntegracion::FUENTE_ENTORNO,
        );
        $ad = $this->ad(
            habilitado: $a->adHabilitado,
            servidores: self::servidores($a->adServidor),
            baseDn: $a->adBaseDn,
            grupoBaseDn: $a->adGrupoBaseDn,
            grupoAdmin: $a->adGrupoAdmin,
            startTls: $a->adStartTls,
            verificarCertificado: $a->adVerificarCertificado,
            usuarioBind: $a->adUsuarioBind,
            passwordBind: $a->adPasswordBind,
            intervaloMinutos: $a->adIntervaloMinutos,
            desactivar: $a->adDesactivar,
        );
        $modo = match (true) {
            $ad->habilitado && $correo->habilitado => ModoIntegracion::CORREO_AD,
            $correo->habilitado => ModoIntegracion::CORREO,
            default => ModoIntegracion::DESACTIVADO,
        };

        return new ConfigIntegracion(ConfigIntegracion::FUENTE_ENTORNO, $modo, $correo, $ad);
    }

    /**
     * Valores del entorno en formato de la API (incluye secretos: uso interno).
     *
     * @return array<string, mixed>
     */
    public function valoresEntorno(): array
    {
        $a = $this->ajustes;
        $entorno = $this->desdeEntorno();

        return [
            'modo' => $entorno->modo->value, 'proveedor' => 'SMTP',
            'cuenta_email' => $entorno->correo->habilitado ? $a->smtpRemitente : null,
            'cuenta_usuario' => $a->smtpUsuario, 'cuenta_password' => $a->smtpPassword,
            'nombre_remitente' => $a->smtpNombreRemitente,
            // El texto de SMTP_HOST tal cual («» si está vacía), como el backend anterior.
            'smtp_host' => $a->smtpHost ?? '', 'smtp_puerto' => $a->smtpPuerto, 'smtp_seguridad' => $this->seguridadEntorno(),
            'graph_tenant_id' => null, 'graph_client_id' => null, 'graph_client_secret' => null,
            'ad_servidor' => $a->adServidor, 'ad_base_dn' => '' === $a->adBaseDn ? null : $a->adBaseDn,
            'ad_grupo_base_dn' => '' === $a->adGrupoBaseDn ? null : $a->adGrupoBaseDn,
            'ad_grupo_admin' => '' === $a->adGrupoAdmin ? null : $a->adGrupoAdmin,
            'ad_start_tls' => $a->adStartTls, 'ad_verificar_cert' => $a->adVerificarCertificado,
            'ad_intervalo_min' => $a->adIntervaloMinutos, 'ad_desactivar' => $a->adDesactivar,
            'ad_usar_cuenta_servicio' => false,
            'ad_bind_usuario' => $a->adUsuarioBind, 'ad_bind_password' => $a->adPasswordBind,
        ];
    }

    /** @return list<string> */
    public static function servidores(mixed $valor): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $valor)), static fn (string $s): bool => '' !== $s));
    }

    /** @param list<string> $servidores */
    private function ad(
        bool $habilitado,
        array $servidores,
        string $baseDn,
        string $grupoBaseDn,
        string $grupoAdmin,
        bool $startTls,
        bool $verificarCertificado,
        ?string $usuarioBind,
        ?string $passwordBind,
        int $intervaloMinutos,
        bool $desactivar,
    ): ConfigAd {
        $a = $this->ajustes;

        return new ConfigAd(
            habilitado: $habilitado,
            servidores: $servidores,
            baseDn: $baseDn,
            grupoBaseDn: $grupoBaseDn,
            filtroUsuarios: $a->adFiltroUsuarios,
            grupoAdmin: $grupoAdmin,
            startTls: $startTls,
            verificarCertificado: $verificarCertificado,
            caCertificado: $a->adCaCertificado,
            usuarioBind: $usuarioBind,
            passwordBind: $passwordBind,
            timeoutSegundos: $a->adTimeoutSegundos,
            cacheGruposSegundos: $a->adCacheGruposSegundos,
            intervaloMinutos: $intervaloMinutos,
            desactivar: $desactivar,
            departamentoDefecto: $a->adDepartamentoDefecto,
            cargoDefecto: $a->adCargoDefecto,
        );
    }

    private function seguridadEntorno(): string
    {
        $a = $this->ajustes;

        return $a->smtpTls && 465 === $a->smtpPuerto ? 'SSL' : ($a->smtpStarttls ? 'STARTTLS' : 'NINGUNA');
    }

    private static function texto(mixed $valor): ?string
    {
        return null === $valor || '' === $valor ? null : (string) $valor;
    }
}
