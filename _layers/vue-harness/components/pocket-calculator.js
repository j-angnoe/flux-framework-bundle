
function PocketCalculator(options) { 
    var defaults = {
        'match': 'input[type=number]',
        'vat': 21,
        'precision': 2
    };
    options = Object.assign(defaults, options);

    document.addEventListener('keydown', function (event) { 
        const isInput = event.target.hasAttribute('data-pocket-calculator') || event.target.matches(options.match);
        const isEqualsSign = event.key == "=";

        // input[type=number] heeft geen selectionStart.
        // normale input wel.
        const isStartOfInput = (event.target.selectionStart||0) == 0;

        console.log(event.target.selectionStart);

        if (isInput && isEqualsSign && (isStartOfInput || event.ctrlKey)) {
            event.preventDefault();

            var selStart = event.target.selectionStart;
            var selEnd = event.target.selectionEnd;

            PocketCalculator.launch({
                ...options,
                value: event.target.value
            }).then(result => {
                if (result > '') { 
                    setTimeout(() => {
                        event.target.value = parseFloat(result).toFixed(options.precision);
                        event.target.focus();
                        event.target.select();
                        event.target.dispatchEvent(new Event('input'));
                    },10);
                }
            })
        }        
    });
}

PocketCalculator.launch = function (options) { 
    
    var vatFactor = null;
    if (options.vat < 1) { 
        vatFactor = 1 + parseFloat(options.vat);
    } else {
        vatFactor = 1 + (parseFloat(options.vat) / 100);
    }
    
    if (vatFactor > 2) { 
        console.error('PocketCalculator: VAT Factor seems excessive: ' + vatFactor);
    }

    return dialog.dialog(`<div title="Pocket Calculator">
        <input 
            data-pocket-calculator
            v-focus 
            v-model="value" 
            @keyup.enter="handleEnter"
            @keyup.backspace.backspace="handleBackspace"
            class="form-control form-control-lg"

            ref="input"
            >

        <template v-if="values.length > 0">
            <div class="mt-2 mb-2 d-flex">

                <button 
                    class="btn btn-sm btn-primary mr-1"
                    @click="resolve(valuesSum)"
                >Sum: {{ valuesSum }}</button>
                <button 
                    class="btn btn-sm btn-primary"
                    v-if="values.length > 1" 
                    @click="resolve(valuesAvg)">
                    Avg: {{ valuesAvg }}
                </button>
                <div style="flex-grow: 1;"></div>
                <button 
                    class="btn btn-sm btn-success"
                    @click="values=[]; $refs.input.focus()"
                    >
                    Reset
                </button>

            </div>

            <ul>
                <li v-for="v in valuesReverse">{{v}}</li>
            </ul>
        </template>
    </div>`, {
        data: {
            value: options.value || '',
            values: options.values || [],
            history: options.history || []
        },
        computed: { 
            valuesReverse() { 
                return [...this.values].reverse();
            },
            valuesSum() { 
                return (this.values.reduce((carry, item) => carry + parseFloat(item), 0)).toFixed(options.precision);
            },
            valuesAvg() {
                return (this.valuesSum / this.values.length).toFixed(options.precision)
            }
        },
        methods: { 
            evaluateExpression(expression) { 
                try { 
                    expression = expression.trim();

                    var value = eval(expression);

                    return value;
                } catch (e) {
                    alert("Error in expression: " + expression + " " + e);
                    throw e;
                }

            },
            handleEnter(event) { 
                expression = event.target.value;
                expression = expression.trim();

                expression = expression
                    // from inc to ex.
                    .replace(/\sto\sexc?l?$/, '/' + vatFactor)
                    // amount is ex, so convert to inc.
                    .replace(/exc?l?$/, '*' + vatFactor)

                    // from ex to inc 
                    .replace(/\sto\sinc?l?$/, '*' + vatFactor)
                    // amount is inc, convert to ex.
                    .replace(/inc?l?$/, '/' + vatFactor)
                ;

                if (expression > '') { 
                    if (expression.match(/^(\*|\/)[0-9.\,]+$/)) { 
                        this.history.push(this.values.concat([expression]));
                        this.values = [ eval(this.valuesSum + expression).toFixed(options.precision) ];
                    } else if (expression.match(/^[-+]?[0-9.\,]+$/)) {
                        this.values.push(expression);
                    } else {
                        var value = this.evaluateExpression(expression);
                        if (this.values.length > 0) {
                            this.values.push(value);
                        } else { 
                            return this.$resolve(value);
                        }
                    }
                }

                if (expression === '' || event.ctrlKey) { 
                    this.resolve(this.valuesSum);
                }

                event.target.value = '';
                event.target.dispatchEvent(new Event('input'));

            },
            handleBackspace(event) { 
                if (event.target.value == '' && this.values.length == 0) {
                    event.target.addEventListener('keyup', event => {
                        if (event.key == "Backspace") { 
                            this.$reject();
                        }
                    }, { once: true });
                }

                if (event.ctrlKey) { 
                    this.values = [];
                    event.target.value = '';
                    event.target.dispatchEvent(new Event('input'));
                    return;
                } 

                if (event.target.value == '') { 
                    if (this.values.length == 1 && this.history.length > 0) { 
                        this.values = this.history.pop();
                    }
                    if (this.values.length > 0) { 
                        event.target.value = this.values.pop();
                        event.target.dispatchEvent(new Event('input'));
                        event.target.selectionStart = 0;
                        event.target.selectionEnd = event.target.value.length;
                    } 
                }

            },
            resolve(finalValue) { 
                this.$resolve(finalValue);
            }
        }
    })
}

window.PocketCalculator = PocketCalculator;