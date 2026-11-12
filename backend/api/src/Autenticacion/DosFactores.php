<?php

declare(strict_types=1);

namespace App\Autenticacion;

use App\Config\Ajustes;
use App\Correo\Notificador;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Dominio\ErrorDominio;
use App\Repositorio\CredencialesRepositorio;
use App\Repositorio\GobiernoRepositorio;
use App\Repositorio\UsuarioRepositorio;
use App\Seguridad\CifradoCampos;
use App\Seguridad\CodigosSeguridad;
use App\Seguridad\PoliticaSesion;
use App\Seguridad\Totp;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Doctrine\DBAL\Connection;

/**
 * Segundo factor: TOTP (app autenticadora; secreto cifrado con Fernet) o código
 * por correo, más códigos de recuperación de un solo uso. Mismas reglas y
 * códigos de error que el backend anterior. Cada operación que escribe es una
 * transacción; los correos se envían después del commit.
 */
final readonly class DosFactores
{
    public function __construct(
        private Connection $conexion,
        private UsuarioRepositorio $usuarios,
        private CredencialesRepositorio $credenciales,
        private GobiernoRepositorio $gobierno,
        private CifradoCampos $cifrado,
        private CodigosSeguridad $codigos,
        private PoliticaSesion $politica,
        private Notificador $notificador,
        private Ajustes $ajustes,
    ) {
    }

    /** @return array{habilitado: bool, metodo: ?string, requerido: bool} */
    public function estado(Usuario $u): array
    {
        return ['habilitado' => $u->dosFactoresHabilitado, 'metodo' => $u->dosFactoresMetodo,
            'requerido' => $this->politica->rolExigeMfa($u->rol)];
    }

    /** @return array{secret: string, otpauth_uri: string, qr_data_uri: string} */
    public function iniciarTotp(Usuario $u): array
    {
        $this->exigirNoHabilitado($u);
        $secreto = Totp::generarSecreto();
        // Se guarda CIFRADO; el 2FA no queda habilitado hasta confirmar un código.
        $u->dosFactoresSecreto = $this->cifrado->cifrar($secreto);
        $u->dosFactoresMetodo = null;
        $this->usuarios->guardarDosFactores($u);
        $uri = Totp::uriAprovisionamiento($secreto, $u->username, $this->ajustes->emisor2fa);

        return ['secret' => $secreto, 'otpauth_uri' => $uri, 'qr_data_uri' => self::qr($uri)];
    }

    /** @return list<string> códigos de recuperación (se muestran una sola vez) */
    public function activarTotp(Usuario $u, string $codigo, ?string $ip, ?string $ua): array
    {
        return $this->conexion->transactional(function () use ($u, $codigo, $ip, $ua): array {
            $this->exigirNoHabilitado($u);
            if (null === $u->dosFactoresSecreto) {
                throw ErrorDominio::invalido('2FA_SETUP_NOT_STARTED');
            }
            if (!$this->aceptarTotp($u, $codigo)) {
                throw ErrorDominio::invalido('INVALID_2FA_CODE');
            }

            return $this->habilitar($u, 'TOTP', $ip, $ua);
        });
    }

    public function iniciarCorreo(Usuario $u): void
    {
        $this->exigirNoHabilitado($u);
        $this->emitirCodigoCorreo($u);
    }

    /** @return list<string> */
    public function activarCorreo(Usuario $u, string $codigo, ?string $ip, ?string $ua): array
    {
        return $this->conexion->transactional(function () use ($u, $codigo, $ip, $ua): array {
            $this->exigirNoHabilitado($u);
            if (!$this->comprobarCodigoCorreo($u, $codigo)) {
                throw ErrorDominio::invalido('INVALID_2FA_CODE');
            }
            // En el método EMAIL no se guarda secreto TOTP.
            $u->dosFactoresSecreto = null;

            return $this->habilitar($u, 'EMAIL', $ip, $ua);
        });
    }

    /**
     * Desactiva el 2FA (exige la contraseña). Los roles que lo requieren no
     * pueden desactivarlo. Una contraseña errónea cuenta para el bloqueo.
     */
    public function desactivar(Usuario $u, bool $passwordCorrecta, ?string $ip, ?string $ua): void
    {
        if (!$passwordCorrecta) {
            throw ErrorDominio::invalido('INCORRECT_CURRENT_PASSWORD');
        }
        if (!$u->dosFactoresHabilitado) {
            return;
        }
        if ($this->politica->rolExigeMfa($u->rol)) {
            throw ErrorDominio::prohibido('2FA_REQUIRED_FOR_THIS_ROLE');
        }
        $this->conexion->transactional(function () use ($u, $ip, $ua): void {
            $u->dosFactoresHabilitado = false;
            $u->dosFactoresMetodo = null;
            $u->dosFactoresSecreto = null;
            $this->usuarios->guardarDosFactores($u);
            $this->credenciales->borrarRecuperacion($u->id);
            $this->credenciales->invalidarOtps($u->id);
            $this->gobierno->registrarAuditoria('2FA_DISABLED', 'INV_USUARIO', ['username' => $u->username], $u->id, $ip, $ua);
        });
    }

    /** @return list<string> */
    public function regenerarRecuperacion(Usuario $u, ?string $ip, ?string $ua): array
    {
        if (!$u->dosFactoresHabilitado) {
            throw ErrorDominio::invalido('2FA_NOT_ENABLED');
        }

        return $this->conexion->transactional(function () use ($u, $ip, $ua): array {
            $codigos = $this->nuevosCodigosRecuperacion($u);
            $this->gobierno->registrarAuditoria('2FA_RECOVERY_REGENERATED', 'INV_USUARIO', ['username' => $u->username], $u->id, $ip, $ua);

            return $codigos;
        });
    }

    /**
     * Segundo paso del login: código TOTP o de correo según el método, o un
     * código de recuperación (un solo uso).
     */
    public function verificarLogin(Usuario $u, string $codigo): bool
    {
        $codigo = trim($codigo);
        $ok = match ($u->dosFactoresMetodo) {
            'TOTP' => $this->aceptarTotp($u, $codigo),
            'EMAIL' => $this->comprobarCodigoCorreo($u, $codigo),
            default => false,
        };
        if ($ok) {
            return true;
        }
        if (str_contains($codigo, '-') || mb_strlen($codigo) >= 8) {
            return $this->credenciales->consumirRecuperacion($u->id, $this->codigos->candidatos($codigo));
        }

        return false;
    }

    /** ¿Es un código TOTP válido que ya se usó? (otra pestaña o doble clic: no es un ataque). */
    public function totpYaUsado(Usuario $u, string $codigo): bool
    {
        if ('TOTP' !== $u->dosFactoresMetodo || null === $u->dosFactoresSecreto) {
            return false;
        }
        $paso = Totp::pasoCoincidente((string) $this->cifrado->descifrar($u->dosFactoresSecreto), $codigo);

        return null !== $paso && null !== $u->dosFactoresUltimoPaso && $paso <= $u->dosFactoresUltimoPaso;
    }

    /**
     * Emite un código por correo (login o enrolamiento): invalida los anteriores
     * y lo envía solo al titular, tras el commit, sin guardarlo en la cola.
     */
    public function emitirCodigoCorreo(Usuario $u): void
    {
        $codigo = CodigosSeguridad::otpNumerico();
        $this->conexion->transactional(function () use ($u, $codigo): void {
            $this->credenciales->invalidarOtps($u->id);
            $this->credenciales->crearOtp($u->id, $this->codigos->hash($codigo),
                Reloj::ahora()->modify("+{$this->ajustes->otpCorreoMinutos} minutes"));
        });
        $persona = $this->usuarios->persona($u);
        $this->notificador->enviar('2fa_code', [
            'persona_nombre' => $persona?->nombreCorto() ?? $u->username,
            'username' => $u->username,
            'code' => $codigo,
            'minutos' => $this->ajustes->otpCorreoMinutos,
        ], [$persona?->correo], false);
    }

    /** Valida un TOTP y consume su paso de forma atómica (un código, una sola vez). */
    private function aceptarTotp(Usuario $u, string $codigo): bool
    {
        if (null === $u->dosFactoresSecreto) {
            return false;
        }
        $paso = Totp::pasoCoincidente((string) $this->cifrado->descifrar($u->dosFactoresSecreto), $codigo);
        if (null === $paso || !$this->usuarios->aceptarPasoTotp($u->id, $paso)) {
            return false;
        }
        $u->dosFactoresUltimoPaso = $paso;

        return true;
    }

    /** Código por correo vigente: tras TWO_FACTOR_MAX_ATTEMPTS fallos se invalida. */
    private function comprobarCodigoCorreo(Usuario $u, string $codigo): bool
    {
        $otp = $this->credenciales->otpActivo($u->id);
        if (null === $otp) {
            return false;
        }
        if ($otp['intentos'] >= $this->ajustes->max2faIntentos) {
            $this->credenciales->usarOtp($otp['id']);

            return false;
        }
        if (\in_array($otp['hash'], $this->codigos->candidatos($codigo), true)) {
            return $this->credenciales->usarOtp($otp['id']);
        }
        $this->credenciales->sumarIntentoOtp($otp['id']);

        return false;
    }

    /** @return list<string> */
    private function habilitar(Usuario $u, string $metodo, ?string $ip, ?string $ua): array
    {
        $u->dosFactoresHabilitado = true;
        $u->dosFactoresMetodo = $metodo;
        $this->usuarios->guardarDosFactores($u);
        $codigos = $this->nuevosCodigosRecuperacion($u);
        $this->gobierno->registrarAuditoria('2FA_ENABLED', 'INV_USUARIO', ['username' => $u->username, 'metodo' => $metodo], $u->id, $ip, $ua);

        return $codigos;
    }

    /** @return list<string> */
    private function nuevosCodigosRecuperacion(Usuario $u): array
    {
        $codigos = CodigosSeguridad::codigosRecuperacion($this->ajustes->codigosRecuperacion);
        $this->credenciales->reemplazarRecuperacion($u->id, array_map($this->codigos->hash(...), $codigos));

        return $codigos;
    }

    private function exigirNoHabilitado(Usuario $u): void
    {
        if ($u->dosFactoresHabilitado) {
            throw ErrorDominio::conflicto('2FA_ALREADY_ENABLED');
        }
    }

    /** QR del URI otpauth:// como imagen SVG en data URI (para un <img>). */
    private static function qr(string $uri): string
    {
        $opciones = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
        ]);

        return (new QRCode($opciones))->render($uri);
    }
}
