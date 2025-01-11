<?php

namespace Flux\Framework\DuckDB;

use Flux\Framework\Chain\Chain;
use Flux\Framework\Chain\Shell;
use SplFileInfo;

class DuckDB { 
    public ?string $dbFile;

    function __construct(string|null $dbFile = null, private ?string $memoryLimit = '', private ?int $threads = null) {
        if ($dbFile instanceof SplFileInfo) {
            $dbFile = $dbFile->getPathname();
        }
        $this->dbFile = $dbFile;
    }

    function open(string $dbFile, ?string $memoryLimit = null, ?int $threads = null): static { 
        return new self($dbFile, $memoryLimit ?? $this->memoryLimit, $threads ?? $this->threads);
    }

    function from($source): QueryBuilder { 
        if (!isset($this)) { 
            return (new DuckDB)->from($source);
        }
        $query = new QueryBuilder($this);
        $query->from($source);
        return $query;
    }

    function exec(string $sql, $mode = 'json'): Shell {
        [$duckMode, $fromFn] = match($mode) {
            'csv' => ['-csv','fromCsv', ''],
            'json' => ['-jsonlines','fromJsonlines'],
            default => throw new \Exception('Unknown mode `'.$mode.'`')
        };

        $sqlPrefix = "SET temp_directory='/tmp/duckdb_swap';\n";
        $sqlPrefix .= "SET max_temp_directory_size='10GB';\n";

        if ($this->memoryLimit) { 
            $sqlPrefix .= "SET memory_limit=".escapeshellarg($this->memoryLimit).";\n";
        }

        if ($this->threads) { 
            $sqlPrefix .= "SET threads=".$this->threads.";\n";
        }
        
        $sql = $sqlPrefix . "\n" . $sql;

        $path = $this->dbFile ? dirname($this->dbFile) : '/tmp';

        return new Shell('cd ?; duckdb ? %s -c ? ', $path, $this->dbFile ?: '', $duckMode, $sql);
    }

    function query(string $sql, $mode = 'json'): Chain  {
        [$duckMode, $fromFn] = match($mode) {
            'csv' => ['-csv','fromCsv', ''],
            'json' => ['-jsonlines','fromJsonlines'],
            default => throw new \Exception('Unknown mode `'.$mode.'`')
        };

        $shell = $this->exec($sql, $mode);

        return (new Chain(fn() => yield from $shell->getIterator()))->$fromFn();

        $command = new Shell('cd ?; duckdb ? %s -c ? ', $path, $this->dbFile ?: '', $mode, $sql);
        return (new Chain(fn() => yield from $command->getIterator()))->$fromFn();
    }
}   