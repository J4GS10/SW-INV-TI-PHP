<?php

declare(strict_types=1);

namespace App\Organizacion\Infrastructure;

use App\Dominio\Ident;
use App\Dominio\Roles;
use App\Dominio\ErrorDominio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Sugerencias para autocompletar formularios: al elegir una persona o un
 * departamento se proponen los datos que se derivan de ellos, cada uno con su
 * `origen`. Nunca se sugiere un rol privilegiado. Un área o una sede solo se
 * sugieren si están en el alcance del usuario; los datos de cuenta solo los ve
 * quien gestiona identidades.
 */
final readonly class Sugerencias
{
    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /** @return array<string, mixed> */
    public function persona(string $personaHex): array
    {
        [$filtro, $parametros] = $this->alcance->lectura('INV_PERSONA', 'p');
        $p = $this->conexion->fetchAssociative('SELECT p.* FROM "INV_PERSONA" p WHERE p."PER_Persona" = :id AND '.$filtro, ['id' => $personaHex, ...$parametros]);
        if (false === $p) {
            throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
        }
        $dep = $this->conexion->fetchAssociative('SELECT "DEP_Departamento", "DEP_Nombre" FROM "INV_DEPARTAMENTO" WHERE "DEP_Departamento" = :d', ['d' => $p['DEP_Departamento']]);
        $car = $this->conexion->fetchAssociative('SELECT "CAR_Cargo", "CAR_Nombre" FROM "INV_CARGO" WHERE "CAR_Cargo" = :c', ['c' => $p['CAR_Cargo']]);
        $jefe = null !== $p['PER_Jefe'] ? $this->personaPorId((string) $p['PER_Jefe']) : $this->jefeDepartamento((int) $p['DEP_Departamento'], $personaHex);

        // Área: la del último movimiento dentro del alcance; si no, la más usada en su departamento.
        [$filtroMov, $parametrosMov] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        $area = null;
        foreach ($this->conexion->fetchFirstColumn(
            'SELECT m."ARE_Area" FROM "INV_MOVIMIENTO" m WHERE m."PER_Persona" = :p AND m."ARE_Area" IS NOT NULL AND '.$filtroMov
            .' ORDER BY m."MOV_Fecha_Asignacion" DESC FETCH FIRST 10 ROWS ONLY', ['p' => $personaHex, ...$parametrosMov]) as $areaId) {
            if (null !== $area = $this->areaRef((int) $areaId, 'ultima_asignacion')) {
                break;
            }
        }
        $area ??= $this->areaRef($this->areaFrecuenteDepartamento((int) $p['DEP_Departamento']), 'departamento');

        [$filtroAct, $parametrosAct] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $vigentes = $this->conexion->fetchFirstColumn(
            'SELECT a."ACT_Codigo_Interno" FROM "INV_ACTIVO" a JOIN "INV_MOVIMIENTO" m ON m."ACT_Activo" = a."ACT_Activo" '
            .'WHERE m."PER_Persona" = :p AND m."MOV_Fecha_Devolucion" IS NULL AND '.$filtroAct.' AND '.$filtroMov,
            ['p' => $personaHex, ...$parametrosAct, ...$parametrosMov],
        );
        $sede = $this->sedeRef(null === $p['SED_Sede'] ? null : (int) $p['SED_Sede'], 'persona')
            ?? $this->sedeRef($area['SED_Sede'] ?? null, 'area')
            ?? $this->sedeRef($this->alcance->sedePorDefecto(), 'alcance');

        $base = [
            'persona' => self::personaRef($p),
            'departamento' => false === $dep ? null : ['DEP_Departamento' => (int) $dep['DEP_Departamento'], 'nombre' => $dep['DEP_Nombre']],
            'cargo' => false === $car ? null : ['CAR_Cargo' => (int) $car['CAR_Cargo'], 'nombre' => $car['CAR_Nombre']],
            'jefe' => null === $jefe ? null : [...self::personaRef($jefe), 'origen' => null !== $p['PER_Jefe'] ? 'registrado' : 'departamento'],
            'area_sugerida' => $area,
            'sede_sugerida' => $sede,
            'activos_vigentes' => array_map('strval', $vigentes),
            'usuario' => null, 'username_sugerido' => null, 'rol_sugerido' => null, 'alcance_sugerido' => null,
        ];
        $rol = $this->alcance->actual()?->rol;
        if (null !== $rol && !\in_array($rol, Roles::IAM, true)) {
            return $base;
        }
        // Quien gestiona cuentas no ve inventario: ni equipos ni área.
        if (null !== $rol) {
            $base['activos_vigentes'] = [];
            $base['area_sugerida'] = null;
        }
        $usuario = $this->conexion->fetchAssociative('SELECT "USU_Usuario", "USU_Username", "USU_Rol" FROM "INV_USUARIO" WHERE "PER_Persona" = :p', ['p' => $personaHex]);
        [$rolSugerido, $origen] = [$this->rolFrecuente('CAR_Cargo', (int) $p['CAR_Cargo']), 'cargo'];
        if (null === $rolSugerido) {
            [$rolSugerido, $origen] = [$this->rolFrecuente('DEP_Departamento', (int) $p['DEP_Departamento']), 'departamento'];
        }
        if (null === $rolSugerido) {
            [$rolSugerido, $origen] = [Roles::CONSULTA, 'minimo_privilegio'];
        }
        $sinCuenta = false === $usuario;

        return [
            ...$base,
            'usuario' => $sinCuenta ? null : ['USU_Usuario' => Ident::aTexto((string) $usuario['USU_Usuario']), 'username' => $usuario['USU_Username'], 'rol' => $usuario['USU_Rol']],
            'username_sugerido' => $sinCuenta ? $this->usernameLibre(self::slugUsername($p['PER_Email_Corporativo'], (string) $p['PER_Primer_Nombre'], (string) $p['PER_Primer_Apellido'])) : null,
            'rol_sugerido' => $sinCuenta ? ['rol' => $rolSugerido, 'origen' => $origen] : null,
            // Mínimo privilegio: la sede de la persona, nunca global.
            'alcance_sugerido' => $sinCuenta && null !== $sede ? ['sedes' => [$sede['SED_Sede']], 'origen' => $sede['origen']] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function departamento(int $depId): array
    {
        if (false === $this->conexion->fetchOne('SELECT 1 FROM "INV_DEPARTAMENTO" WHERE "DEP_Departamento" = :d', ['d' => $depId])) {
            throw ErrorDominio::noEncontrado('DEPARTMENT_NOT_FOUND');
        }
        [$filtro, $parametros] = $this->alcance->lectura('INV_PERSONA', 'p');
        $cargo = $this->conexion->fetchAssociative(
            'SELECT c."CAR_Cargo", c."CAR_Nombre" FROM "INV_CARGO" c WHERE c."CAR_Cargo" = (SELECT p."CAR_Cargo" FROM "INV_PERSONA" p '
            .'WHERE p."DEP_Departamento" = :d AND '.$filtro.' GROUP BY p."CAR_Cargo" ORDER BY COUNT(*) DESC FETCH FIRST 1 ROWS ONLY)',
            ['d' => $depId, ...$parametros],
        );
        $jefe = $this->jefeDepartamento($depId);
        $area = $this->areaRef($this->areaFrecuenteDepartamento($depId), 'departamento');

        return [
            'cargo_sugerido' => false === $cargo ? null : ['CAR_Cargo' => (int) $cargo['CAR_Cargo'], 'nombre' => $cargo['CAR_Nombre'], 'origen' => 'departamento'],
            'jefe_sugerido' => null === $jefe ? null : [...self::personaRef($jefe), 'origen' => 'departamento'],
            'area_sugerida' => $area,
            'sede_sugerida' => $this->sedeRef($area['SED_Sede'] ?? null, 'departamento') ?? $this->sedeRef($this->alcance->sedePorDefecto(), 'alcance'),
        ];
    }

    /**

     * Área (con su sede); como sugerencia, solo si su sede está en el alcance.

     *

     * @return array<string, mixed>|null

     */
    public function areaRef(?int $areaId, string $origen, bool $soloAlcance = true): ?array
    {
        if (null === $areaId) {
            return null;
        }
        $a = $this->conexion->fetchAssociative(
            'SELECT a."ARE_Area", a."ARE_Nombre", e."SED_Sede" FROM "INV_AREA" a JOIN "INV_NIVEL" n ON n."NIV_Nivel" = a."NIV_Nivel" '
            .'JOIN "INV_EDIFICIO" e ON e."EDI_Edificio" = n."EDI_Edificio" WHERE a."ARE_Area" = :a', ['a' => $areaId]);
        if (false === $a || ($soloAlcance && !$this->alcance->permite((int) $a['SED_Sede']))) {
            return null;
        }

        return ['ARE_Area' => (int) $a['ARE_Area'], 'nombre' => $a['ARE_Nombre'], 'SED_Sede' => (int) $a['SED_Sede'], 'origen' => $origen];
    }

    /**
     * Custodio y área actuales del activo y el tipo de movimiento lógico.
     *
     * @return array<string, mixed>
     */
    public function activo(string $activoHex): array
    {
        [$filtro, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $a = $this->conexion->fetchAssociative('SELECT a."SED_Sede", a."ACT_Hostname" FROM "INV_ACTIVO" a WHERE a."ACT_Activo" = :id AND '.$filtro,
            [...$p, 'id' => $activoHex]) ?: throw ErrorDominio::noEncontrado('ASSET_NOT_FOUND');
        $mov = $this->conexion->fetchAssociative('SELECT "PER_Persona", "ARE_Area" FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL '
            .'FETCH FIRST 1 ROWS ONLY', ['a' => $activoHex]);
        $custodio = false === $mov ? false : $this->conexion->fetchAssociative('SELECT p.*, d."DEP_Nombre" FROM "INV_PERSONA" p '
            .'LEFT JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento" WHERE p."PER_Persona" = :p', ['p' => $mov['PER_Persona']]);

        return [
            'custodio' => false === $custodio ? null : [...self::personaRef($custodio), 'departamento' => $custodio['DEP_Nombre']],
            // Ubicación actual del propio activo (informativa, no una sugerencia).
            'area_actual' => false === $mov ? null : $this->areaRef((int) $mov['ARE_Area'], 'movimiento_vigente', soloAlcance: false),
            'sede' => $this->sedeRef(null === $a['SED_Sede'] ? null : (int) $a['SED_Sede'], 'activo'),
            // Asignado → lo lógico es transferir; libre → asignar.
            'tipo_movimiento_sugerido' => false === $mov ? 'asignacion' : 'transferencia',
            'hostname' => $a['ACT_Hostname'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function sedeRef(?int $sedeId, string $origen): ?array
    {
        if (null === $sedeId || !$this->alcance->permite($sedeId)) {
            return null;
        }
        $nombre = $this->conexion->fetchOne('SELECT "SED_Nombre" FROM "INV_SEDE" WHERE "SED_Sede" = :s', ['s' => $sedeId]);

        return false === $nombre ? null : ['SED_Sede' => $sedeId, 'nombre' => $nombre, 'origen' => $origen];
    }

    /**

     * @param array<string, mixed> $p

     *

     * @return array{PER_Persona: string, nombre: string, email: ?string}

     */
    public static function personaRef(array $p): array
    {
        return ['PER_Persona' => Ident::aTexto((string) $p['PER_Persona']), 'nombre' => trim($p['PER_Primer_Nombre'].' '.$p['PER_Primer_Apellido']),
            'email' => $p['PER_Email_Corporativo']];
    }

    public static function slugUsername(?string $correo, string $nombre, string $apellido): string
    {
        $base = explode('@', (string) $correo)[0] ?: "{$nombre}.{$apellido}";
        $ascii = (string) preg_replace('/[^\x00-\x7F]/', '', \Normalizer::normalize($base, \Normalizer::FORM_KD) ?: $base);
        $limpio = trim((string) preg_replace('/[^a-z0-9._-]/', '', strtolower($ascii)), '._-');

        return substr('' === $limpio ? 'usuario' : $limpio, 0, 40);
    }

    /** @return array<string, mixed>|null */
    private function personaPorId(string $hex): ?array
    {
        [$filtro, $parametros] = $this->alcance->lectura('INV_PERSONA', 'p');
        $p = $this->conexion->fetchAssociative('SELECT p.* FROM "INV_PERSONA" p WHERE p."PER_Persona" = :id AND '.$filtro, ['id' => $hex, ...$parametros]);

        return false === $p ? null : $p;
    }

    /**

     * Persona activa con cargo de jefatura en el departamento; si no hay, el jefe más común.

     *

     * @return array<string, mixed>|null

     */
    private function jefeDepartamento(int $depId, ?string $excluir = null): ?array
    {
        [$filtro, $parametros] = $this->alcance->lectura('INV_PERSONA', 'p');
        $parametros = ['d' => $depId, ...$parametros, ...(null === $excluir ? [] : ['x' => $excluir])];
        $jefe = $this->conexion->fetchAssociative(
            'SELECT p.* FROM "INV_PERSONA" p JOIN "INV_CARGO" c ON c."CAR_Cargo" = p."CAR_Cargo" WHERE p."DEP_Departamento" = :d '
            .'AND p."PER_Estado" = 1 AND c."CAR_Es_Jefatura" = 1 AND '.$filtro.(null === $excluir ? '' : ' AND p."PER_Persona" <> :x')
            .' FETCH FIRST 1 ROWS ONLY', $parametros);
        if (false !== $jefe) {
            return $jefe;
        }
        unset($parametros['x']);
        $comun = $this->conexion->fetchOne(
            'SELECT p."PER_Jefe" FROM "INV_PERSONA" p WHERE p."DEP_Departamento" = :d AND p."PER_Jefe" IS NOT NULL AND '.$filtro
            .' GROUP BY p."PER_Jefe" ORDER BY COUNT(*) DESC FETCH FIRST 1 ROWS ONLY', $parametros);

        return false === $comun || $comun === $excluir ? null : $this->personaPorId((string) $comun);
    }

    /** Área más usada por el departamento, entre las del alcance. */
    private function areaFrecuenteDepartamento(int $depId): ?int
    {
        [$filtroMov, $parMov] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        [$filtroPer, $parPer] = $this->alcance->lectura('INV_PERSONA', 'p');
        foreach ($this->conexion->fetchFirstColumn(
            'SELECT m."ARE_Area" FROM "INV_MOVIMIENTO" m JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona" '
            .'WHERE p."DEP_Departamento" = :d AND m."ARE_Area" IS NOT NULL AND '.$filtroMov.' AND '.$filtroPer
            .' GROUP BY m."ARE_Area" ORDER BY COUNT(*) DESC FETCH FIRST 10 ROWS ONLY', ['d' => $depId, ...$parMov, ...$parPer]) as $areaId) {
            if (null !== $this->areaRef((int) $areaId, 'x')) {
                return (int) $areaId;
            }
        }

        return null;
    }

    private function rolFrecuente(string $columna, int $valor): ?string
    {
        $rol = $this->conexion->fetchOne(
            "SELECT u.\"USU_Rol\" FROM \"INV_USUARIO\" u JOIN \"INV_PERSONA\" p ON p.\"PER_Persona\" = u.\"PER_Persona\" WHERE p.\"{$columna}\" = :v "
            ."AND u.\"USU_Estado\" = 1 AND u.\"USU_Rol\" NOT IN ('SUPER_ADMIN', 'ADMIN_SEGURIDAD') GROUP BY u.\"USU_Rol\" ORDER BY COUNT(*) DESC FETCH FIRST 1 ROWS ONLY",
            ['v' => $valor],
        );

        return false === $rol ? null : (string) $rol;
    }

    private function usernameLibre(string $base): string
    {
        $existentes = $this->conexion->fetchFirstColumn('SELECT "USU_Username" FROM "INV_USUARIO" WHERE "USU_Username" LIKE :b ESCAPE \'\\\'', ['b' => addcslashes($base, '\\%_').'%']);
        if (!\in_array($base, $existentes, true)) {
            return $base;
        }
        for ($n = 2; \in_array("{$base}{$n}", $existentes, true); ++$n) {
        }

        return "{$base}{$n}";
    }
}
