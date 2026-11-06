<?php

declare(strict_types=1);

namespace App\Notificaciones;

use App\Config\Ajustes;
use App\Directorio\Directorio;
use App\Directorio\ErrorDirectorio;
use App\Directorio\FabricaDirectorio;
use App\Integracion\FuenteIntegracion;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Destinatarios de un correo según la regla de su evento: lista ordenada y sin
 * repetidos de (correo, origen). Un fallo del directorio no impide el aviso: el
 * grupo afectado simplemente no aporta correos.
 */
final readonly class ResolutorDestinatarios
{
    public function __construct(
        private Connection $conexion,
        private ReglasNotificacion $reglas,
        private FuenteIntegracion $fuente,
        private FabricaDirectorio $directorios,
        private Ajustes $ajustes,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string>      $para       destinatarios directos
     * @param list<string>|null $afectados  personas involucradas (por defecto = $para)
     *
     * @return list<Destinatario> vacía si la regla está inactiva
     */
    public function resolver(string $plantilla, array $para = [], ?array $afectados = null, bool $copiarAdmins = true, ?ReglaNotificacion $regla = null): array
    {
        $para = self::minusculas($para);
        $afectados = null === $afectados ? $para : self::minusculas($afectados);
        // El directorio se resuelve una sola vez y solo si algún grupo lo necesita.
        $directorio = false;
        $grupo = function (string $g) use (&$directorio): array {
            if (false === $directorio) {
                $directorio = $this->directorios->activo();
            }

            return $this->correosDeGrupo($directorio, $g);
        };

        $evento = EventoNotificacion::tryFrom($plantilla);
        if (null === $evento) {
            $pares = array_map(static fn (string $c): Destinatario => new Destinatario($c, 'afectado'), $para);
            if ($copiarAdmins) {
                array_push($pares, ...$this->administradores($grupo));
            }

            return self::sinRepetidos($pares);
        }

        $regla ??= $this->reglas->cargar($evento);
        if (!$regla->activa) {
            return [];
        }
        $pares = [];
        if ($regla->notificarAfectado) {
            array_push($pares, ...array_map(static fn (string $c): Destinatario => new Destinatario($c, 'afectado'), $afectados));
        }
        if ($regla->notificarJefe) {
            array_push($pares, ...array_map(static fn (string $c): Destinatario => new Destinatario($c, 'jefe'), $this->jefes($afectados)));
        }
        if ($regla->copiarAdmins && $copiarAdmins) {
            array_push($pares, ...$this->administradores($grupo));
        }
        foreach ($regla->gruposAd as $g) {
            array_push($pares, ...array_map(static fn (string $c): Destinatario => new Destinatario($c, "grupo:{$g}"), $grupo($g)));
        }
        array_push($pares, ...array_map(static fn (string $c): Destinatario => new Destinatario($c, 'extra'), $regla->correosExtra));

        return self::sinRepetidos($pares);
    }

    /**
     * Correos de los jefes inmediatos (activos) de las personas indicadas.
     *
     * @param list<string> $afectados
     *
     * @return list<string>
     */
    public function jefes(array $afectados): array
    {
        $afectados = array_values(array_filter(self::minusculas($afectados), static fn (string $c): bool => '' !== $c));
        if ([] === $afectados) {
            return [];
        }
        $correos = $this->conexion->fetchFirstColumn(
            'SELECT j."PER_Email_Corporativo" FROM "INV_PERSONA" p JOIN "INV_PERSONA" j ON j."PER_Persona" = p."PER_Jefe" '
            .'WHERE LOWER(p."PER_Email_Corporativo") IN (:correos) AND j."PER_Estado" = 1',
            ['correos' => $afectados],
            ['correos' => ArrayParameterType::STRING],
        );

        return array_values(array_filter(array_map(static fn (mixed $c): string => strtolower((string) $c), $correos)));
    }

    /**
     * NOTIFY_ADMIN_EMAILS más los miembros del grupo de administradores de AD.
     *
     * @param \Closure(string): list<string> $grupo
     *
     * @return list<Destinatario>
     */
    private function administradores(\Closure $grupo): array
    {
        $correos = array_map('strtolower', $this->ajustes->correosAdmin);
        $grupoAdmin = $this->fuente->obtener()->ad->grupoAdmin;
        if ('' !== $grupoAdmin) {
            array_push($correos, ...$grupo($grupoAdmin));
        }

        return array_map(static fn (string $c): Destinatario => new Destinatario($c, 'admins'), $correos);
    }

    /** @return list<string> */
    private function correosDeGrupo(?Directorio $directorio, string $grupo): array
    {
        if (null === $directorio) {
            return [];
        }
        try {
            return $directorio->correosDeGrupo($grupo);
        } catch (ErrorDirectorio $e) {
            $this->logger->warning('notificacion.grupo_no_resuelto', ['grupo' => $grupo, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param list<Destinatario> $pares
     *
     * @return list<Destinatario>
     */
    private static function sinRepetidos(array $pares): array
    {
        $vistos = [];
        foreach ($pares as $d) {
            if ('' !== $d->correo && !isset($vistos[$d->correo])) {
                $vistos[$d->correo] = $d;
            }
        }

        return array_values($vistos);
    }

    /**
     * @param list<string|null> $correos
     *
     * @return list<string>
     */
    private static function minusculas(array $correos): array
    {
        return array_values(array_filter(array_map(static fn (?string $c): string => strtolower((string) $c), $correos),
            static fn (string $c): bool => '' !== $c));
    }
}
