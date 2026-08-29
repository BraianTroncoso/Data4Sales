<?php

declare(strict_types=1);

namespace App\Domain\Emitter;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Cliente HTTP del emisor.
 *
 * Es lo unico que el emisor sabe hacer: hablar con la API. No tiene, ni
 * puede tener, acceso a la base de datos. El contenedor `emitter` del
 * compose no recibe credenciales de PostgreSQL, asi que la regla del
 * enunciado ("la API es el unico punto de entrada") no depende de que
 * alguien se acuerde de respetarla: no hay forma de violarla.
 */
final class IngestApiClient
{
    /**
     * Reintentos ante fallas transitorias.
     *
     * La clave de idempotencia es lo que los hace seguros: un reintento tras
     * un timeout no puede duplicar el lote, porque el servidor reconoce que
     * ya lo recibio y devuelve el original.
     */
    private const RETRIES = 3;

    private const RETRY_DELAY_MS = 500;

    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    public function sendBatch(
        string $entity,
        string $source,
        array $records,
        string $idempotencyKey,
        ?int $expectedCount = null,
    ): array {
        $payload = [
            'source' => $source,
            'records' => $records,
        ];

        if ($expectedCount !== null) {
            $payload['expected_count'] = $expectedCount;
        }

        $response = $this->http
            ->withToken($this->apiKey)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->acceptJson()
            ->timeout(120)
            ->retry(self::RETRIES, self::RETRY_DELAY_MS, throw: false)
            ->post(rtrim($this->baseUrl, '/')."/api/v1/ingest/{$entity}", $payload);

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function batchStatus(string $batchId): array
    {
        $response = $this->http
            ->withToken($this->apiKey)
            ->acceptJson()
            ->get(rtrim($this->baseUrl, '/')."/api/v1/ingest/batches/{$batchId}");

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'La API respondió %d: %s',
                $response->status(),
                mb_substr($response->body(), 0, 500),
            ));
        }

        return $response->json() ?? [];
    }
}
