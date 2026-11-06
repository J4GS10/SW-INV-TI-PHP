<?php

declare(strict_types=1);

namespace App\Documentos\Actas;

use App\Documentos\Idioma;
use App\Documentos\Logo;

/** Todo lo que un generador necesita para dibujar el acta (ya traducido y formateado). */
final readonly class DatosActa
{
    /** @param list<array{dispositivo: string, marca: string, modelo: string, serie: string}> $equipos */
    public function __construct(
        public TipoActa $tipo,
        public Idioma $idioma,
        public string $empresa,
        public ?Logo $logo,
        public string $colaborador,
        public ?string $mensajero,
        public string $ciudad,
        public string $codigoFormulario,
        public string $fechaLarga,
        public string $referencia,
        public string $motivo,
        public array $equipos,
    ) {
    }

    /** @return array{titulo: string, intro: string, cuerpo: string, firma_izq: array{string, string}, firma_der: array{string, string}, motivo: string, archivo: string} */
    public function textos(): array
    {
        return $this->tipo->textos($this->idioma);
    }

    /** @return array<string, string> */
    public function rotulos(): array
    {
        return TextosComunes::rotulos($this->idioma);
    }

    public function titulo(): string
    {
        return str_replace('{empresa}', mb_strtoupper($this->empresa), $this->textos()['titulo']);
    }

    public function introduccion(): string
    {
        return strtr($this->textos()['intro'], ['{empresa}' => TextosComunes::razonSocial($this->empresa), '{colaborador}' => $this->colaborador]);
    }

    public function pieLegal(): string
    {
        return str_replace('{empresa}', $this->empresa, TextosComunes::pieLegal($this->idioma));
    }

    /** Título del documento en sus propiedades (Word/PDF): título con la empresa tal cual y la referencia. */
    public function tituloDocumento(): string
    {
        return trim(str_replace('{empresa}', $this->empresa, $this->textos()['titulo']).' '.$this->referencia);
    }

    /**
     * Las dos firmas [nombre, acción, departamento, empresa]: a la izquierda el
     * colaborador; a la derecha TI o, si lo hay, el mensajero externo que recibe.
     *
     * @return array{0: array{string, string, string, string}, 1: array{string, string, string, string}}
     */
    public function firmas(): array
    {
        $t = $this->textos();
        $r = $this->rotulos();
        $derecha = null !== $this->mensajero
            ? [$this->mensajero, $t['firma_der'][0], $r['mensajero'], str_replace('{empresa}', $this->empresa, $r['externo'])]
            : ['', $t['firma_der'][0], $t['firma_der'][1], $this->empresa];

        return [[$this->colaborador, $t['firma_izq'][0], $t['firma_izq'][1], $this->empresa], $derecha];
    }
}
