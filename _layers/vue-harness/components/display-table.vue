<template>

	<div>
		<div v-if="!isTable">
            <div v-if="Array.isArray(data)">
                <pre v-text='data.join("\n")'></pre>
            </div>
			<pre v-else>{{data}}</pre>
		</div>
		<div v-else="" is="table" class="table">
			<div is="thead" style="position:sticky; top: -10px;">
				<div is="tr">
					<div is="th" v-for="h in headers" @click="setOrder(h)">
                        <slot :name="'header-' + h">
                            {{h}}
                        </slot>
                    </div>
					<div v-if="'actions' in $attrs || 'actions' in $scopedSlots" is="th">&nbsp;</div>
				</div>
				<div is="tr" v-if="$listeners['filter'] || 'filter' in $attrs">
					<div is="th" v-for="h in headers" class="filter-th" @click="$emit('column-click', h)">
						<div class="filter-container">
							<slot :name="'filter-' + h" v-bind="{data}">
								<input :value="rowFilters[h]" @keyup.enter="setFilter({ column: h, value: $event.target.value })">
							</slot>
						</div>
					</div>
					<div v-if="'actions' in $attrs || 'actions' in $scopedSlots" is="th">&nbsp;</div>
				</div>
			</div>
			<div is="tbody" v-if="ordered_data &amp;&amp; ordered_data.length === 0 &amp;&amp; numFilters > 0">
				<div is="tr"><div is="td" colspan="headers.length + 1" style="padding:15px;text-align: center;">
				<pre>{{numFilters}}</pre>
					There are no results that match your filters. <a href="javascript:;" @click="rowFilters={};orderData()">Clear filters</a>
				</div></div>
			</div>
			<div is="tbody">
				<template v-for="row in ordered_data">
					<div is="tr" @click="maybeEmitRowClick(row)">
						<div is="td" v-for="h in headers" v-if="h !== '_id'" :style="row._style || ''" @click="emitCellClick(row[h], h, row)">
							<slot :name="h" v-bind="{value: row[h], row}">{{row[h]}}
							</slot>
						</div>
						<div v-if="'actions' in $attrs || 'actions' in $scopedSlots" is="td">
							<slot name="actions" v-bind="{row}">
								<template v-for="(fn, name) in $attrs.actions">
									<a href="javascript:;" @click="fn(row)">
										<i v-if="name.indexOf('icon:') === 0" class="fa" :class="name.substr(5)"></i>
										<span v-else="">{{name}}</span>
									</a>&nbsp;
								</template>
							</slot>
						</div>
					</div>
					<div v-if="mode == 'accordion' &amp;&amp; selectedRow == row" is="tr">
						<div is="td" :colspan="headers.length + 1">
							<slot name="accordion" v-bind="{ row: selectedRowFull }">
								<div v-if="'accordion' in $attrs" :is="getComponent($attrs.accordion)" :value="selectedRowFull"></div>
							</slot>
						</div>	
					</div>
				</template>
			</div>
			<div is="tfoot" v-if="totals">
				<div is="tr">
					<div is="td" v-for="h in headers" v-if="h !== '_id'">
						<slot :name="h" v-bind="{data, value: totals[h], totals}">{{totals[h] || ''}}
						</slot>
					</div>
				</div>
			</div>
		</div>
	</div>
	
	

</template>
<style scoped>

		.filter-th {
			position: relative;
		}

		.filter-container {
			position: absolute;
			top: 0;
			right: 0;
			bottom: 0;
			left: 0;
			height: 30px;
			min-width: 30px;
		}

		.filter-container {
			position: absolute;
			width: 100%;
		}
		.filter-container input {
			width: 100%;
		}

		thead th {
			background: white;
		}
	
</style>
<script>
export default {
    props: ["from", "pipe", "totals", "sort"],
    data() {
        return {
            grid_order: null,
            grid_reverse: null,
            data: null,
            ordered_data: null,
            selectedRow: null,
            /*  Only does something in accordion mode. */
            selectedRowFull: null,
            headers: null,
            rowFilters: {}
        }
    },
    watch: {
        '$attrs.headers'(h) {
            this.calculateHeaders();
        },
        '$attrs.data'(data) { 
            clearTimeout(this.dataWatcherTimeout);
            this.dataWatcherTimeout = setTimeout(() => {
                console.log('display-table watchers $attrs.data');
                this.data = data.concat([]);
                if (this.isTable) { 
                    this.calculateHeaders()
                    this.orderData();
                }
            }, 25);
        }
    },
    mounted() {
        if (this.sort) { 
            this.grid_order = this.sort[0];
            this.grid_reverse = this.sort[1] || false;
        }
        this.load();
    },
    computed: {
        isTable() {
            if (!this.data) {
                console.log('fail at 1');
                return false;
            }
            if (!Array.isArray(this.data)) {
                console.log('fail at 2, ' + typeof this.data);
                return false;
            }
            if (!this.data[0]) {
                console.log('fail at 3');
                return false;
            }
            if (Array.isArray(this.data[0])) {
                console.log('fail at 4');
                return false;
            }

            if (typeof this.data[0] == 'object') {
                return true;
            }
            console.log('fail at 5');
            return false;
        },
        mode() {
            if ('alert' in this.$attrs) {
                return 'alert';
            }
            if ('popup' in this.$attrs) {
                return 'popup';
            }
            if ('accordion' in this.$attrs || 'accordion' in this.$scopedSlots) {
                return 'accordion'
            }
        },
        numFilters() {
            return Object.keys(this.rowFilters).length;
        }
    },
    methods: {
        async load() {
            if ('data' in this.$attrs) {
                this.data = this.$attrs.data.concat([]);
            } else if (typeof this.from == 'function') {
                this.data = await this.from();
            } else {
                this.data = await Promise.resolve(this.from);
            }
            if (this.isTable) {
                this.calculateHeaders()
                this.orderData();
            }
        },
        getComponent(componentName) {
            if (this.$options.components[componentName]) {
                return this.$options.components[componentName];
            } else if (this.$parent.$options.components[componentName]) {
                return this.$parent.$options.components[componentName]
            } else {
                return componentName;
            }
        },
        calculateHeaders() {
            var calcHeaders = (this.data && this.data[0] && Object.keys(this.data[0]) || []).filter(k => k.substr(0, 1) !== '_');

            if (this.$attrs.headers) {
                var headers = [];
                if (typeof this.$attrs.headers == 'string') {
                    headers = this.$attrs.headers.split(/\s*,\s*/);
                }
                var collectedHeaders = null;
                headers = headers.filter(h => {
                    if (h === '*') {
                        collectedHeaders = collectedHeaders || calcHeaders;
                        return false;
                    }
                    if (h.substr(0, 1) === '-') {
                        if (collectedHeaders === null) {
                            collectedHeaders = calcHeaders;
                        }
                        collectedHeaders = collectedHeaders.filter(c => {
                            return c !== h.substr(1);
                        });
                        return false;
                    }
                    return true;
                })

                calcHeaders = [...new Set([...headers, ...collectedHeaders || []])];
            }
            this.headers = calcHeaders;
            return calcHeaders;
        },
        orderData() {
            var data = (this.data && this.data || []);
            try {
                if (Object.keys(this.rowFilters).length > 0) {
                    var filters = Object.entries(this.rowFilters).map(([column, value]) => {
                        if (value.match(/^[0-9]+$/)) {
                            return row => row[column] == value;
                        } else {
                            var reg = new RegExp(value, 'i');
                            return row => reg.test(row[column]);
                        }
                    });

                    var filterFn = row => {
                        var fn;
                        for (fn of filters) {
                            console.log(fn);
                            if (!fn(row)) {
                                return false;
                            }
                        }
                        return true;
                    };
                    this.ordered_data = data.filter(filterFn);
                } else {
                    this.ordered_data = data;
                }
            } catch (e) {
                console.error('orderData error filtering.', this.rowFilters, JSON.stringify(this.data, null, 3));
                this.ordered_data = data;
            }

            try {
                var sorterFn = (a, b) => {
                    if (this.grid_reverse) {
                        var [b, a] = [a, b];
                    }

                    if (a[this.grid_order] > b[this.grid_order]) {
                        return 1;
                    } else if (a[this.grid_order] == b[this.grid_order]) {
                        return 0;
                    }
                    return -1;
                };

                this.ordered_data = this.ordered_data.sort(sorterFn)
            } catch (e) {
                console.error('orderData error sorting', JSON.stringify(this.data, null, 3));
            }
        },
        setOrder(key) {
            if (this.grid_order == key) {
                this.grid_reverse = !this.grid_reverse;
            } else {
                this.grid_reverse = false;
                this.grid_order = key;
            }

            this.$emit('order', [key, this.grid_reverse]);
            this.orderData();
        },
        setFilter({
            column,
            value
        }) {
            if ('filter' in this.$listeners) {
                this.$emit('filter', {
                    column,
                    value
                });
            } else {
                if (value > '') {
                    this.$set(this.rowFilters, column, value);
                } else {
                    this.$delete(this.rowFilters, column);
                }
                this.orderData();
            }
        },
        async emitCellClick(value, key, row) {
            var emitRow = row;

            if (this.mode == 'accordion' && this.selectedRow === row) {
                this.selectedRow = null;
                this.selectedRowFull = null;
                return;
            }

            if (this.pipe) {
                emitRow = await this.pipe(row);
            }
            this.selectedRow = row;
            this.selectedRowFull = emitRow;
            this.$emit('cell-click', {
                value,
                key,
                row: emitRow
            });

            if (this.mode == 'alert') {
                alert(JSON.stringify(emitRow, null, 3));
            } else if (this.mode == 'popup') {
                dialog.launch(`<div width=500 height=500><${this.$attrs.popup} :value="value"></${this.$attrs.popup}></div>`, {
                    data: {
                        value: emitRow
                    },
                    components: this.$parent.$options.components
                });
            }
        },
        maybeEmitRowClick(row) {
            clearTimeout(this.rowClickTimeout);

            var selectedText = window.getSelection().toString();
            if (!selectedText) { 
                this.rowClickTimeout = setTimeout(() => {
                    this.$emit('row-click', row);
                },100);
            } else {
                console.log('row-click prevented by selectedText: `' + selectedText + '`');
            }
        }
    }
};
</script>