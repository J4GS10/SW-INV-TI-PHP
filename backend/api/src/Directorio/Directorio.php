<?php

declare(strict_types=1);

namespace App\Directorio;

/**
 * Directorio de personas y grupos (Active Directory). Lo consumen la
 * sincronización de personas y la resolución de destinatarios; las pruebas
 * pueden sustituirlo (FabricaDirectorio::sustituir).
 *
 * Todas las operaciones lanzan ErrorDirectorio (o ErrorAutenticacionDirectorio).
 */
interface Directorio
{
    /** Bind y cantidad de usuarios del filtro. */
    public function probarConexion(): int;

    /**
     * Todos los usuarios bajo la base que cumplen el filtro (incluye deshabilitados).
     *
     * @return list<UsuarioDirectorio>
     */
    public function usuarios(): array;

    /**
     * Correos de los miembros habilitados (también por grupos anidados) de un grupo por nombre o DN.
     *
     * @return list<string>
     */
    public function correosDeGrupo(string $grupo): array;

    /**
     * Grupos cuyo nombre contiene el texto (autocompletar), por nombre.
     *
     * @return list<GrupoDirectorio>
     */
    public function buscarGrupos(string $texto, int $limite = 20): array;
}
