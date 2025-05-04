<?php

use Symfony\Component\HttpClient\Exception\ClientException;
use \Flux\Framework\Http\HttpClient;
beforeAll(function () {
    // Check if running inside GitHub Actions
    if (getenv('GITHUB_ACTIONS') === 'true') {
        self::markTestSkipped('Skipping '.__CLASS__.' when running in GitHub Actions.');
    }
});

it('can request stuff', function () {
    $client = new HttpClient();
    $resp = $client->request('GET', 'https://fluxfx.nl');

    expect($resp->getStatusCode())->toEqual(200);

    expect($resp->getContent())->toContain('<html');
});
it('streams stuff', function () {
    $client = new HttpClient();
    $resp = $client->request('GET', 'https://fluxfx.nl');

    $content = '';
    foreach ($client->stream($resp) as $chunk) { 
        $content .= $chunk->getContent();
    }

    expect($content)->toContain('<html');
});
it('can stream lines', function () {
    $client = new HttpClient();
    $resp = $client->request('GET', 'https://fluxfx.nl');

    $content = '';
    foreach ($client->stream($resp) as $chunk) { 
        $content .= $chunk->getContent();
    }

    $resp = $client->request('GET', 'https://fluxfx.nl');
    $lineContent = '';
    foreach ($client->streamLines($resp) as $line) {
        $lineContent .= $line . "\n";
    }

    expect(trim($lineContent))->toEqual(trim($content));
});
test('stream jsonlines will throw decode exceptions', function () {
    $client = new HttpClient();
    $resp = $client->request('GET', 'https://fluxfx.nl');
    $lineContent = '';
    $this->expectException(JsonException::class);
    foreach ($client->streamJsonlines($resp) as $line) {
        $lineContent .= $line . "\n";
    }
});

it("semi succesful requests are debuggable", function () {
    $client = new HttpClient();
    $resp = $client->request('POST', 'https://fluxfx.nl/request_exception', [
        'body' => json_encode(['my_data' => 1, 'expect' => 'a non positive http'])
    ]);
    try { 
        $resp->getContent();
    } catch(ClientException $e) { 
        $result = strtolower($client->debugResponse($e->getResponse()));

        // The HTTP/2 404 header is visible
        expect($result)->toContain('http/2 404');

        // Our request content-type is visible
        expect($result)->toContain('content-type: application/x-www-form-urlencoded');
        
        // The data that we posted is echo'ed back.
        expect($result)->toContain('my_data');

        // A sample of the response body is shown.
        expect($result)->toContain('<html');
    }
});

test('no connection requests are also debuggable', function () {
    $client = new HttpClient();
    $resp = $client->request('POST', 'https://fluxfx.nl:1234/request_exception', [
        'body' => json_encode(['my_data' => 1, 'expect' => 'a non positive http'])
    ]);
    try { 
        $resp->getContent();
    } catch(\Exception $e) { 
        expect(strtolower($client->debugResponse($resp)))->toContain('failed to connect');
    }
});
test('sensitive info is redacted in debug output', function () {
    $client = new HttpClient();
    $resp = $client->request('POST', 'https://fluxfx.nl/request_exception', [
        'body' => json_encode(['my_data' => 1, 'expect' => 'a non positive http']),
        'headers' => [
            'authorization' => 'Basic THIS_IS_MY_PASSWORD'
        ]
    ]);

    try { 
        $resp->getContent();
    } catch(Exception) { 
        expect($client->debugResponse($resp))->toContain('Basic TH************RD');
    }
});
test('statistics are being kept', function () {
    HttpClient::resetStats();

    $client = new HttpClient();
    $resp = $client->request('POST', 'https://fluxfx.nl', [
        'body' => 'blabla'
    ]);

    expect(HttpClient::getStats('reqs'))->toEqual(1, 'There is one request recorded');
    expect(HttpClient::getStats('resps'))->toEqual(0, 'There should be zero responses yet.');

    $resp->getStatusCode();
    $resp->getContent();

    expect(HttpClient::getStats('reqs'))->toEqual(1, 'There is one request recorded');

    expect(HttpClient::getStats('resps'))->toEqual(1, 'There should be one response recorded.');

    // echo "Downloaded bytes: " . HttpClient::getStats('b_down') . "\n"; ob_flush();
    expect(HttpClient::getStats('b_down'))->toBeGreaterThan(0);
    expect(HttpClient::getStats('b_up'))->toBeGreaterThan(0);
});
test('statistics are being kept even when streaming', function () {
    HttpClient::resetStats();

    $client = new HttpClient();
    $resp = $client->request('POST', 'https://fluxfx.nl', [
        'body' => 'blabla'
    ]);

    expect(HttpClient::getStats('reqs'))->toEqual(1, 'There is one request recorded');
    expect(HttpClient::getStats('resps'))->toEqual(0, 'There should be zero responses yet.');

    foreach ($client->stream($resp) as $chunk) {

    }

    expect(HttpClient::getStats('reqs'))->toEqual(1, 'There is one request recorded');
    expect(HttpClient::getStats('resps'))->toEqual(1, 'There should be one response recorded.');

    expect(HttpClient::getStats('b_down'))->toBeGreaterThan(0);

    expect(HttpClient::getStats('b_up'))->toBeGreaterThan(0);
});
test('streaming and non streaming have equal stats', function () {
    HttpClient::resetStats();

    $client = new HttpClient();
    $resp = $client->request('POST', 'https://fluxfx.nl', [
        'body' => 'blabla'
    ]);
    $resp->getContent();

    $nonStreamingStats = HttpClient::getStats();

    HttpClient::resetStats();

    $client = new HttpClient();
    $resp = $client->request('POST', 'https://fluxfx.nl', [
        'body' => 'blabla'
    ]);
    foreach ($client->stream($resp) as $chunk) { }

    $streamingStats = HttpClient::getStats();

    expect($streamingStats)->toEqual($nonStreamingStats, 'Streaming and non-streaming stats should be equal.');
});
test('streaming and non streaming have equal stats streamlines', function () {
    HttpClient::resetStats();

    $client = new HttpClient();
    $resp = $client->request('POST', 'https://fluxfx.nl', [
        'body' => 'blabla'
    ]);
    $resp->getContent();

    $nonStreamingStats = HttpClient::getStats();

    HttpClient::resetStats();

    $client = new HttpClient();
    $resp = $client->request('POST', 'https://fluxfx.nl', [
        'body' => 'blabla'
    ]);
    foreach ($client->streamLines($resp) as $chunk) { }

    $streamingStats = HttpClient::getStats();

    expect($streamingStats)->toEqual($nonStreamingStats, 'Streaming and non-streaming stats should be equal.');
});
test('statistics are being kept even with extended classes', function () {
    HttpClient::resetStats();

    $client = new class extends HttpClient { };

    $resp = $client->request('POST', 'https://fluxfx.nl', [
        'body' => 'blabla'
    ]);

    expect($client::getStats('reqs'))->toEqual(1, 'There is one request recorded');
    expect($client::getStats('resps'))->toEqual(0, 'There should be zero responses yet.');

    foreach ($client->stream($resp) as $chunk) {

    }

    expect($client::getStats('reqs'))->toEqual(1, 'There is one request recorded');
    expect($client::getStats('resps'))->toEqual(1, 'There should be one response recorded.');

    expect($client::getStats('b_down'))->toBeGreaterThan(0);
    expect($client::getStats('b_up'))->toBeGreaterThan(0);
});
