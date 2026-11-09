<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuración de la aplicación leída del entorno, con los mismos nombres,
 * valores por defecto y validaciones de arranque que el backend anterior: durante
 * la ventana de reversión el mismo .env sirve a ambos (tokens, hashes, cifrado
 * de campos y caché de autenticación son compatibles).
 *
 * Una configuración insegura en producción impide arrancar (fallo cerrado).
 */
final readonly class Ajustes
{
    public const string VERSION = '2.0.0-dev';
    /** Tope de inactividad de la sesión en producción (política de sesiones). */
    public const int INACTIVIDAD_MAX_PRODUCCION_MINUTOS = 120;

    public string $entorno;
    public string $nombreProyecto;
    public string $prefijoApi;
    public string $oracleUsuario;
    public ?string $oracleDuenoEsquema;
    public bool $rlsHabilitado;

    public string $claveSecreta;
    public string $algoritmo;
    public int $accesoMinutos;
    public int $refreshDias;
    public int $sesionMaxHoras;
    public int $sesionInactividadMinutos;
    public bool $cookieRefreshPersistente;
    public int $graciaReusoRefreshSegundos;
    public int $bloqueoUmbral;
    public int $bloqueoMinutos;
    public int $bloqueoMaxMinutos;
    public int $bloqueoReinicioHoras;
    public int $bloqueoFactorGlobal;
    public int $resetExpiraMinutos;
    public int $resetEnfriamientoMinutos;
    public string $emisor2fa;
    public int $otpCorreoMinutos;
    public int $desafio2faMinutos;
    public int $max2faIntentos;
    public int $codigosRecuperacion;
    /** @var list<string> */
    public array $rolesConMfa;
    public bool $mfaPuedeDesactivarse;
    /** Días desde el alta en que la persona puede dejar el MFA para más tarde (0 = sin gracia). */
    public int $mfaGraciaDias;
    /** Días que un equipo recordado no pide el código MFA (0 = no se ofrece). */
    public int $mfaRecordarDias;
    /** Máximo de días de una excepción del MFA concedida por el administrador del sistema. */
    public int $mfaExencionMaxDias;
    public int $historialSesionesDias;
    public bool $avisoDispositivoNuevo;
    public bool $ssoMfaLocal;
    public int $cuentasInactivasDias;
    public int $auditoriaRetencionDias;
    public int $outboxRetencionDias;
    public int $paginacionMaxima;
    public bool $ssoHabilitado;
    /** @var list<string> */
    public array $ssoDominios;
    /** @var array{client_id: string, client_secret: string, redirect_uri: string, tenant: string} */
    public array $ssoMicrosoft;
    /** @var list<string> */
    public array $ssoMicrosoftTenants;
    /** @var array{client_id: string, client_secret: string, redirect_uri: string} */
    public array $ssoGoogle;
    public string $urlFrontend;

    public int $passwordLongitudMin;
    public bool $passwordMayuscula;
    public bool $passwordMinuscula;
    public bool $passwordDigito;
    public bool $passwordSimbolo;
    public int $passwordHistorial;
    /** @var list<string> */
    public array $passwordPalabrasBloqueadas;

    /** @var list<string> Claves Fernet: la primera cifra, todas descifran. */
    public array $clavesCifrado;
    /** @var list<string> Claves HMAC: la primera firma, todas verifican. */
    public array $clavesHmac;

    public int $cacheAuthSegundos;
    public string $limiteLogin;
    public string $limiteRefresh;
    public string $limiteDefecto;
    public ?string $redisUrl;
    /** @var list<string> */
    public array $origenesCors;

    public bool $correoHabilitado;
    public ?string $smtpHost;
    public int $smtpPuerto;
    public bool $smtpTls;
    public bool $smtpStarttls;
    public ?string $smtpUsuario;
    public ?string $smtpPassword;
    public float $smtpTimeout;
    public string $smtpRemitente;
    public string $smtpNombreRemitente;
    /** @var list<string> */
    public array $correosAdmin;
    public string $zonaHoraria;
    /** Cola persistente de correos (SYS_EMAIL_OUTBOX): intentos antes de FALLIDO y sondeo del worker. */
    public int $outboxMaxIntentos;
    public int $outboxSondeoSegundos;

    /** Active Directory desde el entorno (se usa si SYS_INTEGRACION_CORREO no tiene fila). */
    public bool $adHabilitado;
    public ?string $adServidor;
    public bool $adStartTls;
    public bool $adVerificarCertificado;
    public ?string $adCaCertificado;
    public ?string $adUsuarioBind;
    public ?string $adPasswordBind;
    public string $adBaseDn;
    public string $adGrupoBaseDn;
    public string $adFiltroUsuarios;
    public string $adGrupoAdmin;
    public int $adTimeoutSegundos;
    public int $adCacheGruposSegundos;
    public int $adIntervaloMinutos;
    public bool $adDesactivar;
    /** Protección de la sincronización: tope de bajas (% de personas vinculadas) sin confirmación. */
    public int $adMaxBajasPorcentaje;
    public string $adDepartamentoDefecto;
    public string $adCargoDefecto;

    /** Adjuntos: "local" (volumen) o "s3" (MinIO/S3). Los archivos ya guardados se leen con el backend con que se guardaron. */
    public string $almacenamiento;
    public string $directorioAdjuntos;
    public int $adjuntoMaxMb;
    /** @var list<string> */
    public array $extensionesPermitidas;
    public ?string $s3Endpoint;
    public ?string $s3Usuario;
    public ?string $s3Clave;
    public string $s3Bucket;
    public string $s3Region;
    public float $almacenamientoTimeout;

    /** @param array<string, mixed> $e */
    private function __construct(array $e)
    {
        $texto = static fn (string $k, string $d = ''): string => trim((string) ($e[$k] ?? $d));
        $opcional = static fn (string $k): ?string => '' === $texto($k) ? null : $texto($k);
        $entero = static fn (string $k, int $d): int => '' === $texto($k) ? $d : self::entero($k, $texto($k));
        $logico = static fn (string $k, bool $d): bool => '' === $texto($k)
            ? $d
            : \in_array(strtolower($texto($k)), ['1', 'true', 'yes', 'on'], true);
        $lista = static fn (string $k, string $d = ''): array => array_values(array_filter(array_map('trim', explode(',', $texto($k, $d)))));

        $this->entorno = $texto('ENVIRONMENT', 'development');
        $this->nombreProyecto = $texto('PROJECT_NAME', 'Sistema Inventario TI');
        $this->prefijoApi = $texto('API_V1_STR', '/api/v1');
        $this->oracleUsuario = $texto('ORACLE_USER');
        $this->oracleDuenoEsquema = $opcional('ORACLE_SCHEMA_OWNER');
        $this->rlsHabilitado = $logico('DB_RLS_ENABLED', true);

        $this->claveSecreta = $texto('SECRET_KEY');
        $this->algoritmo = $texto('ALGORITHM', 'HS256');
        $this->accesoMinutos = $entero('ACCESS_TOKEN_EXPIRE_MINUTES', 15);
        $this->refreshDias = $entero('REFRESH_TOKEN_EXPIRE_DAYS', 30);
        $this->sesionMaxHoras = $entero('SESSION_ABSOLUTE_MAX_HOURS', 720);
        $inactividad = $entero('SESSION_IDLE_TIMEOUT_MINUTES', 120);
        $this->cookieRefreshPersistente = $logico('REFRESH_COOKIE_PERSISTENT', false);
        $this->graciaReusoRefreshSegundos = $entero('REFRESH_REUSE_GRACE_SECONDS', 30);
        $this->bloqueoUmbral = $entero('ACCOUNT_LOCKOUT_THRESHOLD', 5);
        $this->bloqueoMinutos = $entero('ACCOUNT_LOCKOUT_MINUTES', 15);
        $this->bloqueoMaxMinutos = $entero('ACCOUNT_LOCKOUT_MAX_MINUTES', 1440);
        $this->bloqueoReinicioHoras = $entero('ACCOUNT_LOCKOUT_RESET_HOURS', 24);
        $this->bloqueoFactorGlobal = $entero('ACCOUNT_LOCKOUT_GLOBAL_FACTOR', 4);
        $this->resetExpiraMinutos = $entero('PASSWORD_RESET_EXPIRE_MINUTES', 30);
        $this->resetEnfriamientoMinutos = $entero('PASSWORD_RESET_REQUEST_COOLDOWN_MINUTES', 2);
        $this->emisor2fa = $texto('TWO_FACTOR_ISSUER', 'Inventario Lombardi');
        $this->otpCorreoMinutos = $entero('TWO_FACTOR_EMAIL_OTP_EXPIRE_MINUTES', 10);
        $this->desafio2faMinutos = $entero('TWO_FACTOR_CHALLENGE_EXPIRE_MINUTES', 5);
        $this->max2faIntentos = $entero('TWO_FACTOR_MAX_ATTEMPTS', 5);
        $this->codigosRecuperacion = $entero('TWO_FACTOR_RECOVERY_CODES', 8);
        $this->rolesConMfa = $lista('TWO_FACTOR_REQUIRED_ROLES', 'SUPER_ADMIN,ADMIN_SEGURIDAD,ADMIN_TI');
        $this->mfaPuedeDesactivarse = $logico('MFA_CAN_BE_DISABLED', false);
        $this->mfaGraciaDias = max(0, min(30, $entero('MFA_ENROLL_GRACE_DAYS', 7)));
        $this->mfaRecordarDias = max(0, min(90, $entero('MFA_REMEMBER_DAYS', 30)));
        $this->mfaExencionMaxDias = max(1, min(90, $entero('MFA_EXEMPTION_MAX_DAYS', 30)));
        $this->historialSesionesDias = $entero('SESSION_HISTORY_DAYS', 180);
        $this->avisoDispositivoNuevo = $logico('NEW_DEVICE_ALERT_ENABLED', true);
        $this->ssoMfaLocal = $logico('SSO_MFA_LOCAL', true);
        $this->cuentasInactivasDias = $entero('ACCOUNT_INACTIVITY_DISABLE_DAYS', 90);
        $this->auditoriaRetencionDias = $entero('AUDIT_RETENTION_DAYS', 0);
        $this->outboxRetencionDias = $entero('OUTBOX_RETENTION_DAYS', 30);
        $this->paginacionMaxima = $entero('PAGINATION_MAX_LIMIT', 200);
        $this->ssoHabilitado = $logico('SSO_ENABLED', false);
        $this->ssoDominios = array_map('strtolower', $lista('SSO_ALLOWED_EMAIL_DOMAINS'));
        $this->ssoMicrosoft = [
            'client_id' => $texto('SSO_MICROSOFT_CLIENT_ID'),
            'client_secret' => $texto('SSO_MICROSOFT_CLIENT_SECRET'),
            'redirect_uri' => $texto('SSO_MICROSOFT_REDIRECT_URI'),
            'tenant' => $texto('SSO_MICROSOFT_TENANT', 'organizations') ?: 'organizations',
        ];
        $this->ssoMicrosoftTenants = array_map('strtolower', $lista('SSO_MICROSOFT_ALLOWED_TENANT_IDS'));
        $this->ssoGoogle = [
            'client_id' => $texto('SSO_GOOGLE_CLIENT_ID'),
            'client_secret' => $texto('SSO_GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => $texto('SSO_GOOGLE_REDIRECT_URI'),
        ];
        $this->urlFrontend = rtrim($texto('FRONTEND_BASE_URL', 'https://localhost'), '/');

        $this->passwordLongitudMin = $entero('PASSWORD_MIN_LENGTH', 10);
        $this->passwordMayuscula = $logico('PASSWORD_REQUIRE_UPPER', true);
        $this->passwordMinuscula = $logico('PASSWORD_REQUIRE_LOWER', true);
        $this->passwordDigito = $logico('PASSWORD_REQUIRE_DIGIT', true);
        $this->passwordSimbolo = $logico('PASSWORD_REQUIRE_SYMBOL', false);
        $this->passwordHistorial = $entero('PASSWORD_HISTORY_COUNT', 3);
        $this->passwordPalabrasBloqueadas = array_values(array_filter(
            array_map(static fn (string $p): string => mb_strtolower(trim($p)), explode(',', $texto(
                'PASSWORD_BLOCKED_WORDS',
                'lombardi,inventario,password,contrasena,contraseña,admin,bienvenido,welcome',
            ))),
            static fn (string $p): bool => mb_strlen($p) >= 4,
        ));

        $this->clavesCifrado = $lista('FIELD_ENCRYPTION_KEY');
        $hmacConfiguradas = $lista('HMAC_KEYS');
        $this->clavesHmac = $hmacConfiguradas ?: [$this->claveSecreta];

        $this->cacheAuthSegundos = $entero('AUTH_CACHE_TTL_SECONDS', 30);
        $this->limiteLogin = $texto('RATE_LIMIT_LOGIN', '5/minute');
        $this->limiteRefresh = $texto('RATE_LIMIT_REFRESH', '30/minute');
        $this->limiteDefecto = $texto('RATE_LIMIT_DEFAULT', '100/minute');
        $this->redisUrl = $opcional('REDIS_URL');
        $this->origenesCors = self::listaOrigenes($texto('BACKEND_CORS_ORIGINS', '[]'));

        $this->correoHabilitado = $logico('EMAIL_ENABLED', true);
        $this->smtpHost = $opcional('SMTP_HOST');
        $this->smtpPuerto = $entero('SMTP_PORT', 587);
        $this->smtpTls = $logico('SMTP_TLS', true);
        $this->smtpStarttls = $logico('SMTP_STARTTLS', true);
        $this->smtpUsuario = $opcional('SMTP_USER');
        $this->smtpPassword = $opcional('SMTP_PASSWORD');
        $this->smtpTimeout = (float) $texto('SMTP_TIMEOUT_SECONDS', '10');
        $this->smtpRemitente = $texto('SMTP_FROM_EMAIL', 'noreply@lombardi.local');
        $this->smtpNombreRemitente = $texto('SMTP_FROM_NAME', 'Sistema Inventario Lombardi');
        $this->correosAdmin = $lista('NOTIFY_ADMIN_EMAILS');
        $this->zonaHoraria = $texto('ORG_TIMEZONE', 'America/Guatemala');
        $this->outboxMaxIntentos = $entero('OUTBOX_MAX_ATTEMPTS', 7);
        $this->outboxSondeoSegundos = $entero('OUTBOX_POLL_SECONDS', 15);

        $this->adHabilitado = $logico('AD_ENABLED', false);
        $this->adServidor = $opcional('AD_SERVER');
        $this->adStartTls = $logico('AD_START_TLS', false);
        $this->adVerificarCertificado = $logico('AD_VERIFY_CERT', true);
        $this->adCaCertificado = $opcional('AD_CA_CERT_FILE');
        $this->adUsuarioBind = $opcional('AD_BIND_USER');
        $this->adPasswordBind = $opcional('AD_BIND_PASSWORD');
        $this->adBaseDn = $texto('AD_BASE_DN');
        $this->adGrupoBaseDn = $texto('AD_GROUP_BASE_DN');
        $this->adFiltroUsuarios = $texto('AD_USER_FILTER', '(&(objectCategory=person)(objectClass=user)(mail=*))');
        $this->adGrupoAdmin = $texto('AD_ADMIN_GROUP');
        $this->adTimeoutSegundos = $entero('AD_TIMEOUT_SECONDS', 10);
        $this->adCacheGruposSegundos = $entero('AD_GROUP_CACHE_SECONDS', 600);
        $this->adIntervaloMinutos = $entero('AD_SYNC_INTERVAL_MINUTES', 0);
        $this->adDesactivar = $logico('AD_SYNC_DEACTIVATE', true);
        $this->adMaxBajasPorcentaje = $entero('AD_SYNC_MAX_BAJAS_PCT', 10);
        $this->adDepartamentoDefecto = $texto('AD_DEFAULT_DEPARTMENT', 'Sin departamento');
        $this->adCargoDefecto = $texto('AD_DEFAULT_CARGO', 'Sin cargo');

        $this->almacenamiento = strtolower($texto('STORAGE_BACKEND', 'local'));
        $this->directorioAdjuntos = $texto('UPLOAD_DIR', '/app/uploads');
        $this->adjuntoMaxMb = $entero('MAX_UPLOAD_SIZE_MB', 10);
        $this->extensionesPermitidas = array_map('strtolower', self::listaOrigenes($texto('ALLOWED_UPLOAD_EXTENSIONS',
            '.pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.csv,.txt')));
        $this->s3Endpoint = $opcional('S3_ENDPOINT_URL');
        $this->s3Usuario = $opcional('S3_ACCESS_KEY');
        $this->s3Clave = $opcional('S3_SECRET_KEY');
        $this->s3Bucket = $texto('S3_BUCKET', 'lombardi-adjuntos');
        $this->s3Region = $texto('S3_REGION', 'us-east-1');
        $this->almacenamientoTimeout = (float) $texto('STORAGE_TIMEOUT_SECONDS', '5');

        $this->sesionInactividadMinutos = $this->validar($inactividad, [] !== $hmacConfiguradas);
    }

    /** @param array<string, mixed>|null $entorno */
    public static function desdeEntorno(?array $entorno = null): self
    {
        return new self($entorno ?? [...$_SERVER, ...$_ENV]);
    }

    /**
     * BACKEND_CORS_ORIGINS como en el backend anterior: lista JSON
     * (["https://a","https://b"]) o separada por comas. Sin barra final.
     *
     * @return list<string>
     */
    public static function listaOrigenes(string $valor): array
    {
        $lista = str_starts_with($valor, '[') ? json_decode($valor, true, flags: \JSON_THROW_ON_ERROR) : explode(',', $valor);

        return array_values(array_filter(array_map(static fn ($o): string => rtrim(trim((string) $o), '/'), $lista)));
    }

    public function esProduccion(): bool
    {
        return 'production' === $this->entorno;
    }

    /** Usuario con que se abre la conexión, en mayúsculas como lo guarda Oracle. */
    public function usuarioConexion(): string
    {
        return strtoupper($this->oracleUsuario);
    }

    /** Esquema dueño de las tablas (el del usuario de conexión si no se indica otro). */
    public function esquema(): string
    {
        return strtoupper($this->oracleDuenoEsquema ?? $this->oracleUsuario);
    }

    /** Valida la configuración y devuelve la inactividad efectiva de la sesión. */
    private function validar(int $inactividad, bool $hmacConfiguradas): int
    {
        $error = static fn (string $m) => new \InvalidArgumentException($m);
        if (\strlen($this->claveSecreta) < 32) {
            throw $error('SECRET_KEY debe tener al menos 32 caracteres.');
        }
        if (\in_array(strtolower($this->claveSecreta), ['super_secret_key_change_me_in_prod', 'change_me', 'secret'], true)) {
            throw $error('SECRET_KEY es un valor por defecto conocido.');
        }
        if ('HS256' !== $this->algoritmo) {
            throw $error('ALGORITHM: solo se admite HS256.');
        }
        if (!\in_array($this->entorno, ['development', 'staging', 'production'], true)) {
            throw $error('ENVIRONMENT debe ser development, staging o production.');
        }
        try {
            new \DateTimeZone($this->zonaHoraria);
        } catch (\Throwable) {
            throw $error('ORG_TIMEZONE no es una zona horaria válida.');
        }

        if (!\in_array($this->almacenamiento, ['local', 's3'], true)) {
            throw $error('STORAGE_BACKEND debe ser local o s3.');
        }
        if ('s3' === $this->almacenamiento && (null === $this->s3Usuario || null === $this->s3Clave || '' === $this->s3Bucket)) {
            throw $error('S3_ACCESS_KEY, S3_SECRET_KEY y S3_BUCKET son obligatorios cuando STORAGE_BACKEND=s3.');
        }
        // «s3» es el protocolo de MinIO, que corre en el propio servidor: sin su dirección no se arranca,
        // para que ningún adjunto pueda salir hacia un servicio externo.
        if ('s3' === $this->almacenamiento && (null === $this->s3Endpoint || '' === $this->s3Endpoint)) {
            throw $error('S3_ENDPOINT_URL es obligatorio cuando STORAGE_BACKEND=s3 (dirección de MinIO).');
        }

        // Coherencia de tiempos: el refresh (inactividad) debe durar más que el access.
        if ($inactividad <= $this->accesoMinutos) {
            if ($this->esProduccion()) {
                throw $error('SESSION_IDLE_TIMEOUT_MINUTES debe ser mayor que ACCESS_TOKEN_EXPIRE_MINUTES.');
            }
            $inactividad = $this->accesoMinutos * 2;
        }
        if ($this->esProduccion() && $inactividad > self::INACTIVIDAD_MAX_PRODUCCION_MINUTOS) {
            throw $error('SESSION_IDLE_TIMEOUT_MINUTES no puede superar '.self::INACTIVIDAD_MAX_PRODUCCION_MINUTOS.' minutos en producción.');
        }
        if ($this->refreshDias < 1 || $this->refreshDias > 30) {
            throw $error('REFRESH_TOKEN_EXPIRE_DAYS debe estar entre 1 y 30.');
        }
        if ($inactividad > $this->sesionMaxHoras * 60) {
            throw $error('La inactividad no puede superar la vida absoluta de la sesión.');
        }
        if ($this->sesionMaxHoras * 60 < $this->accesoMinutos) {
            throw $error('SESSION_ABSOLUTE_MAX_HOURS no puede ser menor que la vida del access token.');
        }
        if ($this->bloqueoUmbral < 1 || $this->bloqueoMinutos < 1 || $this->bloqueoFactorGlobal < 1) {
            throw $error('ACCOUNT_LOCKOUT_THRESHOLD, ACCOUNT_LOCKOUT_MINUTES y ACCOUNT_LOCKOUT_GLOBAL_FACTOR deben ser positivos.');
        }

        if ($this->esProduccion()) {
            if ([] === $this->clavesCifrado) {
                throw $error('FIELD_ENCRYPTION_KEY es obligatorio en producción.');
            }
            if (!$hmacConfiguradas) {
                throw $error('HMAC_KEYS es obligatorio en producción.');
            }
            if ($this->clavesHmac[0] === $this->claveSecreta) {
                throw $error('La clave primaria de HMAC_KEYS no puede ser SECRET_KEY.');
            }
            foreach ($this->clavesHmac as $clave) {
                if (\strlen($clave) < 32) {
                    throw $error('Cada clave de HMAC_KEYS debe tener al menos 32 caracteres.');
                }
            }
            if (null !== $this->redisUrl && str_contains($this->redisUrl, '://')
                && !str_contains(explode('://', $this->redisUrl, 2)[1], '@')) {
                throw $error('REDIS_URL sin AUTH en producción. Use redis://:<password>@host:port/db');
            }
        }

        return $inactividad;
    }

    private static function entero(string $clave, string $valor): int
    {
        if (1 !== preg_match('/^-?\d+$/', $valor)) {
            throw new \InvalidArgumentException("{$clave} debe ser un número entero.");
        }

        return (int) $valor;
    }
}
