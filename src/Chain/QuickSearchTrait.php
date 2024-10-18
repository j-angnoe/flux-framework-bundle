<?php

namespace Flux\Framework\Chain;

trait QuickSearchTrait {
    /**
     * Splits a string into include/exclude terms
     * Usually +whater -theother
     */
    static function strIncludesExcludes($term): array { 
        // Split words, but support quoted terms.
        // supports -, ! and + 
        $term = str_replace('-"','"-', $term);

        $terms = array_filter(str_getcsv(trim(preg_replace('~\s+~', ' ', $term . ' ""')), " "));

        $includes = $excludes = [];
        foreach ($terms as $t) {
            $char = substr($t,0,1);
            $lastChar  = substr($t,-1,1);
            if ($char === '-') {
                $excludes[] = substr($t, 1);
            } elseif ($lastChar === '!') {
                $excludes[] = substr($t, 0, -1);
            } else {
                $includes[] = $t;
            }
        }
        return compact('includes','excludes');
    }

    function quicksearch($search = ''): static {
        if (!$search || !trim($search)) {
            return $this;
        }
        $terms = static::strIncludesExcludes($search);
        $includes = [];
        $excludes = null;
        $fns = ['includes' => [], 'excludes' => []];
        foreach ($terms as $termCategory => $catTerms) {
            foreach ($catTerms as $termId => $t) { 
                if (preg_match('~(?<field>\w+)(?<operator>[:!<>=]+)(?<test>.+)~', $t, $match)) { 
                    $field = $match['field'];
                    $test = $match['test'];
                    $fns[$termCategory][] = match($match['operator']) {
                        '<' => static function($obj) use ($field,$test) {
                            return ($obj[$field]??null) < $test;
                        },
                        '<=' => static function($obj) use ($field,$test) {
                            return ($obj[$field]??null) < $test;
                        },
                        '>' => static function($obj) use ($field,$test) {
                            return ($obj[$field]??null) > $test;
                        },
                        '>=' => static function($obj) use ($field,$test) {
                            return ($obj[$field]??null) >= $test;
                        },
                        '==' => static function($obj) use ($field,$test) {
                            return strval($obj[$field]??null) === strval($test);
                        },
                        '!=' => static function($obj) use ($field,$test) {
                            return strval($obj[$field]??null) !== strval($test);
                        },
                        '=' => static function($obj) use ($field,$test) {
                            return strval($obj[$field]??null) === strval($test);
                        },
                        ':' => static function($obj) use ($field,$test) {
                            return strval($obj[$field]??null) === strval($test);
                        },
                        default => throw new \Exception('Unknown operator `'.$match['operator'].'`')
                    };
                    unset($terms[$termCategory][$termId]);
                }
            }
        }
        if ($terms['includes']) { 
            $includes = array_map(fn($x)=>str_replace('\*', '.*',preg_quote($x,'~')), $terms['includes']);
        }
        if ($terms['excludes']) { 
            $excludes = "(" . join("|", str_replace('\*', '.*', array_map(fn($x)=>preg_quote($x,'~'), $terms['excludes']))) . ")";
        }

        return $this->filter(function($obj) use ($includes, $excludes, $fns) { 
            $line = is_scalar($obj) ? $obj : json_encode(array_map(fn($x) => is_scalar($x) ? strval($x) : $x,array_values($obj)));

            // echo "SEARCH $search LINE = $line\n";
            $result = true;
            foreach ($includes as $i) { 
                if (substr($i,0,2) === '\+') { 
                    $result = $result || preg_match("~".substr($i,2)."~i", $line);
                } else { 
                    $result = $result && preg_match("~$i~i", $line);
                }
            }
            foreach ($fns['includes'] as $incFn) { 
                $result = $result && $incFn($obj);
            }
            if ($result && $excludes && preg_match("~$excludes~i", $line)) {
                // echo "EXCLUDE FOR $search LINE $line\n";
                return false;
            }
            foreach ($fns['excludes'] as $exFn) { 
                $result = $result && !$exFn($obj);
            }
            // echo "INCLUDE FOR $search LINE $line\n";
            return $result;
        }, keepKeys: false);
    }
}