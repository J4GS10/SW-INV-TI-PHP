<?php

declare(strict_types=1);

/*
 * Files that broke a layer rule when the rule was written (tests/Unit/ArchitectureTest.php).
 * This list only shrinks. Regenerate it after a refactor:
 *   php tests/Unit/architecture-baseline.php --write
 */

if (PHP_SAPI === 'cli' && in_array('--write', $argv ?? [], true) && realpath($argv[0]) === __FILE__) {
    require_once \dirname(__DIR__).'/Soporte/Capas.php';
    $found = App\Tests\Soporte\Capas::infracciones(\dirname(__DIR__, 2).'/src');
    $source = (string) file_get_contents(__FILE__);
    $marker = '// --- generated below ---';
    $head = substr($source, 0, (int) strrpos($source, $marker) + \strlen($marker));
    file_put_contents(__FILE__, $head."\n\nreturn ".var_export($found, true).";\n");
    foreach ($found as $rule => $files) {
        echo str_pad($rule, 20), \count($files), "\n";
    }
    exit(0);
}

// --- generated below ---

return array (
  'negocio-sin-http' => 
  array (
    0 => 'Adjuntos/Subida.php',
    1 => 'Autenticacion/InicioSesion.php',
    2 => 'Correo/Notificador.php',
    3 => 'Crud/Esquema.php',
    4 => 'Crud/Operaciones.php',
    5 => 'Documentos/Descarga.php',
    6 => 'GoodsReceipt/Application/GoodsReceiptService.php',
    7 => 'Integracion/FormularioIntegracion.php',
    8 => 'Inventario/Aprobaciones.php',
    9 => 'Inventario/Esquemas.php',
    10 => 'Realtime/PublishChangesListener.php',
    11 => 'Security/Mfa/TrustedDevices.php',
  ),
  'negocio-sin-sql' => 
  array (
    0 => 'Adjuntos/Adjuntos.php',
    1 => 'Catalogo/CatalogoGenerico.php',
    2 => 'Directorio/CatalogosPersona.php',
    3 => 'Directorio/SincronizacionDirectorio.php',
    4 => 'Documentos/Exportaciones.php',
    5 => 'GoodsReceipt/Application/GoodsReceiptService.php',
    6 => 'Importacion/ImportacionActivos.php',
    7 => 'Inventario/Activos.php',
    8 => 'Inventario/CierreMensual.php',
    9 => 'Inventario/Consumibles.php',
    10 => 'Inventario/Credenciales.php',
    11 => 'Inventario/EdicionLote.php',
    12 => 'Inventario/Faltantes.php',
    13 => 'Inventario/Mantenimientos.php',
    14 => 'Inventario/Movimientos.php',
    15 => 'Inventario/OperacionesLote.php',
    16 => 'Inventario/Software.php',
    17 => 'Inventario/Tomas.php',
    18 => 'Organizacion/Identidades.php',
    19 => 'Tareas/MantenimientoSeguridad.php',
  ),
  'entrega-sin-sql' => 
  array (
    0 => 'Controller/CatalogoTecnicoController.php',
    1 => 'Controller/CatalogosController.php',
    2 => 'Controller/DirectorioController.php',
    3 => 'Controller/GobiernoController.php',
    4 => 'Controller/OrganizacionController.php',
    5 => 'Controller/SaludController.php',
    6 => 'Controller/SoftwareController.php',
    7 => 'Controller/UbicacionController.php',
    8 => 'EventListener/ErroresListener.php',
    9 => 'Http/Idempotencia.php',
  ),
  'dominio-puro' => 
  array (
    0 => 'Dominio/Ident.php',
  ),
);
