<?php

namespace Flux\Framework\Http;

use Cerbero\JsonParser\JsonParser;
use Flux\Framework\Http\HttpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[Exclude]
class DecoratedResponse implements ResponseInterface { 
    function __construct(
        private ResponseInterface $response, 
        private LoggerInterface $logger,
        private HttpClient $parent,
    ) {

        if ($response instanceof DecoratedResponse) { 
            throw new \LogicException('Double decorations occurs, please review your HttpClient class stack');
        }
        $this->logger->debug('http: > ' . $this->response->getInfo('effective_method'). ' ' . $this->response->getInfo('url'));
    }

    function getStatusCode(): int
    {
        $statusCode = $this->response->getStatusCode();
        $this->recordStats();
        return $statusCode;
    }

    function getHeaders(bool $throw = true): array
    {
        $headers = $this->response->getHeaders($throw);
        $this->recordStats();
        return $headers;
    }

    function getContent(bool $throw = true): string
    {
        $content = $this->response->getContent($throw);
        $this->recordStats();
        return $content;
    }

    function toArray(bool $throw = true): array
    {
        $array = $this->response->toArray($throw);
        $this->recordStats();
        return $array;
    }

    function cancel(): void
    {
        $this->response->cancel();
    }

    function getInfo(?string $type = null): mixed
    {
        if ($type === 'debug') { 
            return HttpClient::debugResponse($this->response);
        }
        return $this->response->getInfo($type);
    }

    private $lastDelta = [];

    public function recordStats() { 
        $i = fn($k) => $this->response->getInfo($k);

        $delta['resps'] = 1;    
        $delta['b_down'] = $i('size_download');
        $delta['b_up'] = $i('size_upload');
        $statusCode = intval($i('http_code') ?: 0);
        $statusKey = 'http_' . $statusCode;
        $delta[$statusKey] = 1;
        
        if (!$this->lastDelta) { 
            $this->logger->debug('http: < HTTP ' . ($statusCode) . ' ' . $i('content_type') . ' (' . $i('size_download') . ' bytes)');
            if (intdiv($statusCode,100) !== 2) {
                $this->logger->warning('http: HTTP ' . $statusCode . ' on '.$i('url'));
            }
        }

        foreach ($this->lastDelta as $k => $v) { 
            HttpClient::$HTTP_STATS[$k] -= $v;
        }
        foreach ($delta as $k=>$v) { 
            HttpClient::$HTTP_STATS[$k] ??= 0;
            HttpClient::$HTTP_STATS[$k] += $v;
        }
        $this->lastDelta = $delta;
    }

    function getResponse(): ResponseInterface {
        return $this->response;
    }


    function debug() { 
        dd($this->getInfo('debug'));
    }

    /**
     * @return \Generator<string>
     */
    function stream(): \Generator {
        foreach ($this->parent->stream($this) as $chunk) {
            yield $chunk->getContent();
        } 
    }
    /**
     * @return \Generator<ChunkInterface>
     */
    function streamChunks(): \Generator {
        foreach ($this->parent->stream($this) as $chunk) {
            yield $chunk;
        } 
    }

    /**
     * @return \Generator<string>
     */
    function streamLines(): \Generator { 
        foreach ($this->parent->streamLines($this) as $line) {
            yield $line;
        }
    }

    /**
     * @return \Generator<array>
     */
    function streamJsonlines(): \Generator {
        foreach ($this->parent->streamJsonLines($this) as $json) {
            yield $json;
        }
    }

    function streamJson(?string ...$jsonPointer): \Traversable {
        $parser = JsonParser::parse($this->stream());
        foreach ($jsonPointer as $jp) {
            $parser->pointer($jp);
        };
        return $parser;
    }

    function __destruct() { 
        $this->recordStats();
    }
}

