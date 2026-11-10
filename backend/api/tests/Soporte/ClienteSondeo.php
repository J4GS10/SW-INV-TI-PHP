<?php

declare(strict_types=1);

namespace App\Tests\Soporte;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cliente HTTP de las pruebas: con SONDEO_ENTRADAS, cada petición va precedida de sus variantes
 * mal escritas (Sondeo). Entre variantes se vacían los contadores de límite de peticiones (en disco
 * en las pruebas): sin eso, un 429 ocultaría lo que la ruta hace con el dato.
 */
final class ClienteSondeo extends KernelBrowser
{
    protected function doRequest(object $request): Response
    {
        if (Sondeo::activo()) {
            // Solo los contadores (FilesystemAdapter 'limite'); los cerrojos quedan: sin su carpeta, el siguiente consumo falla.
            $limites = $this->getKernel()->getCacheDir().'/limites/limite';
            $vaciar = static fn () => (new Filesystem())->remove($limites);
            Sondeo::antes($request, fn (Request $variante): Response => parent::doRequest($variante), $vaciar);
            $vaciar();
        }

        return parent::doRequest($request);
    }
}
