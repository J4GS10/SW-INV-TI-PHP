<?php

declare(strict_types=1);

namespace App\Documentos\Actas;

use App\Documentos\Idioma;

/**
 * Acta de entrega o hoja de descargo (devolución). Textos formales en ES/EN/IT;
 * los títulos en español reproducen el formulario corporativo de referencia.
 * Marcadores: {empresa} y {colaborador}.
 */
enum TipoActa: string
{
    case Entrega = 'entrega';
    case Descargo = 'descargo';

    public const string PATRON = '^(entrega|descargo)$';

    private const array TEXTOS = [
        'entrega' => [
            'es' => [
                'titulo' => 'NOTA: ENTREGA DE EQUIPO',
                'intro' => 'El presente documento se extiende como respaldo de la entrega de dispositivos propiedad de {empresa} al colaborador {colaborador}, '
                    .'y en él se detallan las características de los dispositivos entregados.',
                'cuerpo' => 'Se emite para conocimiento y respaldo de la administración. Los activos de la empresa aquí descritos quedan bajo la custodia y '
                    .'responsabilidad del colaborador, quien se compromete a darles un uso adecuado y a devolverlos cuando la empresa así lo requiera.',
                'firma_izq' => ['Recibe el equipo', 'Colaborador'],
                'firma_der' => ['Entrega y autoriza', 'Departamento de TI'],
                'motivo' => 'Asignación de equipo.',
                'archivo' => 'Acta_Entrega',
            ],
            'en' => [
                'titulo' => 'NOTE: EQUIPMENT HANDOVER',
                'intro' => 'This document is issued as a record of the handover of devices owned by {empresa} to employee {colaborador}, and it describes '
                    .'the devices delivered.',
                'cuerpo' => 'It is issued for the information and records of management. The company assets described herein remain in the custody and '
                    .'under the responsibility of the employee, who undertakes to use them appropriately and to return them whenever the company so requires.',
                'firma_izq' => ['Received by', 'Employee'],
                'firma_der' => ['Delivered and authorized by', 'IT Department'],
                'motivo' => 'Equipment assignment.',
                'archivo' => 'Handover_Record',
            ],
            'it' => [
                'titulo' => 'NOTA: CONSEGNA DI APPARECCHIATURE',
                'intro' => 'Il presente documento viene redatto a comprova della consegna di dispositivi di proprietà di {empresa} al collaboratore '
                    .'{colaborador} e ne riporta le caratteristiche.',
                'cuerpo' => "Viene emesso per conoscenza e a supporto dell'amministrazione. I beni aziendali qui descritti sono affidati alla custodia e "
                    ."alla responsabilità del collaboratore, che si impegna a farne un uso appropriato e a restituirli su richiesta dell'azienda.",
                'firma_izq' => ["Riceve l'apparecchiatura", 'Collaboratore'],
                'firma_der' => ['Consegna e autorizza', 'Reparto IT'],
                'motivo' => 'Assegnazione di apparecchiature.',
                'archivo' => 'Verbale_Consegna',
            ],
        ],
        'descargo' => [
            'es' => [
                'titulo' => 'NOTA: DEVOLUCIÓN A {empresa}',
                'intro' => 'El presente documento se extiende como respaldo de la devolución de dispositivos a {empresa}, y en él se detallan las '
                    .'características de los dispositivos recibidos.',
                'cuerpo' => 'Se emite para conocimiento y respaldo de la administración, y hace constar la recepción de los activos de la empresa por '
                    .'causas tales como daño, obsolescencia del equipo, equipo propiedad del colaborador y/o caso especial autorizado.',
                'firma_izq' => ['Entrega el equipo', 'Colaborador'],
                'firma_der' => ['Recibe', 'Departamento de TI'],
                'motivo' => 'Devolución de equipo.',
                'archivo' => 'Acta_Descargo',
            ],
            'en' => [
                'titulo' => 'NOTE: RETURN OF EQUIPMENT TO {empresa}',
                'intro' => 'This document is issued as a record of the return of devices to {empresa}, and it describes the devices received.',
                'cuerpo' => 'It is issued for the information and records of management, and it certifies the receipt of company assets for reasons '
                    .'such as damage, obsolescence, employee-owned equipment and/or a specially authorized case.',
                'firma_izq' => ['Returned by', 'Employee'],
                'firma_der' => ['Received by', 'IT Department'],
                'motivo' => 'Equipment return.',
                'archivo' => 'Return_Record',
            ],
            'it' => [
                'titulo' => 'NOTA: RESTITUZIONE DI APPARECCHIATURE A {empresa}',
                'intro' => 'Il presente documento viene redatto a comprova della restituzione di dispositivi a {empresa} e ne riporta le caratteristiche.',
                'cuerpo' => "Viene emesso per conoscenza e a supporto dell'amministrazione e attesta la ricezione dei beni aziendali per motivi quali "
                    .'danneggiamento, obsolescenza, apparecchiatura di proprietà del collaboratore e/o caso speciale autorizzato.',
                'firma_izq' => ["Restituisce l'apparecchiatura", 'Collaboratore'],
                'firma_der' => ['Riceve', 'Reparto IT'],
                'motivo' => 'Restituzione di apparecchiature.',
                'archivo' => 'Verbale_Restituzione',
            ],
        ],
    ];

    /** @return array{titulo: string, intro: string, cuerpo: string, firma_izq: array{string, string}, firma_der: array{string, string}, motivo: string, archivo: string} */
    public function textos(Idioma $idioma): array
    {
        return self::TEXTOS[$this->value][$idioma->value];
    }

    /** La hoja de descargo certifica una devolución: el equipo debe estar devuelto. */
    public function exigeDevolucion(): bool
    {
        return self::Descargo === $this;
    }
}
