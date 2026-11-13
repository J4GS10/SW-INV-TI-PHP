<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Dominio\ErrorDominio;
use App\Crud\Esquema;
use App\Seguridad\Alcance;
use App\Inventario\Infrastructure\FotoCierreMensual;

/**
 * Cierre mensual para Contabilidad: valor del inventario al cierre de un mes y
 * lo que cambió en el mes, por sede y por moneda (no se convierten monedas):
 * activos (saldo inicial, altas, bajas, saldo final), consumibles (último saldo
 * del kardex), licencias vigentes y mantenimientos cerrados.
 */
final readonly class CierreMensual
{
    public function __construct(
        private FotoCierreMensual $foto,
        private Alcance $alcance,
        private \App\Repositorio\GobiernoRepositorio $gobierno,
        private \App\Db\Transaccion $transaccion,
    ) {
    }

    /** @return array<string, mixed> */
    public function cierre(int $anio, int $mes, ?int $sede): array
    {
        return $this->foto->congelado($anio, $mes, $sede)
            ?? [...$this->foto->calcular($anio, $mes, $sede), 'frozen' => false, 'pendientes' => $this->foto->pendientes($sede)];
    }

    /**
     * Guarda la foto del mes terminado. Nadie la reescribe: un segundo intento se rechaza.
     *
     * @return array<string, mixed>
     */
    public function cerrar(int $anio, int $mes, ?int $sede, string $usuario, ?string $ip): array
    {
        $sede = $this->alcance->exigirSede($sede, requerida: false);
        $fin = (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $anio, $mes)))->modify('last day of this month')->format('Y-m-d');
        if ($fin >= \App\Dominio\Reloj::ahora()->format('Y-m-d')) {
            throw ErrorDominio::conflicto('MONTH_NOT_FINISHED');
        }
        $foto = $this->fotoConsistente($anio, $mes, $sede);

        // El cierre y su auditoría se guardan juntos o no se guarda nada. Dos cierres simultáneos
        // del mismo mes y sede: la clave única deja pasar uno y el otro recibe MONTH_ALREADY_CLOSED.
        return $this->transaccion->ejecutar(function () use ($anio, $mes, $sede, $usuario, $ip, $foto): array {
            if (!$this->foto->guardar($anio, $mes, $sede, $foto, $usuario)) {
                throw ErrorDominio::conflicto('MONTH_ALREADY_CLOSED');
            }
            $this->gobierno->registrarAuditoria('MONTH_END_CLOSE', 'TRC_MONTH_END_CLOSE', ['anio' => $anio, 'mes' => $mes, 'sede' => $sede], $usuario, $ip, sedeId: $sede);

            return (array) $this->foto->congelado($anio, $mes, $sede);
        });
    }

    /**
     * Foto del mes con todas sus consultas leyendo la base en el mismo instante: una entrega o una
     * baja confirmada a mitad del cálculo no la descuadra. Se calcula en una transacción READ ONLY
     * (lectura consistente de Oracle, sin bloquear a nadie). No se usa SERIALIZABLE: Oracle la
     * aborta con ORA-08177 en cuanto otra sesión confirma un cambio en una fila que luego se
     * escribe (la cadena de auditoría), es decir, fallaría al azar con el sistema en uso.
     *
     * @return array<string, mixed>
     */
    private function fotoConsistente(int $anio, int $mes, ?int $sede): array
    {
        return $this->foto->enUnInstante(function () use ($anio, $mes, $sede): array {
            $this->exigirAbierto($anio, $mes, $sede);

            return $this->foto->calcular($anio, $mes, $sede);
        });
    }

    private function exigirAbierto(int $anio, int $mes, ?int $sede): void
    {
        if (null !== $this->foto->congelado($anio, $mes, $sede)) {
            throw ErrorDominio::conflicto('MONTH_ALREADY_CLOSED');
        }
    }
}
