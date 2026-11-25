<?php

declare(strict_types=1);

namespace App\Consola;

use App\Consola\DatosMuestra as M;
use App\Dominio\Roles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sample data to try the system from the first day: locations, departments, people, suppliers, asset types with
 * their accounting category, brands and models, complete workstations (laptop with RAM and SSD installed,
 * monitor, keyboard, mouse) handed over to people, shared equipment installed in areas, stock in storage,
 * consumables, a licence and a goods receipt waiting for IT. Everything goes through the API as `adminti1`
 * (and `compras1` for the receipt), so each record has its kardex and its audit entry, as if typed in.
 *
 * Needs the test accounts (`app:test-accounts`). It runs once: if the sample departments already exist it does
 * nothing. Run it like the API (Redis and Mercure):
 *   docker compose … run --rm --no-deps -e RATE_LIMIT_DEFAULT=100000/minute api_php php bin/console app:demo-data
 */
#[AsCommand('app:demo-data', 'Carga los datos de muestra para probar el sistema (requiere app:test-accounts).')]
final readonly class DemoDataCommand
{
    public function __construct(private ApiInterna $api)
    {
    }

    public function __invoke(OutputInterface $output): int
    {
        $this->api->como('adminti1', Roles::ADMIN_TI);
        if (\in_array('Finanzas', array_column(self::lista($this->api->get('/org/departamentos')), 'DEP_Nombre'), true)) {
            $output->writeln('Los datos de muestra ya están cargados: no se hizo nada.');

            return Command::SUCCESS;
        }
        $sedes = array_column(self::lista($this->api->get('/org/sedes')), 'SED_Sede', 'SED_Nombre');
        foreach (array_keys(M::SEDES) as $sede) {
            if (!isset($sedes[$sede])) {
                $output->writeln("<error>Falta «{$sede}»: corra primero app:test-accounts.</error>");

                return Command::FAILURE;
            }
        }

        $areas = $this->ubicaciones($sedes);
        [$departamentos, $cargos] = $this->organizacion();
        $personas = $this->personas($sedes, $departamentos, $cargos);
        $proveedores = array_map(fn (string $n): int => (int) $this->api->post('/compras/proveedores', ['PRV_Nombre' => $n])['PRV_Proveedor'], M::PROVEEDORES);
        $modelos = $this->catalogo();
        $estados = array_column(self::lista($this->api->get('/cat/estados-operativos')), 'EOP_Estado_Operativo', 'EOP_Nombre');
        $alta = new AltaMuestra($this->api, $modelos, $proveedores, (int) ($estados['En Bodega'] ?? 0));
        $asignacion = (int) (array_column(self::lista($this->api->get('/trazabilidad/tipos')), 'TMO_Tipo_Movimiento', 'TMO_Nombre')['Asignación'] ?? 0);

        $entregados = $this->estaciones($alta, $sedes, $areas, $personas, $asignacion);
        $instalados = $this->compartidos($alta, $sedes, $areas);
        $enBodega = 0;
        foreach (M::EN_BODEGA as [$modelo, $sede, $cantidad]) {
            for ($i = 0; $i < $cantidad; ++$i) {
                $alta->crear($modelo, $sedes[$sede], '2025-11-03');
                ++$enBodega;
            }
        }
        $this->consumibles($sedes);
        $this->licencia($proveedores[0]);
        $this->ingresoPendiente($sedes['Sede Prueba Norte'], $proveedores[1]);

        $output->writeln(\sprintf('Datos de muestra listos: %d áreas, %d personas, %d activos (%d entregados en %d estaciones, %d instalados en áreas, %d en bodega), %d consumibles, 1 licencia y 1 ingreso por confirmar.',
            \count($areas), \count($personas), $alta->creados(), $entregados['activos'], $entregados['estaciones'], $instalados, $enBodega, \count(M::CONSUMIBLES)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $sedes
     *
     * @return array<string, array{area: int, sede: int}> area name => ids
     */
    private function ubicaciones(array $sedes): array
    {
        $areas = [];
        foreach (M::SEDES as $sede => [$edificio, $nombres]) {
            $e = $this->api->post('/geo/edificios', ['EDI_Nombre' => $edificio, 'SED_Sede' => $sedes[$sede]]);
            $n = $this->api->post('/geo/niveles', ['NIV_Numero_Piso' => '1', 'EDI_Edificio' => $e['EDI_Edificio']]);
            foreach ($nombres as $nombre) {
                $areas[$nombre] = ['area' => (int) $this->api->post('/geo/areas', ['ARE_Nombre' => $nombre, 'NIV_Nivel' => $n['NIV_Nivel']])['ARE_Area'], 'sede' => $sedes[$sede]];
            }
        }

        return $areas;
    }

    /** @return array{0: array<string, int>, 1: array<string, int>} departments and positions by name */
    private function organizacion(): array
    {
        $departamentos = [];
        foreach (M::DEPARTAMENTOS as $nombre => $centro) {
            $departamentos[$nombre] = (int) $this->api->post('/org/departamentos', ['DEP_Nombre' => $nombre, 'DEP_Codigo_Costos' => $centro])['DEP_Departamento'];
        }
        $cargos = [];
        foreach (M::CARGOS as $nombre => $jefatura) {
            $cargos[$nombre] = (int) $this->api->post('/org/cargos', ['CAR_Nombre' => $nombre, 'CAR_Es_Jefatura' => $jefatura])['CAR_Cargo'];
        }

        return [$departamentos, $cargos];
    }

    /**
     * @param array<string, int> $sedes
     * @param array<string, int> $departamentos
     * @param array<string, int> $cargos
     *
     * @return list<array{id: string, nombre: string, sede: string, area: string, equipo: ?string}>
     */
    private function personas(array $sedes, array $departamentos, array $cargos): array
    {
        $personas = [];
        foreach (M::PERSONAS as [$nombre, $apellido, $segundo, $departamento, $cargo, $sede, $area, $equipo]) {
            $correo = self::correo($nombre, $apellido);
            $p = $this->api->post('/org/personas', ['PER_Primer_Nombre' => $nombre, 'PER_Primer_Apellido' => $apellido, 'PER_Segundo_Apellido' => $segundo,
                'PER_Email_Corporativo' => $correo, 'DEP_Departamento' => $departamentos[$departamento], 'CAR_Cargo' => $cargos[$cargo], 'SED_Sede' => $sedes[$sede]]);
            $personas[] = ['id' => (string) $p['PER_Persona'], 'nombre' => "{$nombre} {$apellido}", 'sede' => $sede, 'area' => $area, 'equipo' => $equipo];
        }

        return $personas;
    }

    /** @return array<string, array{modelo: int, tipo: int, costo: string, moneda: string, clase: string}> by model key */
    private function catalogo(): array
    {
        $categorias = [];
        foreach (self::lista($this->api->get('/accounting/categories')) as $c) {
            if ('GT' === ($c['country'] ?? null)) {
                $categorias[(string) $c['code']] = (int) $c['id'];
            }
        }
        /** @var array<string, int> $tipos */
        $tipos = [];
        foreach (M::TIPOS as $clave => [$nombre, $prefijo, $clase, $categoria]) {
            $tipos[$clave] = (int) $this->api->post('/cat/tipos-activo', ['TAC_Nombre' => $nombre, 'TAC_Prefijo' => $prefijo, 'TAC_Clase' => $clase,
                'FAC_ASSET_CATEGORY' => $categorias[$categoria] ?? null])['TAC_Tipo_Activo'];
        }
        $marcas = [];
        $modelos = [];
        foreach (M::MODELOS as $clave => [$marca, $modelo, $tipo, $anio, $costo, $moneda]) {
            $marcas[$marca] ??= (int) $this->api->post('/cat/marcas', ['MAR_Nombre' => $marca])['MAR_Marca'];
            $id = (int) $this->api->post('/cat/modelos', ['MOD_Nombre' => $modelo, 'MAR_Marca' => $marcas[$marca], 'TAC_Tipo_Activo' => $tipos[$tipo],
                'MOD_Anio_Lanzamiento' => $anio])['MOD_Modelo'];
            $modelos[$clave] = ['modelo' => $id, 'tipo' => $tipos[$tipo], 'costo' => $costo, 'moneda' => $moneda, 'clase' => M::TIPOS[$tipo][2]];
        }

        return $modelos;
    }

    /**
     * Each person with equipment gets a computer (laptops with RAM and, every other one, an SSD installed), a monitor,
     * a keyboard and a mouse, handed over in their area.
     *
     * @param array<string, int>                                                               $sedes
     * @param array<string, array{area: int, sede: int}>                                       $areas
     * @param list<array{id: string, nombre: string, sede: string, area: string, equipo: ?string}> $personas
     *
     * @return array{estaciones: int, activos: int}
     */
    private function estaciones(AltaMuestra $alta, array $sedes, array $areas, array $personas, int $asignacion): array
    {
        $laptops = ['LAP_DELL', 'LAP_LENOVO', 'LAP_HP'];
        $estaciones = 0;
        $activos = 0;
        foreach ($personas as $i => $p) {
            if (null === $p['equipo']) {
                continue;
            }
            $fecha = M::FECHAS[$i % \count(M::FECHAS)];
            $sede = $sedes[$p['sede']];
            $equipo = $alta->crear('LAP' === $p['equipo'] ? $laptops[$i % 3] : 'PC_DELL', $sede, $fecha);
            if ('LAP' === $p['equipo']) {
                $alta->instalar($equipo, $alta->crear('RAM_KING', $sede, $fecha));
                if (0 === $i % 2) {
                    $alta->instalar($equipo, $alta->crear('SSD_SAMS', $sede, $fecha));
                }
            }
            foreach ([$equipo, $alta->crear('MON_DELL', $sede, $fecha), $alta->crear('TEC_LOGI', $sede, $fecha), $alta->crear('MOU_LOGI', $sede, $fecha)] as $activo) {
                $this->api->post('/trazabilidad/movimientos', ['ACT_Activo' => $activo, 'PER_Persona' => $p['id'], 'ARE_Area' => $areas[$p['area']]['area'],
                    'TMO_Tipo_Movimiento' => $asignacion, 'MOV_Motivo' => 'Entrega de puesto inicial']);
                ++$activos;
            }
            ++$estaciones;
        }

        return ['estaciones' => $estaciones, 'activos' => $activos];
    }

    /**
     * @param array<string, int>                         $sedes
     * @param array<string, array{area: int, sede: int}> $areas
     */
    private function compartidos(AltaMuestra $alta, array $sedes, array $areas): int
    {
        $instalados = 0;
        foreach (M::COMPARTIDOS as $sede => $equipos) {
            foreach ($equipos as [$modelo, $area, $cantidad]) {
                for ($i = 0; $i < $cantidad; ++$i) {
                    $activo = $alta->crear($modelo, $sedes[$sede], '2023-06-15');
                    $this->api->post('/trazabilidad/instalar-area', ['ACT_Activo' => $activo, 'ARE_Area' => $areas[$area]['area'], 'MOV_Motivo' => 'Instalación inicial'], 200);
                    ++$instalados;
                }
            }
        }

        return $instalados;
    }

    /** @param array<string, int> $sedes */
    private function consumibles(array $sedes): void
    {
        foreach (M::CONSUMIBLES as [$nombre, $unidad, $minimo, $sede, $cantidad, $precio]) {
            $c = $this->api->post('/consumibles', ['CON_Nombre' => $nombre, 'CON_Unidad' => $unidad, 'CON_Stock_Minimo' => $minimo, 'SED_Sede' => $sedes[$sede]]);
            $this->api->post("/consumibles/{$c['CON_Consumible']}/entrada", ['MOC_Cantidad' => $cantidad, 'precio_unitario' => (float) $precio, 'moneda' => 'GTQ',
                'MOC_Motivo' => 'Inventario inicial']);
        }
    }

    private function licencia(int $proveedor): void
    {
        $tipos = array_column(self::lista($this->api->get('/soft/tipos-licencia')), 'TLI_Tipo_Licencia', 'TLI_Nombre');
        $tipo = $tipos['Suscripción anual'] ?? (int) $this->api->post('/soft/tipos-licencia', ['TLI_Nombre' => 'Suscripción anual'])['TLI_Tipo_Licencia'];
        $software = (int) $this->api->post('/soft/software', ['SOF_Nombre' => 'Microsoft 365 Business Standard', 'SOF_Fabricante' => 'Microsoft'])['SOF_Software'];
        $this->api->post('/soft/licencias', ['SOF_Software' => $software, 'TLI_Tipo_Licencia' => $tipo, 'LIC_Cantidad_Total' => 20, 'LIC_Costo' => 19800.00,
            'LIC_Proveedor' => $proveedor, 'LIC_Fecha_Inicio' => '2026-01-01', 'LIC_Fecha_Vencimiento' => '2026-12-31', 'LIC_Notas' => 'Licencia de muestra']);
    }

    /** What Purchasing registered and IT still has to count and classify (it shows in «Mis tareas»). */
    private function ingresoPendiente(int $sede, int $proveedor): void
    {
        $this->api->como('compras1', Roles::COMPRAS);
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guatemala')))->format('Y-m-d');
        $this->api->post('/goods-receipts', ['supplier_id' => $proveedor, 'document_number' => 'FAC-2026-0457', 'received_date' => $hoy, 'site_id' => $sede,
            'currency' => 'GTQ', 'notes' => 'Ingreso de muestra: falta que TI confirme lo recibido', 'lines' => [
                ['item_text' => 'Laptop Lenovo ThinkPad T14 Gen 4', 'quantity' => 2, 'unit_cost' => '12200.00', 'warranty_months' => 36],
                ['item_text' => 'Tóner HP 58A', 'quantity' => 4, 'unit_cost' => '650.00'],
            ]]);
        $this->api->como('adminti1', Roles::ADMIN_TI);
    }

    private static function correo(string $nombre, string $apellido): string
    {
        // Sin iconv: la transliteración de musl (imagen Alpine) cambia «í» por «?» y se perdería la letra.
        $ascii = static fn (string $s): string => (string) preg_replace('/[^a-z]/', '', strtr(mb_strtolower($s),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']));

        return $ascii($nombre).'.'.$ascii($apellido).'@empresa-demo.local';
    }

    /**
     * A list response, plain or paginated (`{total, items}`).
     *
     * @param array<int|string, mixed> $respuesta
     *
     * @return list<array<string, mixed>>
     */
    private static function lista(array $respuesta): array
    {
        $filas = isset($respuesta['items']) && \is_array($respuesta['items']) ? $respuesta['items'] : $respuesta;

        return array_values(array_filter($filas, 'is_array'));
    }
}
