<?php

namespace Correios\Services\Batch;

use Correios\Services\Authorization\Authentication;
use Correios\Services\Date\Date;
use Correios\Services\Price\Price;

/**
 * Executes independent Correios requests concurrently via curl_multi with a
 * bounded number of simultaneous connections and optional per-request / global
 * timeouts.
 *
 * The batch only fetches: it returns one structured result per request, keyed
 * by the caller-supplied key, so a slow or failing request is isolated to its
 * own entry and never aborts the rest. It performs no retries, caching or
 * fallback — those remain the caller's responsibility.
 *
 * Each result entry has the shape:
 *   ['key' => string|int, 'success' => bool, 'code' => int, 'data' => stdClass, 'error' => ?string]
 */
class Batch
{
    /** @var array<int, PreparedRequest> */
    private array $prepared = [];

    private ?Price $priceService = null;

    private ?Date $dateService = null;

    public function __construct(
        private readonly Authentication $authentication,
        private readonly string $requestNumber,
        private readonly string $lotId = '',
        private readonly int $concurrency = 10,
        private readonly int $connectTimeoutMs = 5000,
        private readonly int $requestTimeoutMs = 30000,
        private readonly int $totalTimeoutMs = 0,
    ) {}

    /**
     * Add an already-prepared request. This is the generic entry point for
     * callers that build their own payloads (and prepared handles).
     */
    public function add(PreparedRequest $request): self
    {
        $this->prepared[] = $request;

        return $this;
    }

    /**
     * Queue a price request, keyed for result mapping. Mirrors Price::get().
     *
     * @param  array<int, string>  $serviceCodes
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $fields
     */
    public function price(
        string|int $key,
        array $serviceCodes,
        array $products,
        string $originCep,
        string $destinyCep,
        array $fields = [],
        ?int $connectTimeoutMs = null,
        ?int $requestTimeoutMs = null,
    ): self {
        $service = $this->priceService();
        $service->setRequestTimeouts(
            $connectTimeoutMs ?? $this->connectTimeoutMs,
            $requestTimeoutMs ?? $this->requestTimeoutMs,
        );

        return $this->add($service->prepare($key, $serviceCodes, $products, $originCep, $destinyCep, $fields));
    }

    /**
     * Queue a delivery-time (prazo) request, keyed for result mapping.
     * Mirrors Date::get().
     *
     * @param  array<int, string>  $serviceCodes
     * @param  array<string, mixed>  $fields
     */
    public function date(
        string|int $key,
        array $serviceCodes,
        string $originCep,
        string $destinyCep,
        array $fields = [],
        ?int $connectTimeoutMs = null,
        ?int $requestTimeoutMs = null,
    ): self {
        $service = $this->dateService();
        $service->setRequestTimeouts(
            $connectTimeoutMs ?? $this->connectTimeoutMs,
            $requestTimeoutMs ?? $this->requestTimeoutMs,
        );

        return $this->add($service->prepare($key, $serviceCodes, $originCep, $destinyCep, $fields));
    }

    /**
     * Execute every queued request concurrently and return the keyed results,
     * in the order the requests were added.
     *
     * @return array<string|int, array{key: string|int, success: bool, code: int, data: object, error: ?string}>
     */
    public function execute(): array
    {
        $results = [];
        $executable = [];

        foreach ($this->prepared as $request) {
            if ($request->handle === null) {
                $results[$request->key] = $this->failureResult(
                    $request->key,
                    0,
                    new \stdClass(),
                    $request->error ?? 'request preparation failed',
                );

                continue;
            }

            $executable[] = $request;
        }

        if ($executable !== []) {
            $this->runMulti($executable, $results);
        }

        return $this->orderResults($results);
    }

    /**
     * @param  array<int, PreparedRequest>  $executable
     * @param  array<string|int, array{key: string|int, success: bool, code: int, data: object, error: ?string}>  $results
     */
    private function runMulti(array $executable, array &$results): void
    {
        $multi = curl_multi_init();

        $handleMap = [];
        foreach ($executable as $request) {
            $handleMap[spl_object_id($request->handle)] = $request;
        }

        $limit = max(1, $this->concurrency);
        $total = count($executable);
        $index = 0;
        $inFlight = 0;

        for (; $index < $total && $inFlight < $limit; $index++) {
            curl_multi_add_handle($multi, $executable[$index]->handle);
            $inFlight++;
        }

        $deadline = $this->totalTimeoutMs > 0
            ? microtime(true) + ($this->totalTimeoutMs / 1000)
            : null;

        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while ($info = curl_multi_info_read($multi)) {
                $handle = $info['handle'];
                $request = $handleMap[spl_object_id($handle)] ?? null;

                if ($request !== null) {
                    $results[$request->key] = $this->buildResult($request, $handle, $info);
                }

                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                $inFlight--;

                if ($index < $total) {
                    curl_multi_add_handle($multi, $executable[$index]->handle);
                    $inFlight++;
                    $index++;
                }
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            if ($running && $inFlight > 0) {
                $selectTimeout = $deadline !== null
                    ? max(0.0, min(1.0, $deadline - microtime(true)))
                    : 1.0;
                curl_multi_select($multi, $selectTimeout);
            }
        } while ($inFlight > 0 || $index < $total);

        // Global budget exceeded: report unfinished requests as timeouts.
        foreach ($handleMap as $request) {
            if (array_key_exists($request->key, $results)) {
                continue;
            }

            curl_multi_remove_handle($multi, $request->handle);
            curl_close($request->handle);

            $results[$request->key] = $this->failureResult(
                $request->key,
                0,
                new \stdClass(),
                'batch timeout exceeded',
            );
        }

        curl_multi_close($multi);
    }

    /**
     * @param  array{result: int, handle: \CurlHandle}  $info
     * @return array{key: string|int, success: bool, code: int, data: object, error: ?string}
     */
    private function buildResult(PreparedRequest $request, \CurlHandle $handle, array $info): array
    {
        if ($info['result'] !== CURLE_OK) {
            $message = curl_error($handle);

            if ($message === '') {
                $message = curl_strerror($info['result']) ?? 'unknown error';
            }

            return $this->failureResult($request->key, 0, new \stdClass(), 'cURL error: ' . $message);
        }

        $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $decoded = json_decode((string) curl_multi_getcontent($handle), false);
        $data = is_object($decoded) ? $decoded : (object) ($decoded ?? []);

        if ($code >= 400) {
            return $this->failureResult($request->key, $code, $data, $this->errorMessage($data));
        }

        return [
            'key' => $request->key,
            'success' => true,
            'code' => $code,
            'data' => $data,
            'error' => null,
        ];
    }

    /**
     * @return array{key: string|int, success: false, code: int, data: object, error: ?string}
     */
    private function failureResult(string|int $key, int $code, object $data, ?string $error): array
    {
        return [
            'key' => $key,
            'success' => false,
            'code' => $code,
            'data' => $data,
            'error' => $error,
        ];
    }

    private function errorMessage(object $data): string
    {
        if (isset($data->msgs) && is_array($data->msgs) && $data->msgs !== []) {
            return (string) end($data->msgs);
        }

        return 'Não foi possível realizar a requisição. Por favor, verifique os parâmetros enviados';
    }

    /**
     * @param  array<string|int, array{key: string|int, success: bool, code: int, data: object, error: ?string}>  $results
     * @return array<string|int, array{key: string|int, success: bool, code: int, data: object, error: ?string}>
     */
    private function orderResults(array $results): array
    {
        $ordered = [];

        foreach ($this->prepared as $request) {
            if (array_key_exists($request->key, $results)) {
                $ordered[$request->key] = $results[$request->key];
            }
        }

        return $ordered;
    }

    private function priceService(): Price
    {
        return $this->priceService ??= new Price($this->authentication, $this->requestNumber, $this->lotId);
    }

    private function dateService(): Date
    {
        return $this->dateService ??= new Date($this->authentication, $this->requestNumber, $this->lotId);
    }
}
