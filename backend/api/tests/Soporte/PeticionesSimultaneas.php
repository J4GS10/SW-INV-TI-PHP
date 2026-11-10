<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

/**
 * Peticiones verdaderamente simultáneas (curl_multi) contra la API de
 * producción de las pruebas de resistencia. La clase que lo usa define
 * `$url` (base de la API) y `$token` (token por defecto).
 */
trait PeticionesSimultaneas
{
    /**
     * Lanza todas las peticiones a la vez y espera las respuestas.
     *
     * @param list<array{0: string, 1: string, 2?: array<string, mixed>|null, 3?: string, 4?: array<string, mixed>}> $peticiones
     *     [método, ruta, cuerpo JSON, token, formulario multipart]
     *
     * @return list<array{estado: int, cuerpo: mixed, ms: float, ruta: string|null, error: string|null}>
     */
    private function rafaga(array $peticiones): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($peticiones as $i => $p) {
            $h = curl_init($this->url.$p[1]);
            curl_setopt_array($h, [\CURLOPT_CUSTOMREQUEST => $p[0], \CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 120,
                \CURLOPT_HTTPHEADER => [...(isset($p[4]) ? [] : ['Content-Type: application/json']), 'Authorization: Bearer '.($p[3] ?? $this->token),
                    'X-Real-IP: 10.50.'.intdiv($i, 250).'.'.($i % 250)]]);
            if (isset($p[4])) {
                curl_setopt($h, \CURLOPT_POSTFIELDS, $p[4]);
            } elseif (null !== ($p[2] ?? null)) {
                curl_setopt($h, \CURLOPT_POSTFIELDS, [] === $p[2] ? '{}' : json_encode($p[2]));
            }
            curl_multi_add_handle($multi, $h);
            $handles[$i] = $h;
        }
        do {
            $estado = curl_multi_exec($multi, $activos);
            if ($activos > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($activos > 0 && \CURLM_OK === $estado);
        $salida = [];
        foreach ($handles as $i => $h) {
            // Ruta y error de red: un estado 0 (conexión cortada) debe decir dónde y por qué.
            $salida[] = ['estado' => (int) curl_getinfo($h, \CURLINFO_HTTP_CODE), 'cuerpo' => json_decode((string) curl_multi_getcontent($h), true),
                'ms' => 1000 * (float) curl_getinfo($h, \CURLINFO_TOTAL_TIME), 'ruta' => $peticiones[$i][1] ?? null, 'error' => curl_error($h) ?: null];
            curl_multi_remove_handle($multi, $h);
        }
        curl_multi_close($multi);

        return $salida;
    }

    /**
     * @param list<array{estado: int}> $r
     *
     * @return array<int, int> conteo por código HTTP
     */
    private static function porEstado(array $r): array
    {
        $c = array_count_values(array_column($r, 'estado'));
        ksort($c);

        return $c;
    }
}
