<?php

namespace Flux\Framework\EventStream;

use Flux\Framework\EventStream\UpdateStream;
use PSB\Core\Exception\ExceptionUtils;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class TailFileStream implements UpdateStream {
    /** @var resource $fh */
    private $fh;

    function __construct(
        mixed $filename,
        private string $channel = 'message',
        private int $beatsPerMinute = 120,
        private int $updatesPerTick = 1000,
        private ?\Closure $transform = null
    ) { 
        if (is_string($filename)) {
            $this->fh = fopen($filename, 'r');
        } else if (is_resource($filename)) { 
            $this->fh = $filename;
        }
    } 

    function setTransform(?\Closure $transform) { 
        $this->transform = $transform;
    }

    public function channelName(): string {
        return $this->channel;
    }

    public function isEndOfFile(): bool { 
        return feof($this->fh);
    }

    private ?\Closure $endOfStreamDetector;
    function setEndOfStream(\Closure $endOfStreamDetector) { 
        $this->endOfStreamDetector = $endOfStreamDetector;
    }

    function endOfStream(): bool { 
        if (!$this->isEndOfFile()) { 
            return false;
        }
        if (isset($this->endOfStreamDetector)) { 
            return call_user_func($this->endOfStreamDetector);
        }
        return false;
    }

    public function nextUpdate(mixed $lastPosition): mixed {     
        try {            
            if ($lastPosition) fseek($this->fh, $lastPosition);
        } catch (\Throwable) { 
            // some streams, like popen, dont support seeking...
        } 

        while(!$this->isEndOfFile()) {
            $line = fgets($this->fh);
            if ($line) {            
                if ($this->transform) { 
                    try { 
                        $line = ($this->transform)($line);
                    } catch (\Throwable $e) { 
                        yield 'error in TailStream::transform: ' . ExceptionUtils::getMessage($e);
                    }
                }             
                if ($line) { 
                    yield ftell($this->fh) => $line;
                }
            }
        }
        return null;
    }

    public function beatsPerMinute(): int {
        return $this->beatsPerMinute;
    }

    public function updatesPerTick(): int { 
        return $this->updatesPerTick;
    }
}