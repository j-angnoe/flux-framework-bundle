<?php
namespace Flux\Framework\Utils;

use ArgumentCountError;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;

class IoC implements ContainerInterface {
    private array $services = [];

    function get(string $id): mixed 
    {
        return $this->services[$id];
    }   

    function clone(): static { 
        $clone = clone($this);
        return $clone;
    }

    function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    function set(string $id, object $serviceOrFactory): void { 
        if ($serviceOrFactory instanceof \Closure) {
            $this->services[$id] = $serviceOrFactory;
            return;
        }
        $this->services[$id] = fn() => $serviceOrFactory;
    }

    function registerDecorator($decoratorClass, $decorateeClass) { 
        $this->services[$decoratorClass] = eval('return fn('.$decorateeClass.' $x) => new '.$decoratorClass.'($x);'); 
    }

    public function call(callable $function, $positionalArgs = [], $namedArgs = []): mixed { 
        return $this->_call($function, $positionalArgs, $namedArgs, 0);
    }
    public function prepareArgs(callable|ReflectionFunctionAbstract $function, &$positionalArgs = [], &$namedArgs = []): array { 
        return $this->_prepareArgs($function, $positionalArgs, $namedArgs, 0);
    }


    private function _call(callable $function, &$positionalArgs, &$namedArgs, int $recursion): mixed { 
        $args = $this->_prepareArgs($function, $positionalArgs, $namedArgs, $recursion);
        return $function(...$args);
    }
    private function _prepareArgs(callable|ReflectionFunctionAbstract $function, &$positionalArgs, &$namedArgs, int $recursion): array { 
        // resolve arguments
        if ($function instanceof ReflectionFunctionAbstract) { 
            $refl = $function; 
        } else { 
            $refl = new ReflectionFunction($function);
        }

        $resolvedArgs = [];
        foreach ($refl->getParameters() as $paramIdx => $param) { 
            $paramName = '$'.$param->getName();
            $type = $param->getType();
            $requestedType = $type instanceof \ReflectionNamedType ? $type->getName() : '(no-type)';
            // echo "Function request parameter `$paramName` of type `$requestedType`\n";

            if (isset($namedArgs[$paramName])) {
                $resolvedArgs[$paramIdx] = $namedArgs[$paramName];
                continue;
            }
            if (isset($namedArgs[$requestedType])) {
                $resolvedArgs[$paramIdx] = $namedArgs[$requestedType];
                continue;
            }
            
            if (isset($this->services[$requestedType])) { 
                if ($recursion > 10) { 
                    throw new \Exception('Max recursion occured here.');
                }
                // error_log('Resolving requestedType ' . $requestedType);
                $namedArgs[$requestedType] = $this->_call($this->services[$requestedType], $positionalArgs, $namedArgs, $recursion + 1);
                $resolvedArgs[$paramIdx] = $namedArgs[$requestedType];
                continue;
            }
            if (count($positionalArgs) > 0) { 
                $resolvedArgs[$paramIdx] = array_shift($positionalArgs);
            } else if ($param->isOptional()) {
                $resolvedArgs[$paramIdx] = $param->getDefaultValue();
            } else { 
                throw new ArgumentCountError('Missing argument #'.$paramIdx . ' ('.$paramName.' of type `'.$requestedType.')');
            }
        }
        return $resolvedArgs;
    }
}