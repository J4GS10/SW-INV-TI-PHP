<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\ClasesActivo;
use App\Http\ErrorApi;
use Doctrine\DBAL\Connection;

/**
 * Ciclo de vida de los activos: estados canónicos del catálogo INV_ESTADO_OPERATIVO
 * (por nombre, con o sin tildes) y transiciones permitidas entre ellos.
 */
final readonly class EstadosActivo
{
    public const string BODEGA = 'En Bodega';
    public const string ASIGNADO = 'Asignado';
    public const string REPARACION = 'En Reparación';
    public const string BAJA = 'Baja';
    public const string TRANSITO = 'En Tránsito';
    /** Equipo compartido en uso en un área (impresora, switch…), sin custodio. */
    public const string INSTALADO = 'Instalado';

    public const array CANONICOS = [self::BODEGA, self::ASIGNADO, self::REPARACION, self::BAJA, self::TRANSITO, self::INSTALADO];

    /**
     * Estados que se eligen al dar de alta o al editar a mano. Los demás solo se
     * alcanzan con su operación (asignar, abrir ticket, trasladar, dar de baja).
     */
    public const array DE_ALMACEN = [self::BODEGA];

    /** Origen => destinos legales. */
    private const array TRANSICIONES = [
        self::BODEGA => [self::ASIGNADO, self::INSTALADO, self::REPARACION, self::BAJA, self::TRANSITO],
        // Un equipo asignado puede ir a reparación sin cerrar su custodia.
        self::ASIGNADO => [self::BODEGA, self::ASIGNADO, self::TRANSITO, self::REPARACION],
        self::INSTALADO => [self::INSTALADO, self::BODEGA, self::REPARACION, self::TRANSITO],
        self::REPARACION => [self::INSTALADO, self::BODEGA, self::BAJA],
        self::TRANSITO => [self::BODEGA],
        // Reversar una baja por error humano: de vuelta a un estado de almacén.
        self::BAJA => [self::BODEGA],
    ];

    public function __construct(private Connection $conexion)
    {
    }

    /** Nombre del catálogo → estado canónico, o null si es un estado propio. */
    public static function canonico(?string $nombre): ?string
    {
        if (null === $nombre || '' === $nombre) {
            return null;
        }
        $buscado = ClasesActivo::normalizar($nombre);
        // «Disponible» y «En Bodega» eran el mismo estado: el nombre antiguo sigue entendiéndose en archivos y llamadas.
        if ('disponible' === $buscado) {
            return self::BODEGA;
        }
        foreach (self::CANONICOS as $estado) {
            if (ClasesActivo::normalizar($estado) === $buscado) {
                return $estado;
            }
        }

        return null;
    }

    /** Como `canonico`, pero acepta nombres que contienen el canónico ("Disponible (bodega central)"). */
    public static function canonicoFlexible(?string $nombre): ?string
    {
        $exacto = self::canonico($nombre);
        if (null !== $exacto || null === $nombre) {
            return $exacto;
        }
        $normal = ClasesActivo::normalizar($nombre);
        foreach (self::CANONICOS as $estado) {
            if (str_contains($normal, ClasesActivo::normalizar($estado))) {
                return $estado;
            }
        }

        return null;
    }

    public static function permitida(string $actual, string $destino): bool
    {
        return $actual === $destino || \in_array($destino, self::TRANSICIONES[$actual] ?? [], true);
    }

    /** 400 INVALID_STATE_TRANSITION si la transición entre estados canónicos no es legal. */
    public static function exigirTransicion(?string $actual, string $destino): void
    {
        if (null !== $actual && !self::permitida($actual, $destino)) {
            throw new ErrorApi(400, 'INVALID_STATE_TRANSITION');
        }
    }

    /**
     * Transición de una operación de custodia: 400 con `detail` como objeto
     * {error, current_state, target_state, message}, igual que el backend anterior.
     */
    public static function validarTransicion(string $actual, string $destino): void
    {
        if (!self::permitida($actual, $destino)) {
            throw new ErrorApi(400, 'INVALID_STATE_TRANSITION', cuerpo: ['error' => 'INVALID_STATE_TRANSITION', 'current_state' => $actual,
                'target_state' => $destino, 'message' => "No se puede pasar de '{$actual}' a '{$destino}' directamente."]);
        }
    }

    /** Estado canónico del id del catálogo (null si no existe o es propio). */
    public function canonicoDeId(?int $id): ?string
    {
        return null === $id ? null : self::canonico($this->nombre($id));
    }

    public function nombre(int $id): ?string
    {
        $nombre = $this->conexion->fetchOne('SELECT "EOP_Nombre" FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Estado_Operativo" = :id', ['id' => $id]);

        return false === $nombre ? null : (string) $nombre;
    }

    public function existe(int $id): bool
    {
        return null !== $this->nombre($id);
    }

    /** Id del estado por su nombre canónico (sin distinguir mayúsculas). */
    public function idDe(string $estado): ?int
    {
        $id = $this->conexion->fetchOne('SELECT "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO" WHERE LOWER("EOP_Nombre") = LOWER(:n) '
            .'ORDER BY "EOP_Estado_Operativo" FETCH FIRST 1 ROWS ONLY', ['n' => $estado]);

        return false === $id ? null : (int) $id;
    }

    /** Id obligatorio (500 si el catálogo no tiene el estado del sistema). */
    public function idObligatorio(string $estado): int
    {
        return $this->idDe($estado) ?? throw new ErrorApi(500, 'SYSTEM_CONFIGURATION_ERROR_MISSING_STATUS_'.strtoupper(ClasesActivo::normalizar($estado)));
    }
}
