<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Controller\SoftwareController;
use App\Crud\Campo;
use App\Dominio\Ident;
use App\Http\Entrada;
use App\Http\ErrorValidacion;

/**
 * Esquemas de entrada de las operaciones que pasan por aprobación: los usan los
 * controladores y, al aprobar, la ejecución vuelve a leer el `payload` guardado
 * con el mismo esquema (también los creados por el backend anterior).
 */
final class Esquemas
{
    private const string CODIGO_CATALOGO = '^[A-Za-z0-9_]{1,30}$';

    /** @return array{motivo: string, destino: string, fecha: ?string, documento: ?string, observacion: ?string, retirar_componentes: bool} */
    public static function baja(Entrada $e): array
    {
        $motivo = $e->patron('motivo', self::CODIGO_CATALOGO);
        $destino = $e->patron('destino', self::CODIGO_CATALOGO);
        foreach (['motivo', 'destino'] as $requerido) {
            if (!$e->tiene($requerido)) {
                $e->campo(Campo::texto($requerido, true), true);
            }
        }
        [, $fecha] = $e->campo(new Campo('fecha', Campo::FECHA), true);
        [, $documento] = $e->campo(Campo::texto('documento', max: 120), true);
        [, $observacion] = $e->campo(Campo::texto('observacion', max: 500), true);
        [, $retirar] = $e->campo(Campo::logico('retirar_componentes'), true);
        $e->validar();

        return ['motivo' => (string) $motivo, 'destino' => (string) $destino, 'fecha' => $fecha, 'documento' => $documento,
            'observacion' => $observacion, 'retirar_componentes' => true === $retirar];
    }

    /**
     * `model_dump(mode="json")` de la baja, para guardarla en la solicitud.
     *
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    public static function bajaJson(array $b): array
    {
        return ['motivo' => $b['motivo'], 'destino' => $b['destino'], 'fecha' => $b['fecha'], 'documento' => $b['documento'],
            'observacion' => $b['observacion'], 'retirar_componentes' => $b['retirar_componentes']];
    }

    /** UUID de la ruta o del cuerpo → hex. */
    public static function hex(?string $uuid): ?string
    {
        return null === $uuid ? null : Ident::aHex($uuid);
    }

    /** 422 de un validador de modelo. */
    public static function errorDeModelo(string $codigo): ErrorValidacion
    {
        return ErrorValidacion::deModelo($codigo);
    }
}
