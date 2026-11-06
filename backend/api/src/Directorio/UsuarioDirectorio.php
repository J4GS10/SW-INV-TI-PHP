<?php

declare(strict_types=1);

namespace App\Directorio;

/** Cuenta de persona leída del directorio (incluye las deshabilitadas). */
final readonly class UsuarioDirectorio
{
    /** Bit ACCOUNTDISABLE de userAccountControl. */
    public const int CUENTA_DESHABILITADA = 0x2;

    public function __construct(
        public string $guid,
        public string $dn,
        public ?string $correo,
        public ?string $nombres = null,
        public ?string $apellidos = null,
        public ?string $usuario = null,
        public ?string $departamento = null,
        public ?string $cargo = null,
        public ?string $telefono = null,
        public ?string $jefeDn = null,
        public bool $habilitado = true,
    ) {
    }

    /**
     * Desde los atributos de una entrada LDAP (nombres en minúsculas, valores en
     * lista). null si falta el objectGUID o el DN.
     *
     * @param array<string, list<string>> $a
     */
    public static function desdeAtributos(array $a): ?self
    {
        $primero = static fn (string $atributo): ?string => isset($a[$atributo][0]) && '' !== $a[$atributo][0] ? $a[$atributo][0] : null;
        $guid = $primero('objectguid');
        $dn = $primero('distinguishedname');
        if (null === $guid || null === $dn) {
            return null;
        }
        $uac = $primero('useraccountcontrol');
        $correo = $primero('mail');

        return new self(
            guid: self::guidATexto($guid),
            dn: $dn,
            correo: null === $correo ? null : strtolower(trim($correo)),
            nombres: $primero('givenname'),
            apellidos: $primero('sn'),
            usuario: $primero('samaccountname'),
            departamento: $primero('department'),
            cargo: $primero('title'),
            telefono: $primero('telephonenumber') ?? $primero('mobile'),
            jefeDn: $primero('manager'),
            habilitado: null === $uac || !is_numeric($uac) || 0 === ((int) $uac & self::CUENTA_DESHABILITADA),
        );
    }

    /**
     * objectGUID (16 bytes, los tres primeros campos en little-endian, como
     * `uuid.UUID(bytes_le=…)`) → texto en minúsculas con guiones.
     */
    public static function guidATexto(string $crudo): string
    {
        if (16 !== \strlen($crudo)) {
            return strtolower(trim($crudo, '{}'));
        }
        $h = bin2hex($crudo);
        $invertir = static fn (string $hex): string => implode('', array_reverse(str_split($hex, 2)));

        return \sprintf('%s-%s-%s-%s-%s', $invertir(substr($h, 0, 8)), $invertir(substr($h, 8, 4)), $invertir(substr($h, 12, 4)),
            substr($h, 16, 4), substr($h, 20, 12));
    }

    public function conCorreo(?string $correo): self
    {
        return new self($this->guid, $this->dn, $correo, $this->nombres, $this->apellidos, $this->usuario,
            $this->departamento, $this->cargo, $this->telefono, $this->jefeDn, $this->habilitado);
    }
}
