<?php

namespace Flux\Framework\UI\Vue2SPA;

use Flux\Framework\Utils\IoC;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SimpleBridge implements ServerBridgeInterface { 
    private mixed $requestData;
    private object $controller;

    public function setController(object $controller): void
    {
        $this->controller = $controller;
    }

    private function isEventStreamRequest($request): bool { 
        return str_contains($request->headers->get('accept'),'text/event-stream');
    }
    public function isDispatchRequest(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {   
            if ($request->getContentTypeFormat() === 'json') {
                $this->requestData = json_decode($request->getContent(), true);
            }    
            return isset($this->requestData['rpc']);
        } else if ($this->isEventStreamRequest($request)) {
            $this->requestData['rpc'] = json_decode($request->query->get('rpc'), true);
            return !empty($this->requestData['rpc']);
        } elseif ($request->request->has('rpc') && $request->query->has('sbm')) {
            $this->requestData['rpc'] = json_decode($request->request->get('rpc'), true);
            return !empty($this->requestData['rpc']);
        }
        
        return false;
    }

    private array $argumentResolvers = [];

    public function setArgumentResolvers(array $argumentResolvers) { 
        $this->argumentResolvers += $argumentResolvers;
    }
    
    public function dispatch(Request $request): Response
    {
        if (!$this->isDispatchRequest($request)) { 
            throw new \InvalidArgumentException('Not a dispatch request');
        }

        $rpc = $this->requestData['rpc'];

        $methodName = $rpc[0] ?? '';
        $args = $rpc[1] ?? [];


        $controller = $this->controller;

        if (!method_exists($controller, $methodName)) {
            throw new NotFoundHttpException('Action `' . htmlspecialchars($methodName) . '` was not found on ' . get_class($controller));
        }

        $wantsJson = in_array('application/json', $request->getAcceptableContentTypes());

        $result = null;

        try {
            $ioc = new IoC;
            foreach ($this->argumentResolvers as $x=>$y) {
                $ioc->set($x,$y);
            }

            $newArgs = $ioc->prepareArgs(new ReflectionMethod($controller, $methodName), $args);

            $result = call_user_func_array([$controller, $methodName], $newArgs);

            if ($result instanceof \Generator) {
                $result = iterator_to_array($result);
            }

            // Ensures any iterator related error can be captured here.
            if (!($result instanceof Response)) { 
                $result = new JsonResponse($result, 200);
            }
        } catch (\Throwable $e) {
            if ($wantsJson) {

                return new JsonResponse([
                    'error' => get_class($e) . ': ' . $e->getMessage() . ' in file ' . $e->getFile(). ' on line ' . $e->getLine(),
                    'trace' => ($_ENV['APP_DEBUG'] ?? false) ? $e->getTrace() : [],
                ], 500);
            } else {
                throw $e;
            }
        }

        return $result;
    }

    function generateJavascriptClientXhr() { 
        $currentUrl = json_encode($_SERVER['REQUEST_URI']);
        return <<<HTML
        <script>
            if (!createSimpleBridge)  {
                alert("Error xhr loading " + currentUrl + ", there may be a mix-up of non-simple-bridge (full page) which loads simple-bridge-based resources via XHR, please contact developer.");
            }
            window.server = createSimpleBridge($currentUrl);
        </script>
        HTML;
    }
    function generateJavascriptClient(): string
    {
        return <<<'HTML'
        <script>
            var responseHandler = async (res) => {
                if (res.ok) return res.json();

                if (!Vue.options.components['display-server-error']) {
                    let msg;
                    msg = (await res.text());
                    try { 
                        msg = JSON.parse(msg).error;
                    } catch {
                    }
                    const err = new Error(`XHR request failed with status ${res.status}\n${msg}`);
                    err.res = res;
                    err.response = res;
                    throw err;
                }
                console.error('Server error occured, popping up display-server-error');
                var defaultUnhandledRejectionHandler = () => {
                    dialog.dialog({
                        component: 'display-server-error',
                        title: `<span style="color:red;">
                            <i class="fa fa-exclamation-triangle"></i> 
                            <b>HTTP ${res.status}</b> ${res.statusText}
                        </span>`,
                        params: {
                            res
                        },
                        width: 800,
                        height: 800
                    })
                    resetErrorHandler();
                }

                var resetErrorHandler = () => {
                    globalThis.removeEventListener('unhandledrejection', defaultUnhandledRejectionHandler);
                    if (Vue.config.errorHandler === defaultUnhandledRejectionHandler) { 
                        Vue.config.errorHandler = null;
                    }
                    clearTimeout(errorHandlerResetTimeout);
                }
                Vue.config.errorHandler = defaultUnhandledRejectionHandler;
                globalThis.addEventListener('unhandledrejection', defaultUnhandledRejectionHandler, { once: true })

                var errorHandlerResetTimeout = setTimeout(resetErrorHandler, 250);
                return Promise.reject(res);
            };

            var postCall = (url, json) => {
                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(json)
                })
            }

            function createSimpleBridge(baseUri) {
                return new Proxy({
                    call(url, post) {
                        return postCall(url, post).then(responseHandler);
                    }
                }, {
                    get(obj, methodName) { 
                        if (obj[methodName]) { 
                            return obj[methodName];
                        }
                        var fn = function(...args) {
                            var href = `${baseUri}`.split('#')[0];
                            return postCall(href + '?' + methodName, {
                                rpc: [methodName,args]
                            }).then(responseHandler);
                        }   
                        fn.curry = function(...curriedArgs) { 
                            var fn2 = (...args) => fn(...[...curriedArgs, ...args]);
                            fn2.eventStream = (...args) => fn.eventStream(...[...curriedArgs, ...args]);
                            fn2.post = (...args) => fn.post(...[...curriedArgs, ...args]);
                            return fn2;
                        }   
                        fn.eventStream = function (...args) { 
                            var href = `${baseUri}`.split('#')[0];
                            var es = new EventSource(href + (~href.indexOf('?') ? '&' : '?') + 'eventstream=1&rpc=' + encodeURIComponent(JSON.stringify([methodName, args])), {
                                withCredentials: true
                            });
                            es.addEventListener('finished', event => {
                                es.close();
                            })

                            // Expose a throttled version 
                            // this simplifies client usage of eventStreams.
                            var addEventListener = es.addEventListener.bind(es);
                            es.addEventListener = function(eventName, callback, options) { 
                                if (eventName === 'batch') {
                                    var batch = [];
                                    return addEventListener('message', event => {
                                        if (event.data) { 
                                            batch.push(event.data);
                                        }
                                        if (batch.length > 100) { 
                                            callback(batch)
                                            batch = []
                                        } else { 
                                            globalThis.requestIdleCallback(() => {
                                                if (batch.length) { 
                                                    callback(batch);
                                                }
                                                batch = [];
                                            });
                                        }
                                    })
                                }
                                addEventListener(eventName, event => {
                                    globalThis.requestIdleCallback(() => {
                                        callback(event.data);
                                    });
                                }, options);
                            }

                            es.originalAddEventListener = addEventListener;

                            return es;
                        }
                        fn.post = function(...args) { 
                            var href = `${baseUri}`.split('#')[0];
                            var form = document.createElement('form');
                            form.action = href + (~href.indexOf('?') ? '&' : '?') + "sbm=print&" + methodName;
                            form.method = "POST";

                            // dont break out of an iframe
                            // if we detect we are running inside an iframe.
                            // to prevent session-loss (third-party cookies etc)
                            if (self == top) { 
                                form.target = "_blank";
                            }
                            form.style.display = 'none';

                            var input = document.createElement('input');
                            input.name = 'rpc';
                            input.value = JSON.stringify([methodName, args]);
                            form.appendChild(input);

                            var button = document.createElement('button');
                            button.innerHTML = 'submit';
                            form.appendChild(button);
                            setTimeout(() => {
                                form.submit();
                            }, 10);
                            document.body.appendChild(form);
                        }
                        return fn;
                    }
                });
            }
            window.createSimpleBridge = createSimpleBridge;
            window.server = createSimpleBridge(document.location);
        </script>
        HTML;
    }

}