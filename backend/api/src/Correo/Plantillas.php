<?php

declare(strict_types=1);

namespace App\Correo;

use App\Config\Ajustes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;

/**
 * Plantillas de correo (templates/correo/*.html.twig, Twig con autoescape HTML:
 * los datos del contexto nunca inyectan marcado). Mismos nombres, asuntos y
 * contenido que el backend anterior.
 */
final class Plantillas
{
    public const array ASUNTOS = [
        'asignacion' => '[Inventario] Activo asignado: {codigo}',
        'devolucion' => '[Inventario] Activo devuelto: {codigo}',
        'transferencia' => '[Inventario] Transferencia de activo: {codigo}',
        'baja' => '[Inventario] Activo dado de baja: {codigo}',
        'offboarding' => '[Inventario] Salida de empleado: {persona_nombre}',
        'mantenimiento_abierto' => '[Inventario] Mantenimiento abierto: {codigo}',
        'mantenimiento_cerrado' => '[Inventario] Mantenimiento cerrado: {codigo}',
        'stock_bajo' => '[Inventario] Stock bajo: {codigo}',
        'recepcion_diferencias' => '[Inventario] Compra {numero}: diferencias en la recepción',
        'garantia_por_vencer' => '[Inventario] Garantías por vencer: {total}',
        'ingreso_registrado' => '[Inventario] Ingreso {codigo} por confirmar',
        'alerta_operacion' => '[Inventario] Alerta del sistema: {codigo}',
        'ad_sync_pendientes' => '[Inventario] Active Directory: salidas pendientes con activos ({total})',
        'password_reset' => '[Inventario] Restablecimiento de contraseña',
        'password_changed' => '[Inventario] Su contraseña fue cambiada',
        '2fa_code' => '[Inventario] Su código de verificación',
        'nuevo_dispositivo' => '[Inventario] Nuevo inicio de sesión en su cuenta',
        'cuenta_desactivada_inactividad' => '[Inventario] Su cuenta fue desactivada por inactividad',
    ];

    private const string ISO_FECHA_HORA = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    private readonly Environment $twig;

    public function __construct(
        private readonly Ajustes $ajustes,
        #[Autowire('%kernel.project_dir%/templates/correo')] string $directorio,
        #[Autowire('%kernel.cache_dir%/twig_correo')] string $cache,
    ) {
        $this->twig = new Environment(new FilesystemLoader($directorio), [
            'autoescape' => 'html',
            'strict_variables' => false,
            'cache' => $cache,
        ]);
    }

    /**
     * @param array<string, mixed> $contexto
     *
     * @return array{0: string, 1: string} [asunto, html]
     */
    public function renderizar(string $plantilla, array $contexto): array
    {
        if (!isset(self::ASUNTOS[$plantilla])) {
            throw new \InvalidArgumentException("Plantilla desconocida: {$plantilla}");
        }
        $contexto = $this->fechasLegibles($contexto);
        $html = $this->twig->render("{$plantilla}.html.twig", [
            ...$contexto,
            'style' => new Markup($this->twig->render('_estilo.html.twig'), 'UTF-8'),
            'operator_block' => new Markup($this->twig->render('_operador.html.twig', $contexto), 'UTF-8'),
        ]);
        $asunto = (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn (array $m): string => \is_scalar($contexto[$m[1]] ?? null) ? (string) $contexto[$m[1]] : '',
            self::ASUNTOS[$plantilla],
        );

        // Un asunto nunca lleva saltos de línea (cabecera de una sola línea).
        return [trim((string) preg_replace('/[\r\n]+/', ' ', $asunto)), $html];
    }

    /** Versión en texto del correo (clientes sin HTML y filtros antispam). */
    public static function textoPlano(string $html): string
    {
        $texto = (string) preg_replace('#<(style|script)[^>]*>.*?</\1>#is', '', $html);
        $texto = (string) preg_replace('#<br\s*/?>|</(p|div|tr|h1|h2|li)>#i', "\n", $texto);
        $texto = (string) preg_replace('#</td>\s*<td[^>]*>#i', ': ', $texto);
        $texto = html_entity_decode(strip_tags($texto), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $lineas = array_filter(array_map('trim', explode("\n", $texto)), static fn (string $l): bool => '' !== $l);

        return implode("\n", $lineas)."\n";
    }

    /**
     * Las fechas ISO en UTC ("2026-09-29T18:04:11.532") se muestran en la zona
     * de la organización y en formato "29/09/2026 12:04".
     *
     * @param array<string, mixed> $contexto
     *
     * @return array<string, mixed>
     */
    private function fechasLegibles(array $contexto): array
    {
        $zona = new \DateTimeZone($this->ajustes->zonaHoraria);
        foreach ($contexto as $clave => $valor) {
            if (\is_string($valor) && 1 === preg_match(self::ISO_FECHA_HORA, $valor)) {
                try {
                    $fecha = new \DateTimeImmutable(str_replace('Z', '+00:00', $valor), new \DateTimeZone('UTC'));
                    $contexto[$clave] = $fecha->setTimezone($zona)->format('d/m/Y H:i');
                } catch (\Exception) {
                }
            }
        }

        return $contexto;
    }
}
