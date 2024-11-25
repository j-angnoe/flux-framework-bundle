<?php

namespace Flux\Framework\UI\Vue2SPA;

use Flux\Framework\UI\AddonInterface;
use Flux\Framework\UI\HtmlUtils;
use Flux\Framework\UI\LayoutInterface;
use Closure;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Annotation\Route;

class VueBlocksLayout implements LayoutInterface {
    /**
     * @param array<int, AddonInterface|Closure|string> $items
     */
    private array $addons = [];

    static function with(AddonInterface|Closure|string ...$addons): static { 
        $doc = new self;
        $doc->addons = $addons;
        return $doc;
    }

    public function __invoke(string $content): string
    {
        $content = HtmlUtils::autoCompleteHtmlDocument($content);

        $content = $this->ensureDefaultEntrypoint($content);
        $content = $this->ensureDefaultAppComponent($content);


        // $content = HtmlUtils::append('head', '<script src="https://unpkg.com/vue-blocks@0.4.3/dist/vue-blocks.js"></script>', $content);
        $content = HtmlUtils::append('head','<link href="/assets/_vue-harness/dist/vue-harness.css" rel="stylesheet">', $content);
        $content = HtmlUtils::append('head','<script src="/assets/_vue-harness/dist/vue-harness.js"></script>', $content);

        $content = HtmlUtils::append('body', <<<'HTML'
        <script>
        Vue.prototype.appData ??= function (keyName, defaultValue) {
            if (!this.$root.__appData) { 
                var p = this;
                while(!('app-data' in p.$attrs) && p.$parent) { 
                    p = p.$parent;
                }
                if ('app-data' in p.$attrs) { 
                    try { 
                        this.$root.__appData = JSON.parse(p.$attrs['app-data']);
                    } catch (ignore) { 
                        console.error(ignore);
                    }
                }
            } else {

            }
            return this.$root.__appData[keyName] ?? defaultValue;
        }

        window.loadSPA = function(url, registrar) { 
            if (Array.isArray(url)) {
                return Promise.all(url.map(u => loadSPA(u, registrar)));
            }

            console.log("loadSPA(" + url +")");

            this.loadSPA.loaded ??= {};
            
            if (registrar !== null) {
                registrar = registrar || Vue.component.bind(Vue);
            }
            if (this.loadSPA.loaded[url]) { 
                return Promise.resolve(this.loadSPA.loaded[url]);
            }
            return (this.loadSPA.loaded[url] = new Promise (async (resolve) => { 
                console.log('loading');
                var txt = await fetch(url,{
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }).then(res => res.text());

                var container = document.createElement('template')
                container.innerHTML = txt;

                var newWnd = {
                    LOAD_SPA_URL: url
                };

                [...container.content.querySelectorAll('link')].forEach(link => {
                    console.log('adding link', link);
                    // link.parentNode.remove(link);
                    document.body.appendChild(link);
                });
                

                var alreadyLoaded;

                var scripts = [...container.content.querySelectorAll('script[src]')]
                    .filter(script => {
                        alreadyLoaded ??= (() => [...document.querySelectorAll('script[src],link[href]')]
                            .map(resource => resource.src || resource.href)
                        )();
                        
                        return !(~alreadyLoaded.indexOf(script.src));
                    });

                var addScript = (script) => {
                    return new Promise(resolve => {
                        var newScript = document.createElement('script');
                        newScript.src = script.src;
                        newScript.addEventListener('load', resolve);
                        document.body.appendChild(newScript);
                    });
                }
                var deferredScripts = [];
                for(var script of scripts) {
                    if (script.defer) { 
                        deferredScripts.push(addScript(script));
                    } else {
                        await addScript(script);
                    }
                }
                
                await Promise.all(deferredScripts);

                [...container.content.querySelectorAll('script')].forEach(script => {
                    var script = script.innerHTML;
                    script = script.replace(/document\.location/g, JSON.stringify(url));
                    var fn = eval(`(function(window) {\n${script}\n})`);
                    fn(newWnd);
                }); 

                var components = {};
                VueBlocks.loadVueComponents(container.content, (name, def) => {
                    components[name] = def;
                    console.log('adding vue component ' + name + ' (' + url + ')');
                    registrar && registrar(name, def);
                }, newWnd);

                resolve(components);

                console.log('loaded');
            }))
        }
        
        ;[...document.querySelectorAll('link,script')].forEach(function () {

        })
        </script>
        HTML, $content);

        foreach ($this->addons as $addon) { 
            if (is_string($addon)) { 
                $addon = new $addon;
            } 
            $content = $addon($content);
        }

        return $content;
    }

    private function ensureDefaultEntrypoint(string $content): string { 
        $sharedData = json_encode((object) $this->sharedData);

        if (!preg_match('~<app\s*>~', $content)) {
            $content = HtmlUtils::append('body', '<app app-data=\''.$sharedData.'\'></app>' . "\n", $content);
        }   

        return $content;
    }


    private function ensureDefaultAppComponent(string $content): string { 
        if (preg_match('~<template\s+component="app"~', $content)) {
            return $content;
        }

        $content = HtmlUtils::append('body', <<<'HTML'
        <template component="app">
            <div>
                <nav class="main-nav navbar nav navbar-expand" v-if="$router.options.routes.length > 1">
                    <!-- f
                    <div class="navbar-brand">
                        <slot name="brand">
                        </slot>         
                    </div> 
                    -->
                    <div class="navbar-nav">
                        <slot name="nav-before"></slot>
                        <slot name="navbar">
                            <router-link 
                            class="nav-link"
                            v-for="r in $router.options.routes"
                                disabled-v-if="shouldShowInMenu(r)"
                            :to="r.path">
                                <i v-if="r.icon" class="fa" :class="r.icon"></i>
                                <span v-if="r.name || r.title || r.caption">{{r.name || r.title || r.caption}}</span>
                                <span v-else-if="r.path == '/'"><i class="fa fa-home"></i>&nbsp;</span>
                                <span v-else>{{r.path}}</span>
                            </router-link>
                        </slot>
                    </div>
                    <slot name="nav-extra"></slot>
                </nav>
                <div class="main-container">
                    <router-view v-bind="$data"></router-view>
                </div>
            </div>
        </template>

        HTML, $content);
        return $content;
    }

    static function getLayersDir(): string { 
        return __DIR__ . '/../../../_layers';
    }

    #[Route("/assets/_vue-harness/dist/{filename}", stateless: true)]
    function serve_dist(string $filename): BinaryFileResponse { 
        $path = static::getLayersDir() . '/vue-harness/dist/' . $filename;

        // dd(realpath($path));

        // Create a BinaryFileResponse
        $response = new BinaryFileResponse($path);

        // Automatically guess the MIME type based on the file extension
        // $mimeTypes = new MimeTypes();
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $mimeType = match($ext) {   
            'txt' => 'text/plain',
            'xsd' => 'text/plain',
            'avi' => 'video/avi',
            'bmp' => 'image/bmp',
            'css' => 'text/css',
            'gif' => 'image/gif',
            'htm' => 'text/html',
            'html' => 'text/html',
            'htmls' => 'text/html',
            'ico' => 'image/x-ico',
            'jpe' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'js' => 'application/x-javascript',
            'midi' => 'audio/midi',
            'mid' => 'audio/midi',
            'mod' => 'audio/mod',
            'mov' => 'movie/quicktime',
            'mp3' => 'audio/mp3',
            'mpg' => 'video/mpeg',
            'mpeg' => 'video/mpeg',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'swf' => 'application/shockwave-flash',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'wav' => 'audio/wav',
            'xbm' => 'image/xbm',
            'xml' => 'text/xml',
            'ttf' => 'font/ttf',
            'woff2' => 'font/woff2',
            default => throw new \Exception('Could not determine mime-type for extension `'.$ext.'`')
        };

        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->headers->set('Content-Type', $mimeType);
        $response->setPublic();
        $response->setMaxAge(24*3600);
        
        return $response;
    }

    private $sharedData = [];
    function setSharedData(array $sharedData) {
        $this->sharedData += $sharedData;
    }

}