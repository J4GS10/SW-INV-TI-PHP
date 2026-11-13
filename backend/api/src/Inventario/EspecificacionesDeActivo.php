<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\SpecificationRules;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Inventario\Infrastructure\ActivosStore;
use App\Inventario\Infrastructure\DatosActivos;
use App\Inventario\Infrastructure\Kardex;
use App\Repositorio\GobiernoRepositorio;

/**
 * Especificaciones de un activo (RAM, disco, batería…): cada valor se normaliza con las reglas de su
 * tipo y cada cambio queda en el kardex del activo y en la bitácora.
 */
final readonly class EspecificacionesDeActivo
{
    public function __construct(
        private Activos $activos,
        private ActivosStore $datos,
        private DatosActivos $datosComunes,
        private SpecificationRules $specificationRules,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
    ) {
    }

    /** @return list<array<string, mixed>> EspecificacionDetalle, por nombre del tipo */
    public function especificaciones(string $activo): array
    {
        $this->activos->filaO404($activo);

        return array_map(static fn (array $f): array => ['ESP_Especificacion' => (int) $f['ESP_Especificacion'],
            'TES_Tipo_Especificacion' => (int) $f['TES_Tipo_Especificacion'], 'TES_Nombre' => $f['TES_Nombre'],
            'TES_Unidad_Medida' => $f['TES_Unidad_Medida'], 'ESP_Valor' => $f['ESP_Valor']],
            $this->datos->especificacionesConTipo($activo));
    }

    /** @return array<string, mixed> la especificación creada (enriquecida) */
    public function agregarEspecificacion(string $activoId, int $tipo, string $valor, ?string $motivo, string $usuario, ?string $ip): array
    {
        $this->transaccion->ejecutar(function () use ($activoId, $tipo, $valor, $motivo, $usuario, $ip): void {
            $this->activos->bloquearEnOrden([$activoId]);
            $activo = $this->activos->filaO404($activoId);
            if (!$this->datosComunes->existeTipoEspecificacion($tipo)) {
                throw ErrorDominio::noEncontrado('SPECIFICATION_TYPE_NOT_FOUND');
            }
            $valor = $this->specificationRules->normalize((int) $activo['TAC_Tipo_Activo'], $tipo, $valor);
            $this->datosComunes->agregarEspecificacion($activoId, $tipo, $valor);
            $this->kardex->registrar('ESPECIFICACION', activo: $activo, usuario: $usuario, motivo: $motivo,
                detalle: ['tipo' => $tipo, 'antes' => null, 'despues' => mb_substr($valor, 0, 120)]);
            $this->gobierno->registrarAuditoria('CREATE', 'INV_ESPECIFICACION', ['activo' => Ident::aTexto($activoId), 'tipo' => $tipo,
                'valor' => mb_substr($valor, 0, 120)], $usuario, $ip, sedeId: null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede']);
        });
        $todas = $this->especificaciones($activoId);
        foreach ($todas as $e) {
            if ($e['TES_Tipo_Especificacion'] === $tipo) {
                return $e;
            }
        }

        return $todas[\count($todas) - 1];
    }

    /** @return array<string, mixed>|null */
    private function especificacion(int $id): ?array
    {
        $e = $this->datos->especificacion($id);
        if (false === $e || null === $this->activos->fila((string) $e['ACT_Activo'])) {
            return null;
        }

        return $e;
    }

    public function cambiarEspecificacion(int $id, string $valor, ?string $motivo, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $valor, $motivo, $usuario, $ip): void {
            $e = $this->especificacionBloqueada($id);
            $valor = $this->specificationRules->normalize((int) $this->activos->fila((string) $e['ACT_Activo'])['TAC_Tipo_Activo'], (int) $e['TES_Tipo_Especificacion'], $valor);
            $this->datosComunes->cambiarEspecificacion($id, $valor);
            if ($e['ESP_Valor'] !== $valor) {
                $this->kardex->registrar('ESPECIFICACION', activo: $this->activos->fila((string) $e['ACT_Activo']), usuario: $usuario, motivo: $motivo,
                    detalle: ['tipo' => (int) $e['TES_Tipo_Especificacion'], 'antes' => mb_substr((string) $e['ESP_Valor'], 0, 120), 'despues' => mb_substr($valor, 0, 120)]);
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_ESPECIFICACION', ['id' => $id, 'valor' => mb_substr($valor, 0, 120)], $usuario, $ip);
        });
    }

    public function eliminarEspecificacion(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $e = $this->especificacionBloqueada($id);
            $activo = $this->activos->fila((string) $e['ACT_Activo']);
            $this->datosComunes->quitarEspecificacion($id);
            $this->kardex->registrar('ESPECIFICACION', activo: $activo, usuario: $usuario,
                detalle: ['tipo' => (int) $e['TES_Tipo_Especificacion'], 'antes' => mb_substr((string) $e['ESP_Valor'], 0, 120), 'despues' => null]);
            $this->gobierno->registrarAuditoria('DELETE', 'INV_ESPECIFICACION', ['id' => $id, 'tipo' => (int) $e['TES_Tipo_Especificacion'],
                'valor' => mb_substr((string) $e['ESP_Valor'], 0, 120)], $usuario, $ip);
        });
    }

    /**
     * La especificación con su activo bloqueado (y releída después: otro cambio pudo terminar mientras se esperaba).
     *
     * @return array<string, mixed>
     */
    private function especificacionBloqueada(int $id): array
    {
        $previa = $this->datos->especificacion($id) ?: throw ErrorDominio::noEncontrado('SPECIFICATION_NOT_FOUND');
        $this->activos->bloquearEnOrden([(string) $previa['ACT_Activo']]);

        return $this->especificacion($id) ?? throw ErrorDominio::noEncontrado('SPECIFICATION_NOT_FOUND');
    }
}
