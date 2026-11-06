<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Config\Ajustes;
use Psr\Log\LoggerInterface;

/**
 * Cifrado de campos sensibles (Fernet, FIELD_ENCRYPTION_KEY con rotación) y
 * huellas deterministas (HMAC, HMAC_KEYS) para imponer unicidad sin guardar
 * el dato en claro.
 *
 * Sin clave (solo fuera de producción: Ajustes lo exige) el valor se guarda en
 * claro con un aviso. En producción, un valor que no descifra se rechaza
 * (fallo cerrado): no se sirve un dato posiblemente manipulado.
 */
final class CifradoCampos
{
    private ?Fernet $fernet = null;

    public function __construct(private readonly Ajustes $ajustes, private readonly LoggerInterface $logger)
    {
        if ([] !== $ajustes->clavesCifrado) {
            $this->fernet = new Fernet($ajustes->clavesCifrado);
        }
    }

    public function cifrar(?string $valor): ?string
    {
        if (null === $valor || '' === $valor) {
            return $valor;
        }
        if (null === $this->fernet) {
            $this->logger->warning('cifrado.sin_clave', ['detalle' => 'El valor se guarda en claro: configure FIELD_ENCRYPTION_KEY.']);

            return $valor;
        }

        return $this->fernet->cifrar($valor);
    }

    public function descifrar(?string $valor): ?string
    {
        if (null === $valor || '' === $valor || null === $this->fernet) {
            return $valor;
        }
        try {
            return $this->fernet->descifrar($valor);
        } catch (TokenFernetInvalido $e) {
            $this->logger->warning('cifrado.valor_no_descifrable');
            if ($this->ajustes->esProduccion()) {
                throw $e;
            }

            return $valor;
        }
    }

    /** Huella determinista (HMAC-SHA256 con la clave primaria de HMAC_KEYS). */
    public function huella(string $valor): string
    {
        return hash_hmac('sha256', trim($valor), $this->ajustes->clavesHmac[0]);
    }
}
