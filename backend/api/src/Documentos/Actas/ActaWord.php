<?php

declare(strict_types=1);

namespace App\Documentos\Actas;

use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;
use PhpOffice\PhpWord\Style\Language;

/** Acta en Word (.docx) con el mismo contenido y disposición que la versión PDF. */
final readonly class ActaWord implements GeneradorActa
{
    private const string AZUL = '1F3A5F';
    private const string GRIS = '5A6473';
    /** Anchos de DISPOSITIVO | MARCA | MODELO | SERIE en pulgadas. */
    private const array COLUMNAS = [1.9, 1.35, 1.85, 1.65];
    private const array SIN_BORDES = ['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMargin' => 0];

    public function generar(DatosActa $acta): string
    {
        // Los datos de usuario (empresa, nombres, motivo) pueden traer «&» o «<»: se escapan al escribir el XML.
        Settings::setOutputEscapingEnabled(true);
        $word = new PhpWord();
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $idioma = TextosComunes::etiquetaWord($acta->idioma);
        $word->getSettings()->setThemeFontLang(new Language($idioma));
        $word->getDocInfo()->setTitle($acta->tituloDocumento())->setCreator($acta->empresa);

        $seccion = $word->addSection([
            'marginTop' => Converter::inchToTwip(0.8), 'marginBottom' => Converter::inchToTwip(0.7),
            'marginLeft' => Converter::inchToTwip(0.9), 'marginRight' => Converter::inchToTwip(0.9),
        ]);
        $justificado = ['alignment' => Jc::BOTH];
        $centrado = ['alignment' => Jc::CENTER];

        // --- Encabezado: logo (o empresa) a la izquierda; código y fecha a la derecha ---
        $encabezado = $seccion->addTable(self::SIN_BORDES);
        $encabezado->addRow();
        $izquierda = $encabezado->addCell(self::twips(3.4), ['valign' => 'center']);
        if (null !== $acta->logo) {
            [$ancho, $alto] = $acta->logo->ajustadoA(2.6, 1.0);
            $izquierda->addImage($acta->logo->png, ['width' => Converter::inchToPoint($ancho), 'height' => Converter::inchToPoint($alto)]);
        } else {
            $izquierda->addText($acta->empresa, ['bold' => true, 'size' => 22, 'color' => self::AZUL]);
        }
        $derecha = $encabezado->addCell(self::twips(3.4), ['valign' => 'center']);
        $parrafo = $derecha->addTextRun(['alignment' => Jc::END]);
        $parrafo->addText($acta->codigoFormulario, ['bold' => true, 'size' => 10]);
        $parrafo->addTextBreak();
        $parrafo->addText($acta->ciudad.', '.$acta->fechaLarga, ['size' => 10]);

        $seccion->addTextBreak();
        $seccion->addText($acta->titulo(), ['bold' => true, 'size' => 12], $centrado);
        $seccion->addTextBreak();
        $seccion->addText($acta->introduccion(), [], $justificado);
        $seccion->addTextBreak();

        // --- Tabla de equipos ---
        $r = $acta->rotulos();
        $tabla = $seccion->addTable(['borderSize' => 6, 'borderColor' => '000000', 'alignment' => JcTable::CENTER, 'cellMargin' => 60]);
        $tabla->addRow(null, ['tblHeader' => true]);
        foreach ([$r['dispositivo'], $r['marca'], $r['modelo'], $r['serie']] as $i => $rotulo) {
            $tabla->addCell(self::twips(self::COLUMNAS[$i]), ['bgColor' => self::AZUL, 'valign' => 'center'])
                ->addText($rotulo, ['bold' => true, 'size' => 10, 'color' => 'FFFFFF'], $centrado);
        }
        foreach ($acta->equipos as $equipo) {
            $tabla->addRow(null, ['cantSplit' => true]);
            foreach ([$equipo['dispositivo'], $equipo['marca'], $equipo['modelo'], $equipo['serie']] as $i => $valor) {
                $tabla->addCell(self::twips(self::COLUMNAS[$i]), ['valign' => 'center'])->addText($valor, ['size' => 10], $centrado);
            }
        }
        $seccion->addTextBreak();

        // --- Cuerpo y descripción ---
        $seccion->addText($acta->textos()['cuerpo'], [], $justificado);
        $descripcion = $seccion->addTextRun($justificado);
        $descripcion->addText($r['descripcion'].': ', ['bold' => true]);
        $descripcion->addText($acta->motivo);
        $seccion->addTextBreak(5);

        // --- Firmas ---
        $firmas = $seccion->addTable(self::SIN_BORDES);
        $firmas->addRow(null, ['cantSplit' => true]);
        foreach ($acta->firmas() as $firma) {
            self::firma($firmas->addCell(self::twips(3.35)), ...$firma);
        }
        $seccion->addTextBreak(2);

        // --- Pie legal ---
        $seccion->addText('•  '.$acta->pieLegal(), ['size' => 7.5, 'color' => self::GRIS], $justificado);

        return self::comoBytes($word);
    }

    /** Pulgadas → twips enteros (anchos de celda de Word). */
    private static function twips(float $pulgadas): int
    {
        return (int) round(Converter::inchToTwip($pulgadas));
    }

    private static function firma(Cell $celda, string $nombre, string $accion, string $depto, string $empresa): void
    {
        $p = $celda->addTextRun(['alignment' => Jc::CENTER]);
        $p->addText('_______________________________');
        $p->addTextBreak();
        if ('' !== $nombre) {
            $p->addText($nombre, ['bold' => true, 'size' => 10.5]);
            $p->addTextBreak();
        }
        $p->addText($accion, ['size' => 10]);
        $p->addTextBreak();
        $p->addText($depto, ['bold' => true, 'size' => 10]);
        $p->addTextBreak();
        $p->addText($empresa, ['size' => 9.5, 'color' => self::GRIS]);
    }

    private static function comoBytes(PhpWord $word): string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'acta');
        if (false === $temporal) {
            throw new \RuntimeException('No se pudo crear el archivo temporal del acta.');
        }
        try {
            IOFactory::createWriter($word, 'Word2007')->save($temporal);

            return (string) file_get_contents($temporal);
        } finally {
            @unlink($temporal);
        }
    }
}
