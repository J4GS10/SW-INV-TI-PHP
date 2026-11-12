<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

/**
 * The layers of the backend and what each one may depend on, read from the `use` statements and
 * the fully qualified names of every file under src/.
 *
 *   entrega   Controller, Http, EventListener, Kernel: HTTP in, HTTP out.
 *   datos     Repositorio, <módulo>/Infrastructure, Db, Esquema: SQL and the database.
 *   dominio   Dominio, <módulo>/Domain: values and rules with no framework.
 *   negocio   everything else: use cases.
 *
 * Rules (tests/Unit/ArchitectureTest.php enforces them):
 *   negocio-sin-http   the business layer knows nothing about HTTP;
 *   negocio-sin-sql    the business layer reaches the database only through the data layer;
 *   entrega-sin-sql    the delivery layer never queries the database;
 *   dominio-puro       the domain depends on no framework.
 */
final class Capas
{
    public const array REGLAS = [
        'negocio-sin-http' => ['capa' => 'negocio', 'prohibido' => '~^(Symfony\\\\Component\\\\(HttpFoundation|HttpKernel)|App\\\\(Http|Controller))\\\\~'],
        'negocio-sin-sql' => ['capa' => 'negocio', 'prohibido' => '~^Doctrine\\\\DBAL\\\\~'],
        'entrega-sin-sql' => ['capa' => 'entrega', 'prohibido' => '~^Doctrine\\\\DBAL\\\\~'],
        'dominio-puro' => ['capa' => 'dominio', 'prohibido' => '~^(Doctrine|Symfony)\\\\~'],
    ];

    public static function capa(string $relativo): string
    {
        return match (true) {
            1 === preg_match('~^(Controller|Http|EventListener)/|^Kernel\.php$~', $relativo) => 'entrega',
            1 === preg_match('~^(Repositorio|Db|Esquema)/|/Infrastructure/~', $relativo) => 'datos',
            1 === preg_match('~^Dominio/|/Domain/~', $relativo) => 'dominio',
            default => 'negocio',
        };
    }

    /** @return array<string, list<string>> rule => files (relative to src/) that break it, sorted */
    public static function infracciones(string $src): array
    {
        $resultado = array_fill_keys(array_keys(self::REGLAS), []);
        $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($archivos as $archivo) {
            if (!$archivo instanceof \SplFileInfo || 'php' !== $archivo->getExtension()) {
                continue;
            }
            $relativo = str_replace('\\', '/', substr($archivo->getPathname(), \strlen(rtrim($src, '/\\')) + 1));
            $capa = self::capa($relativo);
            $dependencias = self::dependencias((string) file_get_contents($archivo->getPathname()));
            foreach (self::REGLAS as $regla => $definicion) {
                if ($capa !== $definicion['capa']) {
                    continue;
                }
                foreach ($dependencias as $dependencia) {
                    if (1 === preg_match($definicion['prohibido'], $dependencia)) {
                        $resultado[$regla][] = $relativo;
                        break;
                    }
                }
            }
        }
        foreach ($resultado as &$lista) {
            sort($lista);
        }

        return $resultado;
    }

    /** @return list<string> imported classes and fully qualified names written inline, without the leading backslash */
    private static function dependencias(string $codigo): array
    {
        preg_match_all('~^use\s+(?:function\s+|const\s+)?\\\\?([A-Za-z0-9_\\\\]+)~m', $codigo, $importadas);
        preg_match_all('~(?<![A-Za-z0-9_\\\\])\\\\((?:App|Doctrine|Symfony)\\\\[A-Za-z0-9_\\\\]+)~', $codigo, $enLinea);

        return array_values(array_unique([...$importadas[1], ...$enLinea[1]]));
    }
}
