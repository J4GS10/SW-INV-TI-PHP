<?php

declare(strict_types=1);

namespace App\Documentos;

/**
 * Traducción de valores canónicos de los catálogos para documentos y reportes.
 *
 * Los catálogos (estados operativos, tipos de movimiento y de activo, estados de
 * orden, acciones de auditoría, unidades) se guardan en español o como códigos.
 * Un documento en inglés o italiano los traduce aquí; un valor no reconocido
 * (dato libre del usuario) se devuelve sin cambios. Mismas tablas y alias que el
 * backend anterior.
 */
final class Traducciones
{
    public const string ESTADO_OPERATIVO = 'estado_operativo';
    public const string TIPO_MOVIMIENTO = 'tipo_movimiento';
    public const string TIPO_ACTIVO = 'tipo_activo';
    public const string ACCION_AUDITORIA = 'accion_auditoria';
    public const string UNIDAD = 'unidad';

    /** Cada entrada: [es, en, it, ...alias]. */
    private const array CATALOGOS = [
        self::ESTADO_OPERATIVO => [
            ['Disponible', 'Available', 'Disponibile', 'STATUS.AVAILABLE', 'AVAILABLE'],
            ['Asignado', 'Assigned', 'Assegnato', 'STATUS.ASSIGNED', 'ASSIGNED'],
            ['En Bodega', 'In Storage', 'In magazzino', 'Bodega', 'STATUS.IN_STORAGE', 'IN_STORAGE'],
            ['En Reparación', 'Under Repair', 'In riparazione', 'Reparación', 'STATUS.IN_REPAIR', 'IN_REPAIR', 'En Mantenimiento'],
            ['Baja', 'Decommissioned', 'Dismesso', 'De Baja', 'STATUS.RETIRED', 'RETIRED'],
            ['En Tránsito', 'In Transit', 'In transito', 'STATUS.IN_TRANSIT', 'IN_TRANSIT'],
            ['Prestado', 'On Loan', 'In prestito', 'En Préstamo', 'STATUS.ON_LOAN'],
            ['Extraviado', 'Lost', 'Smarrito', 'Perdido', 'STATUS.LOST'],
        ],
        self::TIPO_MOVIMIENTO => [
            ['Ingreso', 'Intake', 'Presa in carico', 'Ingreso Inicial'],
            ['Asignación', 'Assignment', 'Assegnazione', 'Asignación de Equipo', 'ASSIGN'],
            ['Devolución', 'Return', 'Restituzione', 'RETURN'],
            ['Transferencia', 'Transfer', 'Trasferimento', 'TRANSFER'],
            ['Préstamo', 'Loan', 'Prestito', 'LOAN'],
            ['Baja por Retiro', 'Retirement', 'Dismissione', 'Retiro'],
        ],
        self::TIPO_ACTIVO => [
            ['Laptop', 'Laptop', 'Laptop', 'Portátil', 'Notebook'],
            ['Desktop', 'Desktop', 'Desktop', 'Computadora de escritorio', 'PC de escritorio'],
            ['Monitor', 'Monitor', 'Monitor'],
            ['Teclado', 'Keyboard', 'Tastiera'],
            ['Mouse', 'Mouse', 'Mouse', 'Ratón'],
            ['Teclado/Mouse', 'Keyboard/Mouse', 'Tastiera/Mouse', 'Teclado y Mouse', 'Teclado / Mouse'],
            ['Servidor', 'Server', 'Server'],
            ['Impresora', 'Printer', 'Stampante'],
            ['Tablet', 'Tablet', 'Tablet', 'Tableta'],
            ['Teléfono', 'Phone', 'Telefono', 'Telefono'],
            ['Celular', 'Mobile phone', 'Cellulare', 'Teléfono celular', 'Smartphone'],
            ['Escáner', 'Scanner', 'Scanner', 'Scanner'],
            ['Proyector', 'Projector', 'Proiettore'],
            ['Router', 'Router', 'Router'],
            ['Switch', 'Switch', 'Switch'],
            ['Access Point', 'Access point', 'Access point', 'Punto de acceso'],
            ['Diadema', 'Headset', 'Cuffie', 'Audífonos', 'Headset'],
            ['Cámara', 'Camera', 'Videocamera', 'Camara web', 'Webcam'],
            ['Docking Station', 'Docking station', 'Docking station', 'Base de acoplamiento'],
            ['UPS', 'UPS', 'Gruppo di continuità', 'No break'],
            ['Cargador', 'Charger', 'Caricabatterie'],
        ],
        self::ACCION_AUDITORIA => [
            ['Creación', 'Creation', 'Creazione', 'CREATE'],
            ['Actualización', 'Update', 'Aggiornamento', 'UPDATE'],
            ['Eliminación', 'Deletion', 'Eliminazione', 'DELETE'],
            ['Eliminación lógica', 'Soft deletion', 'Eliminazione logica', 'DELETE_LOGIC'],
            ['Inicio de sesión exitoso', 'Successful sign-in', 'Accesso riuscito', 'LOGIN_SUCCESS'],
            ['Inicio de sesión fallido', 'Failed sign-in', 'Accesso non riuscito', 'LOGIN_FAILED'],
            ['Cierre de sesión', 'Sign-out', 'Disconnessione', 'LOGOUT'],
            ['Cierre de todas las sesiones', 'Sign-out from all sessions', 'Disconnessione da tutte le sessioni', 'LOGOUT_ALL'],
            ['Desvinculación de colaborador', 'Employee offboarding', 'Cessazione del collaboratore', 'OFFBOARDING'],
            ['Cambio de contraseña', 'Password change', 'Modifica della password', 'PASSWORD_CHANGE'],
            ['Restablecimiento de contraseña', 'Password reset', 'Reimpostazione della password', 'PASSWORD_RESET'],
            ['Solicitud de restablecimiento de contraseña', 'Password reset request', 'Richiesta di reimpostazione della password', 'PASSWORD_RESET_REQUEST'],
            ['Reutilización de token de renovación', 'Refresh token reuse', 'Riutilizzo del token di rinnovo', 'REFRESH_TOKEN_REUSE'],
            ['Asignación', 'Assignment', 'Assegnazione', 'ASSIGN'],
            ['Devolución', 'Return', 'Restituzione', 'RETURN'],
            ['Transferencia', 'Transfer', 'Trasferimento', 'TRANSFER'],
            ['Instalación', 'Installation', 'Installazione', 'INSTALL'],
            ['Desinstalación', 'Uninstallation', 'Disinstallazione', 'UNINSTALL'],
            ['Entrada de inventario', 'Stock receipt', 'Carico di magazzino', 'STOCK_IN'],
            ['Salida de inventario', 'Stock issue', 'Scarico di magazzino', 'STOCK_OUT'],
            ['Cierre', 'Closure', 'Chiusura', 'CLOSE'],
            ['Importación de datos (simulación)', 'Data import (dry run)', 'Importazione dati (simulazione)', 'DATASET_IMPORT_DRY_RUN'],
            ['Importación de datos', 'Data import', 'Importazione dati', 'DATASET_IMPORT'],
            ['Exportación de la bitácora', 'Audit log export', 'Esportazione del registro di audit', 'AUDIT_EXPORT'],
            ['Inicio de sesión en un dispositivo nuevo', 'Sign-in from a new device', 'Accesso da un nuovo dispositivo', 'LOGIN_NEW_DEVICE'],
            ['Verificación en dos pasos solicitada', 'Two-step verification requested', 'Verifica in due passaggi richiesta', '2FA_CHALLENGE'],
            ['Verificación en dos pasos activada', 'Two-step verification enabled', 'Verifica in due passaggi attivata', '2FA_ENABLED'],
            ['Verificación en dos pasos desactivada', 'Two-step verification disabled', 'Verifica in due passaggi disattivata', '2FA_DISABLED'],
            ['Códigos de recuperación regenerados', 'Recovery codes regenerated', 'Codici di recupero rigenerati', '2FA_RECOVERY_REGENERATED'],
            ['Verificación en dos pasos restablecida por un administrador', 'Two-step verification reset by an administrator',
                'Verifica in due passaggi reimpostata da un amministratore', '2FA_RESET_BY_ADMIN'],
            ['Contraseña restablecida por un administrador', 'Password reset by an administrator', 'Password reimpostata da un amministratore',
                'PASSWORD_RESET_BY_ADMIN'],
            ['Cuenta bloqueada por intentos fallidos', 'Account locked after failed attempts', 'Account bloccato per tentativi falliti', 'ACCOUNT_LOCKED'],
            ['Cuenta desbloqueada por un administrador', 'Account unlocked by an administrator', 'Account sbloccato da un amministratore',
                'ACCOUNT_UNLOCKED_BY_ADMIN'],
            ['Sesiones cerradas por un administrador', 'Sessions closed by an administrator', 'Sessioni chiuse da un amministratore',
                'SESSIONS_REVOKED_BY_ADMIN'],
            ['Sesión cerrada', 'Session closed', 'Sessione chiusa', 'SESSION_CLOSED'],
            ['Cuenta desactivada por inactividad', 'Account disabled for inactivity', 'Account disattivato per inattività', 'ACCOUNT_AUTO_DISABLED'],
            ['Depuración de la bitácora por retención', 'Audit log retention purge', 'Eliminazione del registro per conservazione', 'AUDIT_RETENTION_PURGE'],
            ['Consulta de claves de licencia', 'License keys viewed', 'Consultazione delle chiavi di licenza', 'VIEW_KEYS'],
            ['Cambio de sede del activo', 'Asset site change', "Cambio di sede dell'asset", 'ASSET_SEDE_CHANGE'],
            ['Recepción de compra', 'Purchase receipt', "Ricevimento dell'acquisto", 'RECEIVE'],
            ['Orden cerrada con faltante', 'Order closed with shortage', 'Ordine chiuso con mancanze', 'CLOSE_WITH_SHORTAGE'],
            ['Componente instalado', 'Component installed', 'Componente installato', 'COMPONENT_INSTALL'],
            ['Componente retirado', 'Component removed', 'Componente rimosso', 'COMPONENT_REMOVE'],
            ['Ajuste de stock', 'Stock adjustment', 'Rettifica di magazzino', 'STOCK_ADJUST'],
            ['Toma de inventario abierta', 'Inventory count opened', 'Inventario fisico aperto', 'INVENTORY_COUNT_OPEN'],
            ['Toma de inventario cerrada', 'Inventory count closed', 'Inventario fisico chiuso', 'INVENTORY_COUNT_CLOSE'],
            ['Toma de inventario anulada', 'Inventory count cancelled', 'Inventario fisico annullato', 'INVENTORY_COUNT_CANCEL'],
            ['Corrección de datos', 'Data correction', 'Correzione dei dati', 'DATA_FIX'],
            ['Sincronización con Active Directory', 'Active Directory sync', 'Sincronizzazione con Active Directory', 'AD_SYNC'],
            ['Solicitud de aprobación', 'Approval request', 'Richiesta di approvazione', 'APPROVAL_REQUEST'],
            ['Solicitud aprobada', 'Request approved', 'Richiesta approvata', 'APPROVAL_APPROVE'],
            ['Solicitud rechazada', 'Request rejected', 'Richiesta respinta', 'APPROVAL_REJECT'],
            ['Solicitud anulada', 'Request cancelled', 'Richiesta annullata', 'APPROVAL_CANCEL'],
        ],
        self::UNIDAD => [
            ['unidad', 'unit', 'unità', 'unidades', 'und', 'u'],
            ['caja', 'box', 'scatola', 'cajas'],
            ['paquete', 'pack', 'confezione', 'paquetes'],
            ['rollo', 'roll', 'rotolo', 'rollos'],
            ['resma', 'ream', 'risma', 'resmas'],
            ['litro', 'liter', 'litro', 'litros'],
            ['metro', 'meter', 'metro', 'metros'],
            ['par', 'pair', 'paio', 'pares'],
            ['juego', 'set', 'set', 'juegos', 'kit'],
        ],
    ];

    /** Nombre legible de cada tipo de evento del kardex. */
    public const array TIPOS_EVENTO = [
        'ALTA' => ['es' => 'Alta', 'en' => 'Registration', 'it' => 'Registrazione'],
        'RECEPCION' => ['es' => 'Recepción de compra', 'en' => 'Purchase receipt', 'it' => 'Ricevimento acquisto'],
        'ASIGNACION' => ['es' => 'Asignación', 'en' => 'Assignment', 'it' => 'Assegnazione'],
        'PRESTAMO' => ['es' => 'Préstamo', 'en' => 'Loan', 'it' => 'Prestito'],
        'DEVOLUCION' => ['es' => 'Devolución', 'en' => 'Return', 'it' => 'Restituzione'],
        'TRANSFERENCIA' => ['es' => 'Transferencia', 'en' => 'Transfer', 'it' => 'Trasferimento'],
        'TRASLADO_SEDE' => ['es' => 'Traslado de sede', 'en' => 'Site transfer', 'it' => 'Trasferimento di sede'],
        'MANTENIMIENTO_ENTRADA' => ['es' => 'Entrada a mantenimiento', 'en' => 'Sent to maintenance', 'it' => 'Ingresso in manutenzione'],
        'MANTENIMIENTO_SALIDA' => ['es' => 'Salida de mantenimiento', 'en' => 'Back from maintenance', 'it' => 'Uscita dalla manutenzione'],
        'COMPONENTE_INSTALADO' => ['es' => 'Componente instalado', 'en' => 'Component installed', 'it' => 'Componente installato'],
        'COMPONENTE_RETIRADO' => ['es' => 'Componente retirado', 'en' => 'Component removed', 'it' => 'Componente rimosso'],
        'ESPECIFICACION' => ['es' => 'Cambio de especificación', 'en' => 'Specification change', 'it' => 'Modifica della specifica'],
        'CAMBIO_ESTADO' => ['es' => 'Cambio de estado', 'en' => 'Status change', 'it' => 'Cambio di stato'],
        'BAJA' => ['es' => 'Baja', 'en' => 'Decommissioning', 'it' => 'Dismissione'],
        'INVENTARIO_FISICO' => ['es' => 'Inventario físico', 'en' => 'Physical count', 'it' => 'Inventario fisico'],
        'CONSUMIBLE_ENTRADA' => ['es' => 'Entrada de stock', 'en' => 'Stock in', 'it' => 'Entrata di magazzino'],
        'CONSUMIBLE_SALIDA' => ['es' => 'Salida de stock', 'en' => 'Stock out', 'it' => 'Uscita di magazzino'],
        'CONSUMIBLE_AJUSTE' => ['es' => 'Ajuste de stock', 'en' => 'Stock adjustment', 'it' => 'Rettifica di magazzino'],
        'LICENCIA_ALTA' => ['es' => 'Alta de licencia', 'en' => 'License registered', 'it' => 'Licenza registrata'],
        'LICENCIA_ASIGNADA' => ['es' => 'Licencia asignada', 'en' => 'License assigned', 'it' => 'Licenza assegnata'],
        'LICENCIA_RETIRADA' => ['es' => 'Licencia retirada', 'en' => 'License removed', 'it' => 'Licenza rimossa'],
        'LICENCIA_AJUSTE' => ['es' => 'Ajuste de licencia', 'en' => 'License adjustment', 'it' => 'Rettifica della licenza'],
        'LICENCIA_BAJA' => ['es' => 'Baja de licencia', 'en' => 'License removed', 'it' => 'Licenza dismessa'],
        'CONSUMIBLE_TRASLADO' => ['es' => 'Traslado de bodega', 'en' => 'Storage transfer', 'it' => 'Trasferimento di magazzino'],
    ];

    public const array OBJETOS = [
        'ACTIVO' => ['es' => 'Activo', 'en' => 'Asset', 'it' => 'Asset'],
        'CONSUMIBLE' => ['es' => 'Consumible', 'en' => 'Consumable', 'it' => 'Consumabile'],
        'LICENCIA' => ['es' => 'Licencia', 'en' => 'License', 'it' => 'Licenza'],
    ];

    private const array SI_NO = ['es' => ['Sí', 'No'], 'en' => ['Yes', 'No'], 'it' => ['Sì', 'No']];

    /** @var array<string, array<string, array{es: string, en: string, it: string}>>|null clave normalizada → traducciones */
    private static ?array $indice = null;

    /** Traduce un valor canónico del catálogo; si no se reconoce, se devuelve tal cual. */
    public static function catalogo(string $catalogo, mixed $valor, Idioma $idioma): string
    {
        if (null === $valor) {
            return '';
        }
        $texto = (string) $valor;
        if ('' === trim($texto)) {
            return $texto;
        }

        return self::indice()[$catalogo][self::clave($texto)][$idioma->value] ?? $texto;
    }

    public static function tipoEvento(string $tipo, Idioma $idioma): string
    {
        return self::TIPOS_EVENTO[$tipo][$idioma->value] ?? $tipo;
    }

    public static function objeto(string $objeto, Idioma $idioma): string
    {
        return self::OBJETOS[$objeto][$idioma->value] ?? $objeto;
    }

    public static function siNo(bool $valor, Idioma $idioma): string
    {
        return self::SI_NO[$idioma->value][$valor ? 0 : 1];
    }

    /** Clave de búsqueda: sin acentos, en minúsculas, con «_», «.» y espacios repetidos normalizados. */
    private static function clave(string $valor): string
    {
        $sinAcentos = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($valor, \Normalizer::FORM_KD) ?: $valor) ?? $valor;
        $plano = mb_strtolower(str_replace(['_', '.'], ' ', $sinAcentos));

        return implode(' ', preg_split('/\s+/u', $plano, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @return array<string, array<string, array{es: string, en: string, it: string}>> */
    private static function indice(): array
    {
        if (null === self::$indice) {
            self::$indice = [];
            foreach (self::CATALOGOS as $catalogo => $entradas) {
                foreach ($entradas as $entrada) {
                    $traducciones = ['es' => $entrada[0], 'en' => $entrada[1], 'it' => $entrada[2]];
                    // El primer alias que llega se queda (setdefault del backend anterior).
                    foreach ([$entrada[0], ...\array_slice($entrada, 3), $entrada[1], $entrada[2]] as $alias) {
                        self::$indice[$catalogo][self::clave($alias)] ??= $traducciones;
                    }
                }
            }
        }

        return self::$indice;
    }
}
