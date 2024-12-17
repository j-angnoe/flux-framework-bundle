<?php
namespace Flux\Framework\DuckDB;

use Flux\Framework\Chain\Chain;
use Traversable;

class QueryBuilder implements \IteratorAggregate { 

    function __construct(private DuckDB $duckDb)
    {

    }

    private array $from = [];
    function from($from): static { 
        $this->from[] = $from;
        return $this;
    }   

    private array $fields = [];
    function select(array|string ...$fields): static {  
        $this->fields = array_merge_recursive($this->fields, $fields);
        return $this;
    }

    private array $limit;
    function limit(int ...$limit): static {
        $this->limit = $limit;
        return $this;
    }

    private ?int $offset = null;
    function skip(int $skip): static { 
        $this->offset = $skip;
        return $this;
    }

    /** @var array<Closure> $chainOperations */
    private array $chainOperations = [];
    function tail($tail): static { 
        $this->chainOperations[] = fn($chain) => $chain->tail($tail);
        return $this;
    }

    private function createSql() { 
        $sql = "SELECT ";
        if (empty($this->fields)) { 
            $sql .= " * ";
        } elseif ($this->fields) {
            $sql .= "   " . join(",\n   ", $this->fields);
        }

        $sql .= join("", array_map(fn($x) => "\nFROM $x", $this->from));
        
        if ($this->limit) {
            $sql .= "\nLIMIT " . join(',',$this->limit);
        }
        if ($this->offset) {
            $sql .= "\nOFFSET " . $this->offset;
        }

        // Autoload spatial when st_* functions are called.
        if (preg_match('~st_\w+\s*\(~i', $sql)) {
            $sql = "LOAD spatial;\n$sql";
        }

        return $sql;
    }

    private bool $hasRun = false;

    function getIterator(): Chain
    {
        $this->hasRun = true;
        $chain = $this->duckDb->query($this->createSql());
        foreach ($this->chainOperations as $fn) { 
            $fn($chain);
        }
        return $chain;
    }

    function toArray(): array { 
        return $this->getIterator()->toArray();
    }

}