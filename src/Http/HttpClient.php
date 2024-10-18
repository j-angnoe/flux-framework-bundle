<?php

namespace Flux\Framework\Http;

use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Contracts\Service\ResetInterface;
use Traversable;

class DecoratedResponse implements ResponseInterface { 
    function __construct(
        private ResponseInterface $response, 
        private LoggerInterface $logger) {

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
    function __destruct() { 
        $this->recordStats();
    }

    function debug() { 
        dd($this->getInfo('debug'));
    }
}

class HttpClient implements HttpClientInterface {
    static $HTTP_STATS = [
        'reqs' => 0,
        'resps' => 0,
        'b_down' => 0,
        'b_up' => 0,
    ];

    protected HttpClientInterface $client;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create();
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    

    static function create(array $defaultOptions = [], int $maxHostConnections = 6, int $maxPendingPushes = 50) { 
        return new static(SymfonyHttpClient::create($defaultOptions, $maxHostConnections, $maxPendingPushes));
    }
    
    public static function createForBaseUri(string $baseUri, array $defaultOptions = [], int $maxHostConnections = 6, int $maxPendingPushes = 50): HttpClientInterface {
        return new static(SymfonyHttpClient::createForBaseUri($baseUri, $defaultOptions, $maxHostConnections, $maxPendingPushes));
    }

    protected function getHttpClient(): HttpClientInterface { 
        return $this->client;
    }

    static ?LoggerInterface $logger;

    static function setLogger(LoggerInterface $logger): void {
        static::$logger = $logger;
    }

    function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['user_data'] ??= $options['body'] ?? null;

        static::$HTTP_STATS['reqs'] += 1;
        static::$logger ??= new NullLogger;

        return new DecoratedResponse($this->client->request($method, $url, $options), static::$logger);
    }

    static function debugResponse(ResponseInterface $response) {
        
        $debugInfo = $response->getInfo('debug');

        $pieces = preg_split("~(\r*\n){2}~", $debugInfo);
        if (count($pieces) === 1) { 
            return $pieces[0];
        }

        $nicefy = function($x) { 
            try { 
                return json_encode(json_decode($x, true, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                if (strlen($x) > 32*1024) { 
                    return substr($x, 0, 32*1024) . "\n...[response was truncated to save space]\n";
                }
                return $x;
            }
        };  

        if (count($pieces) >= 2) { 
            $responseHeader = array_pop($pieces);
            $user_data = $response->getInfo('user_data');
            if ($user_data !== null) { 
                $pieces[] = $nicefy($user_data);
            }
            $pieces[] = $responseHeader;
            $pieces[] = $nicefy($response->getContent(false), 0, 32*1024);
        }

        $result = join("\r\n\r\n", $pieces);
        
        $redactedResult = preg_replace_callback(
            '~\s*(authorization|token|pass|password|key)[\s"\']*[:=][\s"\']((basic|bearer|token)\s)*(?<pwd>[^\n"\']+)~i',
            function($match) {
                $tenPercent = max(1, min(2, ceil(sqrt(strlen($match['pwd'])))));
                $redacted = substr($match['pwd'], 0, $tenPercent) . '************' . substr($match['pwd'], -1*$tenPercent-1, $tenPercent);
                return str_replace($match['pwd'], $redacted, $match[0]);
            },
            $result
        );
        return $redactedResult;
    }

    function rethrowException(ClientException|ServerException|ResponseInterface $response, string $exceptionMessage = null) {
        if ($response instanceof ResponseInterface) { 
            throw new \Exception($exceptionMessage ?: 'HTTP Request failed with status ' . $response->getStatusCode() . "\n\n".$this->debugResponse($response));
        } else {
            throw new \Exception($response->getMessage() . "\n\n" . $this->debugResponse($response->getResponse()));
        }
    }

    function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if (!is_array($responses)) { 
            $responses = [$responses];
        }
        $generator = call_user_func(function () use ($responses, $timeout) { 
            $curlResponses = array_map(function($resp) {
                if ($resp instanceof DecoratedResponse) {
                    return $resp->getResponse();
                } else {
                    throw new \InvalidArgumentException('Received a ' . get_class($resp). ' but expected a DecoratedResponse here... maybe you are supplying responses from a REAL HttpClient to our Decorated HTTPClient.');
                }
            }, $responses);

            foreach ($this->client->stream($curlResponses, $timeout) as $response => $chunk) {
                $responseId = array_search($response, $curlResponses);
                $responses[$responseId]->recordStats();
                
                yield $response => $chunk;
            }
        });

        return new class($generator) implements ResponseStreamInterface { 
            function __construct(private \Generator $generator) { }
            function key(): ResponseInterface { 
                return $this->generator->key();
            }
            function current(): ChunkInterface { 
                return $this->generator->current();
            }

            public function next(): void
            {
                $this->generator->next();
            }

            public function rewind(): void
            {
                $this->generator->rewind();
            }

            public function valid(): bool
            {
                return $this->generator->valid();
            }
        };
    }

    function streamLines(ResponseInterface|iterable $responses): Traversable { 
        if (!is_array($responses)) { 
            $responses = [$responses];
        }
        $lastLine = [];

        foreach ($this->stream($responses) as $response => $chunk) { 
            if ($chunk->isTimeout()) {
                return;
            }

            $responseId = array_search($response, $responses);

            // Read the line or chunk of data from the response
            $content = $chunk->getContent();

            if (!$content) { 
                continue;
            }

            // echo "Response #$responseId Chunk of " . strlen($content) . " with offset " . $chunk->getOffset() . "\n";
            // print_r($chunk);

            $lines = explode("\n", ($lastLine[$responseId] ?? '').$content);                    
            $lastLine[$responseId] = array_pop($lines);
            foreach ($lines as $line) {
                yield $line; 
            }
        }
        foreach ($lastLine as $line) if ($line) yield $line;
    }

    public function streamJsonlines($responses): Traversable {
        foreach ($this->streamLines($responses) as $line) { 
            try { 
                yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch(\JsonException $e) {
                throw new \JsonException($e->getMessage(). ' on line `' . $line.'`');
            }
        }
    }

    public static function resetStats(): array { 
        $oldStats = static::$HTTP_STATS;
        foreach (static::$HTTP_STATS as $k=>$v) {
            static::$HTTP_STATS[$k] = 0;
        }
        return $oldStats;
    }

    public static function getStats(?string $key = null): mixed { 
        if ($key) { 
            return static::$HTTP_STATS[$key];
        }
        return static::$HTTP_STATS;
    }
}