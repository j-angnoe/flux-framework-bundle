<?php

namespace Flux\Framework;

use Flux\Framework\Chain\BackgroundCommand;
use Flux\Framework\Chain\Shell;

class BackgroundPHP { 
    private ?string $preamble;

    function __construct() {
    }
    function setPreamble(string $preamble) { 
        $this->preamble = $preamble;
    }
    static function getClosureSource(\Closure $c): array {
        // check our closures-lookup map first.
        $r = new \ReflectionFunction($c);

        $source = $r->getFilename();
        if (stripos($source, "eval()'d code")) { 
            throw new \Exception('Eval\'d closure cannot be sourced.');
        } else {
            $lines = file($r->getFileName());
        }
        
        $uses = "";
        $using = false;
        foreach ($lines as $l) {
            if (!$using && str_starts_with($l, 'use ')) { 
                $using = true;
            }
            if ($using) {
                if (str_contains($l, ';')) {
                    $uses .= strstr($l, ';', true).';'."\n";
                    $using = false;
                } else {
                    $uses .= $l;
                }
            }
        }

        $body = [];
        $firstLine = $lines[$r->getStartLine()-1];
        $pieces = preg_split('~function\s*\(~', $firstLine);
        $str = 'function('.end($pieces) . "\n";

        for($l = $r->getStartLine(); $l < $r->getEndLine(); $l++) {
            if (!isset($lines[$l])) { 
                dd(['Cant find line #'.$l . ' in '. PHP_EOL . $source, $lines, $r->getStartLine(), $r->getEndLine()]);
            }
            $body[] = $lines[$l];
        }
            
        $body[count($body)-1] = strstr($body[count($body)-1],'}',true)."\n".'};';
        $str = $str . join("", $body);

        return [$str, $uses];
    }

    private function getPreamble() { 
        if ($this->preamble ?? false) {
            return $this->preamble;
        }
        
        // Default symfony 7 preamble.
        $autoload = (preg_grep('~vendor/autoload\.php~', get_included_files()));
        $autoload = reset($autoload);
        
        $kernelClass = (preg_grep('~Kernel$~', get_declared_classes()));
        $kernelClass = reset($kernelClass);
        
        return <<<PHP
            namespace tmp;
            require_once "$autoload";
            use $kernelClass;
            \$kernel = new Kernel('debug', true);
            \$kernel->boot();
            PHP;
    }


    static function fullySerializeClosure(\Closure $closure): string { 
        $source = '';
        $refl = new \ReflectionFunction($closure);
        
        [$fnSource, $uses] = static::getClosureSource($closure);
        $source .= "$uses";
        
        $serializeVariables = $refl->getStaticVariables();
        foreach ($serializeVariables as $varname => $value) {
            try { 
                if (is_object($value)) {
                    $source .= '$' . $varname . ' = unserialize(' . var_export(serialize($value), true) . ');' . PHP_EOL;
                    continue;
                }
            } catch (\Throwable $e) { 
                throw new \Exception($e->getMessage() . ' at variable $'.$varname . ' of type ' . get_class($value));
            }
            $source .= '$' . $varname . ' = ' . var_export($value, true) . ';' . PHP_EOL;
        }
        
        $source .= "\n" . 'return ' . rtrim($fnSource, "\n;") . ';';

        return $source;
    }
    
    function dispatch(\Closure $closure): BackgroundCommand { 
        // @fixme - als de source + closure static vars te groot is dan 
        // faalt de command-line aanroep en kreeg je leeg scherm.
        $refl = new \ReflectionFunction($closure);
        
        [$fnSource, $uses] = $this->getClosureSource($closure);
        // $params = $refl->getParameters();
        
        $cwd = getcwd();
        if (file_exists($cwd . "/../vendor/autoload.php")) { 
            $cwd = dirname($cwd);
        }
        
        $source = '<?php ' . $this->getPreamble();
        if (str_contains($uses, '\Kernel')) { 
            $uses = str_replace('\Kernel','\Kernel2',$uses);
        }
        $source .= "$uses";
        
        $serializeVariables = $refl->getStaticVariables();
        foreach ($serializeVariables as $varname => $value) {
            try { 
                if (is_object($value)) {
                    $source .= '$' . $varname . ' = unserialize(' . var_export(serialize($value), true) . ');' . PHP_EOL;
                    continue;
                }
            } catch (\Throwable $e) { 
                throw new \Exception($e->getMessage() . ' at variable $'.$varname . ' of type ' . get_class($value));
            }
            $source .= '$' . $varname . ' = ' . var_export($value, true) . ';' . PHP_EOL;
        }
        
        $source .= 'call_user_func(' . rtrim($fnSource, "\n;") . ');';
        
        $tempnam = tempnam('/tmp/', 'background-php-');
        file_put_contents($tempnam, $source);
        
        // Will throw if a syntax error occurs.
        foreach (new Shell('php -l '. $tempnam) as $l) { }
        
        return (new Shell('cd ' . $cwd.'; php '.$tempnam))->dispatchBackgroundCommand();
    }


    function __invoke(\Closure $closure): BackgroundCommand { 
        return $this->dispatch($closure);
    }
}