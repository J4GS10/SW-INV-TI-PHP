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
    1 => 'Correo/Notificador.php',
    2 => 'Crud/Esquema.php',
    3 => 'Crud/Operaciones.php',
    4 => 'Documentos/Descarga.php',
    5 => 'GoodsReceipt/Application/GoodsReceiptService.php',
    6 => 'Integracion/FormularioIntegracion.php',
    7 => 'Inventario/Aprobaciones.php',
    8 => 'Inventario/Esquemas.php',
    9 => 'Realtime/PublishChangesListener.php',
  ),
  'negocio-sin-sql' => 
  array (
  ),
  'entrega-sin-sql' => 
  array (
    0 => 'Controller/CatalogoTecnicoController.php',
    1 => 'Controller/CatalogosController.php',
    2 => 'Controller/GobiernoController.php',
    3 => 'Controller/OrganizacionController.php',
    4 => 'Controller/SaludController.php',
    5 => 'EventListener/ErroresListener.php',
    6 => 'Http/Idempotencia.php',
  ),
  'dominio-puro' => 
  array (
    0 => 'Dominio/Ident.php',
  ),
);
