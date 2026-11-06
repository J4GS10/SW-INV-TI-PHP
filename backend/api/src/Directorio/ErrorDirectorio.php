<?php

declare(strict_types=1);

namespace App\Directorio;

/**
 * Fallo de conexión, bind o búsqueda contra el directorio. El mensaje es el
 * mismo que muestra el backend anterior («AD_CONNECTION_FAILED: <tipo>»).
 */
class ErrorDirectorio extends \RuntimeException
{
    /** Nombre del tipo de fallo según el código LDAP (los de ldap3, para conservar los textos de la interfaz). */
    public static function tipoLdap(int $codigo): string
    {
        return match ($codigo) {
            -1, 81 => 'LDAPSocketOpenError',
            -5, -7, 85 => 'LDAPSocketReceiveError',
            -11 => 'LDAPStartTLSError',
            1 => 'LDAPOperationsErrorResult',
            3 => 'LDAPTimeLimitExceededResult',
            4 => 'LDAPSizeLimitExceededResult',
            8 => 'LDAPStrongerAuthRequiredResult',
            10 => 'LDAPReferralResult',
            32 => 'LDAPNoSuchObjectResult',
            34 => 'LDAPInvalidDNSyntaxResult',
            49 => 'LDAPInvalidCredentialsResult',
            50 => 'LDAPInsufficientAccessRightsResult',
            51 => 'LDAPBusyResult',
            52 => 'LDAPUnavailableResult',
            53 => 'LDAPUnwillingToPerformResult',
            87 => 'LDAPInvalidFilterError',
            default => 'LDAPOperationResult',
        };
    }
}
