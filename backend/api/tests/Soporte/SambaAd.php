<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

use App\Tests\Soporte\DatosPrueba as D;

/**
 * Active Directory de pruebas (Samba AD en la red de pruebas, ver
 * docker/pruebas/samba-ad): constantes de la semilla, configuración de la
 * integración apuntando a él y cambios con la cuenta Administrator (LDAPS
 * verificado) para simular bajas y altas. Nunca toca un AD real.
 */
final class SambaAd
{
    public const string SERVIDOR = 'ldaps://dc1.pruebas.local';
    public const string SERVIDOR_STARTTLS = 'ldap://dc1.pruebas.local';
    public const string BASE = 'DC=pruebas,DC=local';
    public const string BASE_USUARIOS = 'OU=Usuarios,DC=pruebas,DC=local';
    public const string BASE_GRUPOS = 'OU=Grupos,DC=pruebas,DC=local';
    public const string CUENTA = 'svc.inventario@pruebas.local';
    public const string CLAVE = 'Servicio.Inventario.2026';
    public const string JEFE = 'jefe.ti@ad.pruebas.local';
    public const string ANA = 'ana.lopez@ad.pruebas.local';
    public const string BAJA = 'baja.ad@ad.pruebas.local';
    public const string DN_ANA = 'CN=Ana María López García,OU=Usuarios,DC=pruebas,DC=local';
    public const string DN_JEFE = 'CN=Carlos De la Cruz Pérez,OU=Usuarios,DC=pruebas,DC=local';
    private const string ADMIN = 'Administrator@pruebas.local';
    private const string ADMIN_CLAVE = 'Pruebas.Admin.2026';

    /**
     * Guarda la configuración de la integración (CORREO_AD, correo por Mailpit,
     * bind con una cuenta propia de AD, no la del correo).
     *
     * @param array<string, mixed> $cambios columna => valor
     */
    public static function configurar(array $cambios = []): void
    {
        $c = D::conexion();
        $c->executeStatement('DELETE FROM "SYS_INTEGRACION_CORREO"');
        $fila = [
            'INT_Id' => 1, 'INT_Modo' => 'CORREO_AD', 'INT_Proveedor' => 'SMTP', 'INT_Cuenta_Email' => 'servicio@pruebas.local',
            'INT_Nombre_Remitente' => 'Inventario', 'INT_SMTP_Host' => 'mailpit', 'INT_SMTP_Puerto' => 1025, 'INT_SMTP_Seguridad' => 'NINGUNA',
            'INT_AD_Servidor' => self::SERVIDOR, 'INT_AD_Base_DN' => self::BASE_USUARIOS, 'INT_AD_Grupo_Base_DN' => self::BASE_GRUPOS,
            'INT_AD_Grupo_Admin' => null, 'INT_AD_Start_TLS' => 0, 'INT_AD_Verificar_Cert' => 1, 'INT_AD_Intervalo_Min' => 0,
            'INT_AD_Desactivar' => 1, 'INT_AD_Usar_Cuenta_Servicio' => 0, 'INT_AD_Bind_Usuario' => self::CUENTA,
            'INT_AD_Bind_Password_Enc' => D::fernet()->cifrar(self::CLAVE),
            ...$cambios,
        ];
        $columnas = array_keys($fila);
        $c->executeStatement('INSERT INTO "SYS_INTEGRACION_CORREO" ("'.implode('", "', $columnas).'") VALUES (:'.implode(', :', $columnas).')', $fila);
    }

    /**
     * Las personas que dejaron pruebas anteriores con correos del AD de pruebas se
     * «retiran» (otro correo, sin vínculo): cada prueba empieza como la primera vez.
     */
    public static function retirarPersonas(): void
    {
        D::conexion()->executeStatement(
            "UPDATE \"INV_PERSONA\" SET \"PER_Email_Corporativo\" = 'r' || \"PER_Persona\" || '@retiradas.local', "
            .'"PER_AD_GUID" = NULL, "PER_AD_DN" = NULL, "PER_Jefe" = NULL, "PER_Estado" = 0 '
            ."WHERE \"PER_Email_Corporativo\" LIKE '%@ad.pruebas.local' OR \"PER_AD_GUID\" IS NOT NULL");
    }

    /** Habilita o deshabilita una cuenta (bit ACCOUNTDISABLE de userAccountControl). */
    public static function habilitar(string $dn, bool $habilitada): void
    {
        self::modificar($dn, ['userAccountControl' => [$habilitada ? '512' : '514']]);
    }

    /** @param array<string, list<string>> $atributos */
    public static function modificar(string $dn, array $atributos): void
    {
        $enlace = self::admin();
        try {
            if (!@ldap_mod_replace($enlace, $dn, $atributos)) {
                throw new \RuntimeException('No se pudo modificar '.$dn.': '.ldap_error($enlace));
            }
        } finally {
            ldap_unbind($enlace);
        }
    }

    private static function admin(): \LDAP\Connection
    {
        ldap_set_option(null, \LDAP_OPT_X_TLS_REQUIRE_CERT, \LDAP_OPT_X_TLS_DEMAND);
        ldap_set_option(null, \LDAP_OPT_X_TLS_CACERTFILE, (string) ($_SERVER['AD_CA_CERT_FILE'] ?? $_ENV['AD_CA_CERT_FILE']));
        $enlace = ldap_connect(self::SERVIDOR);
        if (false === $enlace) {
            throw new \RuntimeException('Sin conexión al AD de pruebas.');
        }
        ldap_set_option($enlace, \LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($enlace, \LDAP_OPT_REFERRALS, 0);
        if (!@ldap_bind($enlace, self::ADMIN, self::ADMIN_CLAVE)) {
            throw new \RuntimeException('Bind de administrador rechazado: '.ldap_error($enlace));
        }

        return $enlace;
    }
}
