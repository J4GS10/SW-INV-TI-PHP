<?php

declare(strict_types=1);

namespace App\Documentos\Actas;

use App\Documentos\DescargaLogo;
use App\Documentos\FormatoLocal;
use App\Documentos\Idioma;
use App\Documentos\Traducciones;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Http\ErrorApi;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Actas de entrega y hojas de descargo de uno o varios movimientos de la misma
 * persona, en Word o PDF. Reúne los datos (con el alcance del usuario) y delega
 * el dibujo en el generador del formato.
 */
final readonly class Actas
{
    public const int MAX_MOVIMIENTOS = 50;
    private const string CIUDAD = 'Guatemala';
    private const string CODIGO_FORMULARIO = 'F.IT.GUA.04.01';

    public function __construct(
        private Connection $conexion,
        private Alcance $alcance,
        private DescargaLogo $logos,
        private FormatoLocal $formato,
        private ActaPdf $pdf,
        private ActaWord $word,
    ) {
    }

    /** @param list<string> $movimientos UUID en hex, en el orden pedido */
    public function generar(array $movimientos, FormatoActa $formato, TipoActa $tipo, ?string $mensajero, Idioma $idioma): string
    {
        $acta = $this->datos($movimientos, $tipo, $mensajero, $idioma);
        $generador = match ($formato) {
            FormatoActa::Pdf => $this->pdf,
            FormatoActa::Docx => $this->word,
        };

        return $generador->generar($acta);
    }

    /** «Acta_Entrega_<uuid>» o «Acta_Entrega_Lote_<AAAAMMDD_HHMM>» (sin extensión). */
    public function nombreArchivo(TipoActa $tipo, Idioma $idioma, ?string $movimiento): string
    {
        $base = $tipo->textos($idioma)['archivo'];

        return null !== $movimiento
            ? $base.'_'.Ident::aTexto($movimiento)
            : $base.'_'.TextosComunes::rotulos($idioma)['lote'].'_'.$this->formato->ahora()->format('Ymd_Hi');
    }

    /** @param list<string> $ids */
    private function datos(array $ids, TipoActa $tipo, ?string $mensajero, Idioma $idioma): DatosActa
    {
        // Un mismo movimiento repetido en el lote no duplica la fila del equipo.
        $movimientos = array_map($this->movimiento(...), array_values(array_unique($ids)));
        if ([] === $movimientos) {
            throw new ErrorApi(400, 'NO_MOVEMENTS_PROVIDED');
        }
        if ($tipo->exigeDevolucion() && array_filter($movimientos, static fn (array $m): bool => null === $m['MOV_Fecha_Devolucion']) !== []) {
            throw new ErrorApi(409, 'MOVEMENT_NOT_RETURNED');
        }
        $primero = $movimientos[0];
        foreach ($movimientos as $m) {
            if ($m['PER_Persona'] !== $primero['PER_Persona']) {
                throw new ErrorApi(400, 'CANNOT_MIX_DIFFERENT_PEOPLE_IN_SAME_DOCUMENT');
            }
        }

        $config = $this->conexion->fetchAssociative('SELECT "SYS_Nombre_Empresa", "SYS_Logo_URL", "SYS_Ciudad", "SYS_Codigo_Formulario" '
            .'FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1') ?: [];
        $empresa = trim((string) ($config['SYS_Nombre_Empresa'] ?? '')) ?: 'Mi Empresa';
        $mensajero = trim((string) $mensajero);

        return new DatosActa(
            tipo: $tipo,
            idioma: $idioma,
            empresa: $empresa,
            logo: $this->logos->descargar($config['SYS_Logo_URL'] ?? null),
            colaborador: trim($primero['PER_Primer_Nombre'].' '.$primero['PER_Primer_Apellido']),
            mensajero: '' === $mensajero ? null : $mensajero,
            ciudad: trim((string) ($config['SYS_Ciudad'] ?? '')) ?: self::CIUDAD,
            codigoFormulario: trim((string) ($config['SYS_Codigo_Formulario'] ?? '')) ?: self::CODIGO_FORMULARIO,
            fechaLarga: $this->formato->fechaLarga($this->fechaDelHecho($movimientos, $tipo), $idioma),
            referencia: strtoupper(substr(Ident::aTexto((string) $primero['MOV_Movimiento']), 0, 8)),
            motivo: ($primero['MOV_Observacion'] ?? '') ?: $tipo->textos($idioma)['motivo'],
            equipos: array_map(static fn (array $m): array => self::equipo($m, $idioma), $movimientos),
        );
    }

    /** @return array<string, mixed> */
    private function movimiento(string $id): array
    {
        [$alcance, $p] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        $fila = $this->conexion->fetchAssociative('SELECT m."MOV_Movimiento", m."PER_Persona", m."MOV_Fecha_Asignacion", m."MOV_Fecha_Devolucion", '
            .'m."MOV_Observacion", p."PER_Primer_Nombre", p."PER_Primer_Apellido", a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Hostname", '
            .'t."TAC_Nombre", mo."MOD_Nombre", ma."MAR_Nombre" FROM "INV_MOVIMIENTO" m JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona" '
            .'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
            .'LEFT JOIN "INV_MODELO" mo ON mo."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = mo."MAR_Marca" '
            .'WHERE m."MOV_Movimiento" = :id AND '.$alcance, [...$p, 'id' => $id]);

        return $fila ?: throw new ErrorApi(404, 'MOVEMENT_NOT_FOUND: '.Ident::aTexto($id));
    }

    /**
     * Fecha del hecho en la hora de la organización: la devolución (descargo) o la
     * primera entrega del lote. Regenerar un acta antigua no la fecha con hoy.
     *
     * @param non-empty-list<array<string, mixed>> $movimientos
     */
    private function fechaDelHecho(array $movimientos, TipoActa $tipo): \DateTimeImmutable
    {
        if ($tipo->exigeDevolucion() && null !== $movimientos[0]['MOV_Fecha_Devolucion']) {
            return $this->formato->aLocal(Reloj::desdeBd((string) $movimientos[0]['MOV_Fecha_Devolucion']));
        }
        if (null !== $movimientos[0]['MOV_Fecha_Asignacion']) {
            return $this->formato->aLocal(Reloj::desdeBd(min(array_map(static fn (array $m): string => (string) $m['MOV_Fecha_Asignacion'], $movimientos))));
        }

        return $this->formato->ahora();
    }

    /**
     * Fila de la tabla. «DISPOSITIVO» = hostname si existe; si no, el tipo; si no, el código.
     *
     * @param array<string, mixed> $m
     *
     * @return array{dispositivo: string, marca: string, modelo: string, serie: string}
     */
    private static function equipo(array $m, Idioma $idioma): array
    {
        $tipo = null === $m['TAC_Nombre'] ? '' : Traducciones::catalogo(Traducciones::TIPO_ACTIVO, $m['TAC_Nombre'], $idioma);
        $dispositivo = ($m['ACT_Hostname'] ?? '') ?: $tipo ?: ($m['ACT_Codigo_Interno'] ?? '—');

        return [
            'dispositivo' => '' === $dispositivo ? '—' : $dispositivo,
            'marca' => mb_strtoupper(($m['MAR_Nombre'] ?? '') ?: '—'),
            'modelo' => ($m['MOD_Nombre'] ?? '') ?: '—',
            'serie' => ($m['ACT_Serie_Fabricante'] ?? '') ?: '—',
        ];
    }
}
