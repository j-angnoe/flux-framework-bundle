<?php

namespace Flux\Framework\Chain;

trait ShellTrait { 
    static function formatCommand(...$args): string {	
        return Shell::formatCommand(...$args);
	}

    private object $shell;

    /**
     * a simplified shell runner, more in tune to what we usually use it for.
     */
    static function shell(string $command, mixed ...$args): static { 
        $shell = new Shell($command, ...$args);
        return new static(fn() => yield from $shell->getIterator());
    }

    static function dispatchBackgroundCommand(string $command, mixed ...$args): BackgroundCommand { 
        $shell = new Shell($command, ...$args);
        return $shell->dispatchBackgroundCommand(); 
    }
}
