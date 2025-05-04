<?php

namespace Flux\Framework\Chain;

use Flux\Framework\BackgroundPHP;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class RuntimeExceededException extends \Exception { } 

class Shell implements \IteratorAggregate { 

    const POSITIONS_STREAM = 'shell-background-command-positions';

    static function formatCommand(string $command, mixed ...$args): string {	
		$optstr = function ($array) { 
			$optstr = '';
			foreach ($array as $opt => $values) { 
				if (substr($opt,0,1) === '#') {
					continue;
				}
				if (!is_array($values)) {
					$values = [$values];
				}
				foreach ($values as $value) { 
					if (substr($opt,0,1) === '-') {
						$optstr .= ' '.$opt;
					} elseif (strlen($opt) == 1) {
						$optstr .= ' -'.$opt;
					} else {
						$optstr .= ' --'.$opt;
					}

					if ($value === true) {
						// nothing
					} else {
						$optstr .= ' '.escapeshellarg($value);
					}
				}
			}
			return $optstr;
		};

        $numArgs = count($args);
        $originalCommand = $command;
		$command = preg_replace_callback('/([\'"]*)((?<!\$)\?|%s)\\1/', function ($match) use (&$args, $numArgs, $optstr, $originalCommand) { 
			if (empty($args)) {
                return $match[0];
                throw new \Exception('Command `'.$originalCommand.'` contains more placeholders then arguments supplied ('.$numArgs.').');
			}
			$value = array_shift($args);

			if (!is_scalar($value)) { 
				return $optstr($value);
			}
			
			if ($match[1] || $match[2] === '?') {
				$value = escapeshellarg($value);
			}
			return $value;
		}, $command);

			
		// The rest arguments gaan we gewoon toevoegen.
		foreach ($args as $a) {
			if (is_scalar($a)) { 
				$command .= ' ' . escapeshellarg($a);
			} else if (is_array($a)) { 
				$command .= ' ' . $optstr($a);
			}
		}

		return $command;
    }

    private array $commands = [];
    private ?string $stdoutFile = null;
    private ?string $stderrFile = null;
    private ?string $pidfile = null;

    function __construct(?string $command = null, mixed ...$args) { 
        if ($command) { 
            $this->commands = [static::formatCommand($command, ...$args)];
        }
    }

    function pipe(string $command, mixed ...$args): static { 
        $this->commands[] = static::formatCommand($command, ...$args);
        return $this;
    }


    function redirectStdout(string $filename): static { 
        $this->stdoutFile = $filename;
        // touch($this->stdoutFile);
        return $this;
    }

    function redirectStderr(string $filename): static { 
        $this->stderrFile = $filename;
        // touch($this->stderrFile);
        return $this;
    }

    function enablePidFile(string $pidfile): static {
        $this->pidfile = $pidfile;
        return $this;
    }
    /**
     * Set a limit for how long we want to stream from a command
     * Set NULL for NO time limit.
     */
    private int $runtimeMilliseconds;
    function setRuntime(?int $milliseconds): static { 
        $this->runtimeMilliseconds = $milliseconds;
        return $this;
    }

    /**
     * @protected
     * 
     * Will yield [line, stream-position]
     */
    static function readFromManyHandles($handles, ?\Closure $runWhile = null, &$positions = null, ?float $timeoutInSeconds = null) {
        $noContentTimeout = 10.0;

        $runWhile ??= function () use (&$handles) {
            foreach ($handles as $h) { 
                if (!feof($h)) {
                    return true;
                }
            }
            return false;
        };

        $lastLines = [];
        $flushLastLines = function() use (&$lastLines, &$positions) { 
            // fputs(STDERR, 'Flush last lines ' . print_r($lastLines, true) . "\n");
            foreach ($lastLines as $handleId => $line) {
                if ($line > '') { 
                    $positions[$handleId] += strlen($line) + 1;
                    yield $handleId => $line;
                }
                unset($lastLines[$handleId]); // very important!
            }
        };


        // Load initial positions for all handles.
        foreach ($handles as $handleId => $handle) { 
            $positions[$handleId] = ftell($handle);
            if ($timeoutInSeconds > 0) { 
                stream_set_timeout($handle, floor($timeoutInSeconds), ($timeoutInSeconds-floor($timeoutInSeconds))*1e6);
            }
        }

        $lastYieldTime = microtime(true);

        do { 
            $contentThisCycle = 0;
            
            foreach ($handles as $handleId => $handle) { 
                $lastLines[$handleId] ??= '';
                while(false !== ($chunk = fread($handle, 8*1024))) {             
                    
                    if ($chunk === '') {
                        $timeSinceLastYield = microtime(true) - $lastYieldTime;
                        if ($timeoutInSeconds && $timeSinceLastYield > $timeoutInSeconds) { 
                            $lastYieldTime = microtime(true);
                            yield null;
                        }
                        break;
                    }
                    $contentThisCycle += strlen($contentThisCycle);
                    // fputs(STDERR, 'read ' . strlen($chunk) . ' at position ' . ftell($handle) . ' from ' . $handleId . PHP_EOL);

                    $lines = explode("\n", $lastLines[$handleId] . $chunk);
                    $lastLines[$handleId] = array_pop($lines);

                    // fputs(STDERR, 'yield lines ' . count($lines) . PHP_EOL);
                    // fputs(STDERR, 'keeping lastline: ' . $lastLines[$handleId] . PHP_EOL);
                    foreach($lines as $l) {
                        $positions[$handleId] += strlen($l) + 1;

                        // fputs(STDERR, 'yield line `'.$l.'`' . PHP_EOL);
                        $lastYieldTime = microtime(true);
                        yield $handleId => $l;
                    }
                }
            }     

            if ($contentThisCycle === 0) {
                // DONT FLUSH, you risk flushing an unfinished line.
                // yield from $flushLastLines();

                $noContentTimeout = round(($noContentTimeout ?: 10) * 1.05);
                // fputs(STDERR, 'timing out ' . $noContentTimeout . "\n");
                usleep($noContentTimeout * 1000);
                
            } else {
                // fputs(STDERR, 'resetting timeout on ' . $handleId);
                $noContentTimeout = 0;
            }          
        } while ($runWhile());

        yield from $flushLastLines();
    }

    /**
     * a simplified shell runner, more in tune to what we usually use it for.
     */
    function getIterator(?float $timeoutInSeconds = null): Traversable { 
        
        $command = join(" | \\\n", $this->commands);
        if (!($this->pidfile ?? false)) { 
            $pidfile = $this->pidfile ?: tempnam(ensure_dir('/tmp/shells/'), 'pid-');
            $exitfile = $pidfile.'.exit';
        } else {
            $pidfile = $this->pidfile;
            $exitfile = dirname($this->pidfile) . '/exitcode.txt';
        }
        $wrappedCommand = static::formatCommand("echo \$\$ > ?; set -e; set -o pipefail; $command", $pidfile);
        $wrappedCommand = static::formatCommand(
            'bash -c ?; exit_code=$?; echo $exit_code > ?; rm ?; exit $exit_code', 
                $wrappedCommand,
                    $exitfile,
                    $pidfile,
        );
        
        $descr = [
            ['pipe','r'],
            $this->stdoutFile ? ['file', $this->stdoutFile, 'w'] : ['pipe','w'],
            $this->stderrFile ? ['file', $this->stderrFile, 'w'] : ['pipe','w'],
        ];

        $proc = proc_open($wrappedCommand, $descr, $pipes, null, $_ENV);
        
        if ($this->stdoutFile) { 
            $pipes[1] = fopen($this->stdoutFile, 'r');
        }
        if ($this->stderrFile) { 
            $pipes[2] = fopen($this->stderrFile, 'r');
        }

        fclose($pipes[0]);
        unset($pipes[0]);
        $handle = $pipes[1];

        if (!$handle) { 
            throw new \ErrorException('Could not popen command `'.$command.'`');
        }

        $outputPrefixes = [
            1 => '',
            2 => 'stderr > ',
        ];

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startTime = microtime(true);

        $runWhile = function () use ($pipes, $startTime, $proc) {
            $elapsedMilliseconds = 1000 * (microtime(true) - $startTime);

            if (isset($this->runtimeMilliseconds) && $this->runtimeMilliseconds > 0) {
                // Set a minimum of 100 milliseconds for runtime,
                // because otherwise we'll miss `instant` failures.
                if ($elapsedMilliseconds > max(100, $this->runtimeMilliseconds)) {
                    throw new RuntimeExceededException;
                }
            }

            foreach ($pipes as $p) {
                if (!feof($p)) {
                    // echo __METHOD__ . ": RETURNING TRUE\n";
                    return true;
                }
            }

            // Give it at least 25 ms to let the OS do its 
            // thing delivering the content to our handles.
            // fputs(STDERR, 'looping ' . $elapsedMilliseconds);

            if ($elapsedMilliseconds < 25) { 
                return true;
            }   
            return false;
        };
        
        $streamPositions = [];
        $lastContent = [];

        // fputs(STDERR, 'Start reading from many handles' . "\n");
        foreach (static::readFromManyHandles($pipes, $runWhile, $streamPositions, $timeoutInSeconds) as $stream => $line) { 
            // fputs(STDERR, 'Receive `'.$line.'` on `'.$stream.'` from many handles' . "\n");

            if ($line > '') { 
                $content = ($outputPrefixes[$stream] ?? '') . $line;
                $lastContent[] = $content;
            } else {
                $content = $line;
            }

            yield $content;

            if (count($lastContent) > 100) { 
                array_shift($lastContent);
            }
        }
        
        $status = proc_get_status($proc);
        
        if (!$status['running'] && isset($status['exitcode'])) {
            if ($status['exitcode'] !== 0) { 
                $lastContentWithErrors = $lastContent; // preg_grep('~(PHP|error)~i', $lastContent) ?: $lastContent;

                throw new \Exception(sprintf(
                    "Command exited with code %d\n%s\n%s",
                    $status['exitcode'],
                    join("\n", $lastContentWithErrors),
                    "   ".str_replace("\n", "\n   ", $command)
                ));
            }
        }
    }

    /**
     * Yields a buffer 
     */
    function whileRunning($tickRatePerSecond = 3, bool $yieldBuffer = false) { 
        $timeout = 1 / $tickRatePerSecond;
        $lastYield = microtime(true);
        
        $buffer = null;

        foreach ($this->getIterator($timeout) as $line) { 
            $now = microtime(true);

            if ($yieldBuffer && $line > '') { 
                $buffer[] = $line;
            }

            if (($now - $lastYield) >= $timeout) { 
                $lastYield = $now;
                yield $buffer;
                $buffer = null;
            }
        }
        if ($buffer) { 
            yield $buffer;
        }
    }
    /**
     * Dispatch the command and get a Token back.
     */
    function dispatchBackgroundCommand(): BackgroundCommand { 
        $token = sha1(__METHOD__ . '---'.uniqid());
        $token = substr($token, 0, 4) . '-'. substr($token, 4, 4) . '-'. substr($token, 8, 4).'-'.substr($token, 12,4);

        $dir = BackgroundCommand::TMP_DIR . $token;
        if (!is_dir($dir)) { 
            mkdir($dir, 0777, true);
        }

        $this->redirectStdout("$dir/stdout.txt");
        $this->redirectStderr("$dir/stderr.txt");
        $this->enablePidFile("$dir/pid.txt");
        $this->setRuntime(1);

        foreach ($this->getIterator() as $i) {
            break;
        }

        return new BackgroundCommand($token);
    }

    /**
     * Allows you to map/transform data with a php function
     * you supply as part of a pipe (as a separate process)
     * 
     * like cat data.txt | php -r '...' 
     */
    function pipeMap(\Closure $closure): static { 
        [$transformer, $preamble] = BackgroundPHP::getClosureSource($closure);

        [$stdin] = BackgroundPHP::getClosureSource(function($source) { 
            $lastLine = '';
			do { 
				$chunk = fread($source, 8*1024);
				
				$lines = explode("\n", $lastLine . $chunk);
				$lastLine = array_pop($lines);

				yield from $lines;
			} while (!feof($source));

			if ($lastLine) {
				yield $lastLine;
			}
        });

        [$stdout] = BackgroundPHP::getClosureSource(function($result) {
            if (!$result) {
                return;
            }
            
            if (is_scalar($result) && "$result">"") {
                echo $result . PHP_EOL;
            } elseif (is_array($result) || is_iterable($result)) {
                if (is_array($result)) { 
                    $keys = array_keys($result);
                    $key = end($keys);
                    if (!is_numeric($key)) { 
                        echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
                        return;
                    }
                }
                foreach ($result as $row) {
                    if ($row) { 
                        echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
                    }
                }
            } elseif (is_object($result)) { 
                echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
            } 
        });

        $php = $preamble . "\n" 
            . '$transformer = '.$transformer . "\n"
            . '$stdin = '.$stdin . "\n"
            . '$stdout = '.$stdout . "\n"
            . <<<'PHP'

            foreach ($stdin(STDIN) as $line) { 
                $result = $transformer($line);
                if ($result !== null) { 
                    $stdout($result);
                }
            }
            PHP;
        
        return $this->pipe('php -r', $php);
    }
}

