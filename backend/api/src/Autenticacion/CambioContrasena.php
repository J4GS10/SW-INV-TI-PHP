<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Config\Ajustes;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Dominio\ErrorDominio;
use App\Repositorio\CredencialesRepositorio;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\Contrasenas;
use App\Seguridad\PoliticaContrasenas;

/**
 * Fijar una contraseña nueva (autoservicio o restablecimiento por correo):
 * política completa, sin reutilizar la actual ni las últimas
 * PASSWORD_HISTORY_COUNT, y revocación de TODOS los tokens del usuario (debe
 * volver a iniciar sesión en todos los dispositivos).
 */
final readonly class CambioContrasena
{
    public function __construct(
        private PoliticaContrasenas $politica,
        private CredencialesRepositorio $credenciales,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
        private Ajustes $ajustes,
    ) {
    }

    /** Lanza 400 con el código correspondiente si la contraseña no es aceptable. */
    public function validar(Usuario $u, string $nueva): void
    {
        $this->politica->validar($nueva, $u->username, $this->usuarios->persona($u)?->datosPersonales() ?? []);
        if (Contrasenas::verificar($nueva, $u->passwordHash)) {
            throw ErrorDominio::invalido('PASSWORD_SAME_AS_OLD');
        }
        $n = $this->ajustes->passwordHistorial;
        foreach ($this->credenciales->historial($u->id, $n) as $hash) {
            if (Contrasenas::verificar($nueva, $hash)) {
                throw ErrorDominio::invalido("PASSWORD_REUSED:{$n}");
            }
        }
    }

    /**
     * Aplica la contraseña ya validada (llamar dentro de una transacción): la
     * vigente pasa al historial, deja de ser temporal y se revocan los tokens.
     */
    public function aplicar(Usuario $u, string $nueva, bool $limpiarBloqueo): void
    {
        if (null !== $u->passwordHash && '' !== $u->passwordHash) {
            $this->credenciales->recordarContrasena($u->id, $u->passwordHash, $this->ajustes->passwordHistorial);
        }
        $u->passwordHash = Contrasenas::hashear($nueva);
        $u->debeCambiarPassword = false;
        if ($limpiarBloqueo) {
            // El dueño legítimo (que demostró controlar su correo) recupera el acceso.
            $u->intentosFallidos = 0;
            $u->bloqueadoHasta = null;
            $u->bloqueosConsecutivos = 0;
        }
        $this->usuarios->guardarNuevaContrasena($u);
        $this->gobierno->revocarTodo($u->id, Reloj::ahora()->modify('+1 year'));
    }
}
