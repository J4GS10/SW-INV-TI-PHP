<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Config\Ajustes;
use App\Http\ErrorApi;

/**
 * Política de contraseñas (fuente única, configurable por PASSWORD_*). Mismas
 * reglas y códigos que el backend anterior:
 *   PASSWORD_POLICY_VIOLATION:min_length:N,require_upper,…,too_common,…
 * Sin servicios externos: la lista de contraseñas comunes es local.
 */
final readonly class PoliticaContrasenas
{
    /** Contraseñas y raíces más usadas (listas públicas de filtraciones, inglés, español e italiano). */
    private const array COMUNES = [
        '123456', '1234567', '12345678', '123456789', '1234567890', '12345', '123123',
        '111111', '000000', '121212', '654321', '666666', '696969', '112233', '159753',
        '147258369', '987654321', 'qwerty', 'qwertyuiop', 'qwerty123', 'asdfgh', 'asdfghjkl',
        'zxcvbnm', '1q2w3e4r', '1q2w3e', 'qazwsx', '1qaz2wsx', 'q1w2e3r4', 'zaq12wsx',
        'abc123', 'abcd1234', 'a1b2c3', 'aaaaaa', 'abcdef', 'abcdefg',
        'password', 'passw0rd', 'p@ssw0rd', 'p@ssword', 'letmein', 'welcome', 'admin',
        'administrator', 'root', 'login', 'master', 'secret', 'access', 'shadow', 'superman',
        'batman', 'dragon', 'monkey', 'football', 'baseball', 'soccer', 'hockey', 'princess',
        'sunshine', 'iloveyou', 'trustno1', 'starwars', 'whatever', 'freedom', 'michael',
        'jennifer', 'jordan', 'hunter', 'ranger', 'summer', 'winter', 'spring', 'autumn',
        'flower', 'hello', 'charlie', 'computer', 'internet', 'server', 'default', 'changeme',
        'guest', 'user', 'test', 'testing', 'company', 'office', 'business', 'security',
        'contrasena', 'contraseña', 'clave', 'micontrasena', 'bienvenido', 'bienvenida',
        'hola', 'holamundo', 'teamo', 'tequiero', 'amor', 'amorcito', 'corazon', 'mimamá',
        'mama', 'papa', 'familia', 'futbol', 'barcelona', 'realmadrid', 'america', 'chivas',
        'guatemala', 'mexico', 'colombia', 'peru', 'argentina', 'chile', 'espana',
        'verano', 'invierno', 'primavera', 'otono', 'enero', 'febrero', 'marzo', 'abril',
        'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
        'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo',
        'cambiame', 'temporal', 'usuario', 'sistema', 'empresa', 'oficina', 'soporte',
        'tecnico', 'informatica', 'inventario', 'seguridad', 'acceso', 'entrar',
        'benvenuto', 'ciao', 'amore', 'ciaociao', 'juventus', 'italia', 'estate', 'inverno',
    ];

    /** Secuencias que no aportan entropía aunque se combinen con otros caracteres. */
    private const array SECUENCIAS = [
        '0123456789', '9876543210', 'abcdefghijklmnopqrstuvwxyz', 'zyxwvutsrqponmlkjihgfedcba',
        'qwertyuiop', 'asdfghjkl', 'zxcvbnm', 'poiuytrewq', 'lkjhgfdsa', 'mnbvcxz',
    ];

    /** Sustituciones típicas (p@ssw0rd → password). */
    private const array LEET = ['@' => 'a', '4' => 'a', '3' => 'e', '1' => 'i', '!' => 'i',
        '0' => 'o', '$' => 's', '5' => 's', '7' => 't'];

    public function __construct(private Ajustes $ajustes)
    {
    }

    /**
     * Lanza 400 PASSWORD_POLICY_VIOLATION:<reglas> si no cumple.
     *
     * @param list<string|null> $datosPersonales nombres, apellidos y parte local del correo del titular
     */
    public function validar(string $password, ?string $username = null, array $datosPersonales = []): void
    {
        $errores = $this->errores($password, $username, $datosPersonales);
        if ([] !== $errores) {
            throw new ErrorApi(400, 'PASSWORD_POLICY_VIOLATION:'.implode(',', $errores));
        }
    }

    /**
     * @param list<string|null> $datosPersonales
     *
     * @return list<string>
     */
    public function errores(string $password, ?string $username = null, array $datosPersonales = []): array
    {
        $a = $this->ajustes;
        $errores = [];
        if (mb_strlen($password) < $a->passwordLongitudMin) {
            $errores[] = "min_length:{$a->passwordLongitudMin}";
        }
        if ($a->passwordMayuscula && 1 !== preg_match('/\p{Lu}/u', $password)) {
            $errores[] = 'require_upper';
        }
        if ($a->passwordMinuscula && 1 !== preg_match('/\p{Ll}/u', $password)) {
            $errores[] = 'require_lower';
        }
        if ($a->passwordDigito && 1 !== preg_match('/\p{Nd}/u', $password)) {
            $errores[] = 'require_digit';
        }
        if ($a->passwordSimbolo && 1 !== preg_match('/[^\p{L}\p{N}]/u', $password)) {
            $errores[] = 'require_symbol';
        }

        $bajo = mb_strtolower($password);
        $sinLeet = strtr($bajo, self::LEET);
        $candidatos = array_filter([$bajo, self::quitarAfijos($bajo), $sinLeet, self::quitarAfijos($sinLeet)]);
        if ([] !== array_intersect($candidatos, self::COMUNES)) {
            $errores[] = 'too_common';
        }
        if (null !== $username && mb_strlen($username) >= 3 && str_contains($bajo, mb_strtolower($username))) {
            $errores[] = 'contains_username';
        }
        foreach ($a->passwordPalabrasBloqueadas as $palabra) {
            if (str_contains($bajo, $palabra) || str_contains($sinLeet, $palabra)) {
                $errores[] = 'contains_blocked_word';
                break;
            }
        }
        foreach ($datosPersonales as $dato) {
            $dato = mb_strtolower(trim((string) $dato));
            if (mb_strlen($dato) >= 4 && (str_contains($bajo, $dato) || str_contains($sinLeet, $dato))) {
                $errores[] = 'contains_personal_data';
                break;
            }
        }
        if (self::tieneRepeticion($password)) {
            $errores[] = 'repeated_chars';
        }
        if (self::tieneSecuencia($password)) {
            $errores[] = 'sequential_chars';
        }

        return $errores;
    }

    /**
     * Contraseña temporal aleatoria que cumple la política vigente: mayúscula,
     * minúscula, dígito y símbolo, sin caracteres ambiguos (0/O, 1/l/I).
     */
    public function generarTemporal(?string $username = null, int $longitud = 16): string
    {
        $mayus = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $minus = 'abcdefghijkmnopqrstuvwxyz';
        $digitos = '23456789';
        $simbolos = '#$%&*+-=?@';
        $todos = $mayus.$minus.$digitos.$simbolos;
        $longitud = max($longitud, $this->ajustes->passwordLongitudMin, 12);
        $elegir = static fn (string $de): string => $de[random_int(0, \strlen($de) - 1)];
        while (true) {
            $caracteres = [$elegir($mayus), $elegir($minus), $elegir($digitos), $elegir($simbolos)];
            while (\count($caracteres) < $longitud) {
                $caracteres[] = $elegir($todos);
            }
            for ($i = \count($caracteres) - 1; $i > 0; --$i) {
                $j = random_int(0, $i);
                [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
            }
            $candidata = implode('', $caracteres);
            if ([] === $this->errores($candidata, $username)) {
                return $candidata;
            }
        }
    }

    /** 'verano2026!' → 'verano': quita lo que no es letra al inicio y al final. */
    private static function quitarAfijos(string $valor): string
    {
        return (string) preg_replace('/^\P{L}+|\P{L}+$/u', '', $valor);
    }

    private static function tieneRepeticion(string $valor, int $largo = 4): bool
    {
        return 1 === preg_match('/(.)\1{'.($largo - 1).'}/u', $valor);
    }

    private static function tieneSecuencia(string $valor, int $largo = 4): bool
    {
        $caracteres = mb_str_split(mb_strtolower($valor));
        for ($i = 0; $i + $largo <= \count($caracteres); ++$i) {
            $trozo = implode('', \array_slice($caracteres, $i, $largo));
            foreach (self::SECUENCIAS as $secuencia) {
                if (str_contains($secuencia, $trozo)) {
                    return true;
                }
            }
        }

        return false;
    }
}
