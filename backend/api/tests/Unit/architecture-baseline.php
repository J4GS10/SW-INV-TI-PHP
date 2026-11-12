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
    24 => 'Inventario/Aprobaciones.php',
    25 => 'Inventario/CierreMensual.php',
    26 => 'Inventario/Consumibles.php',
    27 => 'Inventario/Credenciales.php',
    28 => 'Inventario/EdicionLote.php',
    29 => 'Inventario/EstadosActivo.php',
    30 => 'Inventario/Faltantes.php',
    31 => 'Inventario/Instalaciones.php',
    32 => 'Inventario/Kardex.php',
    33 => 'Inventario/Mantenimientos.php',
    34 => 'Inventario/Movimientos.php',
    35 => 'Inventario/OperacionesLote.php',
    36 => 'Inventario/Operador.php',
    37 => 'Inventario/Productividad.php',
    38 => 'Inventario/Reclasificacion.php',
    39 => 'Inventario/ReglasClase.php',
    40 => 'Inventario/Reportes.php',
    41 => 'Inventario/Secuencias.php',
    42 => 'Inventario/Software.php',
    43 => 'Inventario/Solicitudes.php',
    44 => 'Inventario/Tomas.php',
    45 => 'Notificaciones/ReglasNotificacion.php',
    46 => 'Notificaciones/ResolutorDestinatarios.php',
    47 => 'Operations/HealthChecks.php',
    48 => 'Operations/OperationalAlerts.php',
    49 => 'Organizacion/Identidades.php',
    50 => 'Organizacion/Sugerencias.php',
    51 => 'Organizacion/TestAccountsCommand.php',
    52 => 'Reporting/PurchasingReports.php',
    53 => 'Security/Mfa/MfaExemption.php',
    54 => 'Security/Mfa/OracleMfaRequirementStore.php',
    55 => 'Security/Mfa/SkipMfaEnrollment.php',
    56 => 'Security/Mfa/TrustedDevices.php',
    57 => 'Seguridad/Alcance.php',
    58 => 'Tareas/ConexionVivaMiddleware.php',
    59 => 'Tareas/MantenimientoSeguridad.php',
    60 => 'Tareas/PurgaSeguridad.php',
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
