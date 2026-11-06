<?php

declare(strict_types=1);

namespace App\Documentos\Actas;

use App\Documentos\Idioma;

/** Rótulos de la tabla, firmas y pie legal del acta (comunes a entrega y descargo). */
final class TextosComunes
{
    private const array ROTULOS = [
        'es' => ['dispositivo' => 'DISPOSITIVO', 'marca' => 'MARCA', 'modelo' => 'MODELO', 'serie' => 'SERIE', 'descripcion' => 'Descripción',
            'mensajero' => 'Mensajero externo', 'externo' => 'No pertenece a {empresa}', 'lote' => 'Lote'],
        'en' => ['dispositivo' => 'DEVICE', 'marca' => 'BRAND', 'modelo' => 'MODEL', 'serie' => 'SERIAL NUMBER', 'descripcion' => 'Description',
            'mensajero' => 'External courier', 'externo' => 'Not affiliated with {empresa}', 'lote' => 'Batch'],
        'it' => ['dispositivo' => 'DISPOSITIVO', 'marca' => 'MARCA', 'modelo' => 'MODELLO', 'serie' => 'NUMERO DI SERIE', 'descripcion' => 'Descrizione',
            'mensajero' => 'Corriere esterno', 'externo' => 'Esterno a {empresa}', 'lote' => 'Lotto'],
    ];

    private const array PIE_LEGAL = [
        'es' => 'Este documento debe ser escaneado y enviado por correo electrónico a la administración y a las jefaturas relacionadas con la '
            .'adquisición y el retiro de equipos informáticos, ya sean activos propios de la empresa o de terceros. {empresa} no se hace responsable del '
            .'uso indebido que se dé al equipo fuera de sus instalaciones. Este documento no constituye un instrumento legal; se emite únicamente para '
            .'control interno y conocimiento de las partes involucradas, y es de uso exclusivo de {empresa}.',
        'en' => 'This document must be scanned and sent by email to the administration and to the managers involved in the acquisition and withdrawal '
            .'of IT equipment, whether owned by the company or by third parties. {empresa} is not liable for any misuse of the equipment outside its '
            .'premises. This document is not a legal instrument; it is issued solely for internal control and for the information of the parties '
            .'involved, and is for the exclusive use of {empresa}.',
        'it' => "Il presente documento deve essere scansionato e inviato via e-mail all'amministrazione e ai responsabili coinvolti nell'acquisizione "
            ."e nel ritiro delle apparecchiature informatiche, siano esse di proprietà dell'azienda o di terzi. {empresa} declina ogni responsabilità "
            ."per l'uso improprio delle apparecchiature al di fuori dei propri locali. Il presente documento non costituisce un atto avente valore "
            .'legale; è emesso esclusivamente per il controllo interno e per conoscenza delle parti coinvolte, ed è a uso esclusivo di {empresa}.',
    ];

    /** Idioma de corrección ortográfica de Word. */
    private const array ETIQUETA_WORD = ['es' => 'es-GT', 'en' => 'en-US', 'it' => 'it-IT'];

    /** Formas societarias: si el nombre ya termina en una, no se añade «S.A.». */
    private const array SUFIJOS_LEGALES = ['s.a.', 's.a', 'sa', 's.a.s.', 's.r.l.', 'srl', 's.p.a.', 'spa', 'inc.', 'inc', 'ltd.', 'ltd', 'llc',
        's. de r.l.', 's.a. de c.v.', 'gmbh'];

    /** @return array<string, string> */
    public static function rotulos(Idioma $idioma): array
    {
        return self::ROTULOS[$idioma->value];
    }

    public static function pieLegal(Idioma $idioma): string
    {
        return self::PIE_LEGAL[$idioma->value];
    }

    public static function etiquetaWord(Idioma $idioma): string
    {
        return self::ETIQUETA_WORD[$idioma->value];
    }

    /** Nombre legal para el cuerpo del acta: añade «S.A.» salvo que ya tenga forma societaria. */
    public static function razonSocial(string $empresa): string
    {
        $nombre = trim($empresa);
        $minusculas = rtrim(mb_strtolower($nombre), ',');
        foreach (self::SUFIJOS_LEGALES as $sufijo) {
            if (str_ends_with($minusculas, ' '.$sufijo) || str_ends_with($minusculas, ','.$sufijo)) {
                return $nombre;
            }
        }

        return "{$nombre} S.A.";
    }
}
