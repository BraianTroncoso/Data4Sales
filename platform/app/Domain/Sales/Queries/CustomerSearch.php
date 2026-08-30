<?php

declare(strict_types=1);

namespace App\Domain\Sales\Queries;

use App\Domain\Analytics\Enums\RfmSegment;
use App\Domain\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Busqueda y filtrado del padron de compradores.
 *
 * Compartido entre la API publica y el dashboard. La pantalla de soporte y el
 * endpoint tienen que encontrar exactamente lo mismo con el mismo termino:
 * si divergieran, un agente que no encuentra a alguien no sabria si el
 * cliente no existe o si la pantalla busca distinto que la API.
 */
final class CustomerSearch
{
    /**
     * Cuantos caracteres hacen falta para que el indice trigrama sirva.
     *
     * Con menos de tres, PostgreSQL no puede usar el indice GIN y la busqueda
     * degrada a recorrer la tabla entera. Con el tenant grande eso es la
     * diferencia entre milisegundos y que la pantalla no cargue.
     */
    private const MIN_FUZZY_LENGTH = 3;

    /** @var list<string> */
    public const SORTABLE = ['last_seen_at', 'name', 'external_id'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    public function build(array $filters): Builder
    {
        $query = Customer::query()->with('metrics');

        $this->applySearch($query, trim((string) ($filters['q'] ?? '')));
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query;
    }

    /**
     * Los paises presentes en el padron de este tenant, ordenados.
     *
     * La pantalla necesita saber si hay UNO SOLO — y entonces el filtro de
     * pais es un control que no filtra nada — o varios. Mirar la pagina
     * visible no alcanza y ademas engania: MegaRetail tiene 122.400 clientes
     * en ES y exactamente uno en AR, asi que la primera pagina parece un solo
     * pais y no lo es.
     *
     * Sin cache a proposito. `country` no tiene indice, con lo cual esto es un
     * recorrido de tabla; pero medirlo primero sale mas barato que estrenar
     * una invalidacion de cache que hoy no necesita nadie mas en la app.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        /** @var list<string> $countries */
        $countries = Customer::query()
            ->whereNotNull('country')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->all();

        return $countries;
    }

    /**
     * @param  Builder<Customer>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $query->where(function (Builder $where) use ($term): void {
            // El identificador del retailer siempre se busca exacto: es como
            // soporte encuentra un caso cuando el cliente le dicta su numero.
            $where->where('external_id', $term);

            // La misma funcion se aplica a los dos lados: a la columna, para
            // que el motor use el indice, y al termino que escribio el
            // agente. Sin esto, buscar "gonzalez" no encuentra "Gonzalez",
            // que es el caso normal en una plataforma para retailers de
            // habla hispana: nadie escribe los acentos al atender un llamado.
            //
            // El termino viaja como binding, nunca interpolado.
            $patron = mb_strlen($term) >= self::MIN_FUZZY_LENGTH
                ? '%'.$term.'%'
                : $term.'%';

            $where->orWhereRaw(
                'immutable_unaccent(email_normalized) ILIKE immutable_unaccent(?)',
                [$patron],
            );

            // Con menos de tres caracteres no se busca por nombre: un patron
            // de dos letras dentro de un nombre devuelve medio padron y no le
            // sirve a nadie.
            if (mb_strlen($term) >= self::MIN_FUZZY_LENGTH) {
                $where->orWhereRaw(
                    'immutable_unaccent(name) ILIKE immutable_unaccent(?)',
                    [$patron],
                );
            }
        });
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $segment = $filters['segment'] ?? null;

        if (is_string($segment) && RfmSegment::tryFrom($segment) !== null) {
            $query->whereHas('metrics', fn ($q) => $q->where('segment', $segment));
        }

        if (! empty($filters['country'])) {
            $query->where('country', strtoupper(substr((string) $filters['country'], 0, 2)));
        }

        // Contactable: sirve para estimar audiencias antes de una campaña.
        if (in_array($filters['email_status'] ?? null, ['valid', 'invalid', 'missing'], strict: true)) {
            $query->where('email_status', $filters['email_status']);
        }

        if (! empty($filters['with_orders'])) {
            $query->whereHas('orders');
        }
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sort = (string) ($filters['sort'] ?? 'last_seen_at');
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        // Lista blanca. Interpolar un nombre de columna que viene del cliente
        // es inyeccion SQL por mas que Eloquent parezca protegerlo.
        if (! in_array($sort, self::SORTABLE, strict: true)) {
            $sort = 'last_seen_at';
        }

        // El desempate por clave primaria no es cosmetico: sin un orden total,
        // la paginacion por cursor puede repetir u omitir filas cuando varias
        // comparten el mismo valor de ordenamiento.
        $query->orderBy($sort, $direction)->orderBy('id');
    }
}
