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

    /**
     * RecepcionOrden: consumibles, activos, licencias y otros (conformidad), con
     * documento, observación y diferencias justificadas. `payload` es la forma
     * normalizada que se guarda si la entrega pasa por aprobación.
     *
     * @return array<string, mixed>
     */
    public static function recepcion(Entrada $e): array
    {
        $precio = static fn (): Campo => new Campo('precio_unitario', Campo::DECIMAL, max: 14, escala: 2, minimo: 0);
        $lista = static function (string $campo, \Closure $leer) use ($e): array {
            $salida = [];
            foreach ($e->lista($campo, 10000) ?? [] as $item) {
                $sub = Entrada::de(\is_array($item) ? $item : []);
                $salida[] = $leer($sub);
                $e->absorber($sub);
            }

            return $salida;
        };
        $consumibles = $lista('consumibles', static function (Entrada $s) use ($precio): array {
            [, $l] = $s->campo(Campo::entero('OCL_Linea', true), true);
            [, $c] = $s->campo(Campo::entero('CON_Consumible', true), true);
            [, $n] = $s->campo(Campo::entero('cantidad', true, minimo: 1), true);
            [, $p] = $s->campo($precio(), true);

            return ['OCL_Linea' => (int) $l, 'CON_Consumible' => (int) $c, 'cantidad' => (int) $n, 'precio_unitario' => null === $p ? null : (string) $p];
        });
        $activos = $lista('activos', static function (Entrada $s): array {
            $d = [];
            foreach ([Campo::entero('OCL_Linea', true), Campo::texto('ACT_Codigo_Interno', min: 3, max: 50), Campo::texto('ACT_Serie_Fabricante', true, 3, 100),
                Campo::texto('ACT_Hostname', max: 100), Campo::entero('MOD_Modelo', true), Campo::entero('TAC_Tipo_Activo', true),
                new Campo('ACT_Fecha_Compra', Campo::FECHA, requerido: true), new Campo('ACT_Fin_Garantia', Campo::FECHA),
                new Campo('ACT_Costo', Campo::DECIMAL, max: 12, escala: 2, minimo: 0)] as $c) {
                [, $d[$c->nombre]] = $s->campo($c, true);
            }
            $d['ACT_Costo'] = null === $d['ACT_Costo'] ? null : (string) $d['ACT_Costo'];

            return $d;
        });
        $licencias = $lista('licencias', static function (Entrada $s) use ($precio): array {
            $d = [];
            foreach ([Campo::entero('OCL_Linea', true), Campo::entero('SOF_Software', true), Campo::entero('TLI_Tipo_Licencia', true),
                Campo::entero('cantidad', minimo: 1), new Campo('LIC_Fecha_Vencimiento', Campo::FECHA), Campo::texto('LIC_Clave_Activacion', max: 255), $precio()] as $c) {
                [, $d[$c->nombre]] = $s->campo($c, true);
            }
            $d['precio_unitario'] = null === $d['precio_unitario'] ? null : (string) $d['precio_unitario'];
            $d['claves'] = SoftwareController::leerClaves($s, 'claves');
            if ([] !== $d['claves']) {
                if (null !== $d['cantidad'] && $d['cantidad'] !== \count($d['claves'])) {
                    $s->errorDeValor('claves', 'LICENSE_KEY_COUNT_MISMATCH', $d['claves']);
                }
                $d['cantidad'] = \count($d['claves']);
            }
            if (null === $d['cantidad'] || 0 === $d['cantidad']) {
                $s->errorDeValor('cantidad', 'LICENSE_QUANTITY_REQUIRED', null);
            }

            return $d;
        });
        $otros = $lista('otros', static function (Entrada $s) use ($precio): array {
            [, $l] = $s->campo(Campo::entero('OCL_Linea', true), true);
            [, $n] = $s->campo(Campo::entero('cantidad', true, minimo: 1), true);
            [, $p] = $s->campo($precio(), true);

            return ['OCL_Linea' => (int) $l, 'cantidad' => (int) $n, 'precio_unitario' => null === $p ? null : (string) $p];
        });
        $diferencias = $lista('diferencias', static function (Entrada $s): array {
            [, $l] = $s->campo(Campo::entero('OCL_Linea', true), true);
            [, $m] = $s->campo(Campo::texto('motivo', true, 5, 500), true);
            [, $a] = $s->campo(Campo::logico('aceptar_sobrante'), true);

            return ['OCL_Linea' => (int) $l, 'motivo' => (string) $m, 'aceptar_sobrante' => true === $a];
        });
        [, $documento] = $e->campo(Campo::texto('documento', max: 100), true);
        [, $observacion] = $e->campo(Campo::texto('observacion', max: 500), true);
        $e->validar();
        $r = ['consumibles' => $consumibles, 'activos' => $activos, 'licencias' => $licencias, 'otros' => $otros,
            'documento' => $documento, 'observacion' => $observacion, 'diferencias' => $diferencias];
        $r['payload'] = $r;

        return $r;
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
