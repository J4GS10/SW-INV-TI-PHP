<?php

declare(strict_types=1);

namespace App\Organizacion;

use App\Crud\Operaciones;
use App\Db\Transaccion;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Dominio\Origen;
use App\Organizacion\Entidades as E;
use App\Organizacion\Infrastructure\CuentasStore;
use App\Organizacion\Infrastructure\OrganizacionStore;
use App\Seguridad\Alcance;

/**
 * Padrón de personas. Cada usuario ve y administra las de las sedes de su alcance; el correo corporativo es
 * único en toda la empresa, nadie es su propio jefe y quien tiene cuenta de usuario no se da de baja.
 */
final readonly class Personas
{
    public function __construct(
        private OrganizacionStore $datos,
        private CuentasStore $cuentas,
        private Operaciones $crud,
        private Alcance $alcance,
        private Transaccion $transaccion,
    ) {
    }

    /** @return list<array<string, mixed>> las personas de las sedes del alcance; con `$sinCuenta`, solo las que no tienen usuario */
    public function listar(bool $sinCuenta = false): array
    {
        return $this->datos->personasDelAlcance($sinCuenta);
    }

    /** @return array<string, mixed> la persona, si el usuario puede verla (también por el historial de un activo) */
    public function visible(string $id): array
    {
        return $this->datos->personaVisible($id) ?? throw ErrorDominio::noEncontrado('PERSON_NOT_FOUND');
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed> la persona creada, activa
     */
    public function crear(array $d, Origen $origen): array
    {
        $d['PER_Email_Corporativo'] = mb_strtolower((string) $d['PER_Email_Corporativo']);
        $d['SED_Sede'] = $this->alcance->exigirSede($d['SED_Sede'], false);
        $this->exigirJefeVisible($d['PER_Jefe']);
        // El correo es único en toda la empresa, no solo en el alcance.
        if ($this->alcance->elevado(fn (): bool => $this->datos->existeCorreo($d['PER_Email_Corporativo']))) {
            throw ErrorDominio::invalido('EMAIL_ALREADY_EXISTS');
        }

        return $this->crud->crear(E::persona(), [...$d, 'PER_Estado' => 1], $origen, [
            'email' => $d['PER_Email_Corporativo'], 'nombre' => "{$d['PER_Primer_Nombre']} {$d['PER_Primer_Apellido']}", 'sede' => $d['SED_Sede'],
        ], $d['SED_Sede']);
    }

    /**
     * Cambio parcial. Solo personas de las sedes del alcance (no las que se ven solo por el historial de un activo).
     *
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    public function cambiar(string $id, array $cambios, Origen $origen): array
    {
        $persona = $this->visible($id);
        $this->alcance->exigirPropio($persona['SED_Sede']);
        if (\array_key_exists('SED_Sede', $cambios)) {
            $cambios['SED_Sede'] = $this->alcance->exigirSede($cambios['SED_Sede'], false);
        }
        if (isset($cambios['PER_Email_Corporativo'])) {
            $cambios['PER_Email_Corporativo'] = mb_strtolower((string) $cambios['PER_Email_Corporativo']);
        }
        if (null !== ($cambios['PER_Jefe'] ?? null) && $cambios['PER_Jefe'] === $id) {
            throw ErrorDominio::invalido('PERSON_CANNOT_BE_OWN_MANAGER');
        }
        $this->exigirJefeVisible($cambios['PER_Jefe'] ?? null);
        $sede = \array_key_exists('SED_Sede', $cambios) ? $cambios['SED_Sede'] : $persona['SED_Sede'];

        return $this->crud->actualizar(E::persona(), $id, $cambios, $origen, ['id' => Ident::aTexto($id), 'cambios' => (object) $cambios], $sede);
    }

    /** Baja lógica: la persona queda inactiva y su historial se conserva. */
    public function eliminar(string $id, Origen $origen): void
    {
        $persona = $this->visible($id);
        $this->alcance->exigirPropio($persona['SED_Sede']);
        if ($this->cuentas->personaTieneCuenta($id)) {
            throw ErrorDominio::conflicto('CANNOT_DELETE_PERSON_HAS_USER_ACCOUNT');
        }
        $this->transaccion->ejecutar(function () use ($id, $persona, $origen): void {
            $this->crud->repositorio->actualizar(E::persona(), $id, ['PER_Estado' => 0]);
            $this->crud->auditar('DELETE_LOGIC', 'INV_PERSONA', ['id' => Ident::aTexto($id), 'email' => $persona['PER_Email_Corporativo']], $origen, $persona['SED_Sede']);
        });
    }

    private function exigirJefeVisible(?string $jefe): void
    {
        if (null !== $jefe && null === $this->datos->personaVisible(Ident::aHex($jefe))) {
            throw ErrorDominio::noEncontrado('MANAGER_NOT_FOUND');
        }
    }
}
