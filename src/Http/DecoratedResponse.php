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
        } else {
            $this->logger->debug('http: > ' . $this->response->getInfo('http_method'). ' ' . $this->response->getInfo('url'));
        }
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
        $statusKey = 'status.http_' . $statusCode;
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
        dd($this->getInfo('debug') . PHP_EOL . '(dd called in ' . __CLASS__ . ' on line ' . __LINE__ .')');
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


    function __destruct() { 
        $this->recordStats();
    }

    function saveToFile(string $filename): void { 
        $handle = fopen($filename, 'w');
        foreach ($this->stream() as $chunk) { 
            fputs($handle, $chunk);
        }
        fclose($handle);
    }

    private array $resultDecorators;

    function setResultDecorator(\Closure $resultDecorator): static {
        $this->resultDecorators = [];
        return $this->addResultDecorator($resultDecorator);
    }
    function addResultDecorator(\Closure $resultDecorator): static {
        $this->resultDecorators[] = $resultDecorator;
        return $this;
    }

    function getResult(bool $throw = true): mixed { 
        if (isset($this->resultDecorators)) { 
            $result = [];
            foreach ($this->resultDecorators as $cb) { 
                $result = $cb($this, $result);
            }
            return $result;
        }
        return $this->toArray($throw);
    }
}

