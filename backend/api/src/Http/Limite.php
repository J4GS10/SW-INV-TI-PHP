<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Límite de peticiones de una ruta (sustituye al global RATE_LIMIT_DEFAULT).
 *
 *   #[Limite('5/minute')]                      regla fija
 *   #[Limite(ajuste: 'limiteLogin')]           regla tomada de App\Config\Ajustes
 *   #[Limite(ajuste: 'limiteRefresh', clave: Limite::CLAVE_REFRESH)]
 *
 * Clave por defecto: el usuario si la petición trae un access token válido;
 * si no, la IP real. CLAVE_REFRESH usa el usuario de la cookie de refresh.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Limite
{
    public const string CLAVE_USUARIO_O_IP = 'usuario_o_ip';
    public const string CLAVE_REFRESH = 'refresh';

    public function __construct(
        public ?string $regla = null,
        public ?string $ajuste = null,
        public string $clave = self::CLAVE_USUARIO_O_IP,
    ) {
    }
}
