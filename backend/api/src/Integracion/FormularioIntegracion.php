<?php

declare(strict_types=1);

namespace App\Integracion;

use App\Crud\Campo;
use App\Http\Entrada;

/**
 * Formulario de la cuenta de servicio (`IntegracionUpdate` del backend anterior):
 * todos los campos con su valor por defecto, límites y literales, y las reglas
 * del modelo completo (qué exige cada modo). Los textos no se recortan.
 */
final class FormularioIntegracion
{
    /** Textos opcionales y su largo máximo. */
    private const array TEXTOS = [
        'cuenta_usuario' => 150, 'nombre_remitente' => 100, 'smtp_host' => 150, 'graph_tenant_id' => 100,
        'graph_client_id' => 100, 'ad_servidor' => 300, 'ad_base_dn' => 300, 'ad_grupo_base_dn' => 300,
        'ad_grupo_admin' => 150, 'ad_bind_usuario' => 150,
        // Secretos: omitido o null = conservar el guardado; "" = borrarlo.
        'cuenta_password' => 500, 'graph_client_secret' => 500, 'ad_bind_password' => 500,
    ];
    private const array LOGICOS = ['ad_start_tls' => false, 'ad_verificar_cert' => true, 'ad_desactivar' => true, 'ad_usar_cuenta_servicio' => true];

    /**
     * Lee y valida el formulario. Los errores quedan en `$e` (el llamador hace
     * validar()); las reglas del modelo se comprueban después, con exigirReglas().
     *
     * @return array<string, mixed> todos los campos, con sus valores por defecto
     */
    public static function leer(Entrada $e): array
    {
        // En el orden de los campos del contrato: los errores salen en ese orden.
        $orden = ['modo', 'proveedor', 'cuenta_email', 'cuenta_usuario', 'nombre_remitente', 'smtp_host', 'smtp_puerto',
            'smtp_seguridad', 'graph_tenant_id', 'graph_client_id', 'ad_servidor', 'ad_base_dn', 'ad_grupo_base_dn', 'ad_grupo_admin',
            'ad_start_tls', 'ad_verificar_cert', 'ad_intervalo_min', 'ad_desactivar', 'ad_usar_cuenta_servicio', 'ad_bind_usuario',
            'cuenta_password', 'graph_client_secret', 'ad_bind_password'];
        $d = [];
        foreach ($orden as $campo) {
            $d[$campo] = match (true) {
                'modo' === $campo => self::literal($e, $campo, ModoIntegracion::valores(), 'DESACTIVADO'),
                'proveedor' === $campo => self::literal($e, $campo, ['SMTP', 'GRAPH'], 'SMTP'),
                'smtp_seguridad' === $campo => self::literal($e, $campo, ['STARTTLS', 'SSL', 'NINGUNA'], 'STARTTLS'),
                'cuenta_email' === $campo => $e->campo(new Campo($campo, Campo::CORREO), false)[1],
                'smtp_puerto' === $campo => self::entero($e, $campo, 1, 65535, 587),
                'ad_intervalo_min' === $campo => self::entero($e, $campo, 0, 10080, 0),
                isset(self::LOGICOS[$campo]) => self::logico($e, $campo, self::LOGICOS[$campo]),
                default => $e->texto($campo, max: self::TEXTOS[$campo], opcional: true),
            };
        }

        return $d;
    }

    /**
     * Lee el formulario y, si sus campos son válidos, comprueba las reglas del
     * modelo (la primera que falle queda como error del objeto).
     *
     * @return array<string, mixed>
     */
    public static function leerYComprobar(Entrada $e): array
    {
        $d = self::leer($e);
        if (!$e->conErrores()) {
            $codigo = self::reglaIncumplida($d);
            if (null !== $codigo) {
                $e->errorDeModelo($codigo);
            }
        }

        return $d;
    }

    /**
     * Código de la primera regla del modelo que no se cumple (qué exige cada modo).
     *
     * @param array<string, mixed> $d
     */
    public static function reglaIncumplida(array $d): ?string
    {
        if ('DESACTIVADO' === $d['modo']) {
            return null;
        }

        return match (true) {
            null === $d['cuenta_email'] || '' === $d['cuenta_email'] => 'CUENTA_EMAIL_REQUERIDA',
            'SMTP' === $d['proveedor'] && (null === $d['smtp_host'] || '' === $d['smtp_host']) => 'SMTP_HOST_REQUERIDO',
            'GRAPH' === $d['proveedor'] && (null === $d['graph_tenant_id'] || '' === $d['graph_tenant_id']
                || null === $d['graph_client_id'] || '' === $d['graph_client_id']) => 'GRAPH_TENANT_Y_CLIENT_REQUERIDOS',
            'CORREO_AD' !== $d['modo'] => null,
            null === $d['ad_servidor'] || '' === $d['ad_servidor'] || null === $d['ad_base_dn'] || '' === $d['ad_base_dn'] => 'AD_SERVIDOR_Y_BASE_DN_REQUERIDOS',
            !self::servidoresLdap((string) $d['ad_servidor']) => 'AD_SERVIDOR_DEBE_SER_LDAP_O_LDAPS',
            !$d['ad_usar_cuenta_servicio'] && (null === $d['ad_bind_usuario'] || '' === $d['ad_bind_usuario']) => 'AD_BIND_USUARIO_REQUERIDO',
            default => null,
        };
    }

    private static function servidoresLdap(string $servidores): bool
    {
        foreach (explode(',', $servidores) as $url) {
            $url = strtolower(trim($url));
            if ('' !== $url && !str_starts_with($url, 'ldap://') && !str_starts_with($url, 'ldaps://')) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $permitidos */
    private static function literal(Entrada $e, string $campo, array $permitidos, string $defecto): ?string
    {
        if (!$e->presente($campo)) {
            return $defecto;
        }
        if (null === $e->crudo($campo)) {
            $esperado = Entrada::literales($permitidos);
            $e->errorDeTipo('literal_error', $campo, "Input should be {$esperado}", null, ['expected' => $esperado]);

            return null;
        }

        return $e->opcion($campo, $permitidos);
    }

    private static function entero(Entrada $e, string $campo, int $minimo, int $maximo, int $defecto): ?int
    {
        if (!$e->presente($campo)) {
            return $defecto;
        }
        if (null === $e->crudo($campo)) {
            $e->errorDeTipo('int_type', $campo, 'Input should be a valid integer', null);

            return null;
        }
        $valor = $e->campo(Campo::entero($campo, minimo: $minimo, maximo: $maximo), false)[1];

        return null === $valor ? null : (int) $valor;
    }

    private static function logico(Entrada $e, string $campo, bool $defecto): ?bool
    {
        if (!$e->presente($campo)) {
            return $defecto;
        }
        if (null === $e->crudo($campo)) {
            $e->errorDeTipo('bool_type', $campo, 'Input should be a valid boolean', null);

            return null;
        }
        $valor = $e->campo(Campo::logico($campo), false)[1];

        return null === $valor ? null : (bool) $valor;
    }
}
