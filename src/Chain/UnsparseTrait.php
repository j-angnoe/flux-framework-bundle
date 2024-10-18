<?php

namespace Flux\Framework\Chain;

trait UnsparseTrait {
    function unsparse() { 
        return $this
            ->fromJsonlines()
            ->apply(function($iterator) {
                $tmp = fopen('php://temp','rw');
                $keyUnit = [];
                foreach ($iterator as $i) { 
                    if (is_array($i)) { 
                        $keyUnit += $i;
                        fputs($tmp, json_encode($i).PHP_EOL);
                    }
                }
                $keys = array_keys($keyUnit);
                fseek($tmp, 0);
                
                yield from (new static($tmp))
                    ->fromJsonlines()
                    ->map(function($row) use ($keys) { 
                        $res = [];
                        foreach ($keys as $k) { 
                            $res[$k] = $row[$k] ?? null;
                        }
                        return $res;
                    });
        });
    }
} 

