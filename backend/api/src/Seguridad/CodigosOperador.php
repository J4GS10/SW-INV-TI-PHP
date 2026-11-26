<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Dominio\CodigoOperador;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Dominio\Usuario;
use App\Repositorio\CodigoOperadorRepositorio;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;

/**
 * Operator codes: each technician sets their own 6 digits; the scanner asks for them before saving a change,
 * and the change is then done as that technician. Only the hash is kept, nobody can read a code, and the
 * system administrator can only clear it. Five failed attempts lock the code for a while.
 */
final readonly class CodigosOperador
{
    public function __construct(
        private CodigoOperadorRepositorio $codigos,
        private UsuarioRepositorio $usuarios,
        private GobiernoRepositorio $gobierno,
    ) {
    }

    /** @return array{puede_tener: bool, configurado: bool, definido_en: ?string, bloqueado_hasta: ?string} */
    public function estado(Usuario $usuario): array
    {
        $e = $this->codigos->estado($usuario->id);
        $bloqueo = $e['bloqueado_hasta'] ?? null;

        return [
            'puede_tener' => CodigoOperador::rolPuedeTener($usuario->rol),
            'configurado' => null !== ($e['hash'] ?? null),
            'definido_en' => Reloj::iso($e['definido_en'] ?? null),
            'bloqueado_hasta' => null !== $bloqueo && $bloqueo > Reloj::ahora() ? Reloj::iso($bloqueo) : null,
        ];
    }

    /** The owner sets or changes their code; with a password account, the current password is asked too. */
    public function definir(Usuario $usuario, string $codigo, ?string $contrasena, ?string $ip): void
    {
        if (!CodigoOperador::rolPuedeTener($usuario->rol)) {
            throw ErrorDominio::prohibido('OPERATOR_CODE_NOT_FOR_ROLE');
        }
        if (null !== $usuario->passwordHash && !Contrasenas::verificar((string) $contrasena, $usuario->passwordHash)) {
            throw ErrorDominio::invalido('INCORRECT_CURRENT_PASSWORD');
        }
        $problema = CodigoOperador::problema($codigo);
        if (null !== $problema) {
            throw ErrorDominio::noProcesable($problema);
        }
        $this->codigos->guardar($usuario->id, Contrasenas::hashear($codigo));
        $this->gobierno->registrarAuditoria('OPERATOR_CODE_SET', 'INV_USUARIO', ['usuario' => $usuario->username], $usuario->id, $ip);
    }

    /** The system administrator clears someone's code (forgotten or exposed); its owner sets a new one. */
    public function restablecer(Usuario $administrador, string $objetivo, ?string $ip): void
    {
        $cuenta = $this->usuarios->porId($objetivo) ?? throw ErrorDominio::noEncontrado('USER_NOT_FOUND');
        if (!Roles::puedeAdministrar($administrador->rol, $cuenta->rol)) {
            throw ErrorDominio::prohibido();
        }
        $this->codigos->guardar($cuenta->id, null);
        $this->gobierno->registrarAuditoria('OPERATOR_CODE_RESET', 'INV_USUARIO', ['usuario' => $cuenta->username], $administrador->id, $ip);
    }

    /**
     * Checks the code typed on the scanner and returns its owner, who will do the change. Unknown user, wrong
     * code or a role that cannot operate give the same answer, so codes and accounts cannot be guessed.
     */
    public function autorizar(string $username, string $codigo, Usuario $cuenta, ?string $ip): Usuario
    {
        $operador = '' === trim($username) ? null : $this->usuarios->porUsername(trim($username));
        $estado = null === $operador ? null : $this->codigos->estado($operador->id);
        $valido = null !== $operador && $operador->activo && !$operador->bloqueoAdministrativoVigente(Reloj::ahora())
            && CodigoOperador::rolPuedeTener($operador->rol) && null !== ($estado['hash'] ?? null);
        if (!$valido) {
            Contrasenas::verificarSenuelo();
            $this->fallo($cuenta, $username, null, $ip);
            throw ErrorDominio::prohibido('OPERATOR_CODE_INVALID');
        }
        $bloqueo = $estado['bloqueado_hasta'] ?? null;
        if (null !== $bloqueo && $bloqueo > Reloj::ahora()) {
            throw ErrorDominio::prohibido('OPERATOR_CODE_LOCKED');
        }
        if (null !== $bloqueo) {
            // El bloqueo venció: los intentos vuelven a contar desde cero.
            $this->codigos->exito($operador->id);
        }
        if (!Contrasenas::verificar($codigo, $estado['hash'] ?? null)) {
            $intentos = $this->codigos->fallo($operador->id, CodigoOperador::INTENTOS_MAXIMOS,
                Reloj::ahora()->add(new \DateInterval('PT'.CodigoOperador::MINUTOS_BLOQUEO.'M')));
            $this->fallo($cuenta, $operador->username, $intentos, $ip);
            throw ErrorDominio::prohibido($intentos >= CodigoOperador::INTENTOS_MAXIMOS ? 'OPERATOR_CODE_LOCKED' : 'OPERATOR_CODE_INVALID');
        }
        $this->codigos->exito($operador->id);

        return $operador;
    }

    private function fallo(Usuario $cuenta, string $operador, ?int $intentos, ?string $ip): void
    {
        $this->gobierno->registrarAuditoria('OPERATOR_CODE_FAILED', 'INV_USUARIO', ['operador' => mb_substr($operador, 0, 100),
            'cuenta' => $cuenta->username, 'cuenta_id' => Ident::aTexto($cuenta->id), 'intentos' => $intentos], $cuenta->id, $ip);
    }
}
