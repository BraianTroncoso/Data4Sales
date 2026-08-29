<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Requests;

use App\Domain\Ingestion\Enums\IngestSource;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida el SOBRE del lote, no su contenido.
 *
 * La distincion es deliberada y es el nucleo del enfoque: validar cada
 * registro aca significaria rechazar el lote entero por un campo malo en
 * uno de diez mil, que es exactamente lo contrario de lo que pide el
 * enunciado. La suciedad se maneja registro por registro en el pipeline,
 * donde cada problema queda anotado con su motivo y el resto del lote sigue.
 *
 * Aca solo se verifica lo que hace imposible siquiera empezar: que el lote
 * tenga forma de lote y que no exceda el tamano acordado con el tenant.
 */
class IngestBatchRequest extends FormRequest
{
    /**
     * La autorizacion ya la resolvio el middleware de API key: si llegamos
     * aca, hay tenant y tiene el alcance de escritura.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = app(TenantContext::class)->currentOrFail();

        return [
            'source' => ['required', Rule::enum(IngestSource::class)],

            // El limite lo define el tenant, no una constante global: un
            // retailer chico y uno que manda millones no tienen el mismo
            // tamano razonable de lote.
            'records' => ['required', 'array', 'min:1', 'max:'.$tenant->ingest_max_batch_size],
            'records.*' => ['array'],

            // Cuantos registros deberia traer este envio. Si no coincide con
            // los que llegaron, el envio se corto en el camino y el lote
            // queda marcado como parcial en vez de darse por completo.
            'expected_count' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tenant = app(TenantContext::class)->currentOrFail();

        return [
            'records.max' => "El lote excede el máximo de {$tenant->ingest_max_batch_size} registros acordado para este tenant. Dividí el envío.",
            'records.required' => 'El lote no contiene registros.',
        ];
    }

    public function source(): IngestSource
    {
        return IngestSource::from($this->string('source')->toString());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        /** @var list<array<string, mixed>> $records */
        $records = $this->input('records', []);

        return $records;
    }

    /**
     * Clave de idempotencia del envio.
     *
     * Viaja en un header y no en el cuerpo a proposito: es metadato del
     * transporte, no del contenido. Un cliente que reintenta manda el mismo
     * cuerpo con la misma clave, y eso es justamente lo que la hace util.
     */
    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && trim($key) !== '' ? substr(trim($key), 0, 128) : null;
    }

    public function expectedCount(): ?int
    {
        $value = $this->input('expected_count');

        return $value === null ? null : (int) $value;
    }
}
