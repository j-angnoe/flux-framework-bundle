<?php

namespace Flux\Framework\UI;

use Closure;
use Flux\Framework\Chain\BackgroundCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Flux\Framework\UI\LayoutInterface;
use Flux\Framework\UI\Vue2SPA\ServerBridgeInterface;
use Flux\Framework\UI\Vue2SPA\SimpleBridge;
use Flux\Framework\UI\Vue2SPA\VueBlocksLayout;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

class Vue2SPA
{
    protected ?ServerBridgeInterface $bridge;

    protected array $layouts = [];

    static $defaultBridge = SimpleBridge::class;

    private array $spaFiles = [];
    private array $spaFilesNonXhr = [];

    static $defaultLayouts = [
        VueBlocksLayout::class
    ];

    public function __construct(ServerBridgeInterface|LayoutInterface ...$elements) { 
        $this->setup(...$elements);
    }
    public function setup(ServerBridgeInterface|LayoutInterface ...$elements) { 
        $bridge = null;
        $layouts = [];

        foreach ($elements as $element) { 
            if ($element instanceof ServerBridgeInterface) { 
                $bridge = $element;
            } else if ($element instanceof LayoutInterface) { 
                $layouts[] = $element;
            }
        }
        
        if (!empty($bridge)) { 
            $this->setBridge($bridge);
        }   

        if (!empty($layouts)) { 
            $this->setLayouts($layouts);
        }
    }

    protected function setBridge(ServerBridgeInterface $bridge): void {
        $this->bridge = $bridge;
    }

    private array $sharedData = [];
    public function shareData(array|string $name, mixed $value = null) {
        if (is_array($name)) {
            $this->sharedData += $name;
        } else { 
            $this->sharedData[$name] = $value;
        }
    }

    public function setLayouts(mixed $layouts) { 
        if (!is_array($layouts)) { 
            $layouts = [$layouts];
        }
        $this->layouts = $layouts;
    }

    public function addSpaFiles(string|array ...$spaFileOrDir) { 
        foreach ($spaFileOrDir as $f) { 
            if (is_array($f)) {
                $this->spaFiles = array_merge($this->spaFiles, $f);
            } else {
                $this->spaFiles[] = $f;
            }
        }
    }

    public function addSpaFilesNonXhr(string|array $spaFileOrDir): void { 
        foreach ($spaFileOrDir as $f) { 
            if (is_array($f)) {
                $this->spaFilesNonXhr = array_merge($this->spaFilesNonXhr, $f);
            } else {
                $this->spaFilesNonXhr[] = $f;
            }
        }
    }


    public function serveSPA(object $controller, Request $request, string|array $SPAFiles): Response {         
        $isXhr = $request->isXmlHttpRequest();

        return $this->serve($controller, $request, function () use ($isXhr, $SPAFiles) {
            if (!is_array($SPAFiles)) { 
                $SPAFiles = [$SPAFiles];
            }
            $SPAFiles = array_merge($this->spaFiles, $SPAFiles);
            if (!$isXhr) {
                $SPAFiles = array_merge($this->spaFilesNonXhr, $SPAFiles);
            }

            $result = '';
            foreach ($SPAFiles as $SPAFile) {
                if (is_dir($SPAFile)) { 
                    $result .= $this->compile_SPA_Directory($SPAFile) . "\n";
                } else if (file_exists($SPAFile)) {
                    $result .= $this->compile_SPA_File($SPAFile) . "\n";
                } else {
                    throw new \Exception(__METHOD__ . ': Could not include `'.$SPAFile.'` (cwd: ' . getcwd().')');
                }
            }
            return $result;
        });
    }

    private array $argumentResolvers = [];
    function resolveArgument(string $className, Closure $factory) { 
        $this->argumentResolvers[$className] = $factory;
    }
    public function serve(object $controller, Request $request, string|Closure $content): Response
    
    {
        $this->resolveArgument(Request::class, fn() => $request);
        $this->resolveArgument(BackgroundCommand::class, fn(string|array $token) => new BackgroundCommand($token));
        
        $bridge = $this->bridge ?? new static::$defaultBridge();

        $bridge->setController($controller);
        if (method_exists($bridge, 'setArgumentResolvers')) { 
            $bridge->setArgumentResolvers($this->argumentResolvers);
        }

    

        if ($bridge->isDispatchRequest($request)) {

            // VarDumper::setHandler(function ($var, $label = null) {
            //     if ($label) { 
            //         echo '<h1>'.$label.'</h1>';
            //     }
            //     print_r($var);
            // });
            return $bridge->dispatch($request);   
        }

        try { 
            $request->getSession()->save();
        } catch (\Exception $e){ } // ignore.

        if ($content instanceof \Closure) {
            $content = $content();
        }

        if ($request->isXmlHttpRequest() && method_exists($bridge, 'generateJavascriptClientXhr')) {
            $content .= $bridge->generateJavascriptClientXhr();
        } else { 
            $content .= $bridge->generateJavascriptClient();
        }
        if (!$request->isXmlHttpRequest()) { 
            foreach ($this->layouts as $layout) { 
                if ($layout instanceof Closure) { 
                    $content = $layout($content);
                } else if (is_string($layout)) { 
                    $_layout = new $layout;
                    $content = $_layout($content);
                } else {
                    if (method_exists($layout, 'setSharedData')) {
                        $layout->setSharedData($this->sharedData);
                    }
                    $content = $layout($content);
                }
            }
        }

        $content = HtmlUtils::prepend('head','<base href="'.$_SERVER['REQUEST_URI'].'">', $content);
        
        $response = new Response($content, 200, ['Content-type' => 'text/html']);
        
        /* @fixme - we want some caching on these
        // but it doesnt work yet.
        */

        if ($_ENV['APP_DEBUG'] ?? false) {        
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
            $response->setPublic();
            $response->setMaxAge(1*3600);
        }

        $response->headers->set('Content-Type', 'text/html');
        // $response->headers->set('Content-length', strlen($content));
        return $response;
    }

    protected function compile_SPA_Directory(string $directory): string {
        $content = '';
        foreach (glob("$directory/*.{vue,html}", GLOB_BRACE) as $file) { 
            if (str_ends_with($file, '.vue')) { 
                $content .= $this->_compile_vue_file($file);
            } else { 
                $content .= $this->_compile_html_file($file);
            }
            if (substr_count($content, '<template') !== substr_count($content, '</template>')) { 
                throw new \Exception('Template start/end tag imbalance occured at ' . $file);
            }
            if (substr_count($content, '<script') !== substr_count($content, '</script>')) { 
                throw new \Exception('Script start/end tag imbalance occured at ' . $file);
            }
        }
        return $content;
    }

    protected function _compile_vue_file(string $file): string { 
        $name = pathinfo($file, PATHINFO_FILENAME);

        $result = '';
        $eatTemplateEnd = false;
        $eatenTemplateEnd = false;

        foreach (file($file) as $line) { 
            // $result .= 'LINE = `'.$line.'`';
            if (stripos($line, '<template') === 0) { 
                if (strpos($line, 'component=',) === false) { 
                    $result .= str_replace('<template','<template component="'.$name.'" ', $line);
                    $eatTemplateEnd = true;
                } else {
                    $result .= $line;
                    $eatTemplateEnd = false;
                }
            } elseif ($eatTemplateEnd && stripos($line, '</template>') === 0) { 
                $eatenTemplateEnd = true;
                $result .= '';
            } else {
                $result .= $line;
            }   
        }
        if ($eatenTemplateEnd) { 
            $result .= '</template>';
        }
    
        return $result;
    }

    protected function _compile_html_file(string $file): string { 
        return file_get_contents($file) ?: '';
    }

    protected function compile_SPA_File(string $file): string { 
        $fileContent = $file ? file_get_contents($file) : false;
        $fileContent = $fileContent ?: '';

        $fileExtension = pathinfo($file, PATHINFO_EXTENSION);
        $level = 0;
        if (in_array($fileExtension, ['php','ctp'])) { 

            // Read the file contents and only take the top-level
            // inline-html blocks from the file.

            $tokens = token_get_all(str_replace('__halt_compiler','xxx',$fileContent));
            
            $content = '';
            $buffer = '';
            foreach ($tokens as $token) {
                if ($token === '{' || $token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES || $token[0] === T_STRING_VARNAME) {
                    $level++;
                    $buffer .= '(level ' . $level.')';
                } else if ($token === '}') {
                    $level--;
                    $buffer .= '(level ' .$level.')';
                    if ($level < 0) {
                        throw new \Exception(__METHOD__ . ' negative {} level at ' . $buffer);
                    }
                }
                
                if ($level === 0 && is_array($token) && $token[0] === T_INLINE_HTML) {
                    $content .= $token[1];
                }
                $buffer .= $token[1] ?? $token;
            }
        } else {
            $content = $fileContent;
        }

        if (substr_count($content, '<template') !== substr_count($content, '</template>')) { 
            throw new \Exception('Template start/end tag imbalance occured at ' . $file);
        }
        if (substr_count($content, '<script') !== substr_count($content, '</script>')) { 
            throw new \Exception('Script start/end tag imbalance occured at ' . $file);
        }

        if ($level !== 0) { 
            throw new \Exception('Unmatched } in ' . $file);
        }
        return $content;
    }
}
