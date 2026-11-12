<?php

declare(strict_types=1);

namespace App\Integracion;

use App\Integracion\Infrastructure\RepositorioIntegracion;
use App\Config\Ajustes;
use App\Correo\Infrastructure\ColaCorreos;
use App\Correo\Cortacircuitos;
use App\Correo\EntregaCorreo;
use App\Correo\ErrorEnvioCorreo;
use App\Directorio\ErrorAutenticacionDirectorio;
use App\Directorio\ErrorDirectorio;
use App\Directorio\FabricaDirectorio;
use App\Repositorio\GobiernoRepositorio;
use App\Db\Transaccion;

/**
 * Cuenta de servicio (correo + AD) desde la interfaz: lectura sin secretos,
 * guardado auditado (sin secretos en la bitácora) y pruebas con los valores
 * del formulario, sin guardarlos.
 */
final readonly class ServicioIntegracion
{
    private const string ASUNTO_PRUEBA = '[Inventario] Prueba de la cuenta de servicio';
    private const string HTML_PRUEBA = <<<'HTML'

        <div style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937">
          <h2>Correo de prueba</h2>
          <p>La cuenta de servicio del Sistema de Inventario TI puede enviar correos correctamente.</p>
          <p style="color:#6b7280;font-size:12px">Remitente: %s · Proveedor: %s</p>
        </div>

        HTML;
    /** Campos públicos, en el orden del contrato. */
    private const array PUBLICOS = ['modo', 'proveedor', 'cuenta_email', 'cuenta_usuario', 'nombre_remitente', 'smtp_host',
        'smtp_puerto', 'smtp_seguridad', 'graph_tenant_id', 'graph_client_id', 'ad_servidor', 'ad_base_dn', 'ad_grupo_base_dn',
        'ad_grupo_admin', 'ad_start_tls', 'ad_verificar_cert', 'ad_intervalo_min', 'ad_desactivar', 'ad_usar_cuenta_servicio',
        'ad_bind_usuario'];

    public function __construct(
        private Transaccion $transaccion,
        private RepositorioIntegracion $repositorio,
        private FabricaConfigIntegracion $fabrica,
        private FuenteIntegracion $fuente,
        private Cortacircuitos $cortacircuitos,
        private EntregaCorreo $entrega,
        private FabricaDirectorio $directorios,
        private ColaCorreos $cola,
        private GobiernoRepositorio $gobierno,
        private Ajustes $ajustes,
    ) {
    }

    /** @return array<string, mixed> */
    public function obtener(): array
    {
        [$valores, $origen] = $this->guardados();

        return $this->salida($valores, $origen);
    }

    /**
     * @param array<string, mixed> $datos formulario completo (secretos: null = conservar, "" = borrar)
     *
     * @return array<string, mixed>
     */
    public function guardar(array $datos, ?string $usuarioHex, ?string $ip): array
    {
        $valores = $this->transaccion->ejecutar(function () use ($datos, $usuarioHex, $ip): ?array {
            if (!$this->repositorio->existe()) {
                // Primer guardado desde la interfaz: los secretos que el formulario deja
                // «sin cambios» se heredan del entorno.
                $entorno = $this->fabrica->valoresEntorno();
                foreach (array_keys(RepositorioIntegracion::SECRETOS) as $secreto) {
                    if (null === ($datos[$secreto] ?? null) && null !== $entorno[$secreto] && '' !== $entorno[$secreto]) {
                        $datos[$secreto] = $entorno[$secreto];
                    }
                }
            }
            $this->repositorio->guardar($datos, $usuarioHex);
            $modificados = array_values(array_filter(array_keys(RepositorioIntegracion::SECRETOS), static fn (string $s): bool => null !== ($datos[$s] ?? null)));
            sort($modificados);
            $this->gobierno->registrarAuditoria('UPDATE', 'SYS_INTEGRACION_CORREO', [
                ...array_diff_key($datos, RepositorioIntegracion::SECRETOS),
                // Nunca se auditan secretos: solo cuáles cambiaron.
                'secretos_modificados' => $modificados,
            ], $usuarioHex, $ip);

            return $this->repositorio->valores();
        });
        $this->fuente->escribir($valores);
        // Credenciales nuevas: se reanudan los intentos y salen los correos retenidos.
        $this->cortacircuitos->reiniciar();
        $this->cola->reanudarRetenidos();

        return $this->salida($valores ?? [], ConfigIntegracion::FUENTE_INTERFAZ);
    }

    /** Desbloqueo manual: reanuda envíos y consultas pausados por un fallo de autenticación. */
    public function desbloquear(): void
    {
        $this->cortacircuitos->reiniciar();
        $this->cola->reanudarRetenidos();
    }

    /**
     * Envía un correo real con los valores del formulario.
     *
     * @param array<string, mixed> $formulario
     *
     * @return array{ok: bool, codigo: string, mensaje: string, usuarios_encontrados: int}
     */
    public function probarCorreo(array $formulario, string $destinatario): array
    {
        $config = $this->delFormulario($formulario)->correo;
        if (!$config->lista()) {
            return self::resultado(false, 'NOT_CONFIGURED', 'Modo desactivado o faltan datos del proveedor.');
        }
        try {
            $this->entrega->entregar($config, [$destinatario], self::ASUNTO_PRUEBA,
                \sprintf(self::HTML_PRUEBA, $config->remitente, $config->proveedor), usarCortacircuitos: false);
        } catch (ErrorEnvioCorreo $e) {
            return self::resultado(false, $e->codigo, $e->detalle);
        }
        $this->cortacircuitos->reiniciar('smtp');

        return self::resultado(true, 'OK', "Correo enviado a {$destinatario}");
    }

    /**
     * Bind y búsqueda de usuarios con los valores del formulario.
     *
     * @param array<string, mixed> $formulario
     *
     * @return array{ok: bool, codigo: string, mensaje: string, usuarios_encontrados: int}
     */
    public function probarAd(array $formulario): array
    {
        $config = $this->delFormulario([...$formulario, 'modo' => ModoIntegracion::CORREO_AD->value])->ad;
        if (!$config->configurada()) {
            return self::resultado(false, 'NOT_CONFIGURED', 'Faltan servidor, base DN o usuario de conexión.');
        }
        try {
            $total = $this->directorios->paraPrueba($config)->probarConexion();
        } catch (ErrorAutenticacionDirectorio $e) {
            return self::resultado(false, 'AUTH_FAILED', $e->getMessage());
        } catch (ErrorDirectorio $e) {
            return self::resultado(false, 'CONNECTION_FAILED', $e->getMessage());
        }
        $this->cortacircuitos->reiniciar('ad');

        return self::resultado(true, 'OK', 'Conexión correcta', $total);
    }

    /** @return array{0: array<string, mixed>, 1: string} [valores, origen] */
    private function guardados(): array
    {
        $valores = $this->repositorio->valores();

        return null === $valores
            ? [$this->fabrica->valoresEntorno(), ConfigIntegracion::FUENTE_ENTORNO]
            : [$valores, ConfigIntegracion::FUENTE_INTERFAZ];
    }

    /**
     * Formulario sin guardar + secretos guardados si el formulario no los trae.
     *
     * @param array<string, mixed> $formulario
     */
    private function delFormulario(array $formulario): ConfigIntegracion
    {
        [$guardados] = $this->guardados();
        foreach (array_keys(RepositorioIntegracion::SECRETOS) as $secreto) {
            if (null === ($formulario[$secreto] ?? null)) {
                $formulario[$secreto] = $guardados[$secreto] ?? null;
            }
        }

        return $this->fabrica->desdeValores($formulario, ConfigIntegracion::FUENTE_PRUEBA);
    }

    /**
     * @param array<string, mixed> $v
     *
     * @return array<string, mixed>
     */
    private function salida(array $v, string $origen): array
    {
        $publico = [];
        foreach (self::PUBLICOS as $campo) {
            $publico[$campo] = $v[$campo] ?? null;
        }
        $publico['smtp_puerto'] = $publico['smtp_puerto'] ?: 587;
        $publico['smtp_seguridad'] = $publico['smtp_seguridad'] ?: 'STARTTLS';
        $publico['ad_intervalo_min'] = $publico['ad_intervalo_min'] ?: 0;
        // Lógicos con su valor por defecto del contrato si faltan.
        foreach (['ad_start_tls' => false, 'ad_verificar_cert' => true, 'ad_desactivar' => true, 'ad_usar_cuenta_servicio' => true] as $campo => $defecto) {
            $publico[$campo] = null === $publico[$campo] ? $defecto : (bool) $publico[$campo];
        }
        $publico['modo'] ??= ModoIntegracion::DESACTIVADO->value;
        $publico['proveedor'] ??= 'SMTP';
        $hasta = fn (string $tipo): ?string => null === ($fin = $this->cortacircuitos->pausadoHasta($tipo))
            ? null : self::isoDesdeEpoch($fin);

        return [
            ...$publico,
            'origen' => $origen,
            'password_configurada' => null !== ($v['cuenta_password'] ?? null) && '' !== $v['cuenta_password'],
            'graph_secret_configurado' => null !== ($v['graph_client_secret'] ?? null) && '' !== $v['graph_client_secret'],
            'ad_bind_password_configurada' => null !== ($v['ad_bind_password'] ?? null) && '' !== $v['ad_bind_password'],
            'bloqueo' => ['smtp_hasta' => $hasta('smtp'), 'ad_hasta' => $hasta('ad')],
            'cifrado_ok' => [] !== $this->ajustes->clavesCifrado,
        ];
    }

    /** Epoch → ISO en UTC sin zona, con microsegundos si los hay (como `datetime.isoformat()`). */
    private static function isoDesdeEpoch(float $epoch): string
    {
        $fecha = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $epoch), new \DateTimeZone('UTC'));

        return false === $fecha ? gmdate('Y-m-d\TH:i:s', (int) $epoch) : \App\Dominio\Reloj::iso($fecha);
    }

    /** @return array{ok: bool, codigo: string, mensaje: string, usuarios_encontrados: int} */
    private static function resultado(bool $ok, string $codigo, string $mensaje, int $usuarios = 0): array
    {
        return ['ok' => $ok, 'codigo' => $codigo, 'mensaje' => $mensaje, 'usuarios_encontrados' => $usuarios];
    }
}
