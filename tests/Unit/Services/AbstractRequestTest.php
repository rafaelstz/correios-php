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

    expect(fn() => $request->execute())
        ->toThrow(ApiRequestException::class, 'cURL error:');

    $elapsedMs = (microtime(true) - $startedAt) * 1000;

    expect($request->getResponseCode())
        ->toBe(0)
        ->and($request->getResponseBody()->msgs[0] ?? null)
        ->toStartWith('cURL error:')
        ->and($elapsedMs)
        ->toBeLessThan(1000);
});
