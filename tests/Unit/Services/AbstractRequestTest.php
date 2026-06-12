<?php

use Correios\Exceptions\ApiRequestException;
use Correios\Services\AbstractRequest;

test('setRequestTimeouts updates request timeout values', function() {
    $request = new class('http://127.0.0.1') extends AbstractRequest
    {
        private string $url;

        public function __construct(string $url)
        {
            $this->url = $url;

            $this->setMethod('GET');
            $this->setEndpoint('/');
        }

        protected function getRequestUrl(string $endpoint): string
        {
            return $this->url;
        }

        public function getConnectTimeout(): int
        {
            return $this->connectTimeoutMs;
        }

        public function getRequestTimeout(): int
        {
            return $this->requestTimeoutMs;
        }
    };

    $request->setRequestTimeouts(123, 456);

    expect($request->getConnectTimeout())
        ->toBe(123)
        ->and($request->getRequestTimeout())
        ->toBe(456);
});

test('connect timeout throws ApiRequestException quickly with curl error details', function() {
    $request = new class('http://10.255.255.1') extends AbstractRequest
    {
        private string $url;

        public function __construct(string $url)
        {
            $this->url = $url;

            $this->setMethod('GET');
            $this->setEndpoint('/');
        }

        protected function getRequestUrl(string $endpoint): string
        {
            return $this->url;
        }

        public function execute(): void
        {
            $this->sendRequest();
        }
    };

    $request->setRequestTimeouts(200, 400);

    $startedAt = microtime(true);
    $exception = null;

    try {
        $request->execute();
    } catch (ApiRequestException $e) {
        $exception = $e;
    }

    $elapsedMs = (microtime(true) - $startedAt) * 1000;
    $responseBody = $request->getResponseBody();

    expect($exception)
        ->toBeInstanceOf(ApiRequestException::class)
        ->and($exception->getMessage())
        ->toStartWith('cURL error:')
        ->and(strlen($exception->getMessage()))
        ->toBeGreaterThan(strlen('cURL error:'))
        ->and($request->getResponseCode())
        ->toBe(0)
        ->and(isset($responseBody->msgs))
        ->toBeTrue()
        ->and($responseBody->msgs)
        ->toBeArray()
        ->toHaveCount(1)
        ->and($responseBody->msgs[0])
        ->toStartWith('cURL error:')
        ->and($elapsedMs)
        ->toBeLessThan(1000);
});
