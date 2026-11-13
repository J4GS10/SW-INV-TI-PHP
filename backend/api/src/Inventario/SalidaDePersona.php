<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Correo\Notificador;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Inventario\Infrastructure\ActivosStore;
use App\Inventario\Infrastructure\DatosActivos;
use App\Inventario\Infrastructure\Instalaciones;
use App\Inventario\Infrastructure\Kardex;
use App\Inventario\Infrastructure\MovimientosStore;
use App\Inventario\Infrastructure\Operador;
use App\Organizacion\Identidades;
use App\Organizacion\Infrastructure\CuentasStore;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\Alcance;

/** Salida de una persona de la organización: devuelve todo lo que tiene en custodia, libera sus licencias y, si corresponde, desactiva su cuenta. */
final readonly class SalidaDePersona
{
    public function __construct(
        private ReglasCustodia $custodia,
        private MovimientosStore $datos,
        private ActivosStore $activos,
        private DatosActivos $datosDeActivos,
        private CuentasStore $cuentas,
        private Alcance $alcance,
        private Kardex $kardex,
        private Instalaciones $instalaciones,
        private Credenciales $credenciales,
        private GobiernoRepositorio $gobierno,
        private Identidades $identidades,
        private Transaccion $transaccion,
        private Notificador $notificador,
        private Operador $operador,
    ) {
    }

    /**
     * En una transacción: cierra todas sus custodias (los equipos vuelven a
     * bodega, salvo los que están en reparación), libera sus licencias, marca sus
     * cuentas compartidas para cambio de clave, desactiva su cuenta y la inactiva.
     * Idempotente.
     *
     * @return array<string, mixed>
     */
    public function offboarding(string $personaId, bool $desactivarUsuario, string $usuario, string $rol, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($personaId, $desactivarUsuario, $usuario, $rol, $ip): array {
            $persona = $this->custodia->persona($personaId) ?? throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
            $sedePersona = null === $persona['SED_Sede'] ? null : (int) $persona['SED_Sede'];
            $this->alcance->exigirPropio($sedePersona);
            // Si alguna custodia está en una sede fuera del alcance, se haría a medias.
            $visibles = $this->datos->custodiasVisiblesDe($personaId);
            $totales = $this->alcance->elevado(fn (): int => $this->datos->custodiasDe($personaId));
            if ($totales !== $visibles) {
                throw ErrorDominio::prohibido('OFFBOARDING_REQUIRES_SCOPE_OVER_ALL_ASSETS');
            }
            $estabaActiva = 1 === (int) $persona['PER_Estado'];
            // La cuenta solo se desactiva si se puede (nunca el último SUPER_ADMIN, ni la propia, ni fuera de la jerarquía).
            $cuenta = !$desactivarUsuario ? false : $this->datos->cuentaDePersona($personaId);
            if (false !== $cuenta && 1 === (int) $cuenta['USU_Estado']) {
                if (Roles::SUPER_ADMIN === $cuenta['USU_Rol'] && $this->identidades->superAdministradoresActivos() <= 1) {
                    throw ErrorDominio::invalido('CANNOT_OFFBOARD_LAST_SUPER_ADMIN');
                }
                if ((string) $cuenta['USU_Usuario'] === $usuario) {
                    throw ErrorDominio::prohibido('CANNOT_MODIFY_OWN_ACCOUNT');
                }
                if (!self::puedeDesactivarEnSalida($rol, (string) $cuenta['USU_Rol'])) {
                    throw ErrorDominio::prohibido('OFFBOARDING_ACCOUNT_REQUIRES_IAM');
                }
            }
            // Cerrojo único en todo el sistema: activos (en orden) -> movimientos -> sello del kardex.
            $vigentes = $this->datos->bloquearCustodiasDe($personaId);
            $cerrados = $this->datos->cerrarTodosDe($personaId, Reloj::ahora());
            $activos = array_map(static fn (array $m): string => (string) $m['ACT_Activo'], $vigentes);
            $reparacion = array_values(array_filter($activos, fn (string $a): bool => $this->custodia->ticketAbierto($a)));
            $aBodega = array_values(array_diff($activos, $reparacion));
            if ([] !== $aBodega) {
                $bodega = $this->custodia->idEstado(EstadosActivo::BODEGA);
                foreach ($aBodega as $a) {
                    $this->activos->actualizar($a, ['EOP_Estado_Operativo' => $bodega]);
                }
            }
            foreach ($vigentes as $m) {
                $act = $this->activos->porId((string) $m['ACT_Activo']) ?: null;
                $sede = null === $act || null === $act['SED_Sede'] ? null : (int) $act['SED_Sede'];
                $this->kardex->registrar('DEVOLUCION', activo: $act, activoId: (string) $m['ACT_Activo'], usuario: $usuario,
                    origen: ['persona' => $personaId, 'area' => (int) $m['ARE_Area'], 'sede' => $sede], destino: ['sede' => $sede],
                    documento: ['OFFBOARDING', Ident::aTexto($personaId)], motivo: 'Salida de la persona (offboarding)',
                    detalle: ['en_reparacion' => \in_array((string) $m['ACT_Activo'], $reparacion, true)]);
                $this->activos->actualizar((string) $m['ACT_Activo'], ['ARE_Area_Ubicacion' => null]);
            }
            $liberadas = $this->instalaciones->liberarPorDestino($activos, $personaId, 'Salida de la persona (offboarding)');
            foreach ($liberadas as $l) {
                $this->kardex->registrar('LICENCIA_RETIRADA', licencia: $l['licencia'], clave: $l['clave'], referencia: "Licencia #{$l['licencia']}", cantidad: 1,
                    usuario: $usuario, origen: ['persona' => $l['persona'], 'activo' => $l['activo'], 'sede' => $sedePersona], motivo: 'Salida de la persona (offboarding)');
            }
            // Las cuentas que usaba quedan con el pendiente «cambiar la clave».
            $cuentasPorCambiar = $this->credenciales->marcarCambioTrasSalida(array_values(array_filter(array_column($liberadas, 'clave'))));
            $desactivado = false;
            if (false !== $cuenta) {
                // La columna de identidad está protegida: se cambia sin contexto de usuario.
                $this->alcance->elevado(fn () => $this->cuentas->actualizar((string) $cuenta['USU_Usuario'], ['USU_Estado' => 0]));
                $this->identidades->revocarAcceso((string) $cuenta['USU_Usuario']);
                $desactivado = true;
            }
            $this->datos->desactivarPersona($personaId);
            $cambio = $cerrados > 0 || [] !== $liberadas || $desactivado || $estabaActiva;
            if ($cambio) {
                $ids = array_map(static fn (string $a): string => Ident::aTexto($a), $activos);
                $this->gobierno->registrarAuditoria('OFFBOARDING', 'INV_PERSONA', ['persona_id' => Ident::aTexto($personaId), 'usuario_desactivado' => $desactivado,
                    'movimientos_cerrados' => $cerrados, 'activos_devueltos' => \count($activos), 'activos_ids' => $ids, 'licencias_liberadas' => \count($liberadas)],
                    $usuario, $ip, sedeId: $sedePersona);
                $codigos = array_values($this->datosDeActivos->codigosDe($activos));
                $op = $this->operador->de($usuario);
                $correo = $persona['PER_Email_Corporativo'];
                $contexto = ['persona_nombre' => ReglasCustodia::nombre($persona), 'persona_email' => $correo ?: '—', 'num_activos' => \count($codigos), 'activos_lista' => $codigos,
                    'usuario_desactivado' => $desactivado ? 'Sí' : 'No', 'fecha' => Reloj::iso(Reloj::ahora())];
                $this->transaccion->alConfirmar(fn () => $this->notificador->enviar('offboarding', $contexto, [], responderA: $op['correo'], operador: $op['nombre'],
                    rolOperador: $op['rol'], afectados: null === $correo || '' === $correo ? [] : [$correo]));
            }

            return ['status' => 'success', 'persona_id' => Ident::aTexto($personaId), 'movimientos_cerrados' => $cerrados,
                'activos_devueltos_a_bodega' => \count($activos), 'licencias_liberadas' => \count($liberadas), 'cuentas_por_cambiar' => $cuentasPorCambiar,
                'usuario_desactivado' => $desactivado, 'persona_inactivada' => true, 'idempotent_noop' => !$cambio];
        });
    }

    /** Cuentas que quien gestiona una salida puede desactivar sin pasar por IAM. */
    private static function puedeDesactivarEnSalida(string $solicitante, string $objetivo): bool
    {
        return Roles::puedeAdministrar($solicitante, $objetivo) || \in_array($objetivo, match ($solicitante) {
            Roles::ADMIN_TI => [Roles::ADMIN_TI, Roles::TECNICO, Roles::CONSULTA, Roles::COMPRAS],
            default => [],
        }, true);
    }
}
