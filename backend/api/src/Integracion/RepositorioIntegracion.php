<?php

declare(strict_types=1);

namespace App\Integracion;

use App\Dominio\Reloj;
use App\Seguridad\CifradoCampos;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Fila única de SYS_INTEGRACION_CORREO (cuenta de servicio editada desde la
 * interfaz) en formato de la API: claves como las del formulario, lógicos y
 * enteros con su tipo y secretos descifrados (Fernet, mismas claves que el
 * backend anterior). Uso interno: nunca se devuelven secretos al cliente.
 */
final readonly class RepositorioIntegracion
{
    /** Clave de la API => columna. */
    public const array COLUMNAS = [
        'modo' => 'INT_Modo', 'proveedor' => 'INT_Proveedor',
        'cuenta_email' => 'INT_Cuenta_Email', 'cuenta_usuario' => 'INT_Cuenta_Usuario',
        'nombre_remitente' => 'INT_Nombre_Remitente',
        'smtp_host' => 'INT_SMTP_Host', 'smtp_puerto' => 'INT_SMTP_Puerto', 'smtp_seguridad' => 'INT_SMTP_Seguridad',
        'graph_tenant_id' => 'INT_Graph_Tenant_Id', 'graph_client_id' => 'INT_Graph_Client_Id',
        'ad_servidor' => 'INT_AD_Servidor', 'ad_base_dn' => 'INT_AD_Base_DN', 'ad_grupo_base_dn' => 'INT_AD_Grupo_Base_DN',
        'ad_grupo_admin' => 'INT_AD_Grupo_Admin', 'ad_start_tls' => 'INT_AD_Start_TLS',
        'ad_verificar_cert' => 'INT_AD_Verificar_Cert', 'ad_intervalo_min' => 'INT_AD_Intervalo_Min',
        'ad_desactivar' => 'INT_AD_Desactivar', 'ad_usar_cuenta_servicio' => 'INT_AD_Usar_Cuenta_Servicio',
        'ad_bind_usuario' => 'INT_AD_Bind_Usuario',
    ];
    /** Secretos (clave de la API => columna cifrada). */
    public const array SECRETOS = [
        'cuenta_password' => 'INT_Cuenta_Password_Enc',
        'graph_client_secret' => 'INT_Graph_Secret_Enc',
        'ad_bind_password' => 'INT_AD_Bind_Password_Enc',
    ];
    private const array LOGICOS = ['ad_start_tls', 'ad_verificar_cert', 'ad_desactivar', 'ad_usar_cuenta_servicio'];
    private const array ENTEROS = ['smtp_puerto', 'ad_intervalo_min'];
    /** Valores de una fila nueva para las columnas obligatorias que no se envíen. */
    private const array POR_DEFECTO = [
        'modo' => 'DESACTIVADO', 'proveedor' => 'SMTP', 'smtp_puerto' => 587, 'smtp_seguridad' => 'STARTTLS',
        'ad_start_tls' => false, 'ad_verificar_cert' => true, 'ad_intervalo_min' => 0, 'ad_desactivar' => true,
        'ad_usar_cuenta_servicio' => true,
    ];

    public function __construct(
        private Connection $conexion,
        private CifradoCampos $cifrado,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Valores guardados, o null si la interfaz nunca guardó la configuración.
     *
     * @return array<string, mixed>|null
     */
    public function valores(): ?array
    {
        $columnas = implode(', ', array_map(static fn (string $c): string => "\"{$c}\"", [...self::COLUMNAS, ...self::SECRETOS]));
        $fila = $this->conexion->fetchAssociative("SELECT {$columnas} FROM \"SYS_INTEGRACION_CORREO\" WHERE \"INT_Id\" = 1");

        return false === $fila ? null : $this->aValores($fila);
    }

    public function existe(): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "SYS_INTEGRACION_CORREO" WHERE "INT_Id" = 1');
    }

    /**
     * Guarda los valores enviados. Secretos: null = conservar el guardado,
     * "" = borrarlo, otro valor = cifrarlo. Debe llamarse dentro de una transacción.
     *
     * @param array<string, mixed> $datos
     */
    public function guardar(array $datos, ?string $usuarioHex): void
    {
        $columnas = ['INT_Actualizado_En' => Reloj::aBd(Reloj::ahora()), 'USU_Actualizado_Por' => $usuarioHex];
        foreach (self::COLUMNAS as $clave => $columna) {
            if (\array_key_exists($clave, $datos)) {
                $columnas[$columna] = self::aColumna($datos[$clave]);
            }
        }
        foreach (self::SECRETOS as $clave => $columna) {
            $valor = $datos[$clave] ?? null;
            if (null !== $valor) {
                $columnas[$columna] = '' === $valor ? null : $this->cifrado->cifrar((string) $valor);
            }
        }

        if ($this->existe()) {
            $this->actualizar($columnas);

            return;
        }
        foreach (self::POR_DEFECTO as $clave => $valor) {
            $columnas[self::COLUMNAS[$clave]] ??= self::aColumna($valor);
        }
        try {
            $this->conexion->insert('"SYS_INTEGRACION_CORREO"', ['"INT_Id"' => 1, ...self::citadas($columnas)]);
        } catch (UniqueConstraintViolationException) {
            // Otro guardado creó la fila a la vez: se aplica como actualización.
            $this->actualizar($columnas);
        }
    }

    /** @param array<string, mixed> $columnas */
    private function actualizar(array $columnas): void
    {
        $this->conexion->update('"SYS_INTEGRACION_CORREO"', self::citadas($columnas), ['"INT_Id"' => 1]);
    }

    /**
     * @param array<string, mixed> $columnas
     *
     * @return array<string, mixed>
     */
    private static function citadas(array $columnas): array
    {
        $citadas = [];
        foreach ($columnas as $columna => $valor) {
            $citadas["\"{$columna}\""] = $valor;
        }

        return $citadas;
    }

    private static function aColumna(mixed $valor): mixed
    {
        return \is_bool($valor) ? ($valor ? 1 : 0) : $valor;
    }

    /**
     * @param array<string, mixed> $fila
     *
     * @return array<string, mixed>
     */
    private function aValores(array $fila): array
    {
        $valores = [];
        foreach (self::COLUMNAS as $clave => $columna) {
            $v = $fila[$columna];
            $valores[$clave] = match (true) {
                null === $v => null,
                \in_array($clave, self::LOGICOS, true) => 1 === (int) $v,
                \in_array($clave, self::ENTEROS, true) => (int) $v,
                default => (string) $v,
            };
        }
        foreach (self::SECRETOS as $clave => $columna) {
            $valores[$clave] = $this->descifrar($clave, $fila[$columna]);
        }

        return $valores;
    }

    private function descifrar(string $clave, mixed $cifrado): ?string
    {
        if (null === $cifrado || '' === $cifrado) {
            return null;
        }
        try {
            return $this->cifrado->descifrar((string) $cifrado) ?: null;
        } catch (\Throwable) {
            // Clave de cifrado rotada o perdida: sin el secreto, la entrega o el bind fallarán.
            $this->logger->error('integracion.secreto_no_descifrable', ['campo' => $clave]);

            return null;
        }
    }
}
