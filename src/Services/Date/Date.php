<?php

namespace Correios\Services\Date;

use Correios\Exceptions\ApiRequestException;
use Correios\Exceptions\InvalidCepException;
use Correios\Exceptions\SameCepException;
use Correios\Includes\Traits\CepHandler;
use Correios\Services\Batch\PreparedRequest;
use Correios\Services\{
    AbstractRequest,
    Authorization\Authentication
};

class Date extends AbstractRequest
{
    use CepHandler;
    private string $requestNumber;
    private string $lotId;

    public function __construct(Authentication $authentication, string $requestNumber, string $lotId = '')
    {
        $this->requestNumber = $requestNumber;
        $this->lotId = $lotId ?: $requestNumber . 'LT';
        $this->authentication = $authentication;

        $this->setMethod('POST');
        $this->setEndpoint('prazo/v1/nacional');
        $this->setEnvironment($this->authentication->getEnvironment());
    }

    private function buildBody(array $serviceCodes, array $fields = []): void
    {
        $productParams = [];

        foreach ($serviceCodes as $service) {
            $productParam = [
                "coProduto" => $service,
                "cepOrigem" => $this->originCep,
                "cepDestino" => $this->destinyCep,
                "nuRequisicao" => $this->requestNumber
            ];
            $productParams[] = array_merge($fields, $productParam);
        }

        $this->setBody([
            'idLote' => $this->lotId,
            'parametrosPrazo' => $productParams
        ]);
    }

    public function get(array $serviceCodes, string $originCep, string $destinyCep, array $fields = []): array
    {
        try {
            $this->validateCep($originCep, $destinyCep);

            $this->buildBody($serviceCodes, $fields);
            $this->sendRequest();

            return [
                'code' => $this->getResponseCode(),
                'data' => $this->getResponseBody(),
            ];

        } catch (ApiRequestException $e) {
            $this->errors[$e->getCode()] = $e->getMessage();
            return [];
        }
    }

    /**
     * Build a delivery-time request ready for concurrent execution via Batch,
     * instead of sending it immediately. Validation failures are isolated to
     * the returned request (no exception) so one bad input never aborts a batch.
     */
    public function prepare(string|int $key, array $serviceCodes, string $originCep, string $destinyCep, array $fields = []): PreparedRequest
    {
        try {
            $this->validateCep($originCep, $destinyCep);

            $this->buildBody($serviceCodes, $fields);

            return new PreparedRequest($key, $this->prepareHandle());

        } catch (InvalidCepException | SameCepException $e) {
            return PreparedRequest::failed($key, $e->getMessage());
        }
    }
}
