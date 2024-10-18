/**
 * Standard debounce functionality
 * 
 * How to use it, inside a vue component:
 * 
 * 
 * var result = await this.debounce(timeout).someComponentMethod(param1, param2)
 * 	
 * 		This will call this.someComponentMethod(param1, param2) in a bit. 
 * 
 * Please note that the promise returned by debounced calls may not return ever.
 * 
 * Usage 2:
 * 
 * this.debounce(timeout, function() { 
 * 		alert("Do this in a bit")
 * })
 * 
 * Usage 3:
 * this.debounce(function() {
 * 		alert("Do this in a bit")
 * });
 */
Vue.prototype.debounce = function(timeout = 333, fn = null) { 
    if (typeof timeout == 'function') { 
        [fn, timeout] = [timeout, fn]
    }
    timeout = timeout || 333;
    var component = this;
    component.debounceTimeouts = component.debounceTimeouts || {};
    if (fn) { 
        var fnId = fn.toString().replace(/\s/g,'');
        clearTimeout(component.debounceTimeouts[fnId]);
        return new Promise(resolve => {
            component.debounceTimeouts[fnId] = setTimeout(async () => {
                var result = await fn();
                resolve(result);
            }, timeout);
        })
    }
    return new Proxy({}, {
        get(obj, variable) {
            return function(...args) {
                return new Promise(resolve => {
                    clearTimeout(component.debounceTimeouts[variable]);
                    component.debounceTimeouts[variable] = setTimeout(async () => {
                        var result = await component[variable](...args);
                        resolve(result);
                    }, timeout)
                })
            }
        }
    })
}
