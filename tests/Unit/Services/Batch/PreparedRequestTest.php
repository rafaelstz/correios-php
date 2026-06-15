<?php

use Correios\Services\Batch\PreparedRequest;

test('it holds the key and handle it is constructed with', function () {
    $handle = curl_init();
    $request = new PreparedRequest('pac:chunk-1', $handle);

    expect($request->key)->toBe('pac:chunk-1')
        ->and($request->handle)->toBe($handle)
        ->and($request->error)->toBeNull();

    curl_close($handle);
});

test('it accepts integer keys', function () {
    $request = new PreparedRequest(7, null, 'boom');

    expect($request->key)->toBe(7);
});

test('the failed() factory builds a handle-less request carrying the error', function () {
    $request = PreparedRequest::failed('bad-cep', 'invalid CEP');

    expect($request->key)->toBe('bad-cep')
        ->and($request->handle)->toBeNull()
        ->and($request->error)->toBe('invalid CEP');
});
