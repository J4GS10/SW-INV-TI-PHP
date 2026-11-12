<?php

declare(strict_types=1);

namespace App\Catalogo;

use App\Dominio\ErrorDominio;

/**
 * Catálogos estándar administrables desde el centro de catálogos. Cada uno se
 * declara una vez; las tablas que lo referencian se descubren de las llaves
 * foráneas del esquema (App\Catalogo\CatalogoGenerico).
 *
 * Forma de cada definición:
 *  - tabla, pk, prefijo, grupo, largo_nombre, codigo (¿tiene columna Codigo?), descripcion
 *  - extras: columna => [tipo (bool|texto|aplica_a|catalogo), largo, catálogo referido, [columna texto, fuente]]
 *  - codigo_automatico, largo_codigo: los registros del usuario reciben un código
 *  - textos: "TABLA.COLUMNA_FK" => [columna de texto heredada, columna del catálogo que la llena]
 *  - codigos: [[tabla, columna]] que guardan el CÓDIGO (sin FK) y se trasladan al fusionar
 *  - permite_crear: false si la lógica depende solo de los registros del sistema
 *  - sistema: códigos de los registros del sistema (no se renombran, desactivan ni fusionan)
 */
final class RegistroCatalogos
{
    public const array GRUPOS = ['ACTIVOS', 'MOVIMIENTOS', 'LICENCIAS', 'CONSUMIBLES', 'MANTENIMIENTO', 'UBICACIONES', 'DOCUMENTOS'];
    public const array APLICA_A = ['ASIGNACION', 'DEVOLUCION', 'TRANSFERENCIA', 'PRESTAMO'];
    private const array CREDENCIALES = ['usuario', 'secreto', 'serial', 'clave', 'servidor', 'archivo', 'dispositivo'];

    /** @return array<string, mixed> */
    public static function definicion(string $clave): array
    {
        return self::todos()[$clave] ?? throw ErrorDominio::noEncontrado('CATALOG_NOT_FOUND');
    }

    /** @return array<string, array<string, mixed>> */
    public static function todos(): array
    {
        static $todos = null;

        return $todos ??= self::construir();
    }

    /** @return array<string, array<string, mixed>> */
    private static function construir(): array
    {
        $detalle = static fn (string $p): array => ["{$p}_Pide_Detalle" => ['bool', 0, null, null]];
        $credenciales = [];
        foreach (self::CREDENCIALES as $c) {
            $credenciales['MAC_Pide_'.ucfirst($c)] = ['bool', 0, null, null];
        }
        foreach (self::CREDENCIALES as $c) {
            $credenciales['MAC_Etiqueta_'.ucfirst($c)] = ['texto', 60, null, null];
        }
        $d = static fn (string $clave, string $tabla, string $pk, string $prefijo, string $grupo, array $o = []): array => [
            'clave' => $clave, 'tabla' => $tabla, 'pk' => $pk, 'prefijo' => $prefijo, 'grupo' => $grupo,
            'largo_nombre' => $o['largo_nombre'] ?? 100, 'codigo' => $o['codigo'] ?? true, 'descripcion' => true,
            'extras' => $o['extras'] ?? [], 'codigo_automatico' => $o['codigo_automatico'] ?? false,
            'largo_codigo' => $o['largo_codigo'] ?? 40, 'textos' => $o['textos'] ?? [], 'codigos' => $o['codigos'] ?? [],
            'permite_crear' => $o['permite_crear'] ?? true, 'sistema' => $o['sistema'] ?? [],
        ];

        $lista = [
            $d('marcas', 'INV_MARCA', 'MAR_Marca', 'MAR', 'ACTIVOS', ['codigo' => false,
                'textos' => ['INV_SOFTWARE.MAR_Marca' => ['SOF_Fabricante', 'MAR_Nombre']]]),
            $d('tipos-conexion', 'INV_TIPO_CONEXION', 'TCN_Tipo_Conexion', 'TCN', 'ACTIVOS', ['codigo' => false, 'largo_nombre' => 50]),
            $d('tipos-especificacion', 'INV_TIPO_ESPECIFICACION', 'TES_Tipo_Especificacion', 'TES', 'ACTIVOS', ['codigo' => false,
                'extras' => ['UME_Unidad' => ['catalogo', 0, 'unidades-medida', ['TES_Unidad_Medida', 'UME_Simbolo']],
                    'TES_Data_Type' => ['tipo_dato', 0, null, null], 'TES_Min_Value' => ['numero', 0, null, null], 'TES_Max_Value' => ['numero', 0, null, null]]]),
            $d('unidades-medida', 'INV_UNIDAD_MEDIDA', 'UME_Unidad', 'UME', 'ACTIVOS', [
                'extras' => ['UME_Simbolo' => ['texto', 20, null, null]],
                'textos' => ['INV_CONSUMIBLE.UME_Unidad' => ['CON_Unidad', 'UME_Nombre'],
                    'INV_TIPO_ESPECIFICACION.UME_Unidad' => ['TES_Unidad_Medida', 'UME_Simbolo']],
                'sistema' => ['CAJA', 'CICLOS', 'G', 'GB', 'GHZ', 'KG', 'LITRO', 'MAH', 'METRO', 'MHZ', 'ML', 'NUCLEOS', 'PAQUETE',
                    'PORCENTAJE', 'PULGADAS', 'ROLLO', 'TB', 'UNIDAD', 'VA', 'W']]),
            $d('motivos-movimiento', 'INV_MOTIVO_MOVIMIENTO', 'MMO_Motivo', 'MMO', 'MOVIMIENTOS', [
                'extras' => ['MMO_Aplica_A' => ['aplica_a', 0, null, null], ...$detalle('MMO')],
                'sistema' => ['CAMBIO_EQUIPO', 'CAMBIO_PUESTO', 'FALLA', 'FIN_PRESTAMO', 'INGRESO_PERSONAL', 'INSTALACION_NUEVA', 'OTRO',
                    'PERDIDA_ROBO', 'PRESTAMO_TEMPORAL', 'REASIGNACION', 'REEMPLAZO_FALLA', 'RENOVACION', 'RENUNCIA', 'RETIRO_FALLA',
                    'RETIRO_USO', 'REUBICACION', 'REVERTIDO', 'VISITA_PRESENCIAL']]),
            $d('motivos-baja', 'INV_MOTIVO_BAJA', 'MBA_Motivo', 'MBA', 'MOVIMIENTOS', ['extras' => $detalle('MBA'),
                'codigo_automatico' => true, 'largo_codigo' => 30,
                'sistema' => ['DANO_IRREPARABLE', 'FIN_ARRENDAMIENTO', 'GARANTIA_REEMPLAZO', 'OBSOLESCENCIA', 'OTRO', 'PERDIDA',
                    'RECLASIFICACION', 'ROBO']]),
            $d('destinos-baja', 'INV_DESTINO_BAJA', 'DBA_Destino', 'DBA', 'MOVIMIENTOS', ['extras' => $detalle('DBA'),
                'codigo_automatico' => true, 'largo_codigo' => 30,
                'sistema' => ['CONSUMIBLES', 'DESTRUCCION', 'DEVOLUCION_PROVEEDOR', 'DONACION', 'NO_RECUPERADO', 'OTRO', 'RECICLAJE',
                    'RESGUARDO', 'VENTA']]),
            $d('categorias-consumible', 'INV_CATEGORIA_CONSUMIBLE', 'CCO_Categoria', 'CCO', 'CONSUMIBLES', [
                'textos' => ['INV_CONSUMIBLE.CCO_Categoria' => ['CON_Categoria', 'CCO_Nombre']],
                'sistema' => ['BATERIAS', 'CABLES', 'HERRAMIENTA', 'LIMPIEZA', 'OTRO', 'TINTAS_TONER']]),
            $d('tipos-mantenimiento', 'INV_TIPO_MANTENIMIENTO', 'TMA_Tipo_Mantenimiento', 'TMA', 'MANTENIMIENTO', ['codigo' => false, 'largo_nombre' => 50]),
            $d('tipos-falla', 'INV_TIPO_FALLA', 'TFA_Tipo_Falla', 'TFA', 'MANTENIMIENTO', ['extras' => $detalle('TFA'),
                'sistema' => ['BATERIA', 'DANO_FISICO', 'DISCO', 'NO_ENCIENDE', 'OTRO', 'PANTALLA', 'RED', 'SOFTWARE_SO', 'TECLADO_TOUCHPAD']]),
            $d('acciones-mantenimiento', 'INV_ACCION_MANTENIMIENTO', 'AMA_Accion', 'AMA', 'MANTENIMIENTO', ['extras' => $detalle('AMA'),
                'sistema' => ['ACTUALIZACION', 'CAMBIO_PIEZA', 'GARANTIA', 'LIMPIEZA', 'OTRO', 'REINSTALACION_SO']]),
            $d('tipos-area', 'INV_TIPO_AREA', 'TAR_Tipo_Area', 'TAR', 'UBICACIONES', [
                'sistema' => ['BODEGA', 'OFICINA', 'OTRO', 'REMOTO', 'SERVICIO_TECNICO']]),
            $d('tipos-documento', 'INV_TIPO_DOCUMENTO', 'TDO_Tipo_Documento', 'TDO', 'DOCUMENTOS', ['codigo_automatico' => true, 'largo_codigo' => 30,
                'sistema' => ['ACTA', 'BAJA', 'FACTURA', 'MOVIMIENTO', 'OFFBOARDING', 'ORDEN_COMPRA', 'OTRO', 'RECEPCION', 'TICKET', 'TOMA_INVENTARIO']]),
            $d('modelos-comerciales', 'INV_MODELO_COMERCIAL', 'MCO_Modelo_Comercial', 'MCO', 'LICENCIAS', [
                'sistema' => ['GRATUITA', 'OEM', 'PERPETUA', 'PRUEBA', 'SAAS', 'SUSCRIPCION', 'VOLUMEN']]),
            $d('metodos-activacion', 'INV_METODO_ACTIVACION', 'MAC_Metodo', 'MAC', 'LICENCIAS', ['codigo_automatico' => true,
                'extras' => $credenciales,
                'sistema' => ['ARCHIVO', 'CUENTA', 'DONGLE', 'PRODUCT_KEY', 'SERIAL', 'SERIAL_KEY', 'SERVIDOR', 'SIN_CREDENCIAL', 'TOKEN']]),
            $d('formas-conteo', 'INV_FORMA_CONTEO', 'FCO_Forma', 'FCO', 'LICENCIAS', ['permite_crear' => false,
                'sistema' => ['CONCURRENTE', 'EQUIPO', 'PERSONA', 'SITIO']]),
            $d('tipos-evidencia', 'INV_TIPO_EVIDENCIA', 'TEV_Tipo_Evidencia', 'TEV', 'DOCUMENTOS', ['codigo_automatico' => true,
                'largo_codigo' => 20, 'largo_nombre' => 50, 'codigos' => [['INV_ADJUNTO', 'ADJ_Categoria']],
                'sistema' => ['acta', 'factura', 'foto', 'otro', 'reporte']]),
        ];
        $todos = [];
        foreach ($lista as $definicion) {
            $todos[$definicion['clave']] = $definicion;
        }

        return $todos;
    }
}
