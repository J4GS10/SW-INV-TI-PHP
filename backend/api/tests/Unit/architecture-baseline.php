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
    1 => 'Autenticacion/DosFactores.php',
    2 => 'Autenticacion/Sso.php',
    3 => 'Catalogo/CatalogoGenerico.php',
    4 => 'Catalogo/PlantillaEspecificaciones.php',
    5 => 'Catalogo/SpecificationRules.php',
    6 => 'Correo/ColaCorreos.php',
    7 => 'Correo/Notificador.php',
    8 => 'Crud/Operaciones.php',
    9 => 'Crud/RepositorioEntidad.php',
    10 => 'Directorio/CatalogosPersona.php',
    11 => 'Directorio/SincronizacionDirectorio.php',
    12 => 'Documentos/Actas/Actas.php',
    13 => 'Documentos/DescargaLogo.php',
    14 => 'Documentos/Etiquetas.php',
    15 => 'Documentos/Exportaciones.php',
    16 => 'Documentos/HojaDeVida.php',
    17 => 'Documentos/ReportesCsv.php',
    18 => 'GoodsReceipt/Application/GoodsReceiptAct.php',
    19 => 'GoodsReceipt/Application/GoodsReceiptService.php',
    20 => 'Importacion/ImportacionActivos.php',
    21 => 'Integracion/RepositorioIntegracion.php',
    22 => 'Integracion/ServicioIntegracion.php',
    23 => 'Inventario/Activos.php',
    24 => 'Inventario/CierreMensual.php',
    25 => 'Inventario/Consumibles.php',
    26 => 'Inventario/Credenciales.php',
    27 => 'Inventario/EdicionLote.php',
    28 => 'Inventario/EstadosActivo.php',
    29 => 'Inventario/Faltantes.php',
    30 => 'Inventario/Instalaciones.php',
    31 => 'Inventario/Kardex.php',
    32 => 'Inventario/Mantenimientos.php',
    33 => 'Inventario/Movimientos.php',
    34 => 'Inventario/OperacionesLote.php',
    35 => 'Inventario/Operador.php',
    36 => 'Inventario/Productividad.php',
    37 => 'Inventario/ReglasClase.php',
    38 => 'Inventario/Reportes.php',
    39 => 'Inventario/Secuencias.php',
    40 => 'Inventario/Software.php',
    41 => 'Inventario/Tomas.php',
    42 => 'Notificaciones/ReglasNotificacion.php',
    43 => 'Notificaciones/ResolutorDestinatarios.php',
    44 => 'Organizacion/Identidades.php',
    45 => 'Organizacion/Sugerencias.php',
    46 => 'Organizacion/TestAccountsCommand.php',
    47 => 'Reporting/PurchasingReports.php',
    48 => 'Security/Mfa/MfaExemption.php',
    49 => 'Security/Mfa/OracleMfaRequirementStore.php',
    50 => 'Security/Mfa/SkipMfaEnrollment.php',
    51 => 'Security/Mfa/TrustedDevices.php',
    52 => 'Seguridad/Alcance.php',
    53 => 'Tareas/ConexionVivaMiddleware.php',
    54 => 'Tareas/MantenimientoSeguridad.php',
    55 => 'Tareas/PurgaSeguridad.php',
  ),
  'entrega-sin-sql' => 
  array (
    0 => 'Controller/CatalogoTecnicoController.php',
    1 => 'Controller/CatalogosController.php',
    2 => 'Controller/DirectorioController.php',
    3 => 'Controller/DosFactoresController.php',
    4 => 'Controller/GobiernoController.php',
    5 => 'Controller/LoginController.php',
    6 => 'Controller/OrganizacionController.php',
    7 => 'Controller/ProfilePhotoController.php',
    8 => 'Controller/SaludController.php',
    9 => 'Controller/SoftwareController.php',
    10 => 'Controller/SsoController.php',
    11 => 'Controller/SystemSecurityController.php',
    12 => 'Controller/UbicacionController.php',
    13 => 'EventListener/ErroresListener.php',
    14 => 'Http/Idempotencia.php',
  ),
  'dominio-puro' => 
  array (
    0 => 'Dominio/Ident.php',
  ),
);
