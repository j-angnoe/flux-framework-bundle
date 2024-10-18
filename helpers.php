<?php

/**
 * Standard functions that have been taken from 
 * univas and have been around for a really really long time
 * Probably pre 2010 :-D
 */

// Taken from univas
if (!function_exists('firstval')) {
    function firstval($a)
    {
        if (func_num_args() > 1) {
            $a = func_get_args();
        } else {
            $a = toa($a);
        }
        foreach ($a as $y) {
            if ($y || (is_scalar($y) && "$y" > '')) {
                return $y;
            }
        }
    }
}
if (!function_exists('lastval')) {
    function lastval($a)
    {
        if (func_num_args() > 1) {
            $a = func_get_args();
        } else {
            $a = toa($a);
        }
        return end($a);
    }
}

if (!function_exists('ensure_dir')) { 
    function ensure_dir(string $path, int $permissions = 0777): string { 
        if (!is_dir($path)) { 
            if (!mkdir($path, $permissions, true)) { 
                throw new \Exception('Could not create directory ' . $path);
            }
        }
        return $path;

    }
}

if (!class_exists('InspectException')) { 
    class InspectException extends Error { 
        private $data;
        function __construct($data) {
            $this->data = $data;
        }

        function getDescription() { 
            if (is_string($this->data)) { 
                return 'inspect> string(' . strlen($this->data).') `' . htmlentities($this->data).'`';
            } else if (!$this->data) {
                return 'inspect> value is ' . htmlentities(var_export($this->data, true));
            } else { 
                return htmlentities(print_r($this->data, true));
            }
        }

        function __toString() {
            return '<pre>'.$this->getDescription();
        }
    }
}

if (!function_exists('dd')) {
    // Throw an exception with the data so it will popup.
    // @todo - Make this more sophisticated.
    
    function dd($data) {
        throw new InspectException($data);
    }
}


/** 
 * Taken from univas
 * Make sure argument is an array **/
if (!function_exists('to_array')) {
    function to_array($a)
    {
        if (is_object($a) && $a instanceof Traversable) {
            $r = array();
            foreach ($a as $x => $y) {
                $r[$x] = $y;
            }
            return $r;
        } else {
            return is_array($a) ? $a : ($a ? array($a) : array());
        }
    }
}

/** 
 * Taken from univas
 * alias for to_array 
 **/
if (!function_exists('toa')) {
    function toa($a)
    {
        return to_array($a);
    }
}

/** 
 * Taken from univas
 * Create a nested array multiple times
 * @param $data data to be supernested
 * @param $supernest an array of which keys need to used for nesting..
 *
 * @warning this will convert objects to arrays.
 * Example: supernest($users, array('function', 'gender')) gives you
 * array(
 * 	'Programmer' => array(
 *			'male' => array(
 *				0 => $programmer1,
 *				1 => $programmer2
 *			),
 *			'female' => array($programmer3, $programmer4),
 *		),
 *		'Sales Representative' => array(
 * 			'male' => array(), 
 *      'female'=>array($sales1)
 *      )
 *	)
 **/
if (!function_exists('supernest')) {
    function supernest($data, $supernest)
    {
        $result = array();
        $supernest = toa($supernest);
        foreach ($data as $e) {
            $e = (array)$e;
            $ref = &$result;
            foreach ($supernest as $s) {
                $ref = &$ref[$e[$s]];
            }
            $ref[] = $e;
        }
        return $result;
    }
}

/** 
 * Inverse of supernest, this can convert a supernested array to 
 * a two-dimensional 
 **/
if (!function_exists('unsupernest')) {
    function unsupernest($data, $nest, $writeValue = true)
    {
        if (!$nest) {
            return $data;
        }
        $result = array();
        $field = array_shift($nest);
        $fn = __FUNCTION__;

        foreach ($data as $value => $rows) {
            //debug: pr ("$field = $value");
            foreach ($fn($rows, $nest) as $r) {
                if ($writeValue) $r[$field] = $value;
                $result[] = $r;
            }
        }
        return $result;
    }
}

/**
 * Nest an array on a certain value, this is the
 * 1-time supernest
 * @warning this will convert objects to arrays.
 **/
if (!function_exists('makeNested')) {
    function makeNested($data, $nestOn)
    {
        $result = array();
        foreach ($data as $value) {
            $value = (array)$value;
            if (!isset($result[$value[$nestOn]])) {
                $result[$value[$nestOn]] = array();
            }
            $result[$value[$nestOn]][] = $value;
        }

        return $result;
    }
}

/** 
 * Taken from univas
 * preg_grep based on keys, returns an array with keys and their values
 **/
if (!function_exists('preg_grep_keys')) {
    function preg_grep_keys($pattern, $input, $flags = 0)
    {
        $keys = preg_grep($pattern, array_keys($input), $flags);
        $vals = array();
        foreach ($keys as $key) {
            $vals[$key] = $input[$key];
        }
        return $vals;
    }
}

/**
 * Returns the matched result
 * @usage get_preg_match($subject, $pattern)
 */
if (!function_exists('get_preg_match')) {
    function get_preg_match($subject, $pattern)
    {
        if (preg_match($pattern, $subject, $match)) {
            return $match;
        }
        return [];
    }
}

/** ymdtotime = date(y-m-d, strtotime(x,y)) **/
if (!function_exists('ymdtotime')) {
    function ymdtotime($relative, $timestamp = null, $format = 'Y-m-d')
    {
        $x = $relative;
        $y = $timestamp;

        if (func_num_args() == 0) {
            return date($format);
        } elseif (func_num_args() == 1) {
            $x = is_numeric($x) ? $x : ($x !== '0000-00-00' ? strtotime($x) : false);
            return date($format, $x);
        } else {
            if ($y) {
                // Infinity handling.
                if (substr($y,0,10) === '9999-12-31') {
                    return $y;
                }
                $y = is_numeric($y) ? $y : ($y !== '0000-00-00' ? strtotime($y) : false);
            }
            
            return date($format, strtotime($x, $y));
        }
    }
}

/** ymdtointerval('2012-01-01', '+1 week'); **/
if (!function_exists('ymdinterval')) {
    function ymdinterval($a, $b)
    {
        $a = is_numeric($a) ? $a : ($a !== '0000-00-00' ? strtotime($a) : false);
        $b = ymdtotime($b, $a);
        $r = array(date('Y-m-d', $a), $b);
        sort($r);
        return $r;
    }
}


if (!function_exists('read_json')) { 
    function read_json($file, $asObjects = false) {
        return json_decode(file_get_contents($file), $asObjects ? 0 : 1);
    }
}

if (!function_exists('write_json')) { 
    function write_json($file, $data) {
        return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE));
    }
}

