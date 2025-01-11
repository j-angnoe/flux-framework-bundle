<?php

use Flux\Framework\Http\HttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class HttpClientTest extends TestCase { 
    /**
     * @test
     */
    function is_mockable() { 
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
        $this->assertCount(3, $lines);
        $this->assertEquals('Line 1', $lines[0]);
        $this->assertEquals('Line 3', $lines[2]);
    }

    /**
     * @test 
     */
    function it_can_stream_jsonlines() { 
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
        $this->assertCount(3, $lines);
        $this->assertEquals(['id' => 1], $lines[0]);
        $this->assertEquals(['id' => 3], $lines[2]);        
    }

    /**
     * @test
     */
    function can_stream_lines_directly_from_response() { 
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
        $this->assertCount(3, $lines);
        $this->assertEquals('Line 1', $lines[0]);
        $this->assertEquals('Line 3', $lines[2]);
    }
}