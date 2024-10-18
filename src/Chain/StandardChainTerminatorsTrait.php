<?php

namespace Flux\Framework\Chain;

trait StandardChainTerminatorsTrait {

	// Terminating functions
	// @test toArray() - executes the iterator and returns the result as an array.
	function toArray(): array {
		return iterator_to_array($this->result);
	}

	function values() { 
		return array_values($this->toArray());
	}

	
	// @tests count()
	function count() { 
		$count = 0;
		foreach ($this->getIterator() as $r) {
			$count++;
		}
		return $count;
	}

	function run() {
		$this->count();
		return true;
	}

	// @test last() - executes the iterator and returns only the last line.
	function last() {
		$this->tail(1);
		return $this->first();
	}

	// @test first() - executes the iterator and returns only the first line.
	function first(?Callable $filter = null) {
		foreach ($this->getIterator() as $result) { 
			if ($filter && !$filter($result)) {
				continue;
			}
			return is_string($result) ? trim($result) : $result;
		}
	}

	// @test toString()
	// @test toString(custom separator)
	// @test toString(trimming) - trims the final result.
	function toString($separator = "\n", $trim = true, $limit = null): string { 
		$result = "";
		$sep = "";
		$this->stats['out_bytes'] = -1;
		$this->stats['out_lines'] = 0;

		$bytes = 0;
		foreach ($this->getIterator() as $r) {
			$str = $sep . rtrim($r, $separator);
			$strlen = strlen($str);
			if ($limit && ($bytes + $strlen) > $limit) { 
				$result .= $str;
				break;
			}
			$result .= $str;
			$sep = $separator;
			$this->stats['out_lines']++;
		}

		if ($trim) { 
			$result = trim($result);
			if (strlen($sep) == 1) {
				$result = trim($result, $sep);
			}
		}
		$len = strlen($result);
		if ($limit && $len > $limit) {
			$len = $limit;
			$result = substr($result, 0, $len);
		}
		$this->stats['out_bytes'] = $len;
		return $result;
	}

	// @test output() - echo's the output
	// @test output('/my/file') - outputs to a file
	// @test output(resource) - outputs to resource
	// @test output('php://stderr') - outputs to stderr
	// @test output(null) - does execute but outputs nothing
	function output($handle = null): int {
		if (is_resource($handle)) { 
			// do nothing to handle.
		} else if (is_string($handle)) {
			$handle = fopen($handle,'w');
		} else if (func_num_args() == 1 && $handle === null) { 
			// User explicitly says output(null), so we put it to /dev/null
			$handle = fopen('/dev/null', 'w');
		} else {
			$handle = fopen('php://output','w');
		}

		if (!is_resource($handle)) {
			throw new \Exception(__METHOD__ . ': Invalid argument #1, should be string|resource');
		}

		$this->stats['out_bytes'] = -1;
		$this->stats['out_lines'] = 0;
		$writtenLines = 0;
		foreach ($this->getIterator() as $r) {
			if (!$r) {
				continue;
			}
			$this->stats['out_lines']++;
			if (is_string($r)) {
				$str = rtrim($r) . "\n";
			} else {
				$str =json_encode($r) . "\n";
			}
			$this->stats['out_bytes'] += strlen($str);
			fputs($handle, $str);
			$writtenLines++;
		}
		if ($this->debug) { 
			$nice_num = function() {
				return $this->nice_number(...func_get_args());
			};

			$nice_mb = function($n) use ($nice_num) {
				return $this->nice_mb(...func_get_args());
			};
			

			$stats = array_merge($this->stats);
			$stats['0.Time elapsed'] = round(microtime(true) - $stats['start_time'],3) . ' sec';
			unset($stats['start_time']);

			$stats['1.Memory before'] = $nice_mb($stats['memory']) .' / ' . $nice_mb($stats['memory_peak']);
			$stats['2.Memory after'] = $nice_mb(memory_get_usage()) .' / ' . $nice_mb(memory_get_peak_usage());
			unset($stats['memory'], $stats['memory_peak']);

			if (isset($stats['read_lines'])) { 
				$stats['3.Read'] = $nice_mb($stats['read_bytes']) . ', ' . $nice_num($stats['read_lines']) . ' lines/chunks';
				unset($stats['read_bytes'], $stats['read_lines']);
			}

			$stats['4.Outputted'] = $nice_mb($stats['out_bytes']) . ', ' . $nice_num($stats['out_lines']) . ' lines/chunks';
			unset($stats['out_bytes'], $stats['out_lines']);

			ksort($stats);
			echo "\nDebug info:\n";
			foreach ($stats as $key=>$value) {
				printf("   %-20s: %s\n", preg_replace('/^[0-9]\./','',$key), $value);
			}
		}
		return $writtenLines;
	}

	function outputIfNotExists(string $filename) { 
		if (file_exists($filename)) { 
			return;
		}
		return $this->output($filename);
	}

	function outputIfOlder(string $filename, $ageInSeconds = null, $ageInHours = null, $ageInDays = null)  {
		if (isset($ageInDays)) { 
			$ageInSeconds = $ageInDays * 3600 * 24;
		}
		if (isset($ageInHours)) { 
			$ageInSeconds = $ageInHours * 3600;
		}
		if (!isset($ageInSeconds)) {
			throw new \InvalidArgumentException('chain::outputIfOlder - should at ageInDays or ageInHours or ageInSeconds argument');
		}

		if (file_exists($filename) && (time() - filemtime($filename)) < $ageInSeconds) {
			return;
		}
		return $this->output($filename);
	}

	/**
	 * automatic __toStrings can be problematic in all sorts of scenarios
	 * it may seem handy, but you may undoublty decide to trigger the iterator
	 * by printing stuff somewhere. same goes for automatic json serializing.
	 */
	// @test __toString() when magicStrings is enabled (executes the iterator and outputs result)
	// @test __toString() can be used inside a string.
	// @test __toString() when magicStrings is disabled
	function __toString(): string {
		if (!$this->yielded) { 
			if (static::$magicStrings) {
				return $this->toString();
			}
			return '<unyielded chain, (chain::magicStrings == false)>';
		}
		return '<already yielded>';
	}
	
	// @test done() - finalizes the result, after this no more operations can be performed.
	function done() {
		$this->getIterator();
		// $this->result->current();
	}

	static function countLines(string $file): int {
        $count = 0;
        if (file_exists($file)) {
            $fh = fopen($file,'r');
			$buffer = null;
            while(!feof($fh)){
                $tmp = fread($fh, 4092);
				if ($tmp) {
					$buffer = $tmp;
					$count += substr_count($tmp, "\n");
				}
            }
			fclose($fh);
			if ($buffer && str_ends_with($buffer, "\n")) {
				$count -= strlen($buffer) - strlen(rtrim($buffer));
			}
			$count += 1;
        }
        return $count;
    }


	function reduce(\Closure $reducer, mixed $initialState): mixed { 
		$result = $initialState;
		foreach ($this->getIterator() as $value) { 
			$result = $reducer($result, $value);
		}
		return $result;
	}

}