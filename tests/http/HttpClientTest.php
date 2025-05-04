<?php

use Flux\Framework\Http\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

test('is mockable', function () {
    $mock = new MockHttpClient([
        new MockResponse(<<<RESP
            Line 1
            Line 2 
            Line 3
            RESP)
    ]);

    $http = new HttpClient($mock);

    $resp = $http->request('GET', 'whatever');

    $lines = [];
    foreach ($http->streamLines($resp) as $line) { 
        $lines[] = $line;
    }
    expect($lines)->toHaveCount(3);
    expect($lines[0])->toEqual('Line 1');
    expect($lines[2])->toEqual('Line 3');
});
it('can stream jsonlines', function () {
    $mock = new MockHttpClient([
        new MockResponse(<<<RESP
            {"id": 1}
            {"id": 2}
            {"id": 3}
            RESP)
    ]);

    $http = new HttpClient($mock);

    $resp = $http->request('GET', 'whatever');

    $lines = [];
    foreach ($http->streamJsonLines($resp) as $line) { 
        $lines[] = $line;
    }
    expect($lines)->toHaveCount(3);
    expect($lines[0])->toEqual(['id' => 1]);
    expect($lines[2])->toEqual(['id' => 3]);
});
test('can stream lines directly from response', function () {
    $mock = new MockHttpClient([
        new MockResponse(<<<RESP
            Line 1
            Line 2 
            Line 3
            RESP)
    ]);

    $http = new HttpClient($mock);

    $resp = $http->request('GET', 'whatever');

    $lines = [];
    foreach ($resp->streamLines() as $line) { 
        $lines[] = $line;
    }
    expect($lines)->toHaveCount(3);
    expect($lines[0])->toEqual('Line 1');
    expect($lines[2])->toEqual('Line 3');
});