<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Dominio\Roles;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * TECNICO y CONSULTA operan el inventario pero no ven valores: se quitan las
 * llaves con dinero de TODA respuesta JSON de /api/ (así ninguna ruta nueva
 * puede filtrar un costo por olvido). El rol lo deja AutenticacionListener.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -128)]
final class OcultarCostosListener
{
    /** Llaves con dinero en las respuestas de la API (modelos y reportes). */
    public const array LLAVES_DINERO = [
        'ACT_Costo', 'CON_Costo_Unitario', 'LIC_Costo', 'KDX_Costo', 'KDX_Saldo_Valor',
        'OCL_Precio_Unitario', 'OCL_Subtotal', 'OCO_Total', 'RCL_Precio_Real', 'precio_unitario', 'precio_real',
        'MAN_Costo_Total', 'DMA_Costo_Item', 'costo', 'costo_total', 'costo_por_moneda',
        'valor_por_moneda', 'costo_inventario_por_moneda', 'costo_mantenimiento_por_moneda', 'saldo_valor', 'valor_saldo',
    ];

    /** Nombre con aspecto de dinero: se oculta aunque nadie lo haya agregado a la lista. */
    private const string PATRON_DINERO = '/cost|precio|price|valor|monto|amount|subtotal/i';
    /** Permitidos: coinciden con el patrón, pero no son dinero. */
    private const array NO_SON_DINERO = ['ESP_Valor', 'valores', 'DEP_Codigo_Costos', 'sin_costo', 'final_sin_costo', 'hay_sin_costo'];

    public static function esDinero(string $llave): bool
    {
        return \in_array($llave, self::LLAVES_DINERO, true)
            || (1 === preg_match(self::PATRON_DINERO, $llave) && !\in_array($llave, self::NO_SON_DINERO, true));
    }

    public static function ocultaCostos(?string $rol): bool
    {
        return null !== $rol && \in_array($rol, Roles::SIN_COSTOS, true);
    }

    /**
     * Quien no ve costos tampoco los escribe: el campo se ignora (como si no
     * viniera), así una edición no borra el costo que registró un administrador.
     *
     * @param array<string, mixed> $datos
     *
     * @return array<string, mixed>
     */
    public static function descartarCostos(array $datos, ?string $rol, string ...$campos): array
    {
        return self::ocultaCostos($rol) ? array_diff_key($datos, array_flip($campos)) : $datos;
    }

    /** Recorre objetos (stdClass, para no convertir `{}` en `[]`) y listas. */
    public static function quitarDinero(mixed $dato): mixed
    {
        if ($dato instanceof \stdClass) {
            $limpio = new \stdClass();
            foreach (get_object_vars($dato) as $clave => $valor) {
                if (!self::esDinero((string) $clave)) {
                    $limpio->{$clave} = self::quitarDinero($valor);
                }
            }

            return $limpio;
        }
        if (\is_array($dato)) {
            return array_map(self::quitarDinero(...), $dato);
        }

        return $dato;
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $respuesta = $event->getResponse();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')
            || !self::ocultaCostos($request->attributes->get('_rol'))
            || !str_starts_with((string) $respuesta->headers->get('Content-Type'), 'application/json')) {
            return;
        }
        $contenido = (string) $respuesta->getContent();
        try {
            $datos = json_decode($contenido, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        $respuesta->setContent(json_encode(self::quitarDinero($datos), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION));
    }
}
