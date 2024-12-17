<?php

namespace Flux\Framework\Chain;


trait CacheTrait { 

	private string $cacheFileInUse;

	function cache($expires, ...$args): self {
		$args = func_get_args();
		$options = [];
		if (is_array($args[count($args)-1])) {
			$options = &$args[count($args)-1];
		} 
		$expires = is_array($expires) ? $options['expires'] : $expires;
		$shouldRefreshCache = $options['refreshCache'] ?? false;
		unset($options['refreshCache']); // so it wont affect the id.
		$shouldReverse = $options['reverse'] ?? false;

        $expireSeconds = null;
        if (is_int($expires)) {
            $expireSeconds = $expires;
        } else if (preg_match('~([0-9\.]+)\s*((?<s>s|sec|seconds?)|(?<h>h|hours?)|(?<m>m|min|minutes?)|(?<d>d|days?))~i', $expires, $match)) {
            if ($match['s'] ?? false)
                $expireSeconds = intval($match[1]);
            if ($match['m'] ?? false)
                $expireSeconds = intval($match[1] * 60);
            if ($match['h'] ?? false)
                $expireSeconds = intval($match[1] * 3600);
            if ($match['d'] ?? false)
                $expireSeconds = intval($match[1] * 3600 * 24);
        }
        
        if (!is_numeric($expireSeconds)) { 
            throw new \InvalidArgumentException('Invalid expire seconds `'.$expires.'`');
        }

		/**
		 * Caching: We need to know when we need to invalidate the cache.
		 * This is done by supplying some sort of state. Include (a checksum of) all
		 * the inputs we need to be reactive to and be done with it.
		 * 
		 * example: cache('1h', ['state' => md5_file('some source file')])
		 * 
		 * You can also tell cache where to store the cache file by supplying file via options.
		 * 
		 * cache('1h', ['file' => '/path/to/save']).
		 */
		
		if (preg_grep('/state|watch|track|arguments|params|args|id/', array_keys($options))) {
			$cacheId = substr(sha1(json_encode($args)),0,12);
		} else { 
			$callerFrame = (new \Exception)->getTrace()[0];
			// you may set zend.exception_ignore_args to false for improved security/performance.
			unset($callerFrame['args']);
			$cacheId = substr(sha1(join(' ', $callerFrame).json_encode($args)),0,12);
		}



		$fullPath = $options['file'] ?? '/tmp/chain-caches/' . $cacheId . '.bin';
		$this->cacheFileInUse = $fullPath;
		$options['file'] ??= $fullPath;

        if (!is_dir(dirname($fullPath))) {
			mkdir(dirname($fullPath), 0777, true);
            if (!is_writable(dirname($fullPath))) {
                throw new \Exception('Cannot create directory ' . $fullPath);
            }
		}
		
		$convertStreamFunction = fn(...$args) => static::convertStream(...$args);
		if ($shouldReverse) { 
			$convertStreamFunction = fn(...$args) => static::convertStreamReverse(...$args);
		}
        if (file_exists($fullPath)) { 
			$age = time() - filemtime($fullPath);
			$jsonlines = $options['jsonlines'] ?? false;
			if (is_link($fullPath)) { 
				$link = readlink($fullPath);
				if (strpos($link, '.jsonl')!==false) { 
					$jsonlines = true;
				}
			} 

			$cacheIsStillFresh = $age < $expireSeconds;

			$mayUseCache = $cacheIsStillFresh && !$shouldRefreshCache;

            // dd("Age = $age, expires = $expireSeconds");


            if ($mayUseCache) {
				
				// dd("USE CACHE");
				
				$handle = fopen($fullPath,'r');

				$this->result = $convertStreamFunction($handle, $this);

				if ($jsonlines) {
					$this->fromJsonlines();
				}
				if ($options['mark_cached'] ?? false) { 
					$mark_cache_field = is_string($options['mark_cached']) ? $options['mark_cached'] : 'cached';
					$this->map(function($i) use ($mark_cache_field) {
						$i[$mark_cache_field] = true;
						return $i;
					});
				}
		
				$this->stats['From cache'] = $fullPath . ', '.$this->nice_mb(filesize($fullPath)).' (age: ' . $this->nice_number($age,['s','m','h'],60) . ')';
				//error_log($this->stats['From cache']);
                return $this;
            }
		}
				
        return $this->apply(function ($iterator) use ($fullPath, $options, $convertStreamFunction) {
			// Eerst naar een busy file schrijven
			$busyFile = $options['file'].'.'.uniqid().'.busy';
			$handle = fopen($busyFile, 'w+');
			
			$this->stats['To cache'] = $fullPath;
			$jsonlines = $options['jsonlines'] ?? false;
			foreach ($iterator as $line) {
				if (!is_scalar($line)) {
					$jsonlines = true;
					$line = json_encode($line, JSON_UNESCAPED_SLASHES + JSON_THROW_ON_ERROR);
				}
				$line = rtrim($line); 
				if ($line) { 
					fputs($handle, $line . PHP_EOL);
				}
			}

			// var_dump($jsonlines);

			error_Log('Writing to '. $options['file']);
			
			fflush($handle);
			// Als alles succesvol was, dan pas wegschrijven.
			rename($busyFile, $options['file']);
			if ($jsonlines) { 
				rename($fullPath, "$fullPath.jsonl");
				symlink("$fullPath.jsonl", $fullPath);
				$fullPath = $fullPath . '.jsonl';
				$this->stats['To cache'] = $fullPath;
			} else if (!$jsonlines && $options['file'] !== $fullPath) { 
				error_log($options['file'] . ' != ' . $fullPath);

				symlink(realpath($options['file']), $fullPath);
			}
			fseek($handle,0);
			if ($jsonlines) { 
				$json_decode = function_exists("simdjson_decode") ? "simdjson_decode" : "json_decode";

				foreach ($convertStreamFunction($handle, $this) as $line) {
					$char = substr($line, 0, 1);
					if ($char === '{' || $char === '[') {
						yield $json_decode($line, 1);
					}
				}
			} else {
				yield from $convertStreamFunction($handle, $this);
			}
		});
        return $this;
    }

	function getCacheFile(): ?string {
		if (isset($this->cacheFileInUse)) { 
			return $this->cacheFileInUse;
		} else if (isset($this->stats['file'])) {
			return $this->stats['file'];
		} else {
			return null;
		}
	}
	function getCacheLineCount(): int {
		$file = $this->getCacheFile();
		if ($file) { 
			return chain::countLines($file);
		}
		return -1;
	}
	function reread(): static { 
		$file = $this->getCacheFile();
		if ($file) { 
			return static::cat($file)->fromJsonlines();
		} else {
			return $this;
		}
	}

}


