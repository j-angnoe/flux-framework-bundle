<?php

namespace Flux\Framework\Chain;

class BackgroundCommand implements \IteratorAggregate, \JsonSerializable {
    const TMP_DIR = '/tmp/shell-dispatched-commands/';

    private string $token;

    function __construct(string|array|BackgroundCommand $token) { 
        if ($token instanceof BackgroundCommand) {
            $this->token = $token->token;
        } elseif (is_array($token) && isset($token['backgroundCommandId'])) {
            $this->token = $token['backgroundCommandId'];
        } else {
            $this->token = $token;
        }
    }

    /**
     * Pid is something that is time-sensitive.
     * Now there can be a PID, but in a few seconds the pid may be gone.
     */
    function getPid(): int {
        
        $pidfile = static::TMP_DIR . $this->token . '/pid.txt';
        if (!file_exists($pidfile)) { 
            throw new NoActivePidException;
        }
        $content = file_get_contents($pidfile);
        if ($content) { 
            $pid = intval($content);
            if ($pid < 10) { 
                throw new \Exception('Illegal PID: `'.$pid.'`');
            }
            return $pid;
        } else {
            return throw new NoActivePidException;
        }
    }

    function isStillRunning(): bool { 
        try { 
            return (bool) $this->getPid();
        } catch (NoActivePidException) {
            return false;
        }
    }

    function stop(): void {
        try { 
            $pid = $this->getPid();
            posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
        } catch (NoActivePidException) {
            error_Log('There was no active pid...');
        }
    }

    private array $streamPositions = [];

    function getIterator($stderr = true, $stdout = true, array|string|null $positions = null): \Traversable {


        $handles = array_filter([
            'stdout' => $stdout ? fopen(static::TMP_DIR . $this->token . '/stdout.txt','r') : false,
            'stderr' => $stderr ? fopen(static::TMP_DIR . $this->token . '/stderr.txt','r') : false,
        ]);

        if ($positions && is_string($positions)) { 
            $positions = json_decode($positions);
            foreach($positions as $handleId => $position) { 
                if (isset($handles[$handleId])) { 
                    fseek($handles[$handleId], $position);
                }
            }
        }

        $outputPrefixes = [
            'stdout' => '',
            'stderr' => 'stderr > ',
        ];


        $runWhile = function () use (&$deadAt) { 
            if (!file_exists(static::TMP_DIR . $this->token . '/pid.txt')) { 
                $deadAt ??= microtime(true);
                // 250ms to catch up / fsync etc.
                return (microtime(true) - $deadAt) < 0.10;
            }
            return true;
        };

        $streamPositions = [
            'stdout' => 0,
            'stderr' => 0,
        ];
        foreach (Shell::readFromManyHandles($handles, $runWhile, $streamPositions) as $stream => $line) { 
            $this->streamPositions = $streamPositions;
            yield $outputPrefixes[$stream] . $line;
        }
        $this->streamPositions = $streamPositions;
    }

    function serializePositions(): string { 
        return json_encode((object)$this->streamPositions);
    }

    function jsonSerialize(): mixed { 
        return ['backgroundCommandId' => $this->token];
    }

    function simpleEventStream(?\Closure $callback = null) { 
        // End whatever sessions are left.
        if (session_status() === PHP_SESSION_ACTIVE) { 
            session_write_close();
        }

        // End all output buffering
        while(ob_get_level()) ob_end_clean();

        header('HTTP/1.1 200');

        $last_position = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;

        header('Content-type: text/event-stream');

        $output = function (string $line, $position = null) { 
            echo "data: " . str_replace("\n", "\ndata: ", rtrim($line, "\n")) . "\n";
            if ($position) {
                echo "id: $position\n";
            }
            echo "\n";

            flush();
        };

        $event = function(string $event, string $line = null, $position = null) use ($output) {
            echo $event ? "event: $event\n" : "";
            $output($line, $position);
        };

        // set_exception_handler(function($ex) use ($output, $event, $last_position) { 
        //     $output("$ex");
        //     $event('finished','bye', $last_position);
        //     exit;
        // });

        foreach ($this->getIterator(true, true, $last_position) as $line) { 
            $output($line, $this->serializePositions());
            if ($callback) {
                $callback($line);
            }
        }
        $event('exitcode', file_get_contents(static::TMP_DIR . $this->token.'/exitcode.txt'));
        $event('finished', 'bye', $this->serializePositions());
    }
}