<?php

declare(strict_types=1);

namespace App\Esquema;

use Doctrine\DBAL\Connection;

/**
 * Catálogo mínimo canónico (seguro en producción, idempotente): solo las filas
 * de las que depende la lógica, sin datos de demostración.
 *
 * - Por nombre: estados operativos y tipos de movimiento (las transiciones los
 *   buscan por nombre), tipos de mantenimiento, de especificación y de evidencia.
 * - Por código (catálogos estándar): motivos, bajas, unidades, fallas, documentos,
 *   licencias… Se insertan solo si faltan; nunca se renombra, reactiva ni borra lo
 *   que el usuario cambió. Si ya hay una fila con ese nombre y sin código (creada
 *   a mano), se le asigna el código en vez de duplicarla.
 */
final readonly class SemillaMinima
{
    /** tabla => [columna del nombre, filas] */
    private const array POR_NOMBRE = [
        'INV_ESTADO_OPERATIVO' => ['EOP_Nombre', [
            ['EOP_Nombre' => 'Asignado', 'EOP_Descripcion' => 'En uso por una persona'],
            ['EOP_Nombre' => 'En Reparación', 'EOP_Descripcion' => 'Mantenimiento abierto'],
            ['EOP_Nombre' => 'Baja', 'EOP_Descripcion' => 'Fuera de inventario'],
            ['EOP_Nombre' => 'En Bodega', 'EOP_Descripcion' => 'Almacenado, listo para entregar'],
            ['EOP_Nombre' => 'En Tránsito', 'EOP_Descripcion' => 'Movimiento entre sedes'],
            ['EOP_Nombre' => 'Instalado', 'EOP_Descripcion' => 'Equipo compartido en uso en un área'],
        ]],
        'INV_TIPO_MOVIMIENTO' => ['TMO_Nombre', [
            ['TMO_Nombre' => 'Ingreso'], ['TMO_Nombre' => 'Asignación'], ['TMO_Nombre' => 'Devolución'],
            ['TMO_Nombre' => 'Préstamo'], ['TMO_Nombre' => 'Transferencia'], ['TMO_Nombre' => 'Baja por Retiro'],
        ]],
        'INV_TIPO_MANTENIMIENTO' => ['TMA_Nombre', [
            ['TMA_Nombre' => 'Preventivo'], ['TMA_Nombre' => 'Correctivo'], ['TMA_Nombre' => 'Predictivo'],
        ]],
        'INV_TIPO_ESPECIFICACION' => ['TES_Nombre', [
            ['TES_Nombre' => 'RAM', 'TES_Unidad_Medida' => 'GB', 'TES_Data_Type' => 'NUMBER', 'TES_Min_Value' => 1, 'TES_Max_Value' => 4096],
            ['TES_Nombre' => 'Tipo de RAM'],
            ['TES_Nombre' => 'Almacenamiento', 'TES_Unidad_Medida' => 'GB', 'TES_Data_Type' => 'NUMBER', 'TES_Min_Value' => 1, 'TES_Max_Value' => 1048576],
            ['TES_Nombre' => 'Tipo de disco'],
            ['TES_Nombre' => 'Procesador'],
            ['TES_Nombre' => 'Tarjeta Gráfica'],
            ['TES_Nombre' => 'Sistema Operativo'],
            ['TES_Nombre' => 'Tamaño Pantalla', 'TES_Unidad_Medida' => 'pulgadas', 'TES_Data_Type' => 'NUMBER', 'TES_Min_Value' => 1, 'TES_Max_Value' => 200],
        ]],
        'INV_TIPO_EVIDENCIA' => ['TEV_Nombre', [
            ['TEV_Nombre' => 'Fotografía'], ['TEV_Nombre' => 'Acta firmada'], ['TEV_Nombre' => 'Reporte técnico'],
        ]],
    ];

    /** tabla => [prefijo, [[código, nombre, columnas extra]]] */
    private const array POR_CODIGO = [
        'INV_MOTIVO_MOVIMIENTO' => ['MMO', [
            ['INGRESO_PERSONAL', 'Ingreso de personal', ['MMO_Aplica_A' => ['ASIGNACION']]],
            ['RENOVACION', 'Renovación', ['MMO_Aplica_A' => ['ASIGNACION', 'TRANSFERENCIA']]],
            ['REEMPLAZO_FALLA', 'Reemplazo por falla', ['MMO_Aplica_A' => ['ASIGNACION', 'TRANSFERENCIA']]],
            ['VISITA_PRESENCIAL', 'Visita presencial', ['MMO_Aplica_A' => ['ASIGNACION', 'PRESTAMO']]],
            ['PRESTAMO_TEMPORAL', 'Préstamo temporal', ['MMO_Aplica_A' => ['PRESTAMO']]],
            ['CAMBIO_PUESTO', 'Cambio de puesto', ['MMO_Aplica_A' => ['ASIGNACION', 'TRANSFERENCIA', 'DEVOLUCION']]],
            ['RENUNCIA', 'Salida de la persona', ['MMO_Aplica_A' => ['DEVOLUCION']]],
            ['CAMBIO_EQUIPO', 'Cambio de equipo', ['MMO_Aplica_A' => ['DEVOLUCION']]],
            ['FIN_PRESTAMO', 'Fin del préstamo', ['MMO_Aplica_A' => ['DEVOLUCION']]],
            ['FALLA', 'Falla del equipo', ['MMO_Aplica_A' => ['DEVOLUCION']]],
            ['REASIGNACION', 'Reasignación', ['MMO_Aplica_A' => ['DEVOLUCION', 'TRANSFERENCIA']]],
            ['REVERTIDO', 'Revertido', ['MMO_Aplica_A' => ['DEVOLUCION']]],
            ['PERDIDA_ROBO', 'Pérdida o robo', ['MMO_Aplica_A' => ['DEVOLUCION'], 'MMO_Pide_Detalle' => true]],
            // Equipos compartidos (impresora, switch…): instalar, reubicar y retirar.
            ['INSTALACION_NUEVA', 'Instalación nueva', ['MMO_Aplica_A' => ['INSTALACION']]],
            ['REUBICACION', 'Reubicación', ['MMO_Aplica_A' => ['INSTALACION']]],
            ['RETIRO_USO', 'Fin de uso en el área', ['MMO_Aplica_A' => ['RETIRO']]],
            ['RETIRO_FALLA', 'Retiro por falla', ['MMO_Aplica_A' => ['RETIRO']]],
            ['OTRO', 'Otro', ['MMO_Pide_Detalle' => true]],
        ]],
        'INV_MOTIVO_BAJA' => ['MBA', [
            ['OBSOLESCENCIA', 'Obsolescencia', []], ['DANO_IRREPARABLE', 'Daño irreparable', []], ['ROBO', 'Robo', []],
            ['PERDIDA', 'Pérdida', []], ['FIN_ARRENDAMIENTO', 'Fin de arrendamiento', []],
            ['GARANTIA_REEMPLAZO', 'Reemplazo por garantía', []], ['RECLASIFICACION', 'Reclasificado como consumible', []],
            ['OTRO', 'Otro', ['MBA_Pide_Detalle' => true]],
        ]],
        'INV_DESTINO_BAJA' => ['DBA', [
            ['DESTRUCCION', 'Destrucción', []], ['RECICLAJE', 'Reciclaje', []], ['DONACION', 'Donación', []],
            ['VENTA', 'Venta', []], ['DEVOLUCION_PROVEEDOR', 'Devolución al proveedor', []], ['RESGUARDO', 'Resguardo en bodega', []],
            ['NO_RECUPERADO', 'No recuperado (pérdida o robo)', []], ['CONSUMIBLES', 'Pasa a consumibles', []],
            ['OTRO', 'Otro', ['DBA_Pide_Detalle' => true]],
        ]],
        'INV_UNIDAD_MEDIDA' => ['UME', [
            ['UNIDAD', 'Unidad', ['UME_Simbolo' => 'u']], ['CAJA', 'Caja', []], ['PAQUETE', 'Paquete', []], ['ROLLO', 'Rollo', []],
            ['LITRO', 'Litro', ['UME_Simbolo' => 'l']], ['ML', 'Mililitro', ['UME_Simbolo' => 'ml']],
            ['METRO', 'Metro', ['UME_Simbolo' => 'm']], ['KG', 'Kilogramo', ['UME_Simbolo' => 'kg']],
            ['G', 'Gramo', ['UME_Simbolo' => 'g']], ['GB', 'Gigabyte', ['UME_Simbolo' => 'GB']],
            ['TB', 'Terabyte', ['UME_Simbolo' => 'TB']], ['MHZ', 'Megahercio', ['UME_Simbolo' => 'MHz']],
            ['GHZ', 'Gigahercio', ['UME_Simbolo' => 'GHz']], ['PULGADAS', 'Pulgadas', ['UME_Simbolo' => '"']],
            ['W', 'Vatio', ['UME_Simbolo' => 'W']], ['VA', 'Voltamperio', ['UME_Simbolo' => 'VA']],
            ['MAH', 'Miliamperio hora', ['UME_Simbolo' => 'mAh']], ['NUCLEOS', 'Núcleos', []],
            ['PORCENTAJE', 'Porcentaje', ['UME_Simbolo' => '%']], ['CICLOS', 'Ciclos', []],
        ]],
        'INV_CATEGORIA_CONSUMIBLE' => ['CCO', [
            ['LIMPIEZA', 'Limpieza', []], ['CABLES', 'Cables', []], ['TINTAS_TONER', 'Tintas y tóner', []],
            ['BATERIAS', 'Baterías', []], ['HERRAMIENTA', 'Herramienta', []], ['OTRO', 'Otro', []],
        ]],
        'INV_TIPO_AREA' => ['TAR', [
            ['OFICINA', 'Oficina', []], ['BODEGA', 'Bodega', []], ['REMOTO', 'Trabajo remoto', []],
            ['SERVICIO_TECNICO', 'Servicio técnico', []], ['OTRO', 'Otro', []],
        ]],
        'INV_TIPO_FALLA' => ['TFA', [
            ['NO_ENCIENDE', 'No enciende', []], ['PANTALLA', 'Pantalla', []], ['BATERIA', 'Batería', []], ['DISCO', 'Disco', []],
            ['TECLADO_TOUCHPAD', 'Teclado o touchpad', []], ['RED', 'Red', []], ['SOFTWARE_SO', 'Software o sistema operativo', []],
            ['DANO_FISICO', 'Daño físico', []], ['OTRO', 'Otro', ['TFA_Pide_Detalle' => true]],
        ]],
        'INV_ACCION_MANTENIMIENTO' => ['AMA', [
            ['LIMPIEZA', 'Limpieza', []], ['CAMBIO_PIEZA', 'Cambio de pieza', []],
            ['REINSTALACION_SO', 'Reinstalación del sistema operativo', []], ['ACTUALIZACION', 'Actualización', []],
            ['GARANTIA', 'Garantía', []], ['OTRO', 'Otro', ['AMA_Pide_Detalle' => true]],
        ]],
        'INV_TIPO_DOCUMENTO' => ['TDO', [
            ['FACTURA', 'Factura', []], ['TICKET', 'Ticket', []], ['ACTA', 'Acta', []], ['ORDEN_COMPRA', 'Orden de compra', []],
            ['RECEPCION', 'Recepción', []], ['MOVIMIENTO', 'Movimiento', []], ['BAJA', 'Baja', []],
            ['OFFBOARDING', 'Salida del empleado', []], ['TOMA_INVENTARIO', 'Toma de inventario', []], ['OTRO', 'Otro', []],
        ]],
        // Unificados con las categorías de adjunto, cuyos códigos en minúscula se conservan (INV_ADJUNTO.ADJ_Categoria).
        'INV_TIPO_EVIDENCIA' => ['TEV', [
            ['factura', 'Factura', []], ['foto', 'Fotografía', []], ['acta', 'Acta firmada', []],
            ['reporte', 'Reporte técnico', []], ['otro', 'Otro', []],
        ]],
        'INV_MODELO_COMERCIAL' => ['MCO', [
            ['PERPETUA', 'Perpetua', []], ['SUSCRIPCION', 'Suscripción', []], ['OEM', 'OEM', []], ['VOLUMEN', 'Volumen', []],
            ['SAAS', 'SaaS', []], ['PRUEBA', 'Prueba', []], ['GRATUITA', 'Gratuita / código abierto', []],
        ]],
        'INV_METODO_ACTIVACION' => ['MAC', [
            ['SERIAL', 'Serial', ['MAC_Pide_Serial' => true]],
            ['PRODUCT_KEY', 'Product key', ['MAC_Pide_Clave' => true]],
            ['SERIAL_KEY', 'Serial + key', ['MAC_Pide_Serial' => true, 'MAC_Pide_Clave' => true]],
            ['CUENTA', 'Cuenta (correo + clave)', ['MAC_Pide_Usuario' => true, 'MAC_Pide_Secreto' => true,
                'MAC_Etiqueta_Usuario' => 'Correo', 'MAC_Etiqueta_Secreto' => 'Contraseña']],
            ['SERVIDOR', 'Servidor de licencias', ['MAC_Pide_Servidor' => true, 'MAC_Etiqueta_Servidor' => 'host:puerto']],
            ['ARCHIVO', 'Archivo de licencia', ['MAC_Pide_Archivo' => true]],
            ['DONGLE', 'Llave USB (dongle)', ['MAC_Pide_Dispositivo' => true, 'MAC_Pide_Serial' => true]],
            ['TOKEN', 'Token / API key', ['MAC_Pide_Secreto' => true, 'MAC_Etiqueta_Secreto' => 'Token']],
            ['SIN_CREDENCIAL', 'Sin credencial', []],
        ]],
        'INV_FORMA_CONTEO' => ['FCO', [
            ['EQUIPO', 'Por equipo', []], ['PERSONA', 'Por persona (usuario nominal)', []],
            ['CONCURRENTE', 'Concurrente', []], ['SITIO', 'Por sitio / ilimitada', []],
        ]],
    ];

    public function __construct(private Connection $conexion)
    {
    }

    /** Inserta lo que falte. Devuelve cuántas filas creó o completó (0 si ya estaba). */
    public function sembrar(): int
    {
        return $this->conexion->transactional(fn (): int => $this->porNombre() + $this->porCodigo());
    }

    private function porNombre(): int
    {
        $creadas = 0;
        foreach (self::POR_NOMBRE as $tabla => [$columnaNombre, $filas]) {
            $existentes = array_map('strval', $this->conexion->fetchFirstColumn("SELECT \"{$columnaNombre}\" FROM \"{$tabla}\""));
            foreach ($filas as $fila) {
                if (!\in_array($fila[$columnaNombre], $existentes, true)) {
                    $this->conexion->insert($tabla, self::comillas($fila));
                    ++$creadas;
                }
            }
        }

        return $creadas;
    }

    private function porCodigo(): int
    {
        $creadas = 0;
        foreach (self::POR_CODIGO as $tabla => [$prefijo, $filas]) {
            $presentes = array_fill_keys(array_map('strval', $this->conexion->fetchFirstColumn(
                "SELECT column_name FROM user_tab_columns WHERE table_name = :t", ['t' => $tabla])), true);
            if ([] === $presentes) {
                continue;
            }
            $codigos = array_map(static fn (mixed $c): string => (string) $c,
                $this->conexion->fetchFirstColumn("SELECT \"{$prefijo}_Codigo\" FROM \"{$tabla}\""));
            $nombres = array_map(static fn (mixed $n): string => mb_strtolower(trim((string) $n)),
                $this->conexion->fetchFirstColumn("SELECT \"{$prefijo}_Nombre\" FROM \"{$tabla}\""));
            foreach ($filas as $i => [$codigo, $nombre, $extras]) {
                if (\in_array($codigo, $codigos, true)) {
                    continue;
                }
                $clave = mb_strtolower(trim($nombre));
                if (\in_array($clave, $nombres, true)) {
                    $creadas += $this->conexion->executeStatement(
                        "UPDATE \"{$tabla}\" SET \"{$prefijo}_Codigo\" = :c WHERE LOWER(TRIM(\"{$prefijo}_Nombre\")) = :n AND \"{$prefijo}_Codigo\" IS NULL",
                        ['c' => $codigo, 'n' => $clave],
                    );
                    continue;
                }
                $fila = ["{$prefijo}_Codigo" => $codigo, "{$prefijo}_Nombre" => $nombre, "{$prefijo}_Orden" => ($i + 1) * 10];
                foreach ($extras as $columna => $valor) {
                    if (isset($presentes[$columna])) {
                        $fila[$columna] = self::valor($valor);
                    }
                }
                $this->conexion->insert($tabla, self::comillas($fila));
                ++$creadas;
            }
        }

        return $creadas;
    }

    /**
     * Listas como texto JSON (columnas CLOB validadas con IS JSON), en el mismo
     * formato que las filas existentes («["A", "B"]»); booleanos como 1.
     */
    private static function valor(mixed $valor): string|int
    {
        return match (true) {
            \is_array($valor) => '['.implode(', ', array_map(static fn (mixed $v): string => (string) json_encode($v, \JSON_UNESCAPED_UNICODE), $valor)).']',
            \is_bool($valor) => $valor ? 1 : 0,
            default => (string) $valor,
        };
    }

    /**
     * Identificadores entre comillas para DBAL::insert (el esquema usa mayúsculas y minúsculas).
     *
     * @param array<string, mixed> $fila
     *
     * @return array<string, mixed>
     */
    private static function comillas(array $fila): array
    {
        $salida = [];
        foreach ($fila as $columna => $valor) {
            $salida["\"{$columna}\""] = $valor;
        }

        return $salida;
    }
}
