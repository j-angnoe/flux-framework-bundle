<?php

declare(strict_types=1);

namespace Flux\Framework\EventStream;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

use Exception;
use Generator;
use Iterator;

#[Exclude]
class EventStreamService
{
    private array $streamOffsets = [];
    private array $updateStreams = [];
    private float $lastWriteAt = 0;
    private array $buffer = [];
    private bool $started = false;

    public function __construct(private Request $request)
    {

    }

    public function isEventStreamRequest(): bool
    {
        return $this->request->headers->contains('accept', 'text/event-stream');
    }

    public function setStreamOffset(mixed $offset, int $index = 0): void
    {
        $this->streamOffsets[$index] = $offset;
    }
    public function getStreamOffset(int $index = 0): mixed
    {
        return $this->streamOffsets[$index] ?? null;
    }

    public function parseStreamOffsets(string $lastEventId): array
    {
        $offsets = [];
        foreach (explode(';', $lastEventId) as $o) {
            if (str_contains($o, ':')) {
                [$offset, $position] = explode(':', $o);
                $offsets[$offset] = $position;
            }
        }
        return $offsets;
    }
    private function serializeStreamOffsets(): string
    {
        $offsets = [];
        foreach ($this->streamOffsets as $k => $v) {
            $offsets[] = $k . ':' . $v;
        }
        return str_replace("\n", '', join(';', $offsets));
    }

    public function register(UpdateStream $updateStream): void
    {
        $this->updateStreams[] = $updateStream;
    }

    public function start(float $maxRuntimeInMinutes = 1, $exit = true, bool $debug = false): void
    {
        if (PHP_SAPI !== 'cli') {
            while(ob_get_level()) {
                ob_end_clean();
            }
            try { 
                $this->request->getSession()->save();
                session_write_close();
            } finally { }
        } 


        if ($this->isEventStreamRequest()) {
            if (PHP_SAPI !== 'cli') {
                header('HTTP/1.1 200');
                header('Content-type: text/event-stream');
            }
        } else {
            throw new Exception('startEventStream() on a non event-stream http request');
        }
        $this->started = true;

        if ($this->buffer) {
            foreach ($this->buffer as $b) {
                echo $b;
            }
            flush();
            $this->buffer = [];
        }

        $streamOffsetsFromHeader = $this->request->headers->get('last-event-id') ?: '';
        $this->streamOffsets = $this->parseStreamOffsets($streamOffsetsFromHeader);

        if (empty($this->updateStreams)) {
            throw new \Exception('No update streams registered');
            return;
        }

        // determine max bpm
        $maxBeatsPerMinute = 1;
        foreach ($this->updateStreams as $updateStream) {
            $maxBeatsPerMinute = max($maxBeatsPerMinute, $updateStream->beatsPerMinute());
        }
        $maxRuntimeInMinutes = round($maxRuntimeInMinutes,2);
        $maxRuntimeInTicks = floor($maxBeatsPerMinute * $maxRuntimeInMinutes);

        $lastStreamHit = [];

        if ($debug) { 
            $this->send('debug: max runtime in ticks = ' . $maxRuntimeInTicks . ' (in minutes: ' . $maxRuntimeInMinutes. ')');
        }

        $updateStreams = $this->updateStreams;
        for ($tick = 0; $tick < $maxRuntimeInTicks; $tick++) {
            $loopStart = microtime(true);

            if ($debug) $this->send('debug: tick ' . $tick);
            
            foreach ($updateStreams as $streamId => $updateStream) {
                $streamStart = microtime(true);
                $streamElapsedSinceLastHit = ($streamStart - ($lastStreamHit[$streamId] ?? 0));

                $streamRuntime = (1 / ($updateStream->beatsPerMinute() / 60));
                if (($streamElapsedSinceLastHit * 1.05) > $streamRuntime) {

                    $nextUpdate = $updateStream->nextUpdate($this->getStreamOffset($streamId));

                    if ($nextUpdate && is_a($nextUpdate, Iterator::class) || is_a($nextUpdate, Generator::class)) {
                        $updatesPerTick = 0;
                        foreach ($nextUpdate as $position => $update) {
                            $updatesPerTick += 1;

                            $this->setStreamOffset($position, $streamId);

                            $this->sendEvent($updateStream->channelName(), $update);
                            $streamElapsed = microtime(true) - $streamStart;
                            if ($streamElapsed > $streamRuntime) {
                                break;
                            }
                            if ($updatesPerTick >= $updateStream->updatesPerTick()) {
                                break;
                            }
                        }
                    } else if ($nextUpdate) {
                        $this->sendEvent($updateStream->channelName(), $nextUpdate);
                    }
                    $lastStreamHit[$streamId] = microtime(true);

                    if (method_exists($updateStream, 'endOfStream') && $updateStream->endOfStream()) { 
                        unset($updateStreams[$streamId]);
                    }
                } else {
                }
            }

            if (empty($updateStreams)) { 
                break;
            }

            $loopElapsed = microtime(true) - $loopStart;
            $timeSinceLastWrite = microtime(true) - $this->lastWriteAt;

            // This is to ensure the connection is still alive.
            if ($timeSinceLastWrite > 5) {
                $this->sendEvent('tick');
            }

            usleep(intval(max(0, (10e5 / ($maxBeatsPerMinute / 60)) - ($loopElapsed))));
        }

        if (empty($updateStreams)) { 
            $this->sendEvent('finished', 'bye');
        }
        if ($exit) {
            exit();
        }
    }

    public function send(string $lines, mixed $offset = null): void
    {
        $this->lastWriteAt = microtime(true);
        echo "data: " . str_replace("\n", "\ndata: ", trim($lines)) . "\n";
        if (isset($offset)) {
            $this->setStreamOffset($offset, 0);
        }
        if ($this->streamOffsets) {
            echo "id: " . $this->serializeStreamOffsets() . "\n\n";
        }

        echo "\n";
        flush();
    }

    public function sendOffset(mixed $offset = null, int $index = 0): void
    {
        if (isset($offset)) {
            $this->setStreamOffset($offset, $index);
        }

        if ($this->streamOffsets) {
            $this->lastWriteAt = microtime(true);
            echo "id: " . $this->serializeStreamOffsets() . "\n\n";
        }
    }
    static function sendDebug(mixed $message) { 
        $message = str_replace("\n", "\ndata: debug: ", print_r(['debug' => $message],true));
        echo $message . "\n\n";
    }
    public function sendEvent(string $eventName, mixed $data = null, mixed $offset = null): void
    {
        if ($eventName === 'message') { 
            $this->send(strval($data), $offset);
            return;
        }

        $this->lastWriteAt = microtime(true);
        echo "event: $eventName\n";
        if ($data !== null) {
            echo "data: " . json_encode($data) . "\n";
        }
        if (isset($offset)) {
            $this->setStreamOffset($offset, 0);
        }
        if ($this->streamOffsets) {
            echo "id: " . $this->serializeStreamOffsets() . "\n\n";
        }
        echo "\n";
        flush();
    }

    public function sendRetry(int $retrySeconds): void
    {
        $this->lastWriteAt = microtime(true);
        $message = "retry: " . ($retrySeconds * 1000) . "\n\n";
        if ($this->started) { 
            echo $message;
        } else { 
            $this->buffer[] = $message;
        }
    }

    public function sendFinished(): void
    {
        $data = 'bye';
        if ($this->streamOffsets) {
            $data = ['position' => $this->serializeStreamOffsets()];
        }
        $this->sendEvent('finished', $data);
    }
}
