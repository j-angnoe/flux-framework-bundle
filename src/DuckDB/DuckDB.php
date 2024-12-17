<?php

namespace Flux\Framework\DuckDB;

use Flux\Framework\Chain\Chain;
use Flux\Framework\Chain\Shell;
use SplFileInfo;

class DuckDB { 
    public ?string $dbFile;
    function __construct(string|null $dbFile = null) {
        if ($dbFile instanceof SplFileInfo) {
            $dbFile = $dbFile->getPathname();
        }
        $this->dbFile = $dbFile;
    }

    function from($source): QueryBuilder { 
        if (!isset($this)) { 
            return (new DuckDB)->from($source);
        }
        $query = new QueryBuilder($this);
        $query->from($source);
        return $query;
    }

    function query(string $sql, $mode = 'json'): Chain {
        [$mode, $fromFn] = match($mode) {
            'csv' => ['-csv','fromCsv', ''],
            'json' => ['-jsonlines','fromJsonlines'],
            default => throw new \Exception('Unknown mode `'.$mode.'`')
        };

        $command = new Shell('cd ?; duckdb ? %s -c ? ', dirname(realpath($this->dbFile)) ?: '', $this->dbFile ?: '', $mode, $sql);
        return (new Chain(fn() => yield from $command->getIterator()))->$fromFn();
    }
}   