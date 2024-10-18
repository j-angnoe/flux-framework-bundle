<?php

namespace Flux\Framework\Utils;

class ArrayUtils { 
    /**
     * Put in a deep array, receive a flattened array
     * 
     * $input = ['a' => ['b' => 'c']]
     * $output = ['a.b' => 'c'];
     *
     */
    static function flattenArray(iterable $array, string $prefix = '', string $separator = '.'): array {
        $result = array();
        foreach ($array as $key => $value) {
            $newKey = $prefix . ($prefix ? $separator : '') . $key;
            if (is_array($value) || is_object($value)) {
                $result = array_merge($result, static::flattenArray((array)$value, $newKey, $separator));
            } else {
                $result[$newKey] = $value;
            }
        }
        return $result;
    }

    /**
     * Take a previously flattened array and convert it back to a nested array.
     * $input = ['a.b' => 'c'];
     * $output = ['a' => ['b' => 'c']]
     */
    static function unflattenArray($flattenedArray, $separator = '.') {
        $nestedObject = [];

        foreach ($flattenedArray as $key => $value) {
            $keys = explode($separator, $key);
            $current = &$nestedObject;

            foreach ($keys as $nestedKey) {
                if (!isset($current[$nestedKey])) {
                    $current[$nestedKey] = [];
                }
                $current = &$current[$nestedKey];
            }

            $current = $value;
        }

        return $nestedObject;
    }

    /**
     * Visit each value in a deep array.
     */
    static function walkPath(&$obj, $path, $callback = null, $context = [], $currentPath = '') { 
        $return = null;
        $callback ??= function($match) use (&$return) { 
            $return[] = $match;
        };
        $hideNonScalars = function($obj) { 
            $res = [];
            foreach ($obj as $key=>$value) { 
                if (is_scalar($value)) { 
                    $res[$key] = $value;
                }
            }
            return $res;
        };
        foreach ($obj as $key=>$value) {
            if ($key == $path) { 
                if (array_is_list($value)) { 
                    foreach ($value as $_key => $_value) { 
                        $callback($obj[$key][$_key], $context);
                    }            
                } else {
                    $callback($value, $context);
                }
            } else if (array_is_list($value)) { 
                foreach ($obj[$key] as $idx => $item) { 
                    $myContext = $context + [$key => $hideNonScalars($obj[$key][$idx])];
                    static::walkPath($obj[$key][$idx], $path, $callback, $myContext, $currentPath .'.'.$key);
                } 
            } else if (is_array($value)) { 
                // echo 'Walk ' . $key . "\n";
                // print_r($getNonScalars($obj[$key]));
                $myContext = $context + [is_numeric($key) ? 'root' : $key => $hideNonScalars($obj[$key])];
                static::walkPath($obj[$key], $path, $callback, $myContext, $currentPath .'.'.$key);	
            }
        }
        return $return;
    }

    /**
     * array_pop + the possibility to `pop` a specific key, it removes the key
     * from the array and returns the result ;-)
     */
    static function pop(&$array, $key = null) { 
        if ($key) { 
            $return = $array[$key] ?? null;
            unset($array[$key]);
            return $return;
        } else {
            return array_pop($array);
        }
    }


    static function checksum(array $array, ...$fields) { 
        if (count($fields) === 1 && is_array($fields[0])) {
            $fields = $fields[0];
        }
        if (empty($fields)) {
            $fields = array_keys($array);
        }   
        $extract = [];
        sort($fields);
        foreach ($fields as $f) { 
            $extract[] = $array[$f] ?? null;
        }

        return sha1(json_encode($extract));
    }

    /**
     * Insert a value into a deep/nested array given the dot-seperated path
     */
    static function insert(array &$array, string $path, mixed $value): array {
        // Split the dot-separated path into individual keys
        $keys = explode('.', $path);
        
        // Reference to the array, we will iterate through and modify it
        $current = &$array;
        
        // Loop through all keys, creating nested arrays if necessary
        foreach ($keys as $key) {
            // If the key doesn't exist or is not an array, create an empty array
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            // Move deeper into the array
            $current = &$current[$key];
        }
        
        // Finally, set the value at the deepest point
        $current = $value;
        return $array;
    }

    /**
     * Replace all non-alphanumeric characters in the keys
     * works on nested arrays.
     */
    static function canonicalizeKeys(array $array, bool $lowercase = true): array { 
        $new = [];
        foreach ($array as $a => $b) { 
            if (is_array($b)) { 
                $b = static::canonicalizeKeys($b, $lowercase);
            }
            $a = static::canonicalizeKey($a, $lowercase);
            $new[$a] = $b;
        }
        return $new;
    }   
    static function canonicalizeKey(string $key, bool $lowercase = true): string { 
        $freshKey = str_replace('__','_', preg_replace('/[^a-z0-9_]+/i', '_', $key));
        return $lowercase ? strtolower($freshKey) : $freshKey;
    }
}