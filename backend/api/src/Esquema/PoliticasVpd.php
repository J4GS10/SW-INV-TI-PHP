<?php

declare(strict_types=1);

namespace App\Esquema;

/**
 * Políticas VPD por sede (fuente única de los predicados).
 *
 * El predicado se calcula en cada ejecución leyendo INV_USUARIO /
 * INV_USUARIO_SEDE: un cambio de sedes rige de inmediato. Un usuario global
 * (USU_Alcance_Global o rol de gobierno) no recibe predicado; uno inactivo o sin
 * sedes no ve nada. Las escrituras llevan `update_check`: no se puede insertar ni
 * mover un registro a una sede fuera del alcance (ORA-28115 → SEDE_OUT_OF_SCOPE).
 * App\Seguridad\Alcance lee los mismos predicados de INV_VPD_PREDICADO para la
 * primera capa (en la aplicación): las dos capas no pueden divergir.
 */
final readonly class PoliticasVpd
{
    private const array TODAS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    public function __construct(private string $contexto)
    {
        if (1 !== preg_match('/^[A-Z][A-Z0-9_]{0,127}$/', $contexto)) {
            throw new \InvalidArgumentException("Contexto de aplicación inválido: {$contexto}");
        }
    }

    /** @return list<PoliticaVpd> */
    public function todas(): array
    {
        $personaRelacionada = implode(' OR ', [
            $this->sede('"INV_PERSONA"."SED_Sede"'),
            '"INV_PERSONA"."PER_Persona" = '.$this->personaPropia(),
            self::existe('INV_MOVIMIENTO', 'm', 'm."PER_Persona" = "INV_PERSONA"."PER_Persona"'),
            self::existe('INV_MANTENIMIENTO', 'mt', 'mt."PER_Persona_Solicita" = "INV_PERSONA"."PER_Persona"'),
            self::existe('INV_INSTALACION', 'i', 'i."PER_Persona" = "INV_PERSONA"."PER_Persona"'),
            self::existe('INV_MOVIMIENTO_CONSUMIBLE', 'mc', 'mc."PER_Persona" = "INV_PERSONA"."PER_Persona"'),
            // Responsable de un equipo compartido visible (impresora de su área).
            self::existe('INV_ACTIVO', 'ar', 'ar."ACT_Responsable" = "INV_PERSONA"."PER_Persona"'),
        ]);
        $movimientoVisible = self::activoVisible('INV_MOVIMIENTO');
        $evidencia = '('.self::existe('INV_MOVIMIENTO', 'm', 'm."MOV_Movimiento" = "INV_EVIDENCIA"."MOV_Movimiento_Ref"')
            .' OR '.self::existe('INV_MANTENIMIENTO', 'mt', 'mt."MAN_Mantenimiento" = "INV_EVIDENCIA"."MAN_Mantenimiento_Ref"').')';
        // Los archivos de licencia no tienen sede (las licencias son globales).
        $adjunto = '('.self::activoVisible('INV_ADJUNTO').' OR '.self::ordenVisible('INV_ADJUNTO')
            .' OR '.self::existe('PUR_GOODS_RECEIPT', 'g', 'g."GRC_GOODS_RECEIPT" = "INV_ADJUNTO"."GRC_Goods_Receipt"')
            // Pueden contener la activación: solo administradores de TI, también dentro de la base.
            .' OR ("INV_ADJUNTO"."LIC_Licencia" IS NOT NULL AND EXISTS (SELECT 1 FROM "INV_USUARIO" ua WHERE ua."USU_Usuario" = '.$this->usuario()." AND ua.\"USU_Rol\" = 'ADMIN_TI')))";
        // Una instalación va a un activo o, sin activo, directamente a una persona.
        // INV_VPD.PERSONA_SEDE lee la sede sin aplicar la política de INV_PERSONA,
        // que a su vez consulta INV_INSTALACION (evita la política circular).
        $instalacion = '('.self::activoVisible('INV_INSTALACION').' OR ("INV_INSTALACION"."ACT_Activo" IS NULL AND '
            .$this->sede('INV_VPD.PERSONA_SEDE("INV_INSTALACION"."PER_Persona")').'))';
        $kardex = '('.$this->sede('"INV_KARDEX"."SED_Sede"').' OR '.$this->sede('"INV_KARDEX"."KDX_Origen_Sede"')
            .' OR '.$this->sede('"INV_KARDEX"."KDX_Destino_Sede"').' OR "INV_KARDEX"."USU_Usuario" = '.$this->usuario().')';
        $auditoria = '('.$this->sede('"INV_AUDITORIA_SISTEMA"."AUD_Sede"').' OR "INV_AUDITORIA_SISTEMA"."USU_Usuario" = '.$this->usuario().')';
        $aprobacion = '('.$this->sede('"INV_APROBACION"."SED_Sede"').' OR "INV_APROBACION"."APR_Solicitante" = '.$this->usuario().')';
        $areaMovimiento = '(SELECT e."SED_Sede" FROM "INV_AREA" ar '
            .'JOIN "INV_NIVEL" n ON n."NIV_Nivel" = ar."NIV_Nivel" '
            .'JOIN "INV_EDIFICIO" e ON e."EDI_Edificio" = n."EDI_Edificio" '
            .'WHERE ar."ARE_Area" = "INV_MOVIMIENTO"."ARE_Area")';

        $lectura = static fn (string $tabla, string $predicado): PoliticaVpd => new PoliticaVpd($tabla, PoliticaVpd::LECTURA, self::TODAS, $predicado);

        return [
            $lectura('INV_ACTIVO', $this->sede('"INV_ACTIVO"."SED_Sede"')),
            new PoliticaVpd('INV_PERSONA', PoliticaVpd::LECTURA, ['SELECT'], $personaRelacionada),
            new PoliticaVpd('INV_PERSONA', PoliticaVpd::ESCRITURA, ['INSERT', 'UPDATE', 'DELETE'], $this->sede('"INV_PERSONA"."SED_Sede"')),
            $lectura('INV_ESPECIFICACION', self::activoVisible('INV_ESPECIFICACION')),
            new PoliticaVpd('INV_MOVIMIENTO', PoliticaVpd::LECTURA, ['SELECT'], $movimientoVisible),
            new PoliticaVpd('INV_MOVIMIENTO', PoliticaVpd::ESCRITURA, ['UPDATE', 'DELETE'], $movimientoVisible),
            // Una asignación nueva exige que el área de destino esté en el alcance.
            new PoliticaVpd('INV_MOVIMIENTO', PoliticaVpd::INSERCION, ['INSERT'], $movimientoVisible.' AND '.$this->sede($areaMovimiento)),
            $lectura('INV_MANTENIMIENTO', self::activoVisible('INV_MANTENIMIENTO')),
            $lectura('INV_DETALLE_MANT', self::existe('INV_MANTENIMIENTO', 'mt', 'mt."MAN_Mantenimiento" = "INV_DETALLE_MANT"."MAN_Mantenimiento"')),
            $lectura('INV_EVIDENCIA', $evidencia),
            $lectura('INV_INSTALACION', $instalacion),
            $lectura('INV_ADJUNTO', $adjunto),
            $lectura('INV_ORDEN_COMPRA', $this->sede('"INV_ORDEN_COMPRA"."SED_Sede"')),
            $lectura('PUR_GOODS_RECEIPT', $this->sede('"PUR_GOODS_RECEIPT"."GRC_SED_SITE"')),
            $lectura('TRC_MONTH_END_CLOSE', $this->sede('"TRC_MONTH_END_CLOSE"."MEC_SED_SITE"')),
            $lectura('PUR_GOODS_RECEIPT_LINE', self::existe('PUR_GOODS_RECEIPT', 'g', 'g."GRC_GOODS_RECEIPT" = "PUR_GOODS_RECEIPT_LINE"."GRL_GRC_GOODS_RECEIPT"')),
            $lectura('PUR_GOODS_RECEIPT_EVENT', self::existe('PUR_GOODS_RECEIPT', 'g', 'g."GRC_GOODS_RECEIPT" = "PUR_GOODS_RECEIPT_EVENT"."GRE_GRC_GOODS_RECEIPT"')),
            $lectura('PUR_GOODS_RECEIPT_UNIT', self::existe('INV_ACTIVO', 'a', 'a."ACT_Activo" = "PUR_GOODS_RECEIPT_UNIT"."GRU_ACT_ASSET"')),
            $lectura('INV_ORDEN_COMPRA_LINEA', self::ordenVisible('INV_ORDEN_COMPRA_LINEA')),
            $lectura('INV_ORDEN_COMPRA_LINEA_ACTIVO', self::existe('INV_ORDEN_COMPRA_LINEA', 'l', 'l."OCL_Linea" = "INV_ORDEN_COMPRA_LINEA_ACTIVO"."OCL_Linea"')),
            $lectura('INV_CONSUMIBLE', $this->sede('"INV_CONSUMIBLE"."SED_Sede"')),
            $lectura('INV_MOVIMIENTO_CONSUMIBLE', self::existe('INV_CONSUMIBLE', 'c', 'c."CON_Consumible" = "INV_MOVIMIENTO_CONSUMIBLE"."CON_Consumible"')),
            $lectura('INV_RECEPCION', self::ordenVisible('INV_RECEPCION')),
            $lectura('INV_RECEPCION_LINEA', self::existe('INV_RECEPCION', 'r', 'r."REC_Recepcion" = "INV_RECEPCION_LINEA"."REC_Recepcion"')),
            $lectura('INV_TOMA_INVENTARIO', $this->sede('"INV_TOMA_INVENTARIO"."SED_Sede"')),
            $lectura('INV_TOMA_LECTURA', self::existe('INV_TOMA_INVENTARIO', 't', 't."TOM_Toma" = "INV_TOMA_LECTURA"."TOM_Toma"')),
            $lectura('INV_FALTANTE', self::activoVisible('INV_FALTANTE')),
            $lectura('INV_CONSUMIBLE_COMPATIBLE', self::existe('INV_CONSUMIBLE', 'c', 'c."CON_Consumible" = "INV_CONSUMIBLE_COMPATIBLE"."CON_Consumible"')),
            // Solicitudes de aprobación: las de las sedes del alcance y las propias.
            $lectura('INV_APROBACION', $aprobacion),
            // Kardex y bitácora: se lee lo del alcance y lo propio; insertar no se
            // restringe (toda acción debe quedar registrada); nunca se modifican.
            new PoliticaVpd('INV_KARDEX', PoliticaVpd::LECTURA, ['SELECT'], $kardex),
            new PoliticaVpd('INV_AUDITORIA_SISTEMA', PoliticaVpd::LECTURA, ['SELECT'], $auditoria),
        ];
    }

    private function usuario(): string
    {
        return "SYS_CONTEXT('{$this->contexto}', 'USUARIO')";
    }

    private function sedesDelUsuario(): string
    {
        return '(SELECT us."SED_Sede" FROM "INV_USUARIO_SEDE" us '
            .'JOIN "INV_USUARIO" u ON u."USU_Usuario" = us."USU_Usuario" AND u."USU_Estado" = 1 '
            .'WHERE us."USU_Usuario" = '.$this->usuario().')';
    }

    private function personaPropia(): string
    {
        return '(SELECT u."PER_Persona" FROM "INV_USUARIO" u WHERE u."USU_Usuario" = '.$this->usuario().')';
    }

    private function sede(string $columna): string
    {
        return "{$columna} IN ".$this->sedesDelUsuario();
    }

    // La subconsulta sobre otra tabla con política aplica también esa política.
    private static function existe(string $tabla, string $alias, string $condicion): string
    {
        return "EXISTS (SELECT 1 FROM \"{$tabla}\" {$alias} WHERE {$condicion})";
    }

    private static function activoVisible(string $tabla): string
    {
        return self::existe('INV_ACTIVO', 'a', "a.\"ACT_Activo\" = \"{$tabla}\".\"ACT_Activo\"");
    }

    private static function ordenVisible(string $tabla): string
    {
        return self::existe('INV_ORDEN_COMPRA', 'o', "o.\"OCO_Orden\" = \"{$tabla}\".\"OCO_Orden\"");
    }
}
