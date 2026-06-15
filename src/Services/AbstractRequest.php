<?php

namespace Correios\Services;

use Correios\Exceptions\ApiRequestException;
use Correios\Services\Authorization\Authentication;
use stdClass;

abstract class AbstractRequest
{
    private array $body         = [];
    private array $headers      = [];
    private string $environment = 'production';
    protected array $errors     = [];
    protected int $responseCode = 0;
    protected object $responseBody;
    private readonly string $method;
    protected int $connectTimeoutMs = 5000;
    protected int $requestTimeoutMs = 30000;
    private string $endpoint;
    protected ?Authentication $authentication = null;

    private ?\CurlHandle $curlHandle = null;

    public function __clone()
    {
        $this->curlHandle = null;
    }

    public function __destruct()
    {
        if (isset($this->curlHandle)) {
            curl_close($this->curlHandle);
        }
    }

    protected function sendRequest(): void
    {
        if (!isset($this->curlHandle)) {
            $this->curlHandle = curl_init();
        }

        curl_reset($this->curlHandle);

        $this->applyCurlOptions($this->curlHandle);

        $response = curl_exec($this->curlHandle);

        if ($response === false) {
            $this->responseCode = 0;
            $this->responseBody = (object) ['msgs' => ['cURL error: ' . curl_error($this->curlHandle)]];

            throw new ApiRequestException($this->responseBody);
        }

        $response = json_decode($response, false);

        $code = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);

        $data = is_object($response) ? $response : (object) $response;

        $this->responseBody = $data;
        $this->responseCode = $code;

        if ($code >= 400) {
            throw new ApiRequestException($data);
        }
    }

    /**
     * Apply the configured method, URL, headers, timeouts and body to a cURL
     * handle. Shared by the synchronous sendRequest() and the concurrent
     * prepareHandle() paths so both speak to Correios identically.
     */
    private function applyCurlOptions(\CurlHandle $handle): void
    {
        curl_setopt($handle, CURLOPT_URL, $this->getRequestUrl($this->endpoint));
        curl_setopt($handle, CURLOPT_HTTPHEADER, $this->getHeaders());
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, $this->connectTimeoutMs);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, $this->requestTimeoutMs);

        if ($this->method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($this->body));
        }
    }

    /**
     * Build a fresh, fully configured cURL handle for concurrent execution
     * (see Batch). The current body is baked into the handle here, so a single
     * service instance can prepare many handles, each capturing its own payload.
     * The keep-alive handle used by sendRequest() is intentionally untouched —
     * concurrent requests require independent handles.
     */
    public function prepareHandle(): \CurlHandle
    {
        $handle = curl_init();

        $this->applyCurlOptions($handle);

        return $handle;
    }

    protected function setAuthentication(Authentication $authentication): void
    {
        $this->authentication = $authentication;
    }

    protected function getRequestUrl(string $endpoint): string
    {
        $isTestMode = $this->authentication?->getEnvironment() === 'sandbox';

        return settings()->getEnvironmentUrl($isTestMode) . "/$endpoint";
    }

    protected function getEnvironment(): string
    {
        return $this->environment;
    }

    protected function setEnvironment(string $environment): void
    {
        $this->environment = $environment;
    }

    protected function setBody(array $body): void
    {
        $this->body = $body;
    }

    protected function getBody(): array
    {
        return $this->body;
    }

    protected function setHeaders(array $headers): void
    {
        $this->headers = array_merge($headers, $this->headers);
    }

    protected function getHeaders(): array
    {
        $headers = [];

        if (isset($this->authentication)) {
            $this->headers['Authorization'] = 'Bearer ' . $this->authentication->getToken();
            $this->headers['Content-Type']  = 'application/json';
        }

        foreach ($this->headers as $key => $header) {
            $headers[] = "$key:$header";
        }

        return $headers;
    }

    protected function setMethod(string $method): void
    {
        $this->method = $method;
    }

    protected function setEndpoint(string $endpoint): void
    {
        $this->endpoint = $endpoint;
    }

    public function setRequestTimeouts(int $connectMs, int $totalMs): void
    {
        $this->connectTimeoutMs = $connectMs;
        $this->requestTimeoutMs = $totalMs;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getResponseCode(): int
    {
        return $this->responseCode;
    }

    public function getResponseBody(): object
    {
        return $this->responseBody ?? new stdClass;
    }

}
