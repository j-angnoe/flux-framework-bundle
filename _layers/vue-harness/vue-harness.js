import 'regenerator-runtime/runtime'

import axios from 'axios';

import 'bootstrap/dist/css/bootstrap.min.css';
import 'font-awesome/css/font-awesome.css';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Vue from 'vue/dist/vue.common.dev';

window.Vue = Vue;
// Turn of that warning
Vue.config.productionTip = false

import VueBlocks from 'vue-blocks';
import VueRouter from "vue-router";

window.VueBlocks = VueBlocks;

// Font awesome is still problematic with its fonts not being loaded..
// import 'font-awesome/css/font-awesome.min.css';

/* @inserts javascript-functions */
// @snippet 692dcc-61ac6c-80bdd3-c9e2ae
	// one weakness: insertTab destroys your undo history...
	var tab = "\t";
	function insertTab(o, e){
		var kC = e.which;
	
		if (kC == 9 && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.mId) {         
			var oS = o.scrollTop;
			if (o.setSelectionRange) {
				var sS = o.selectionStart;
				var sE = o.selectionEnd;
				o.value = o.value.substring(0, sS) + tab + o.value.substr(sE);
				o.setSelectionRange(sS + tab.length, sS + tab.length);
				o.focus();
			}
			else if (o.createTextRange) {
				document.selection.createRange().text = tab;
				e.returnValue = false;
			}
			o.scrollTop = oS;
			e.mId = true;
			if (e.preventDefault) {  e.preventDefault(); }
			return false;
		}  return true; 
	}
	
	Vue.directive('tab', (el) => {
		el.addEventListener('keydown', event => insertTab(el, event));
	});
// @endsnippet

// @snippet de4011-d9640c-6f208e-d680ce
	/**
	 * @common de4011-d9640c-6f208e-d680ce/link-to-storage.js
	 * Links vue component variables to either localStorage or sessionStorage.
	 * 
	 * Usage inside your vue components;
	 * 
	 * yourComponent = {
	 *      mounted() {
	 *          this.link('myVariable').to.localStorage('someLocalStorageKey');
	 *          this.link('otherVar').to.sessionStorage();
	 *      }
	 * }
	 * 
	 */
	
	Vue.prototype.link = function link(key) {
		this.__linksToStorage = this.__linksToStorage || {};

		var linkToStorage = (storage, storageKey) => {
			if (!window.APP_NAME) {
				console.error('Please set window.APP_NAME in this unit.');
				var APP_NAME = 'UnknownApp';
			} else {
				var APP_NAME = window.APP_NAME;
			}
		
			var fullStorageKey = [
				APP_NAME || this.$options._componentTag,
				storageKey
			].filter(Boolean).join('.');
	
			// console.log("Read " + storageKey);
	
			if (storage[fullStorageKey]) {
				try { 
					var storedData = JSON.parse(storage[fullStorageKey] || 'null');
					// De nieuwe manier:

					// console.log('Stored data is ', storedData);

					if ('$$$value$$$' in storedData) {
						this.$set(this, key, storedData['$$$value$$$']);
						// console.log("Sets " + key + " to " + storageKey, storedData['$$$value$$$']);
					} 
				} catch (ignore) {
					// console.log("Error reading " + storageKey, ignore);
				}
			}

			var writeValueToStorage = () => {
				storage[fullStorageKey]= JSON.stringify({'$$$value$$$': this[key]});
			}	
			
			var watcherWriteValueTimeout = null;
			var unwatcher = this.$watch(key, function() {
				// console.log("Watcher Updates " + storageKey + ' ' + value);
				// @fixme: Throttle this function
				clearTimeout(watcherWriteValueTimeout);
				watcherWriteValueTimeout = setTimeout(writeValueToStorage, 25);
			}, {deep:true});

			this.__linksToStorage[key] = {
				restore: () => { 
					// noop.
					console.log('NOOP Restore');
				},
				unlink: () => {
					clearTimeout(watcherWriteValueTimeout);
					unwatcher();
					storage.removeItem(fullStorageKey);
					console.log('Unlinking ' + key + ' from ' + storageKey);
					setTimeout(() => {
						this.__linksToStorage[key].restore = () => {
							console.log('Restoring ' + key + ' to ' + storageKey);
							writeValueToStorage();
							linkToStorage(storage, storageKey);	
						}
					}, 25);
				}
			}
			return this.__linksToStorage[key];
		};
	
		return {
			to: {
				sessionStorage: (storageKey) => {
					return linkToStorage(sessionStorage, storageKey);
				},
				localStorage: (storageKey) => {
					return linkToStorage(localStorage, storageKey);
				}
			},
			restore: () => {
				if (!this.__linksToStorage[key].restore) {
					throw new Error('There is no link to restore for ' + key);
				}
				this.__linksToStorage[key].restore();
			},
			unlink: () => { 
				try { 
					this.__linksToStorage[key].unlink();
				} catch (ignore) {} 
			}
		}
	};
	
// @endsnippet
// @snippet 5f8864-960ae0-59fb0f-2d4bff
	/**
	 * Vue ctrl-s handler
	 * 
	 * Usage: <main v-ctrl-s="submit">
	 * 
	 * @author Joshua Angnoe
	 * @package BOS - VueBase
	 * @common 5f8864-960ae0-59fb0f-2d4bff/ctrl-s.js
	 */
	
	var autoSaveHandlers = [];
	
	document.addEventListener('keydown', async (event) => {
		if (event.ctrlKey && event.key === "s") {
			if (event.defaultPrevented) { 
				return;
			}
	
			event.preventDefault(); // console.log(autoSaveHandlers);

			var p = event.target; // When there are no autoSaveHandlers
			
			// Ensure the last value gets saved properly.
			p.dispatchEvent(new Event('change'));
		
			// and wait a bit to let that settle before we trigger the submit event.
			await wait(1);
		
			if (!autoSaveHandlers.length && event.target === document.body) {
			//   console.log("DOE DIT");
			var forms = document.querySelectorAll('form');
			//   console.log("DIT ZIJN DE FORMS", forms);
			if (forms.length) {
				p = { form: forms[forms.length - 1], parentNode: document.body };
			}
			}
		
			// console.log('ctrl-s', p);
		
			while(p && p.parentNode) {
				if (p.autoSave) {
					return p.autoSave(event);
				}
		
				if (p.form) {
				if (p.form.checkValidity && !p.form.checkValidity()) {
				p.form.reportValidity();
					return;
				}
		
				var submit = p.form.querySelector('[type=submit]');
				if (submit) { 
					submit.click();
				} else { 
					p.form.dispatchEvent(new Event('submit', {
					cancelable: true
					}));
				}
				return;
			}
		
				p = p.parentNode;
			}
	
		if (autoSaveHandlers.length) {
				autoSaveHandlers[autoSaveHandlers.length-1]();
			}
		}
	});
	
	Vue.directive('ctrl-s', {
		bind(el, attrs) {
			console.log(attrs.value);
	
			el.autoSave = () => {
				attrs.value();
			}
	
			autoSaveHandlers.push( () => {
				attrs.value();
			});
		},
		unbind(el) {
			autoSaveHandlers.pop();
		}
	});
	
// @endsnippet
/* @snippet 561cad-fa2976-b27553-d60a7a */
		// Popup error 
		window.axios.interceptors.response.use(
		function(response) {
			// console.log(response, 'response intercepted');
			
			return response;
		},
		function(error) {
			if (error && error.response && error.response.status === 500) {
			popupError(error);
			}
			return Promise.reject(error);
		}
		);
		

		/**
		 * popupError - when encountering a HTTP 500 we show this.
		 * It contains a hidden input to ensure we capture the focus.
		 * It will also return focus to the last focus element if it
		 * was found.
		 * @param {*} error 
		 */
		function popupError(error) {
			var lastFocussed = document.activeElement;
			window.dialog.dialog({
			width: 800,
			height: 800,
			centered: true,
			modal: true,
			title: '<span style="color:red;">Server error occured</span>',
			component: {
				data: error && error.response || {},
			template: `
			<div>
					<input style="position: absolute; left: -1000px;" v-focus>
					<div v-if="data">
						<div v-if="data.type">
							<h3 v-text="data.type"></h3>
							<pre v-text="data.message || data.error || ''"></pre>
						</div>
						
						<div v-else-if="data.error">
							<div v-if="~data.error.indexOf('\\n')">
								<pre>{{data.error}}</pre>
							</div>
							<h3 v-else>{{data.error}}</h3>
						</div>
						<div v-else-if="data.exception && data.message">
							<h3 v-text="data.exception"></h3>
							<div>{{data.file}} on line {{data.line}}</div>
							<pre v-text="data.exception"></pre>
						</div>
						<div v-if="data.description" style="margin-bottom:200px;">
							<pre v-if="data.description" v-html="data.description" style="white-space: pre-wrap;"></pre>
						</div>
						<div v-if="data.nice_trace || data.trace" style="margin-top: 30px;">
							<hr>
							<div v-for="t in (data.nice_trace || data.trace).slice(0,15)" 
							@dblclick="api.__harness.view_error(t.file, t.line)"
							style="cursor: pointer;"
							title="Double click to open in editor"
							>
								<div 
									@click.prevent="api.__harness.view_error(t.file, t.line)"
									style="text-decoration: underline;"
								>{{t.file}} line {{t.line}}</div>
								<pre v-if="t.code">{{t.code}}</pre>
							</div>
						
							<!-- <pre>{{data.trace}}</pre> -->
						</div>
						<div v-else>
							<pre v-text="data"></pre>
						</div>
				</div>
				<div v-else>
					$data:
					<pre style="white-space: pre-wrap;">{{$data}}</pre>
				</div>
				</div>`,
				methods: {
					recoverStringData(stringData) { 
						try { 
							var lines = (stringData||'').split(/\n/);
						} catch (e) {
							console.error('recoverStringData() could not `split()` stringData', stringData, e);
							return stringData;
						}
						var result = {};
						for (var i = 0; i < lines.length; i++) { 
							var line = lines[i];
							var f = line.substr(0,1);
							var l = line.substr(-1,1);
							if ((f == '{' && l == '}') || (f == '[' && l == ']')) {
								try { 
									line = JSON.parse(line);
								} catch(ignore) { }
			}
							result['line ' + i] = line;
						}
						return result;
					}
				}
				}
			}).finally(() => {
				lastFocussed && lastFocussed.focus();
			})
		}
/* @endsnippet */

/* @snippet fcb201-2ff8ae-94c4e6-e8f2b9 */

	Vue.directive('autoheight', (el) => {
		el.wrap = 'off';
	
		var resizeFn = () => {
			var extra = el.scrollWidth > el.offsetWidth;
			el.style.height = (el.scrollHeight - 10) + 'px';
			el.style.height = (el.scrollHeight + (extra ? 25 : 5)) + 'px';
		};
		var timeout;
		var resizeFnDebounce = () => {
			clearTimeout(timeout);
			timeout = setTimeout(resizeFn, 50);
		};
		el.addEventListener('keyup', resizeFnDebounce)
		resizeFn();
		setTimeout(resizeFn, 50);
	});
/* @endsnippet */

/* @snippet f4f7c0-bb683c-28d08f-36f061 */

	function wait(n) {
		return new Promise(resolve => setTimeout(resolve, n));
	}
	window.wait = wait;

	function debounce(timeout, name) { 
		debounce.timeouts[name] && debounce.timeouts[name]();
		return new Promise((resolve, reject) => {
			var tm = setTimeout((...args) => {
				resolve(...args);
				delete debounce.timeouts[name]
			}, timeout)
			debounce.timeouts[name] = () => {
				clearTimeout(tm);
				reject();
			} 
		});
	}
	debounce.timeouts = {};
	window.debounce = debounce;
/* @endsnippet */

/* @snippet javascript/utils/clone */
	function clone(data) {
		return JSON.parse(JSON.stringify(data));
	}
	window.clone = clone;
/* @endsnippet */

/* @snippet vue/utils/alert */
	Vue.prototype.alert = window.alert.bind(window);
/* @endsnippet */
/* @snippet fc7904-60d727-743469-1029a6-loader */
	import UiSuggest from './components/ui-suggest.vue';

	Vue.component('ui-suggest', UiSuggest);
	
	import ProvideSuggestions from './components/provide-suggestions.vue';
	Vue.component('provide-suggestions', ProvideSuggestions);
	
/* @endsnippet */

/* @snippet e5da0b-156d5c-494533-b53424-loader */
	var Toast = require("./components/toast/index");
	require("./components/toast/toast.css");
	Toast.exposeAs("toast");
	
/* @endsnippet */

/* @snippet e11e3d-98d579-b5c5c1-28784a-loader */
	require('./components/dialog/index')
	require('./components/dialog/dialog.css')
	
/* @endsnippet */

/* @snippet javascript/utils/axios-xsrf */
window.axios.interceptors.response.use(
	function(response) {
		if (response.headers['xsrf-token']) {
			axios.defaults.headers.common['xsrf-token'] = response.headers['xsrf-token'];
		}
		return response;
	}, function (error) {
		if (error && error.response && error.response.headers['xsrf-token']) {
			axios.defaults.headers.common['xsrf-token'] = error.response.headers['xsrf-token'];
		} 
	}
);
/* @endsnippet */

/* @snippet 7ab6ab-c4cac7-3b9d1c-aa6cea */

	
	/**
	 * v-focus
	 * @common 7ab6ab-c4cac7-3b9d1c-aa6cea/focus.js
	 * To be applied directly on an element
	 * 
	 * @usage <input v-focus>
	 * @author Joshua Angnoe
	 * @package VueBase
	 */
	
	Vue.directive('focus', {
		inserted(el) {
			el.focus()
		}
	});
	
	/**
	 * v-focus-first
	 * 
	 * To be applied on a container, will set the focus to the first
	 * input 
	 * 
	 * @usage <div v-focus-first> .... <input name="input"> </div>
	 */
	Vue.directive('focus-first', {
		inserted(el) {
			setTimeout(() => {
				try { 
					el.querySelector('input,select,textarea').focus();
				} catch(ignore) { }
			}, 200);
		}
	});
	
/* @endsnippet */

/* @endinserts */

Vue.prototype.api = window.api;

var mountAttr = document.currentScript.getAttribute('mount');

if (!mountAttr || mountAttr == 'true') {  
	function startApp() {
		console.log("startApp(): STARTING APP");
		Vue.use(VueBlocks);
		Vue.use(VueRouter);
		
		const app = new Vue({
			el: "app",
			router: new VueRouter({ routes: VueBlocks.collectRoutes() }),
			...(window.createApp && window.createApp() || {})
		});
	}

	// This must be come last.
	document.addEventListener('DOMContentLoaded', startApp, { once: true});
}



// php's date function 
window.date = require('locutus/php/datetime/date')
window.strtotime = require('locutus/php/datetime/strtotime')

// @snippet javascript/peri-dates
window.peri = {
	make(period) {
		if (typeof period == 'number') {
			return period;
		} else {
			return strtotime(period);
		}	
	},

	/**
	 * Converts a given time/date to a period like
	 * 2020-Q01, 2020-W01, 2020-M03 
	 * 
	 * @param period - date/timestamp or something
	 * @param period-type to extract
	 */
	extract(period, type) {
		period = peri.make(period);

		var year = date('Y', period);
		var month = date('m', period);
		var monday;

		var pad = n => n < 10 ? '0' + n : n;

		switch(type) {
			case 'year': 
				return year;
			case 'quarter': 
				return year + '-Q' + pad(Math.ceil(parseInt(month) / 3));
			case 'month':
				return year + '-M' + month;
			case 'week':
				monday = date('N', period) == 1 ? period : strtotime('last monday', period);
				return date('o', monday) + '-W' + date('W', monday);
			case 'period':
				monday = date('N', period) == 1 ? period : strtotime('last monday', period);
				return  date('o', monday) + '-P' + pad(Math.ceil(date('W', period) / 4));
			case 'day':
			case 'date':
				return date('Y-m-d', period)
			default:
				throw new Error('Unknown peri type `' + type + '`');
		}
	},

	/**
	 * Convert a period (2020-M01, 2020-W03, 2020-Q02) to
	 * an interval [start_date, end_date]
	 * 
	 * @param period (example: 2020-M01)
	 * @param returnClosedInterval (default: true)
	 *
	 * a closed interval of 2020-M01 would be [2020-01-01, 2020-01-31]
	 * an open interval of 2020-M01 would be [2020-01-01, 2020-02-01]
	 */
	interval(period, returnClosedInterval = true) {
		var match;
		if (period.match(/^[0-9]{4}$/)) {
			return [`${period}-01-01`, returnClosedInterval ? `${period}-12-31` : `${period+1}-01-01`];
		}
		if (!(match = period.match(/([0-9]{4})-(Q|M|W|P)([0-9]{2})/i))) {
			throw new Error('Invalid period pattern, give something like 2020-M01');
		}

		var [_, year, type, period] = match;
		var periodInt = parseInt(period);
		var pad = n => parseInt(n) < 10 ? '0' + parseInt(n) : n;

		var ts, tse;

		switch(type.toUpperCase()) {
			case 'Q':
				ts = strtotime(`${year}-${pad(period*3 -2)}-01`);
				tse = strtotime('+3 months', ts);
			break;
			case 'M':
				ts = strtotime(_ = `${year}-${pad(period)}-01`);
				tse = strtotime('+1 month', ts);
			break;
			case 'W': 
				var first_monday = strtotime('last monday', strtotime(`${year}-01-07`));
				var first_week = date('W', first_monday);
				var delta = periodInt - parseInt(first_week);

				ts = strtotime(`+${delta*7} days`, first_monday);
				tse = strtotime(`+7 days`, ts);
			break;
			case 'P':
				var first_monday = strtotime('last monday', strtotime(`${year}-01-07`));
				var first_week = date('W', first_monday);
				
				var delta = (1+(periodInt-1)*4) - parseInt(first_week);

				ts = strtotime(`+${delta*7} days`, first_monday);
				tse = strtotime(`+28 days`, ts);
			break;
		}

		if (returnClosedInterval) { 
			tse = strtotime('-1 day', tse);
		}

		return [date('Y-m-d', ts), date('Y-m-d', tse)];
	},

	/**
	 * Enumerate periods of type `to_period` in a given `period`.
	 * 
	 * @param {} period 
	 * @param {*} to_period 
	 */
	enum(period, to_period) {

		var chunkSizes = {
			'year' : '+1 year',
			'quarter': '+3 months',
			'month': '+1 month',
			'period': '+4 weeks',
			'week': '+1 week',
			'date' : '+1 day',
			'day': '+1 day'
		};
		
		if (Array.isArray(period)) {
			var [ts, tse] = period.map(strtotime);
		} else { 
			var [ts, tse] = peri.interval(period).map(strtotime);
		}

		var i;
		var result = new Set;

		for (i = ts; i <= tse;) {
			result.add(peri.extract(i, to_period));
			i = strtotime(chunkSizes[to_period], i);
		}
		// Prevent 2013-M09 week doesnt contain 2013-W40.
		result.add(peri.extract(tse, to_period));

		return [...result];
	}
}
// @endsnippet

// @snippet vue/fullheight-directive
Vue.directive('fullheight', {
	bind(el) {
		function setHeight() { 
			el.style.height = calculateAvailableHeight() + 'px';
		}

		function calculateAvailableHeight() {
			var bb = el.getBoundingClientRect();
			var availableHeight = window.innerHeight - parseInt(window.getComputedStyle(document.body)['padding-bottom']) - FULLHEIGHT_OFFSET;
			return availableHeight - bb.top; 
		}

		function resizeHandler() {
			clearTimeout(timeout);
			timeout = setTimeout(setHeight, 30);
		}

		const FULLHEIGHT_OFFSET = window.FULLHEIGHT_OFFSET || 0;
		var timeout;

		el.resizeEventListener = resizeHandler;
		el.style.overflow = 'auto';
		window.addEventListener('resize', el.resizeEventListener, true);

		resizeHandler();
	},

	unbind(el) {
		window.removeEventListener('resize', el.resizeEventListener);
	}
});
// @endsnippet


function ymdtotime(term, timestamp) {
	if (!term && !timestamp) {
		return date('Y-m-d');
	}
	timestamp = timestamp && peri.make(timestamp);
	return date('Y-m-d', strtotime(term, timestamp));
}
window.ymdtotime = ymdtotime;

import Debug from './components/debug.vue';
Vue.component('debug', Debug);

import TabbedView from './components/tabbed-view.vue';
Vue.component('tabbed-view', TabbedView);

import UiToggle from './components/ui-toggle.vue';
Vue.component('ui-toggle', UiToggle);

import DisplayTable from './components/display-table.vue';
Vue.component('display-table', DisplayTable);

import DisplayObject from './components/display-object.vue';
Vue.component('display-object', DisplayObject);

require('./components/pocket-calculator.js');

window.componentExists = Vue.prototype.componentExists = function (component) {
	return Vue.options.components[component] || false;
}

function isScalar(mixedVar) {
	// @fixme - moeten we `null` ook als scalar beschouwen?
	return /boolean|number|string/.test(typeof(mixedVar));
}

window.isScalar = isScalar;
Vue.prototype.isScalar = isScalar;

// shortcuts for json stringify and parse.
function json(...args) { 
	// by default nice output with 3 spaces
	if (args.length == 1) { 
		args = [...args, null, 3];
	}
	return JSON.stringify(...args)
}
json.parse = JSON.parse.bind(JSON);

window.json = json;

Vue.prototype.json = json;

require('./components/vue-debounce');
