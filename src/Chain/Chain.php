<?php

namespace Flux\Framework\Chain;

use IteratorAggregate;
use JsonSerializable;
use Closure;
use ArrayIterator;
use ReflectionFunction;
use Traversable;
use EmptyIterator;
use Flux\Framework\Chain\CacheTrait;
use Flux\Framework\Chain\StandardChainTerminatorsTrait;
use GlobIterator;
use NoRewindIterator;
use SplFileObject;

// Just an interface to define stuff as chainable.
// Use this to as return type to indicate 
// that you return something that still `acts` chainable

interface PreparedChain { 
	public function apply(Chainable $chain);
} 


/** @phpstan-consistent-constructor */
class Chain implements IteratorAggregate, JsonSerializable, Chainable { 
	use CacheTrait;
	use QuickSearchTrait;

    use StandardChainTerminatorsTrait;

	protected Traversable $result;
    
	protected $returnValue = -1;
	protected $debug = false;
	protected $stats = [
		'read_lines' => 0,
		'read_bytes' => 0
	];
	protected $yielded = false;

	public function getIterator(): Traversable {
		$this->yielded = true;
		if (isset($this->result)) { 
			$this->stats['mempeak_before'] = memory_get_peak_usage();
			$start_time = microtime(true);
			foreach ($this->result as $k=>$v) { 
				yield $k=>$v;
			}
			$end_time = microtime(true);
			$this->stats['mempeak_after'] = memory_get_peak_usage();
			$this->stats['elapsed'] = round($end_time - $start_time, 3);
		}
	}

	// Json Serialize functions
	// Undecided, keep it in, or not? same auto-magic footguns like __toString()..
	// @tests jsonSerialize()
	function jsonSerialize(): mixed {
		return $this->toArray();
	}

	function chunks(int $chunkSize, ?\Closure $callback = null) { 
		return $this->chunk($chunkSize, $callback);
	}
	function chunk(int $chunkSize, ?\Closure $callback = null) {

		return $this->apply(function ($iterator) use ($chunkSize, $callback) {
			$buffer = [];
			foreach ($iterator as $i) { 
				$buffer[] = $i;

				if (count($buffer) >= $chunkSize) { 
					foreach($buffer as $b) { 
						yield from $callback($b);
					}
					$buffer = [];
				}
			}
			foreach($buffer as $b) { 
				yield from $callback($b);
			}
		});
	}

	protected static function convertStream($source, $object): Traversable { 		
		return call_user_func(function($source, $object) {
			$lastLine = '';
			do { 
				$chunk = fread($source, 8*1024);
				
				$lines = explode("\n", $lastLine . $chunk);
				$lastLine = array_pop($lines);

				$object->stats['read_bytes'] += strlen($chunk);
				$object->stats['read_lines'] += count($lines);

				yield from $lines;
			} while (!feof($source));

			if ($lastLine) {
				yield $lastLine;
				$object->stats['read_lines'] += 1;
			}
		}, $source, $object);
	}

	public static function convertStreamReverse($source, $object): Traversable { 		
		return call_user_func(function($source, $object) {
			$firstLine = '';
			fseek($source, -1, SEEK_END);
			$position = ftell($source);
			$chunkSize = 8*1024; // rand(50,100);

			// if (fgets($source) !== "\n") { 
			// 	$position += 1;
			// }
			
			if ($position === 0) { 
				// empty file.
				return;
			}
			do { 
				// echo("read from $position - $chunkSize\n");
				fseek($source, max(0, $position - $chunkSize ));
				$chunk = strrev(fread($source, min($position, $chunkSize)));
				
				// echo "Chunk size = " . strlen($chunk) . " vs " . $chunkSize . "\n";
				$lines = explode("\n", $firstLine . $chunk);
				$firstLine = array_pop($lines);
				// print_r($lines);
				// print_r(array_map('strrev', $lines));

				$position -= strlen($chunk);

				// print_R([$lines, 'rest' => $firstLine]);
				if ($object) { 
					$object->stats['read_bytes'] += strlen($chunk);
					$object->stats['read_lines'] += count($lines);
				}

				foreach ($lines as $line) {
					yield strrev($line);
				}
			} while ($position > 0);

			if ($firstLine) {
				yield strrev($firstLine);
				if ($object) $object->stats['read_lines'] += 1;
			}
		}, $source, $object);
	}

	protected static function convertClosure(\Closure $source, $object): Traversable {
		return call_user_func(function ($source, $object) {
			foreach ($source($object) as $key=>$value) {
				$object->stats['read_lines']++;
				$object->stats['read_bytes']+= is_string($value) ? strlen($value) : 0;
				yield $key=>$value;
			}
			
		}, $source, $object);
	}


	/**
	 * @params resource|array|iterable|closure $source
	 *		$source can be anything that can be streamed.	 		
	 **/

	 // @tests __construct(null)
	 // @tests __construct(array)
	 // @tests __construct(Iterator)
	 // @tests __construct(Closure (generator))
	 // @tests __construct(stream|resource)
	 // @tests __construct(FluxFX\Chain) 
	function __construct($source) {
		if ($source instanceof static) { 
			$this->stats = $source->stats;
		} else if (is_resource($source)) { 
			$source = static::convertStream($source, $this);
		} elseif ($source instanceof \Closure) {
			$source = static::convertClosure($source, $this);
		} elseif (is_array($source)) { 
			$source = new ArrayIterator($source);
		} elseif (is_a($source, static::class)) { 
			$source = $source->result;
		} elseif (!$source) {
			$source = new EmptyIterator();
		} 

		// Final garantee, it must be iterable.
		if (!is_iterable($source)) { 
			throw new \InvalidArgumentException('Argument 1 passed to '.__METHOD__.' must be either resource, array, iterator or callable.');
		}

		$this->result = $source;
		$this->returnValue = -1;	
	}

	// @tests debug(true)
	// @tests debug(false)
	function debug($debug = true): static { 
		if ($debug instanceof Closure) { 
			$debug($this);
		}
		$this->debug = $debug;
		return $this;
	}

	/**
	 * print_r used to view values at some point in the stream.
	 * 
	 * @test print_r
	 */
	function print_r($options = []): static {
		
		$this->result = call_user_func(function($iter) use ($options) {
			$output = [];
			$encoder = function($x) { return json_encode($x, JSON_UNESCAPED_SLASHES); };
			if ($options['pretty'] ?? false) {
				$encoder = function($x) { return json_encode($x, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES); };
			}
			if ($options['printer'] ?? false) {
				$encoder = $options['printer'];
			}

			$count = 0;
			foreach ($iter as $key=>$value) { 
				yield $key => $value;
				$count++;

				if (count($output) < 50)
					$output[] = $key . ") " . (($value && is_string($value)) ? $value : $encoder($value));
			}

			echo "\n";
			if (isset($options['title'])) {
				if (count($output) == $count) {
					$results = $count;
				} else {
					$results = sprintf("%s of %s", count($output), $count);
				}
				printf("%s (%s results)\n", $options['title'], $results);
			}
			$tab = "   ";
			echo $tab.str_replace("\n","\n$tab", join("\n", $output)) . "\n";
		}, $this->result);
		return $this;
	}




	// @tests pipe(Closure)
	// @tests pipe(Generator) (aka apply)
	// @tests pipe(closure) doesnt yield NULL values and empty strings and empty arrays.
	// @tests pipe(closure) does yield false, 0
	// @tests pipe(closure) yields everything else.
	// @tests pipe(Chain) will put the iterator into the new chain.
	function pipe($fn): static { 
		if (method_exists($fn, 'setInput')) { 
			$fn->setInput($this->result);
			return $fn;
		}

		$refl = new ReflectionFunction($fn);
		if ($refl->isGenerator()) {
			$this->result = call_user_func(function ($result, $fn) {
				foreach ($fn($result) as $key => $value) { 
					if ($value !== null && $value !== '' && $value !== []) {
						yield $value;
					}
				}
			}, $this->result, $fn);
			return $this;
		} 
		$this->result = call_user_func(function($result) use($fn) {
			foreach ($result as $key => $value) { 
				$value = $fn($value, $key);
				if ($value !== null && $value !== '' && $value !== []) {
					yield $value;
				}
			}	
		}, $this->result);
		return $this;
	}

	/**
	 * Apply passes the iterator to your functions, do with it what you will.
	 * 
	 * @test apply(generator) should work
	 * @test apply(closure) that doesn't yield, what will happen?
	 * 
	 * @fixme - may cause problems when you it's not a generator.
	 */
	function apply(PreparedChain|Closure $fn): static {
		if ($fn instanceof PreparedChain) {
			return $fn->apply($this);
		}
		$this->result = call_user_func($fn, $this->result);
		return $this;
	}
	
	// Return a window
	// window(1) is like map.
	// window(2) will call closure with (newest, oldest)
	// window(3) will call closure(newest, older, oldest)
	/**
	 * Lets say you have a dataset
	 * row1
	 * row2
	 * row3
	 * row4
	 * 
	 * window(2) will deliver to call in sequence:
	 * 		closure(row1, row2)
	 * 		closure(row2, row3)
	 * 		closure(row3, row4)
	 * 
	 * The arguments to this function may be flipped
	 * 
	 * @test window() rows are received in right order
	 * @test window(int, Closure) is supported
	 * @test window(Closure, int) is supported
	 * @test window(Closure) is supported, we will check out how many parameters the closure wants to determine the window size.
	 * @test window() result is 1 element shorter than the input.
	 * 				
	 * The result of your windowing closure will be returned as results.
	 * This resultset will be n-1 length.
	 * 
	 */
	function window($windowSize = 2, $closure = null): static {
		if ($windowSize instanceof Closure) {
			list($closure, $windowSize) = [$windowSize, $closure];
			if ($windowSize == null) {
				$refl = new ReflectionFunction($closure);
				$windowSize = $refl->getNumberOfParameters();
			}
		}
		$buffer = [];
		$this->result = call_user_func(function($result) use(&$buffer, $windowSize, $closure) {
			foreach ($result as $key=>$value) { 
				array_push($buffer, $value);
				$n = count($buffer);
				if ($n < $windowSize) continue;

				if (count($buffer) > $windowSize) {
					array_shift($buffer);
				}
				
				yield call_user_func_array($closure, $buffer);
			
			}
		}, $this->result);
		return $this;
	}

	/**
	 * Map, apply your function against every element and adds your result (whatever that may be)
	 * to the resulting array.
	 * 
	 * @test map(closure) - closure should receive (value, key)
	 * @test map(closure) - may not yield
	 * @test map(callable string) - this may be a built-in php function, that receives only value as first argument
	 */
	function map(callable $fn): static {
		if (is_string($fn)) { 
			$fn = function($value) use ($fn) { return call_user_func($fn, $value); };
		}
		$this->result = call_user_func(function($result) use($fn) {
			foreach ($result as $key=>$value) { 
				yield $key => $fn($value);
			}
		}, $this->result);
		return $this;
	}

	function mapWithKeys(callable $fn): static {
		if (is_string($fn)) { 
			$fn = function($value) use ($fn) { return call_user_func($fn, $value); };
		}
		$this->result = call_user_func(function($result) use($fn) {
			foreach ($result as $key=>$value) { 
				yield $key => $fn($value, $key);
			}
		}, $this->result);
		return $this;
	}
	/**
	 * Like map but this one will yield from whatever you return.
	 * 
	 * @test each(generator) - if you return a generator/iterable each will yield from that generator 
	 * @test each(non-generator) - will probably error.
	 **/
	function each(callable $fn) { 
		if (is_string($fn)) { 
			$fn = function($value) use ($fn) { return call_user_func($fn, $value); };
		}
		$this->result = call_user_func(function($result) use($fn) {
			$count = -1;
			foreach ($result as $key => $value) {
				$count = -1; 
				$result = $fn($value, $key);
				if (is_iterable($result)) { 
					foreach ($result as $key => $row) {
						$count++;
						if (is_int($key) && $key === $count) { 
							yield $row;
						} else {
							yield $key => $row;
						}
					}
				} elseif ($result) { 
					throw new \Exception('Chain::each(cb) returns a non-iterable result, this is unsupported.');
				}
			}
		}, $this->result);
		return $this;
	}

	/**
	 * Deduplicate the iterator
	 * 
	 * scalar values be stringified, so NULL and "" are the same
	 * non scalar values will be json_encoded, which may be not totally efficient 
	 * when you have arrays that have same values but different key orderings...
	 * but that's a pretty rare case i presume.
	 * 
	 * @params ?int $bufferSize - determine how many rows will be kept in memory
	 * 							  for comparison. Sacrifice efficiency for accuracy.
	 * 							  NULL we be unlimited buffer size and will be accurate
	 * 							  1 - deduplicates immediate duplicates
	 * 
	 * @test unique() - results are deduplicated accurately.
	 * @test unique(1) - only sequential duplicates are dedupped.
	 * @test unique(100) - buffer size remains static at 100
	 */
	function unique(?int $bufferSize = null) { 
		$buffer = [];
		$this->result = call_user_func(function($result) use(&$buffer, $bufferSize) {
			foreach ($result as $key=>$value) { 
				$valueId = is_scalar($value) ? "$value" : json_encode(array_values($value));
				if (!isset($buffer[$valueId])) { 
					yield $value;
					$buffer[$valueId] = 1;
					// error_log('Register ' . $value . ' in buffer ' . join(',',array_keys($buffer)));
					if ($bufferSize && count($buffer) > $bufferSize) {
						reset($buffer);
						unset($buffer[key($buffer)]);
						// error_log('Shrinking buffer to ' . $bufferSize . ' -> '. var_export($buffer,true));
					}
					
					// error_log('buffer size ' . count($buffer));
				} else {
					// error_log('EAT non-unique ' . $value);
				}
			}
		}, $this->result);
		return $this;
	}
	
	/**
	 * trim/rtrim/ltrim applies to each element of the iterator.
	 * 
	 * @test trim() - what happens to empty lines...?
	 */
	public function trim($characters = null) {
		return $this->_trim($characters, 'trim');
	}
	public function rtrim($characters = null) { 
		return $this->_trim($characters, 'rtrim');
	}
	public function ltrim($characters = null) { 
		return $this->_trim($characters, 'ltrim');
	}
	protected function _trim($characters = null, $fn = 'trim') { 
		return $this->map(function($value) use ($characters, $fn) {
			$args = [$value];
			if ($characters) $args[] = $characters;
			return $fn(...$args);
		});
	}

	/**
	 * head/take n elements from the start of the iterator.
	 * 
	 * @test head(n) - gives us the first n results
	 */
	function head(int $n): static {
		if ($n < 0) {
			throw new \Exception('head(): negative head `'.$n.'` is unsupported.');
		}
		$this->result = call_user_func(function ($iterator) use ($n) {
			$i = 0;
			foreach ($iterator as $key=>$value) { 
				$i++;
				yield $key=>$value;
				if ($i >= $n) {
					break;
				}
			}
		}, $this->result);
		return $this;
	}

	// take: alias for head.
	// @test take(n) - gives us the first n results
	function take(int $n): static {
		return $this->head($n);
	}

	/**
	 * skip: Skip n elements from the start of the iterator and return/yield the rest.
	 * 
	 * @test skip(n)
	 */
	function skip(int $n): static { 
		if ($n < 0) {
			throw new \Exception('skip: skipping a negative number `'.$n.'` is nonsense.');
		}
		$this->result = call_user_func(function ($iterator) use ($n) {
			foreach ($iterator as $key=>$value) { 
				$n--;
				if ($n < 0) {
					yield $value;
				}
			}
		}, $this->result);
		return $this;
	}

	/**
	 * tail: returns n elements from the end of the iterator.
	 * 
	 * @test tail(n) - gives us the last n results
	 */
	function tail($n): static { 
		return $this->apply(function($iterator) use ($n) {
			$units = [];
			$count = 0;
			foreach ($iterator as $key => $value) {
				$units[] = $value;
				if ($count++ >= $n) {
					array_shift($units);
				}
			}
			// error_log('tail, number of units: ' . count($units));
			foreach($units as $u) {
				yield $u;
			}
		});
	}
	

	/**
	 * Filter elements
	 * 
	 * @test filter(string callable)
	 * @test filter() - will strip out null, false, '' and [] values
	 * @test filter(closure) - keep elements that are truthy.
	 * 
	 */
	function filter($fn = null, $keepKeys = false): static {
		if (is_string($fn)) { 
			$fn = function($value) use ($fn) { return call_user_func($fn, $value); };
		} else if ($fn === null) { 
			$fn = function($value) { 
				return $value !== null && $value !== false && $value !== "" && $value !== [];
			};
		}
		
		$this->result = call_user_func(function ($iterator) use ($fn, $keepKeys) {
			foreach ($iterator as $key=>$value) { 
				if ($fn($value)) { 
					if ($keepKeys) { 
						yield $key=>$value;
					} else {
						yield $value;
					}
				}
			}
		}, $this->result);

		return $this;
	}
	/**
	 * reject: inverse of filter
	 * 
	 * @test reject() is the inverse of filter
	 */
	function reject($fn = null): static {
		if (is_string($fn)) { 
			$fn = function($value) use ($fn) { return call_user_func($fn, $value); };
		} else if ($fn === null) { 
			$fn = function($value) { 
				return ($value !== null && $value !== false && $value !== "" && $value !== []);
			};
		}

		$this->result = call_user_func(function ($iterator) use ($fn) {
			foreach ($iterator as $key=>$value) { 
				if (!$fn($value)) { 
					yield $key=>$value;
				}
			}
		}, $this->result);
		return $this;
	}

	protected function _sort(Closure $scoringFunction, $direction = 'asc', $limit = null) { 
		return $this->apply(function ($iterator) use ($scoringFunction, $direction, $limit) {
			// $iterator = new NoRewindIterator($iterator);
			$values = [];
			foreach ($iterator as $row) {
				$lowerBound ??= $scoringFunction($row);
				if (!isset($upperBound)) { 
					$upperBound = $lowerBound;
				}
				$value = $scoringFunction($row);
				if ($value > $upperBound) {
					$upperBound = $value;
					$values[] = [$value, $row];
					// echo "PUSH VALUE $value TO END (".count($values). "/".($lowerBound."-".$upperBound).")\n";
				} elseif ($value < $lowerBound) {
					$lowerBound = $value;
					array_unshift($values, [$value, $row]);
					// echo "PUSH VALUE $value TO BEGIN (".count($values)."/".($lowerBound."-".$upperBound).")\n";
				} else {
					$index = 0;
					foreach ($values as $index => $v) {
						if($v[0] > $value) {
							$index;
							break;
						}
					}
					//echo "INSERT VALUE $value at $index\n";
					array_splice($values, $index, 0, [[$value, $row]]);
				}
				if ($limit) { 
					if ($direction == 'desc') { 
						$values = array_slice($values, -$limit, $limit);
						$lowerBound = $values[0][0];
					} else {
						$values = array_slice($values, 0, $limit);
						$upperBound = $values[count($values)-1][0];
					}
				}
			}
			
			// Done iterating, give the results.
			if ($direction === 'desc') { 
				$values = array_reverse($values);
			}
			foreach ($values as $v) {
				yield $v[1];
			}
		});
	}
	/**
	 * Sort: Sorts the iterator based on a scoring function
	 * 
	 * Your scoring function should return a score (any value)
	 * That value is compared to other values using greater than/lesser than.
	 * 
	 * For instance, to perform a natural case compare on a list of strings: 
	 * ->sort('strtolower')
	 * 
	 * @params callable scoringFunction
	 * @params ?string direction (asc/desc)
	 * @params ?int limit (falsy for unlimited)
	 * 
	 * @todo - support for array keys and dotnotation paths for scoring.
	 * 
	 * @test sort()
	 * @test sort(closure) 
	 * @test sort(function($a, $b) { }) - this will not work, should throw an error
	 * @test sort('strtolower') 
	 * @test sortAsc() - results are ordered neatly.
	 * @test sortDesc() - results are ordered in reverse
	 **/
	function sort(string|Closure $scoringFunction = null, $direction = 'asc', $limit = null) { 
		$scoringFunction = $this->createValueExtractor($scoringFunction);
		return $this->_sort($scoringFunction, $direction, $limit);
	}
	function sortAsc($scoringFunction = null, $limit = null) {
		return $this->sort($scoringFunction,'asc',$limit);
	}
	function sortDesc($scoringFunction = null, $limit = null) {
		return $this->sort($scoringFunction,'desc',$limit);
	}

	// @test toCsv() converts you tabular data to array.
	function toCsv($separator = ',', $enclosure = '"', $escape = "\\", $eol = PHP_EOL, $preventCsvInjection = true, $outputHeaders = false): static { 
		$args = [$separator, $enclosure, $escape, $eol];
		return $this->apply(function($iterator) use ($args, $preventCsvInjection, &$outputHeaders) {
			$handle = fopen('php://memory', 'r+');

			foreach ($iterator as $row) { 
				if ($outputHeaders) {
					$headers = array_map(fn($x) => ltrim($x, '= '), array_keys($row));
					fputcsv($handle, $headers, ...$args);
					$outputHeaders = false;
				}
				if ($preventCsvInjection) { 
					foreach ($row as $k=>$v) {
						if (str_starts_with(ltrim($v),'=')) { 
							$row[$k] = ltrim(ltrim($v),'=');
						}
					}
				}
				if (fputcsv($handle, $row, ...$args) === false) {
					continue;
				}
				rewind($handle);
				yield stream_get_contents($handle);
				ftruncate($handle, 0);
			}
		});
	}

	function tee($file, $options = []) { 
		$this->result = call_user_func(function($iterator) use ($file, $options) { 
			$handle = fopen($file, 'w+');
			$jsonlines = false;
			foreach ($iterator as $line) { 
				$oriLine = $line;
				if (is_object($line) || is_array($line)) { 
					$line = json_encode($line, JSON_UNESCAPED_SLASHES + JSON_THROW_ON_ERROR) . PHP_EOL;
					$jsonlines = true;
				} else {
					$line = rtrim($line) . PHP_EOL;
				}
				fputs($handle, $line);
				if ($options['realtimeYield'] ?? false) { 
					yield $line;
				}
			}
			fseek($handle, 0);

			if (isset($options['after'])) {
				call_user_func($options['after']);
			}

			$json_decode = function_exists("simdjson_decode") ? "simdjson_decode" : "json_decode";

			if (!($options['realtimeYield'] ?? false)) { 
				foreach (static::convertStream($handle, $this) as $line) { 
					if ($jsonlines) {
						yield $json_decode($line, true);
					} else {
						yield $line;
					}
				}
			}
		}, $this->result);
		return $this;
	}

	// @fixme - Work in progress.
	// semantics zouden gelijk moeten zijn aan grep.
	function grep($term, $options = [], $invert = false): static { 
		return $this->filter(function($line) use ($term, $invert) {
			if (!is_string($line)) { 
				$line = json_encode(array_values($line));
			}
			$match = preg_match('/'.preg_quote($term,'/').'/i', $line);
			return $invert ? !$match : $match;
		});
	}

	function fromJsonlines() { 
		$this->result = call_user_func(function($result) { 
			$json_decode = function_exists("simdjson_decode") ? "simdjson_decode" : "json_decode";
			foreach ($result as $id => $r) { 
				if (is_string($r) && ($r=trim($r)) && (substr($r, 0, 1) === '{' || substr($r, 0, 1) === '[')) {
					try { 
						$obj = $json_decode($r, true);
					} catch (\Throwable $e) {

						throw new \Exception("fromJsonlines: invalid jsonline: " . $e->getMessage() . ' with object `'.$r.'`');
					}
					yield $obj;
				} else if ($r) {
					yield $r;
				}
			}
		}, $this->result);

		return $this;
	}

	function toJsonlines() { 
		$this->result = call_user_func(function($result) { 
			foreach ($result as $r) { 
				yield json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
		}, $this->result);
        return $this;
	}
	
	// @todo - fromCsv()
	// @todo - fromJsonlines()
	// @todo - toJsonlines()
	// @todo - fromJson()
	// @todo - toJson() 

	private function nice_number($n, $scales = ['','K','M','G'], $factor = 1000) {
		$scale = array_shift($scales);
		$precision = 0;
		while ($n > (0.6*$factor) && count($scales) > 0) {
			$n = $n / $factor;
			$scale = array_shift($scales);
			$precision = $factor > 100 ? 2 : 0;
		}
		return number_format($n, $precision) . ' ' .$scale;
	}

	private function nice_mb($n, $scales = ['','KB','MB','GB'], $factor = 1024) {
		return $this->nice_number($n, $scales, $factor);
	}


	// @test static::magicStrings() will control the behaviour of __toString()
	static protected bool $magicStrings = false;
	static function magicStrings($enabled = false) { 
		static::$magicStrings = $enabled;
	}
	
	/**
	 * Output buffering 
	 * @param Closure|Traversable $function
	 * 
	 * @test ob(closure) - captures your output so you can chain operations afterwards
	 * @test ob(closure) - is lazy, executes only when the chain is executed.
	 */
	static function ob($function, $options = []): static {
		$options['chunk_size'] ??= 64*1024;
		$options['file'] ??= 'php://temp'; // niet php://memory, dan wordt alsnog alles in geheugen geladen.

		return chain(function ($chain) use ($function, $options) {
			if (is_resource($options['file'])) {
				$stream = $options['file'];
			} else { 
				touch($options['file']);
				$stream = fopen($options['file'],'w+');
			}

			$bytes = $writes = 0;
			ob_start(function ($chunk) use ($stream, &$bytes, &$writes) { 
				$writes++;
				$bytes += strlen($chunk);
				fputs($stream, $chunk);
			}, $options['chunk_size']);

			try { 
				if ($function instanceof Traversable) {
					foreach ($function as $result) {
						echo $result;
					}
				} else { 
					$function();
				}
			} catch (\Exception $e) { 
				throw $e;
			} finally { 
				ob_end_clean();
			}
			$chain->stats['Output buffer'] = sprintf(
				"%s, %s chunks (%s)",
				$chain->nice_mb($bytes), 
				$chain->nice_number($writes),
				str_replace(['.00',' '],'', $chain->nice_number($options['chunk_size']))
			);
			@rewind($stream);
			yield from static::convertStream($stream, $chain);
		});
	}

	static function cat(string|\SplFileInfo $file, $mustExist = true) {
		if ($file instanceof \SplFileInfo) {
			$file = $file->getRealPath();
		}

		if ($mustExist === false && (!$file || !file_exists($file))) { 
			return new static([]);
		}

        $obj = new static(fopen($file,'r'));
		$obj->stats['file'] = $file;
		return $obj;
    }
	
	static function glob($directory) { 
		return new static(new GlobIterator($directory));
	}




	// @test getStats() - i can receive useful info about the last operation.
	function getStats($key = null) { 
		if ($key) {
			return $this->stats[$key] ?? null;
		} else {
			return $this->stats;
		}
	}

	// usage: chain::record()..... ->export()
	static function record(...$args) { 
		return new LazyChain($args, static::class);
	}

	function fromCsv(string $separator = ',', string $enclosure = '"', string $escape = '\\', bool $interpretFirstLineAsHeaders = true): static { 
		// auto detect
		$this->apply(function($iterator) use ($separator, $enclosure, $escape) { 
			$buffer = [];
			$chars = [',' => [], ';' => [], "\t" => [],'|' => []];

			foreach ($iterator as $i) { 
				foreach ($chars as $c => $count) { 
					$chars[$c][] = substr_count($i, $c);
				}	
				$buffer[] = $i;
				if (count($buffer) > 50) { 
					break; 
				}
			}

			$chars = array_map('array_unique', $chars);
			$candidates = array_filter($chars, function($i) { 
				if (count($i) === 1 && reset($i) > 0) { 
					return true;
				}
				return false;
			});

			uasort($candidates, function($a,$b) {
				return count($a) <=> count($b);
			});

			$separator = array_key_first($candidates);			

			foreach ($buffer as $b) { 
				
				yield !$separator ? $b : str_getcsv($b, $separator, $enclosure, $escape);
			}
			if ($iterator->valid()) { 
				foreach (new NoRewindIterator($iterator) as $i) { 
					yield !$separator ? $i : str_getcsv($i, $separator, $enclosure, $escape);
				}
			}
		});

		// $this->buffer(50);

		// $this
		// 	->map(fn($line) => str_getcsv($line, $separator, $enclosure, $escape))
		// ;

		if ($interpretFirstLineAsHeaders) { 
			return $this->apply(function($iterator) { 
				$headers = $iterator->current();
				$iterator->next();
				if ($iterator->valid()) { 
					foreach (new \NoRewindIterator($iterator) as $line) {
						yield array_combine($headers, $line);
					}
				}
			});
		} else {
			return $this;
		}
	}

	/**
	 * page
	 */
	function page(int $page, int $pageSize = 100) { 
        $skip = max(0, ($page-1)*$pageSize);

        return $this
            ->skip($skip)
            ->head($pageSize);
    }


	protected function createValueExtractor(string|Closure $column = null): Closure {
		if ($column === null) {
			// identity
			$column = static function ($x) { return $x; };
		} else if (is_string($column)) {
			$column = static function($record) use ($column) { 
				return match(true) {
					is_object($record) => $record->$column,
					is_array($record) => $record[$column],
					default => $record
				};
			};
		}
		return $column;
	}



	function greatest(string|Closure $column = null) {
		$getValue = $this->createValueExtractor($column);
		$greatest = [PHP_INT_MIN, null];
		foreach ($this->getIterator() as $record) {
			$valueToCompare = $getValue($record); 
			if ($valueToCompare > $greatest[0]) {
				$greatest = [$valueToCompare, $record];
			}
		}
		return $greatest[1];
	}

	function least(string|Closure $column = null) {
		$getValue = $this->createValueExtractor($column);
		$least = [PHP_INT_MAX, null];
		foreach ($this->getIterator() as $record) {
			$valueToCompare = $getValue($record); 
			if ($valueToCompare < $least[0]) {
				$least = [$valueToCompare, $record];
			}
		}
		return $least[1];
	}


	/**
	 * nest - nest or group by a set of keys
	 * 
	 * this is a terminal function, it unwinds the iterator and gives you the resulting 
	 * nested array.
	 * 
	 * based on joshua's `supernest` function.
	 * 
	 * warning: Less memory efficient
	 */
	function nest(string|array $nestingKeys, $value = null): array { 
		$result = array();
        $nestingKeys = is_array($nestingKeys) ? $nestingKeys : array_filter([$nestingKeys]);
		
		foreach ($this->getIterator() as $e) {
			try { 
				$e = (array)$e;
				$ref = &$result;
				foreach ($nestingKeys as $s) {
					$ref = &$ref[$e[$s]];
				}
				if ($value !== null) {
					$ref = $e[$value] ?? null;
				} else {
					$ref[] = $e;
				}
			} catch (\Exception $ex) { 
				throw new \Exception($ex->GetMessage() . "\nLast item: " . print_r($e, true));
			}
		}
		return $result;
	}

	/**
	 * A more simplistic, but memory efficient nester.
	 */
	function lazyNest(string $key) { 
		$buffer = [];
		$yieldedKeys = [];

		foreach ($this->getIterator() as $row) {
			if (!$row[$key]) {
				continue;
			}

			if (!isset($buffer[$row[$key]])) { 
				foreach ($buffer as $bk => $b) { 
					$yieldedKeys[$bk] = $key;
					yield $bk=>$b;
				}

				$buffer = [];
				$buffer[$row[$key]] = [];
			}

			$buffer[$row[$key]][] = $row;
		}

		yield from $buffer;
	}



	function prepend(iterable $itemsToPrepend) { 
		return $this->apply(function($iterator) use ($itemsToPrepend) {
			yield from $itemsToPrepend;
			yield from $iterator;
		});
	}

	/**
	 * chain::prepare() to prepare/group certain action
	 * this can be applied to an existing chain via apply.
	 */
	static function prepare() {
		return new class implements PreparedChain { 
			private $calls;
			function __call($method, $args) { 
				$this->calls[] = [$method, $args];
				return $this;
			}
			function apply(Chainable $chain) { 
				foreach($this->calls as $c) {
					list($method, $args) = $c;
					$chain = $chain->$method(...$args);
				}
				return $chain;
			}
		};
	}


	static function withFeatures(string ...$traits): \Closure { 
		return function ($chainConstructorArg) use ($traits) { 
			if (!$chainConstructorArg) { 
				throw new \InvalidArgumentException('Should supply arg #0');
			}
			return eval('return new class($chainConstructorArg) extends ' . static::class . ' {' 
				. join("\n", array_map(fn($trait) => "use $trait;", $traits)) .
			'};');
		};
	}

	function union(iterable $dataset): static { 
		return $this->apply(function($iterator) use ($dataset) {
			yield from $iterator;
			yield from $dataset;
		});
	}

	function buffer(?int $howMany = null) {
		$buffer = [];
		foreach ($this->result as $key=>$value)  {
			$buffer[$key] = $value;
			if ($howMany !== null && count($buffer) >= $howMany) { 
				break;
			}
		}
		return $this->apply(function($iterator) use ($buffer) { 
			yield from $buffer;
			yield from $iterator;
		});
	}

	function when($condition, Closure $function, Closure $otherwise = null) { 
        $result = null;
        if ($condition) {
            $result = call_user_func($function, $this);
        } else {
            if ($otherwise) { 
                $result = call_user_func($otherwise, $this);
            }
        }

        if ($result instanceof Chainable) {
            return $result;
        }

        return $this;
    }


	/**
	 * Its not memory efficient per se.
	 */
	function reverse() { 
		return $this->apply(function($iterator) {
			yield from array_reverse(iterator_to_array($iterator));
		});
	}
}

class LazyChain implements Chainable { 
	protected $constructorArgs = [];
	protected $constructor = null;
	protected $calls = [];

	function __construct(array $args, $class = Chain::class) {
		$this->constructorArgs = $args;
		$this->constructor = $class;
		$this->calls = [];
	}

	private function _replayOnChainable($instance = null) {
		if ($instance == null) { 
			$class = $this->constructor;
			$instance = new $class(...$this->constructorArgs);
		}
		foreach ($this->calls as $call) { 
			list($method, $args) = $call;
			$instance->$method(...$args);
		}

		return $instance;
	}

	function __call($method, $args) {
		
		if (method_exists(StandardChainTerminatorsTrait::class, $method)) { 
			$instance = $this->_replayOnChainable();
			return $instance->$method(...$args);
		}

		// keep recording.
		$this->calls[] = [$method, $args];
	}


	// Finish the lazy chain.
	function getIterator() { 
		yield from $this->_replayOnChainable();
	}

	// @todo - export()
}