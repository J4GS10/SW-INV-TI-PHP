<?php

declare(strict_types=1);

namespace App\Esquema;

use App\Inventario\Credenciales;
use App\Seguridad\CifradoCampos;
use App\Seguridad\TokenFernetInvalido;
use Doctrine\DBAL\Connection;

/**
 * Recalcula las huellas de unicidad de las credenciales de licencia
 * (INV_LICENCIA_CLAVE.LCL_Clave_Hash) con la clave primaria de HMAC_KEYS.
 *
 * Hace falta tras rotar HMAC_KEYS: una huella calculada con la clave anterior ya
 * no coincide y la misma key podría registrarse dos veces. Los datos de origen
 * se recuperan (keys y secretos cifrados con Fernet, el resto en claro), así que
 * el cálculo es exacto. Idempotente: solo escribe las huellas que cambian.
 */
final readonly class RecalculoHuellas
{
    public function __construct(
        private Connection $conexion,
        private Credenciales $credenciales,
        private CifradoCampos $cifrado,
    ) {
    }

    /** @return array{total: int, actualizadas: int, conflictos: list<int>, errores: int} */
    public function recalcular(): array
    {
        return $this->conexion->transactional(function (Connection $c): array {
            $filas = $c->fetchAllAssociative('SELECT "LCL_Licencia_Clave", "LIC_Licencia", "LCL_Clave_Activacion", "LCL_Serial", "LCL_Dispositivo", '
                .'"LCL_Usuario", "LCL_Servidor", "LCL_Secreto_Enc", "LCL_Clave_Hash" FROM "INV_LICENCIA_CLAVE" FOR UPDATE');
            $nuevas = [];
            $actuales = [];
            $errores = 0;
            foreach ($filas as $f) {
                $id = (int) $f['LCL_Licencia_Clave'];
                $actuales[$id] = null === $f['LCL_Clave_Hash'] ? null : (string) $f['LCL_Clave_Hash'];
                try {
                    $nuevas[$id] = $this->credenciales->huella((int) $f['LIC_Licencia'], [
                        'clave' => self::texto($this->cifrado->descifrar(self::texto($f['LCL_Clave_Activacion']))),
                        'serial' => self::texto($f['LCL_Serial']), 'dispositivo' => self::texto($f['LCL_Dispositivo']),
                        'usuario' => self::texto($f['LCL_Usuario']), 'servidor' => self::texto($f['LCL_Servidor']),
                        'secreto' => self::texto($this->cifrado->descifrar(self::texto($f['LCL_Secreto_Enc']))),
                    ]);
                } catch (TokenFernetInvalido) {
                    // Sin poder descifrar se conserva la huella actual.
                    ++$errores;
                    $nuevas[$id] = $actuales[$id];
                }
            }

            // Dos credenciales con la misma huella nueva violarían la restricción
            // única: se dejan como están y se informan para revisarlas a mano.
            $vistas = [];
            $conflictos = [];
            foreach ($nuevas as $id => $h) {
                if (null === $h) {
                    continue;
                }
                if (isset($vistas[$h])) {
                    $conflictos[$id] = true;
                    $conflictos[$vistas[$h]] = true;
                }
                $vistas[$h] = $id;
            }
            $cambios = array_filter($nuevas, static fn (?string $h, int $id): bool => !isset($conflictos[$id]) && $h !== $actuales[$id], \ARRAY_FILTER_USE_BOTH);

            // Primero se liberan las huellas a reemplazar: una nueva nunca choca con una vieja de otra fila.
            foreach (array_keys($cambios) as $id) {
                $c->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET "LCL_Clave_Hash" = NULL WHERE "LCL_Licencia_Clave" = :id', ['id' => $id]);
            }
            foreach ($cambios as $id => $h) {
                $c->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET "LCL_Clave_Hash" = :h WHERE "LCL_Licencia_Clave" = :id', ['h' => $h, 'id' => $id]);
            }
            $ids = array_keys($conflictos);
            sort($ids);

            return ['total' => \count($filas), 'actualizadas' => \count($cambios), 'conflictos' => $ids, 'errores' => $errores];
        });
    }

    /** Texto vacío = sin valor (la huella usa el primer dato presente). */
    private static function texto(mixed $valor): ?string
    {
        return null === $valor || '' === $valor ? null : (string) $valor;
    }
}
