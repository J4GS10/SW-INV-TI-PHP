<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\CatalogoGenerico;
use App\Correo\Notificador;
use App\Crud\Esquema;
use App\Db\InsercionOracle;
use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\ErrorDominio;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/**
 * Tickets de mantenimiento: abrir pasa el equipo a «En Reparación» (si la
 * máquina de estados lo permite); cerrar lo devuelve a «Asignado» si tiene
 * custodio, a «Instalado» si es un equipo compartido, o a «En Bodega». Entrada
 * y salida quedan en el kardex con el costo del ticket.
 */
final readonly class Mantenimientos
{
    public function __construct(
        private Connection $conexion,
        private Alcance $alcance,
        private Kardex $kardex,
        private EstadosActivo $estados,
        private CatalogoGenerico $catalogos,
        private GobiernoRepositorio $gobierno,
        private Transaccion $transaccion,
        private Notificador $notificador,
        private Operador $operador,
        private \App\Db\LogicalDelete $borrado,
    ) {
    }

    // ---------------- Tipos ----------------

    /** @return list<array<string, mixed>> */
    public function tipos(): array
    {
        return array_map(static fn (array $t): array => ['TMA_Nombre' => $t['TMA_Nombre'], 'TMA_Tipo_Mantenimiento' => (int) $t['TMA_Tipo_Mantenimiento']],
            $this->conexion->fetchAllAssociative('SELECT "TMA_Tipo_Mantenimiento", "TMA_Nombre" FROM "INV_TIPO_MANTENIMIENTO" ORDER BY "TMA_Tipo_Mantenimiento"'));
    }

    /** @return array<string, mixed> */
    public function tipo(int $id): array
    {
        $n = $this->conexion->fetchOne('SELECT "TMA_Nombre" FROM "INV_TIPO_MANTENIMIENTO" WHERE "TMA_Tipo_Mantenimiento" = :id', ['id' => $id]);

        return false === $n ? throw ErrorDominio::noEncontrado('MAINTENANCE_TYPE_NOT_FOUND') : ['TMA_Nombre' => $n, 'TMA_Tipo_Mantenimiento' => $id];
    }

    /** @return array<string, mixed> */
    public function crearTipo(string $nombre, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($nombre, $usuario, $ip): array {
            $id = (int) InsercionOracle::insertar($this->conexion, 'INV_TIPO_MANTENIMIENTO', ['TMA_Nombre' => $nombre], 'TMA_Tipo_Mantenimiento');
            $this->gobierno->registrarAuditoria('CREATE', 'INV_TIPO_MANTENIMIENTO', ['nombre' => $nombre], $usuario, $ip);

            return $this->tipo($id);
        });
    }

    /**
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function cambiarTipo(int $id, array $cambios, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $cambios, $usuario, $ip): array {
            $this->tipo($id);
            if (\array_key_exists('TMA_Nombre', $cambios)) {
                $this->conexion->executeStatement('UPDATE "INV_TIPO_MANTENIMIENTO" SET "TMA_Nombre" = :n WHERE "TMA_Tipo_Mantenimiento" = :id', ['n' => $cambios['TMA_Nombre'], 'id' => $id]);
            }
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_TIPO_MANTENIMIENTO', ['id' => $id, 'cambios' => (object) $cambios], $usuario, $ip);

            return $this->tipo($id);
        });
    }

    public function eliminarTipo(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $t = $this->tipo($id);
            if ($this->alcance->elevado(fn (): bool => false !== $this->conexion->fetchOne(
                'SELECT 1 FROM "INV_MANTENIMIENTO" WHERE "TMA_Tipo_Mantenimiento" = :id FETCH FIRST 1 ROWS ONLY', ['id' => $id]))) {
                throw ErrorDominio::conflicto('CANNOT_DELETE_TYPE_HAS_TICKETS');
            }
            $this->borrado->archive('INV_TIPO_MANTENIMIENTO', 'TMA_Tipo_Mantenimiento', $id);
            $this->gobierno->registrarAuditoria('DELETE', 'INV_TIPO_MANTENIMIENTO', ['id' => $id, 'nombre' => $t['TMA_Nombre']], $usuario, $ip);
        });
    }

    // ---------------- Tickets ----------------

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed> MantenimientoResponse
     */
    private function aApi(array $f): array
    {
        $detalles = array_map(self::detalleAApi(...), $this->conexion->fetchAllAssociative('SELECT "DMA_Detalle_Mant", "DMA_Accion_Realizada", "DMA_Costo_Item", "AMA_Accion" '
            .'FROM "INV_DETALLE_MANT" WHERE "MAN_Mantenimiento" = :m ORDER BY "DMA_Detalle_Mant"', ['m' => $f['MAN_Mantenimiento']]));

        return [
            'ACT_Activo' => Ident::aTexto((string) $f['ACT_Activo']), 'PER_Persona_Solicita' => Ident::aTexto((string) $f['PER_Persona_Solicita']),
            'TMA_Tipo_Mantenimiento' => (int) $f['TMA_Tipo_Mantenimiento'], 'MAN_Descripcion_Falla' => $f['MAN_Descripcion_Falla'],
            'TFA_Tipo_Falla' => null === $f['TFA_Tipo_Falla'] ? null : (int) $f['TFA_Tipo_Falla'],
            'MAN_Costo_Total' => Esquema::decimal((string) $f['MAN_Costo_Total'], 2), 'MAN_Moneda' => $f['MAN_Moneda'],
            'MAN_Mantenimiento' => Ident::aTexto((string) $f['MAN_Mantenimiento']), 'MAN_Fecha_Ingreso' => Reloj::iso(Reloj::desdeBd((string) $f['MAN_Fecha_Ingreso'])),
            'MAN_Fecha_Cierre' => Reloj::iso(Reloj::desdeBd($f['MAN_Fecha_Cierre'])),
            'tipo_mantenimiento' => null === $f['TMA_Nombre'] ? null : ['TMA_Nombre' => $f['TMA_Nombre'], 'TMA_Tipo_Mantenimiento' => (int) $f['TMA_Tipo_Mantenimiento']],
            'detalles' => $detalles,
            'activo' => null === $f['ACT_Codigo_Interno'] ? null : ['ACT_Activo' => Ident::aTexto((string) $f['ACT_Activo']),
                'ACT_Codigo_Interno' => $f['ACT_Codigo_Interno'], 'ACT_Hostname' => $f['ACT_Hostname']],
            'persona_solicita' => null === $f['PER_Primer_Nombre'] ? null : ['PER_Persona' => Ident::aTexto((string) $f['PER_Persona_Solicita']),
                'PER_Primer_Nombre' => $f['PER_Primer_Nombre'], 'PER_Primer_Apellido' => $f['PER_Primer_Apellido'], 'PER_Email_Corporativo' => $f['PER_Email_Corporativo']],
        ];
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed> DetalleResponse
     */
    private static function detalleAApi(array $d): array
    {
        return ['DMA_Accion_Realizada' => $d['DMA_Accion_Realizada'], 'DMA_Costo_Item' => Esquema::decimal((string) $d['DMA_Costo_Item'], 2),
            'AMA_Accion' => null === $d['AMA_Accion'] ? null : (int) $d['AMA_Accion'], 'DMA_Detalle_Mant' => (int) $d['DMA_Detalle_Mant']];
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return list<array<string, mixed>>
     */
    private function consultar(string $donde, array $p, int $limite = 50): array
    {
        [$alcance, $pa] = $this->alcance->lectura('INV_MANTENIMIENTO', 'm');

        return array_map($this->aApi(...), $this->conexion->fetchAllAssociative('SELECT m.*, t."TMA_Nombre", a."ACT_Codigo_Interno", a."ACT_Hostname", p."PER_Primer_Nombre", '
            .'p."PER_Primer_Apellido", p."PER_Email_Corporativo" FROM "INV_MANTENIMIENTO" m LEFT JOIN "INV_TIPO_MANTENIMIENTO" t ON t."TMA_Tipo_Mantenimiento" = m."TMA_Tipo_Mantenimiento" '
            .'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona_Solicita" WHERE '
            .$donde.' AND '.$alcance.' ORDER BY m."MAN_Fecha_Ingreso" DESC, m."MAN_Mantenimiento" FETCH FIRST '.$limite.' ROWS ONLY', [...$p, ...$pa]));
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->consultar('1 = 1', []);
    }

    /** @return array<string, mixed> */
    public function uno(string $id): array
    {
        return $this->consultar('m."MAN_Mantenimiento" = :id', ['id' => $id], 1)[0] ?? throw ErrorDominio::noEncontrado('MAINTENANCE_TICKET_NOT_FOUND');
    }

    /** «Nombre del catálogo: detalle», sin repetir el nombre si el texto ya lo trae. */
    private static function conPrefijo(string $nombre, string $texto): string
    {
        if ('' === $texto || $texto === $nombre) {
            return $nombre;
        }

        return str_starts_with($texto, "{$nombre}:") ? $texto : "{$nombre}: {$texto}";
    }

    /**
     * Acción del catálogo + detalle escrito (obligatorio sin acción o si la acción lo pide).
     *
     * @param array{DMA_Accion_Realizada?: ?string, AMA_Accion?: ?int} $d
     */
    private function textoDeDetalle(array $d): string
    {
        $texto = trim((string) ($d['DMA_Accion_Realizada'] ?? ''));
        if (null === ($d['AMA_Accion'] ?? null)) {
            if (mb_strlen($texto) < 5) {
                throw ErrorDominio::noProcesable('MAINTENANCE_ACTION_REQUIRED');
            }

            return $texto;
        }
        $nombre = $this->catalogos->nombreVigente('acciones-mantenimiento', (int) $d['AMA_Accion']);
        if (1 === (int) $this->conexion->fetchOne('SELECT "AMA_Pide_Detalle" FROM "INV_ACCION_MANTENIMIENTO" WHERE "AMA_Accion" = :a', ['a' => $d['AMA_Accion']]) && '' === $texto) {
            throw ErrorDominio::noProcesable('MAINTENANCE_ACTION_DETAIL_REQUIRED');
        }

        return mb_substr(self::conPrefijo($nombre, $texto), 0, 255);
    }

    /**
     * @param array<string, mixed>       $d
     * @param list<array<string, mixed>> $detalles
     *
     * @return array<string, mixed>
     */
    public function abrir(array $d, array $detalles, string $usuario, ?string $ip): array
    {
        try {
            return $this->transaccion->ejecutar(function () use ($d, $detalles, $usuario, $ip): array {
                [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');
                $activo = $this->conexion->fetchAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :id AND '.$alcance.' FOR UPDATE',
                    [...$p, 'id' => $d['ACT_Activo']]) ?: throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
                $persona = $this->conexion->fetchAssociative('SELECT "PER_Primer_Nombre", "PER_Primer_Apellido", "PER_Email_Corporativo", "PER_Estado" FROM "INV_PERSONA" '
                    .'WHERE "PER_Persona" = :p', ['p' => $d['PER_Persona_Solicita']]) ?: throw ErrorDominio::noEncontrado('REQUESTING_PERSON_NOT_FOUND');
                if (1 !== (int) $persona['PER_Estado']) {
                    throw ErrorDominio::invalido('PERSON_INACTIVE');
                }
                if (false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_MANTENIMIENTO" WHERE "ACT_Activo" = :a AND "MAN_Fecha_Cierre" IS NULL FETCH FIRST 1 ROWS ONLY',
                    ['a' => $d['ACT_Activo']])) {
                    throw ErrorDominio::conflicto('ASSET_ALREADY_HAS_OPEN_MAINTENANCE_TICKET');
                }
                $reparacion = $this->conexion->fetchOne("SELECT \"EOP_Estado_Operativo\" FROM \"INV_ESTADO_OPERATIVO\" WHERE LOWER(\"EOP_Nombre\") LIKE '%reparación%' "
                    .'ORDER BY "EOP_Estado_Operativo" FETCH FIRST 1 ROWS ONLY') ?: $this->estados->idDe(EstadosActivo::REPARACION);
                if (null === $reparacion) {
                    throw ErrorDominio::interno('SYSTEM_CONFIG_ERROR_MISSING_OPERATIONAL_STATE');
                }
                // Solo entra a reparación lo que la máquina de estados permite (no una baja ni un tránsito).
                $actual = $this->estados->canonicoDeId((int) $activo['EOP_Estado_Operativo']);
                if (null !== $actual && !EstadosActivo::permitida($actual, EstadosActivo::REPARACION)) {
                    throw ErrorDominio::invalido('ASSET_STATE_DOES_NOT_ALLOW_MAINTENANCE');
                }
                $texto = trim((string) $d['MAN_Descripcion_Falla']);
                if (null === $d['TFA_Tipo_Falla']) {
                    if ('' === $texto) {
                        throw ErrorDominio::noProcesable('FAULT_DESCRIPTION_REQUIRED');
                    }
                } else {
                    $nombre = $this->catalogos->nombreVigente('tipos-falla', (int) $d['TFA_Tipo_Falla']);
                    if (1 === (int) $this->conexion->fetchOne('SELECT "TFA_Pide_Detalle" FROM "INV_TIPO_FALLA" WHERE "TFA_Tipo_Falla" = :t', ['t' => $d['TFA_Tipo_Falla']]) && '' === $texto) {
                        throw ErrorDominio::noProcesable('FAULT_DESCRIPTION_REQUIRED');
                    }
                    $texto = mb_substr(self::conPrefijo($nombre, $texto), 0, 2000);
                }
                $textos = array_map(fn (array $x): string => $this->textoDeDetalle($x), $detalles);
                $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "EOP_Estado_Operativo" = :e WHERE "ACT_Activo" = :a', ['e' => (int) $reparacion, 'a' => $d['ACT_Activo']]);
                $id = Ident::nuevo();
                $ingreso = Reloj::ahora();
                $this->conexion->executeStatement('INSERT INTO "INV_MANTENIMIENTO" ("MAN_Mantenimiento", "MAN_Fecha_Ingreso", "MAN_Descripcion_Falla", "TFA_Tipo_Falla", '
                    .'"MAN_Costo_Total", "MAN_Moneda", "ACT_Activo", "PER_Persona_Solicita", "TMA_Tipo_Mantenimiento") VALUES (:id, :f, :desc, :tfa, :costo, :moneda, :a, :p, :t)',
                    ['id' => $id, 'f' => Reloj::aBd($ingreso), 'desc' => $texto, 'tfa' => $d['TFA_Tipo_Falla'], 'costo' => $d['MAN_Costo_Total'], 'moneda' => $d['MAN_Moneda'],
                        'a' => $d['ACT_Activo'], 'p' => $d['PER_Persona_Solicita'], 't' => $d['TMA_Tipo_Mantenimiento']], ['desc' => ParameterType::LARGE_OBJECT]);
                foreach ($detalles as $i => $x) {
                    InsercionOracle::insertar($this->conexion, 'INV_DETALLE_MANT', ['DMA_Accion_Realizada' => $textos[$i], 'AMA_Accion' => $x['AMA_Accion'] ?? null,
                        'DMA_Costo_Item' => $x['DMA_Costo_Item'] ?? '0', 'MAN_Mantenimiento' => $id], 'DMA_Detalle_Mant');
                }
                $sede = null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
                $this->kardex->registrar('MANTENIMIENTO_ENTRADA', activo: $activo, usuario: $usuario, origen: ['sede' => $sede], destino: ['sede' => $sede],
                    documento: ['TICKET', Ident::aTexto($id)], motivo: mb_substr($texto, 0, 500),
                    detalle: ['solicitante' => Ident::aTexto($d['PER_Persona_Solicita']), 'tipo' => (int) $d['TMA_Tipo_Mantenimiento']]);
                $this->gobierno->registrarAuditoria('CREATE', 'INV_MANTENIMIENTO', ['activo' => Ident::aTexto($d['ACT_Activo']), 'falla' => mb_substr($texto, 0, 200)],
                    $usuario, $ip, sedeId: $sede);
                $tipo = $this->conexion->fetchOne('SELECT "TMA_Nombre" FROM "INV_TIPO_MANTENIMIENTO" WHERE "TMA_Tipo_Mantenimiento" = :t', ['t' => $d['TMA_Tipo_Mantenimiento']]);
                $op = $this->operador->de($usuario);
                $contexto = ['codigo' => $activo['ACT_Codigo_Interno'], 'tipo' => false === $tipo ? '' : $tipo,
                    'persona_nombre' => $persona['PER_Primer_Nombre'].' '.$persona['PER_Primer_Apellido'], 'descripcion' => mb_substr($texto, 0, 300),
                    'fecha' => Reloj::iso($ingreso)];
                $para = null === $persona['PER_Email_Corporativo'] ? [] : [$persona['PER_Email_Corporativo']];
                $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('mantenimiento_abierto', $contexto, $para, responderA: $op['correo'],
                    operador: $op['nombre'], rolOperador: $op['rol']));

                return $this->alcance->elevado(fn (): array => $this->uno($id));
            });
        } catch (UniqueConstraintViolationException) {
            // Choque del índice único parcial: otro ticket abierto se creó a la vez.
            throw ErrorDominio::conflicto('ASSET_ALREADY_HAS_OPEN_MAINTENANCE_TICKET');
        }
    }

    /**
     * Cierre condicional (dos cierres simultáneos no ganan ambos).
     *
     * @return array<string, mixed>
     */
    public function cerrar(string $id, string $costo, ?string $moneda, ?\DateTimeImmutable $fecha, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $costo, $moneda, $fecha, $usuario, $ip): array {
            $m = $this->uno($id);
            if (null !== $m['MAN_Fecha_Cierre']) {
                throw ErrorDominio::invalido('TICKET_ALREADY_CLOSED');
            }
            $ahora = Reloj::ahora();
            $fecha ??= $ahora;
            $ingreso = Reloj::desdeBd((string) $this->conexion->fetchOne('SELECT "MAN_Fecha_Ingreso" FROM "INV_MANTENIMIENTO" WHERE "MAN_Mantenimiento" = :id', ['id' => $id]));
            if ($fecha < $ingreso) {
                throw ErrorDominio::invalido('CLOSE_DATE_CANNOT_BE_BEFORE_ENTRY_DATE');
            }
            if ($fecha > $ahora->modify('+5 minutes')) {
                throw ErrorDominio::invalido('CLOSE_DATE_CANNOT_BE_IN_THE_FUTURE');
            }
            $moneda ??= $m['MAN_Moneda'];
            if (1 !== $this->conexion->executeStatement('UPDATE "INV_MANTENIMIENTO" SET "MAN_Fecha_Cierre" = :f, "MAN_Costo_Total" = :c, "MAN_Moneda" = :m '
                .'WHERE "MAN_Mantenimiento" = :id AND "MAN_Fecha_Cierre" IS NULL', ['f' => Reloj::aBd($fecha), 'c' => $costo, 'm' => $moneda, 'id' => $id])) {
                throw ErrorDominio::invalido('TICKET_ALREADY_CLOSED');
            }
            $activoId = Ident::aHex((string) $m['ACT_Activo']);
            $custodia = $this->conexion->fetchOne('SELECT "PER_Persona" FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL', ['a' => $activoId]);
            $activo = $this->conexion->fetchAssociative('SELECT '.Activos::select().' FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :a', ['a' => $activoId]) ?: null;
            // Un equipo compartido vuelve a su área («Instalado»).
            $instalado = null !== $activo && 1 === (int) $activo['ACT_Instalado'] && false === $custodia;
            $final = false !== $custodia ? 'Asignado' : ($instalado ? 'Instalado' : 'En Bodega');
            $nuevo = false !== $custodia ? $this->estados->idDe(EstadosActivo::ASIGNADO)
                : ($instalado ? $this->estados->idDe(EstadosActivo::INSTALADO) : ($this->conexion->fetchOne(
                    "SELECT \"EOP_Estado_Operativo\" FROM \"INV_ESTADO_OPERATIVO\" WHERE LOWER(\"EOP_Nombre\") LIKE '%bodega%' ORDER BY 1 FETCH FIRST 1 ROWS ONLY") ?: null));
            if (null === $nuevo) {
                throw ErrorDominio::interno('SYSTEM_CONFIG_ERROR_MISSING_OPERATIONAL_STATE');
            }
            // Un equipo dado de baja mientras estaba en taller no "resucita".
            if (null !== $activo && (int) $activo['EOP_Estado_Operativo'] !== $this->estados->idDe(EstadosActivo::BAJA)) {
                $this->conexion->executeStatement('UPDATE "INV_ACTIVO" SET "EOP_Estado_Operativo" = :e WHERE "ACT_Activo" = :a', ['e' => (int) $nuevo, 'a' => $activoId]);
            }
            $sede = null === $activo || null === $activo['SED_Sede'] ? null : (int) $activo['SED_Sede'];
            $this->kardex->registrar('MANTENIMIENTO_SALIDA', activo: $activo, activoId: $activoId, usuario: $usuario, origen: ['sede' => $sede], destino: ['sede' => $sede],
                costo: $costo, moneda: $moneda, documento: ['TICKET', $m['MAN_Mantenimiento']], detalle: ['estado_final' => $final]);
            $this->gobierno->registrarAuditoria('CLOSE', 'INV_MANTENIMIENTO', ['ticket_id' => $m['MAN_Mantenimiento'], 'costo_total' => $costo, 'moneda' => $moneda],
                $usuario, $ip, sedeId: $sede);
            $custodio = false === $custodia ? null : $this->conexion->fetchOne('SELECT "PER_Email_Corporativo" FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $custodia]);
            $op = $this->operador->de($usuario);
            $contexto = ['codigo' => $activo['ACT_Codigo_Interno'] ?? '', 'costo' => "{$costo} {$moneda}", 'fecha' => Reloj::iso($fecha),
                'estado_final' => false !== $custodia ? 'Asignado' : 'En Bodega'];
            $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('mantenimiento_cerrado', $contexto, [], responderA: $op['correo'], operador: $op['nombre'],
                rolOperador: $op['rol'], afectados: null === $custodio || false === $custodio ? [] : [(string) $custodio]));

            return $this->uno($id);
        });
    }

    public function eliminar(string $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $m = $this->uno($id);
            if (null === $m['MAN_Fecha_Cierre']) {
                throw ErrorDominio::invalido('CANNOT_DELETE_OPEN_TICKET');
            }
            // Se archiva con sus detalles y evidencias (ON DELETE CASCADE → también archivados).
            $this->borrado->archive('INV_MANTENIMIENTO', 'MAN_Mantenimiento', $id);
            $this->gobierno->registrarAuditoria('DELETE', 'INV_MANTENIMIENTO', ['ticket_id' => $m['MAN_Mantenimiento']], $usuario, $ip);
        });
    }

    // ---------------- Detalles ----------------

    /** @return list<array<string, mixed>> */
    public function detalles(string $ticket): array
    {
        $this->uno($ticket);

        return array_map(self::detalleAApi(...), $this->conexion->fetchAllAssociative('SELECT "DMA_Detalle_Mant", "DMA_Accion_Realizada", "DMA_Costo_Item", "AMA_Accion" '
            .'FROM "INV_DETALLE_MANT" WHERE "MAN_Mantenimiento" = :m ORDER BY "DMA_Detalle_Mant"', ['m' => $ticket]));
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function agregarDetalle(string $ticket, array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($ticket, $d, $usuario, $ip): array {
            if (null !== $this->uno($ticket)['MAN_Fecha_Cierre']) {
                throw ErrorDominio::invalido('CANNOT_ADD_DETAILS_TO_CLOSED_TICKET');
            }
            $texto = $this->textoDeDetalle($d);
            $id = (int) InsercionOracle::insertar($this->conexion, 'INV_DETALLE_MANT', ['DMA_Accion_Realizada' => $texto, 'AMA_Accion' => $d['AMA_Accion'],
                'DMA_Costo_Item' => $d['DMA_Costo_Item'], 'MAN_Mantenimiento' => $ticket], 'DMA_Detalle_Mant');
            $this->gobierno->registrarAuditoria('CREATE', 'INV_DETALLE_MANT', ['mantenimiento_id' => Ident::aTexto($ticket), 'accion' => mb_substr($texto, 0, 200)], $usuario, $ip);
            $r = $this->detalle($id);
            unset($r['_ticket']);

            return $r;
        });
    }

    /** @return array<string, mixed> */
    private function detalle(int $id): array
    {
        $d = $this->conexion->fetchAssociative('SELECT "DMA_Detalle_Mant", "DMA_Accion_Realizada", "DMA_Costo_Item", "AMA_Accion", "MAN_Mantenimiento" FROM "INV_DETALLE_MANT" '
            .'WHERE "DMA_Detalle_Mant" = :id', ['id' => $id]);
        if (false === $d) {
            throw ErrorDominio::noEncontrado('MAINTENANCE_DETAIL_NOT_FOUND');
        }
        $this->uno((string) $d['MAN_Mantenimiento']);

        return [...self::detalleAApi($d), '_ticket' => (string) $d['MAN_Mantenimiento']];
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public function cambiarDetalle(int $id, array $d, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $d, $usuario, $ip): array {
            $actual = $this->detalle($id);
            if (null !== $this->uno($actual['_ticket'])['MAN_Fecha_Cierre']) {
                throw ErrorDominio::invalido('CANNOT_MODIFY_DETAILS_OF_CLOSED_TICKET');
            }
            $d['DMA_Accion_Realizada'] = $this->textoDeDetalle($d);
            $this->conexion->executeStatement('UPDATE "INV_DETALLE_MANT" SET "DMA_Accion_Realizada" = :t, "AMA_Accion" = :a, "DMA_Costo_Item" = :c WHERE "DMA_Detalle_Mant" = :id',
                ['t' => $d['DMA_Accion_Realizada'], 'a' => $d['AMA_Accion'], 'c' => $d['DMA_Costo_Item'], 'id' => $id]);
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_DETALLE_MANT', ['detalle_id' => $id, 'cambios' => $d], $usuario, $ip);
            $r = $this->detalle($id);
            unset($r['_ticket']);

            return $r;
        });
    }

    public function eliminarDetalle(int $id, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): void {
            $actual = $this->detalle($id);
            if (null !== $this->uno($actual['_ticket'])['MAN_Fecha_Cierre']) {
                throw ErrorDominio::invalido('CANNOT_DELETE_DETAILS_OF_CLOSED_TICKET');
            }
            $this->borrado->archive('INV_DETALLE_MANT', 'DMA_Detalle_Mant', $id);
            $this->gobierno->registrarAuditoria('DELETE', 'INV_DETALLE_MANT', ['detalle_id' => $id], $usuario, $ip);
        });
    }
}
