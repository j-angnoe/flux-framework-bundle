<?php

use Flux\Framework\Http\HttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;

class HttpClientAgainstRealDomainTest extends TestCase { 

    public static function setUpBeforeClass(): void
    {
        // Check if running inside GitHub Actions
        if (getenv('GITHUB_ACTIONS') === 'true') {
            self::markTestSkipped('Skipping '.__CLASS__.' when running in GitHub Actions.');
        }
    }

    /**
     * @test
     */
    function it_can_request_stuff()  {
        $client = new HttpClient();
        $resp = $client->request('GET', 'https://fluxfx.nl');

        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertStringContainsString('<html', $resp->getContent());
    }

    /**
     * @test
     */
    function it_streams_stuff() { 
        $client = new HttpClient();
        $resp = $client->request('GET', 'https://fluxfx.nl');

        $content = '';
        foreach ($client->stream($resp) as $chunk) { 
            $content .= $chunk->getContent();
        }
        $this->assertStringContainsString('<html', $content);
    }

    /**
     * @test
     */
    function it_can_stream_lines() { 
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

        $this->assertEquals(trim($content), trim($lineContent));
    }

    /**
     * @test
     */
    function stream_jsonlines_will_throw_decode_exceptions() { 
        $client = new HttpClient();
        $resp = $client->request('GET', 'https://fluxfx.nl');
        $lineContent = '';
        $this->expectException(JsonException::class);
        foreach ($client->streamJsonlines($resp) as $line) {
            $lineContent .= $line . "\n";
        }
    }


    /**
     * @ test
     * @skip
     */
    function semi_succesful_requests_are_debuggable(): void {
        $client = new HttpClient();
        $resp = $client->request('POST', 'https://fluxfx.nl/request_exception', [
            'body' => json_encode(['my_data' => 1, 'expect' => 'a non positive http'])
        ]);
        try { 
            $resp->getContent();
        } catch(ClientException $e) { 
            $result = $client->debugResponse($e->getResponse());

            // The HTTP/2 404 header is visible
            $this->assertStringContainsString('HTTP/2 404', $result);

            // Our request content-type is visible
            $this->assertStringContainsString('content-type: application/x-www-form-urlencoded', $result);
            
            // The data that we posted is echo'ed back.
            $this->assertStringContainsString('my_data', $result);

            // A sample of the response body is shown.
            $this->assertStringContainsString('<html', $result);
        }
    }
    
    /**
     * @test
     */
    function no_connection_requests_are_also_debuggable() {
        $client = new HttpClient();
        $resp = $client->request('POST', 'https://fluxfx.nl:1234/request_exception', [
            'body' => json_encode(['my_data' => 1, 'expect' => 'a non positive http'])
        ]);
        try { 
            $resp->getContent();
        } catch(\Exception $e) { 
            $this->assertStringContainsString('failed to connect', strtolower($client->debugResponse($resp)));
        }
    }

    /**
     * @test
     */
    function sensitive_info_is_redacted_in_debug_output() { 
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
            $this->assertStringNotContainsString('Basic THIS_IS_MY_PASSWORD', $client->debugResponse($resp));
        }
    }

    /**
     * @test
     */
    function statistics_are_being_kept() {

        HttpClient::resetStats();

        $client = new HttpClient();
        $resp = $client->request('POST', 'https://fluxfx.nl', [
            'body' => 'blabla'
        ]);
        
        $this->assertEquals(1, HttpClient::getStats('reqs'), 'There is one request recorded');
        $this->assertEquals(0, HttpClient::getStats('resps'), 'There should be zero responses yet.');

        $resp->getStatusCode();
        $resp->getContent();
        
        $this->assertEquals(1, HttpClient::getStats('reqs'), 'There is one request recorded');

        $this->assertEquals(1, HttpClient::getStats('resps'), 'There should be one response recorded.');

        // echo "Downloaded bytes: " . HttpClient::getStats('b_down') . "\n"; ob_flush();

        $this->assertGreaterThan(0, HttpClient::getStats('b_down'));
        $this->assertGreaterThan(0, HttpClient::getStats('b_up'));
    }

    /**
     * @test
     */
    function statistics_are_being_kept_even_when_streaming() {

        HttpClient::resetStats();

        $client = new HttpClient();
        $resp = $client->request('POST', 'https://fluxfx.nl', [
            'body' => 'blabla'
        ]);
        
        $this->assertEquals(1, HttpClient::getStats('reqs'), 'There is one request recorded');
        $this->assertEquals(0, HttpClient::getStats('resps'), 'There should be zero responses yet.');

        foreach ($client->stream($resp) as $chunk) {

        }
        
        $this->assertEquals(1, HttpClient::getStats('reqs'), 'There is one request recorded');
        $this->assertEquals(1, HttpClient::getStats('resps'), 'There should be one response recorded.');

        $this->assertGreaterThan(0, HttpClient::getStats('b_down'));

        $this->assertGreaterThan(0, HttpClient::getStats('b_up'));
    }

    /**
     * @test 
     */
    function streaming_and_non_streaming_have_equal_stats() { 

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

        $this->assertEquals($nonStreamingStats, $streamingStats, 'Streaming and non-streaming stats should be equal.');
    }
    /**
     * @test 
     */
    function streaming_and_non_streaming_have_equal_stats_streamlines() { 

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

        $this->assertEquals($nonStreamingStats, $streamingStats, 'Streaming and non-streaming stats should be equal.');
    }


    /**
     * @test
     */
    function statistics_are_being_kept_even_with_extended_classes() {

        HttpClient::resetStats();

        $client = new class extends HttpClient { };
        
        $resp = $client->request('POST', 'https://fluxfx.nl', [
            'body' => 'blabla'
        ]);
        
        $this->assertEquals(1, $client::getStats('reqs'), 'There is one request recorded');
        $this->assertEquals(0, $client::getStats('resps'), 'There should be zero responses yet.');

        foreach ($client->stream($resp) as $chunk) {

        }
        
        $this->assertEquals(1, $client::getStats('reqs'), 'There is one request recorded');
        $this->assertEquals(1, $client::getStats('resps'), 'There should be one response recorded.');

        $this->assertGreaterThan(0, $client::getStats('b_down'));
        $this->assertGreaterThan(0, $client::getStats('b_up'));
    }
}
