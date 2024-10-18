<template>
    <div>
        <slot v-if="!('target' in $attrs)">
			<input type="search">
        </slot>
		<div class="suggestions-container" ref="sc" :style="{
				display: focus &amp;&amp; suggestions || scDisplay ? 'block' : 'none', 
				opacity: focus &amp;&amp; suggestions ? 1 : 0
			}">
			<ul class="table">
				<template v-if="suggestions &amp;&amp; !suggestions.length">
					<li class="empty">
						<slot name="empty" v-bind="{ search: lastSearch }">
							<em>No matches found for `{{lastSearch}}`</em>
						</slot>
					</li>
				</template>
				<template v-if="loading">
					<li class="loading"><i class="fa fa-spin fa-spinner"></i> Loading...</li>
				</template>
			</ul>
            <table class="table table-sm" v-if="'table' in $attrs">
                <tr v-for="(s, index) in suggestions" @click="resolve(s)" :class="{
						suggestion: true, 
						highlight: (index == selectedIndex) 
					}">
                    <slot name="display" v-bind="s">
                    </slot>
                </tr>
            </table>
            <ul v-else>
                <li v-for="(s, index) in suggestions" @click="resolve(s)" :class="{
						suggestion: true, 
						highlight: (index == selectedIndex) 
					}">

                    <slot name="display" v-bind="s">
                        {{ displayItem(s) }} 
                    </slot>
                </li>
            </ul>
        </div> 
    </div>
</template>
<style scoped>
    .suggestion.highlight,
    .suggestion.highlight td { 
        background: rgba(255,255,75,0.45);
    }
    .suggestion.highlight:hover, 
    .suggestion.highlight:hover td { 
        background: rgba(255,255,75,0.65);
    }
    .suggestion,
    .suggestion td { 
        transition: background-color 0.05s;
    }
    .empty, 
    .empty td { 
        background: #ddd;
        color: #666;
    }
    .suggestion:hover, 
    .suggestion:hover td { 
        background-color: rgba(255,255,75,0.25);
    }
    .suggestions-container { 
        position: absolute;
        /* max-width: 750px; */
        z-index: 1000;
        background: white;
        box-shadow: 0 5px 20px rgba(0,0,0,0.5);
        border-bottom-left-radius: 10px;
        border-bottom-right-radius: 10px;
        /* overflow: hidden; */
        overflow: auto;
        transition: opacity 0.2s;
        max-height: 30vh;
        
    }
    ul, table.table { 
        padding: 0;
        margin: 0;
    }
    li:first-child { 
        border-top: 1px solid #eee;
    }
    li { 
        padding: 0;
        margin: 0;
        list-style:none;
        border-bottom: 1px solid #eee;
        padding: 8px 16px;
    }
</style>
<script>
export default {
    data() {
        return {
            focus: false,
            selectedIndex: 0,
            scDisplay: false,
            suggestions: null,
            lastSearch: null,
            loading: false
        }
    },
    watch: {
        focus(focus) {
            if (focus) {
                this.scDisplay = true;
            } else {
                clearTimeout(this.animateTimeout)
                this.animateTimeout = setTimeout(() => {
                    this.scDisplay = false;
                }, 200);
            }
        },
        suggestions() {
            if (this.suggestions) {
                this.selectedIndex = (this.selectedIndex||0) % (this.suggestions || []).length;
            } else {
                this.selectedIndex = 0;
            }

        },
        selectedIndex() {
            this.$refs.sc.scrollTop = this.selectedIndex * this.$el.querySelector('.highlight').offsetHeight;
        }
    },
    async mounted() {
        if ('target' in this.$attrs) {
            this.search = this.$parent.$refs[this.$attrs.target];
            this.$parent.$el.querySelector(this.$attrs.target)

            console.log(this.search, 'search from target');
        } else {
            this.search = this.$el.querySelector('input');
        }


        var w = this.$attrs.width || this.search.offsetWidth;

        if (w) {
            if (('' + w).match(/^[0-9]+$/)) {
                w = w + 'px';
            }
            this.$refs.sc.style.width = w;
            if ('target' in this.$attrs) {
                console.log('set width to ' + w);
            }
        }
        this.$refs.sc.style.marginLeft = (this.search.offsetLeft - this.$el.offsetLeft) + 'px';

        this.search.addEventListener('input', event => {
            if (this.focus) {
                this.performSearchDebounced(event.target.value);
            }
        })
        var blurTimeout;
        this.search.addEventListener('focus', event => {
            this.focus = true;
            clearTimeout(blurTimeout);
        });
        this.search.addEventListener('blur', event => {
            clearTimeout(blurTimeout);
            blurTimeout = setTimeout(() => {
                this.focus = false;
            }, 100);
        });

        this.$refs.sc.addEventListener('mousedown', event => {
            setTimeout(() => {
                clearTimeout(blurTimeout)
            },10);
        })

        this.focus = this.search == document.activeElement;

        this.search.addEventListener('keydown', event => {
            this.focus = true;

            if (event.key == 'Escape') {
                this.resolve(null);
                event.preventDefault();
            }
            if (event.key == 'Enter') {
                if (this.suggestions) {
                    this.resolve(this.suggestions[this.selectedIndex]);
                    event.preventDefault();


                    if (event.ctrlKey || event.metaKey) {
                        // Als je ctrl+enter doet dan sluiten we de
                        // suggesties sowieso. (zeker ook met ook op multi mode)
                        this.search.blur();
                    }
                }
            }
            if (event.key.match(/(ArrowUp|ArrowDown)/)) {
                event.preventDefault();
                if (this.suggestions) {
                    this.selectedIndex += (event.key == 'ArrowUp') ? -1 : 1;
                    this.selectedIndex += this.suggestions.length;
                    this.selectedIndex %= this.suggestions.length;
                }
            }
        })

        console.log(Object.keys(this.$attrs));

        if (this.$attrs['display-value']) {
            this.search.value = this.displayItem(this.$attrs['display-value']);
        } else if (this.$attrs['value']) {
            this.performSearch(this.$attrs['value'], results => {
                if (results && results[0]) {
                    this.search.value = this.displayItem(results[0]);
                }
            })
        }
    },
    methods: {
        resolve(value) {
            if ('value' in this.$attrs) {
                this.$emit('input', value);
            }

            if (value) {
                this.$emit('select', value);

                // If multi mode, re-focus on search.
                if ('multi' in this.$attrs || 'multiple' in this.$attrs) { 
                    this.search.focus();
                    return;
                } 

                this.search.blur();
                // alert("VALUE in attrs");
                this.search.value = this.displayItem(value);

                if (this.search.form) {
                    var i = 0;
                    for (i = 0; i < this.search.form.length; i++) {
                        if (this.search.form[i] == this.search) {
                            var e = this.search.form[i + 1];
                            e && e.focus();
                        }
                    }
                }
            }
            this.focus = false;
        },
        async performSearch(term, callback) {

            var call = this.$attrs.call || this.$attrs.from || null;
            this.loading = true;
            if (typeof call === 'function') {
                this.searchPromise = Promise.resolve(call(term));
            } else if (typeof call === 'string') {
                this.searchPromise = eval('api.' + call)(term);
            } else if (this.$attrs.items) {
                this.searchPromise = Promise.resolve(this.$attrs.items)
                    .then(items => {
                        return this._getMatches(items, term);
                    })
                    .then(items => {
                        return items.map(item => {
                            return item;
                        })
                    })
            }

            this.searchPromise.finally(() => {
                setTimeout(() => {
                    this.loading = false;
                }, 250)
            })
            if (callback) {
                callback(await this.searchPromise);
            } else {
                this.lastSearch = term;
                var suggestions = await this.searchPromise;
                this.suggestions = suggestions;
            }
        },
        performSearchDebounced(term) {
            clearTimeout(this.searchTimeout);

            this.searchTimeout = setTimeout(async () => {
                return this.performSearch(term);
            }, 150);
        },
        displayItem(item) {
            if (typeof item !== 'object') {
                return item;
            } else if (!item) {
                return '';
            } else if ('display' in this.$attrs && typeof this.$attrs.display == 'function') {
                return item && this.$attrs.display(item);
            } else if ('dislay' in this.$attrs && typeof this.$attrs.display == 'string') {
                return item[this.$attrs.display];
            } else if (typeof item === 'object') {
                // some intelligent guesses
                if ('name' in item) return item.name;
                if ('title' in item) return item.title;
                if ('caption' in item) return item.caption;
                if (this.primaryKey in item) return '[item #' + item[this.primaryKey] + ']';

                return Object.values(item).join(', ');
            }
        },
        getTermScoreFor(searchable,term) {
          var score = 0;

          // ensure both arguments are strings.
          searchable = '' + searchable;
          term = '' + term;

          if (term) {
              if (searchable.match(new RegExp('^' + term,'i'))) {
                  score += 100;
              }
              if (searchable.match(term)) {
                  score += 50;
              }
              if (term.length < 6) {
                  var fuzzy = term.split('').join('*.*');
                  if (searchable.match(new RegExp(fuzzy,'i'))) {
                      // matching characters
                      var fzy_score = 2;
                      term.split('').map(c => {
                          if (~searchable.indexOf(c)) {
                              fzy_score *= 1.5;
                          } else {
                              fzy_score /= 2;
                          }
                      });
                      if (fzy_score > 2) {
                          score += fzy_score;
                      }
                  }
              }
          }
          return score;
        },
         _getMatches(items, term) {
          return items.map(key => {

              var score;

              if (typeof key == 'object') {
                var score1 = this.getTermScoreFor(this.displayItem(key), term);
                var score2 = this.getTermScoreFor(Object.values(key).join(','), term);

                score = (score1 * 10) + score2;
              } else {
                score = this.getTermScoreFor(key, term);
              }

              return {
                  score,
                  value: key
              }

          }).filter(match => {
              return !term || match.score > 0;
          })
          .sort((a, b) => {
              //console.log('term ', term, a[0] + ' ' + a[1].score + ' versus ' + b[0] + ' ' + b[1].score);

              return b.score - a.score;
          })
          .map(({value}) => value);
      },
    }
};
</script>