<?php

namespace Flux\Framework\EventStream;

use Flux\Framework\EventStream\UpdateStream;
use PSB\Core\Exception\ExceptionUtils;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class TailFileStream implements UpdateStream {
    /** @var resource $fh */
    private $fh;

    private string $filename;

    function __construct(
        mixed $filename,
        private string $channel = 'message',
        private int $beatsPerMinute = 120,
        private int $updatesPerTick = 1000,
        private ?\Closure $transform = null
    ) { 
        $this->filename = $filename;
    } 

    function setTransform(?\Closure $transform) { 
        $this->transform = $transform;
    }

    public function channelName(): string {
        return $this->channel;
    }

    public function isEndOfFile(): bool { 
        return !$this->fh || feof($this->fh);
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
        if (!isset($this->fh)) {  
            for($i=0; $i<100; $i++) { 
                if (file_exists($this->filename)) { 
                    $this->fh ??= fopen($this->filename, 'r');
                    break;
                }
                // hand control back to the event stream service
                yield null;
                usleep(25_000);
            }

            if (!$this->fh) {
                return null;
            }
        }

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