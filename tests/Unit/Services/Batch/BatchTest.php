<?php

use Correios\Services\Authorization\Authentication;
use Correios\Services\Batch\Batch;
use Correios\Services\Batch\PreparedRequest;

/**
 * Build a prepared request whose handle points at a non-routable (blackhole)
 * address so it always fails fast on the configured timeouts — exercising the
 * real curl_multi path without depending on the live Correios API.
 */
function blackholeRequest(string|int $key, int $connectMs = 300, int $totalMs = 600): PreparedRequest
{
    $handle = curl_init();
    curl_setopt($handle, CURLOPT_URL, 'http://10.255.255.1/');
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, $connectMs);
    curl_setopt($handle, CURLOPT_TIMEOUT_MS, $totalMs);

    return new PreparedRequest($key, $handle);
}

function batchAuthentication(): Authentication
{
    return new Authentication('user', '1234567890', 'password', true);
}

test('prepare validation failures are isolated to their own keyed result entries', function () {
    $batch = new Batch(batchAuthentication(), (string) time(), '', 5, 300, 600, 0);

    $batch->price('same-cep', ['04014'], [['weight' => 300]], '71930000', '71930000')
        ->price('bad-cep', ['04014'], [['weight' => 300]], '7193000', '05336010')
        ->price('no-weight', ['04014'], [['width' => 10]], '71930000', '05336010')
        ->date('date-same-cep', ['04014'], '71930000', '71930000');

    $results = $batch->execute();

    expect($results)->toHaveKeys(['same-cep', 'bad-cep', 'no-weight', 'date-same-cep']);

    foreach ($results as $result) {
        expect($result['success'])->toBeFalse()
            ->and($result['code'])->toBe(0)
            ->and($result['error'])->toBeString()
            ->and($result['data'])->toBeInstanceOf(stdClass::class);
    }
});

test('a failed validation request does not abort other requests in the batch', function () {
    $batch = new Batch(batchAuthentication(), (string) time(), '', 5, 300, 600, 0);

    // One validation failure (no handle) mixed with two network requests.
    $batch->date('bad-cep', ['04014'], '7193000', '05336010')
        ->add(blackholeRequest('live-1'))
        ->add(blackholeRequest('live-2'));

    $results = $batch->execute();

    expect($results)->toHaveCount(3)
        ->and($results['bad-cep']['error'])->toContain('CEP')
        ->and($results['live-1']['error'])->toStartWith('cURL error:')
        ->and($results['live-2']['error'])->toStartWith('cURL error:');
});

test('it returns one keyed entry per request, preserving add order', function () {
    $batch = new Batch(batchAuthentication(), (string) time(), '', 5, 300, 600, 0);

    $batch->add(blackholeRequest('first'))
        ->add(PreparedRequest::failed('second', 'nope'))
        ->add(blackholeRequest('third'));

    $results = $batch->execute();

    expect(array_keys($results))->toBe(['first', 'second', 'third']);
});

test('it enforces per-request timeouts while running requests concurrently', function () {
    // 6 requests, concurrency 3, each timing out at ~300ms => ~2 waves (~600ms),
    // well under the ~1800ms a serial run would take.
    $batch = new Batch(batchAuthentication(), (string) time(), '', 3, 300, 600, 0);

    foreach (range(1, 6) as $i) {
        $batch->add(blackholeRequest("req-$i"));
    }

    $startedAt = microtime(true);
    $results = $batch->execute();
    $elapsedMs = (microtime(true) - $startedAt) * 1000;

    expect($results)->toHaveCount(6);

    foreach ($results as $result) {
        expect($result['success'])->toBeFalse()
            ->and($result['code'])->toBe(0)
            ->and($result['error'])->toStartWith('cURL error:');
    }

    expect($elapsedMs)->toBeLessThan(1500);
});

test('the global timeout bounds the batch and reports unfinished requests as timeouts', function () {
    // Per-request timeout is generous (5s) but the batch budget is 400ms.
    $batch = new Batch(batchAuthentication(), (string) time(), '', 4, 5000, 5000, 400);

    $batch->add(blackholeRequest('x', 5000, 5000))
        ->add(blackholeRequest('y', 5000, 5000));

    $startedAt = microtime(true);
    $results = $batch->execute();
    $elapsedMs = (microtime(true) - $startedAt) * 1000;

    expect($results)->toHaveCount(2)
        ->and($results['x']['success'])->toBeFalse()
        ->and($results['x']['error'])->toBe('batch timeout exceeded')
        ->and($results['y']['error'])->toBe('batch timeout exceeded')
        ->and($elapsedMs)->toBeLessThan(2000);
});

test('more requests than the concurrency limit still all resolve', function () {
    $batch = new Batch(batchAuthentication(), (string) time(), '', 2, 200, 400, 0);

    foreach (range(1, 7) as $i) {
        $batch->add(blackholeRequest("c-$i", 200, 400));
    }

    $results = $batch->execute();

    expect($results)->toHaveCount(7)
        ->and(array_keys($results))->toBe(['c-1', 'c-2', 'c-3', 'c-4', 'c-5', 'c-6', 'c-7']);
});

test('an empty batch executes to an empty result set', function () {
    $batch = new Batch(batchAuthentication(), (string) time(), '', 5, 300, 600, 0);

    expect($batch->execute())->toBe([]);
});
