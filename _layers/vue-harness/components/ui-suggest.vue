<!--

@common fc7904-60d727-743469-1029a6/ui-suggest.vue
Auto-suggest component features:
- suggest - hit an api endpoint (by calling a method from the global window.api object) with the search term
      and display suggestions

- suggest - run callback - by supplying a function as `call` attribute.

- single item and multi item mode,
  multi-mode: by supplying options.multi = true or by v-model="someArray"
  single-mode: by supplying an object or non-array as v-model.

- New item mode: Add a @create listener (<ui-suggest @create="alert('Create ' + $event)">)

</style>
Usage examples:
Example 1:
  <ui-suggest call="Search.article" v-model="selectedArticle" :options="{
      // when the primary key of the suggested items is not `id`
      primaryKey:'id_article',

      // when the display item does not have a name, title or caption

      // display: 'some_field'   // optie 1, toon alleen titel.

      // optie 2: geef een display functie
      //display: item => `${item.artist}, ${item.title}`
    }">

    // optie 3: geef een item slot mee zodat we zelf controle hebben
    <div slot="item" slot-scope="item">
      <span>
        {{item.artist}}, {{item.title}}

        // remove option is supplied to you.
        <button v-if="item.remove" @click="item.remove">x</button>
      </span>
    </div>


Nice to have:
- keyboard navigation (up/down = select)

Attributes
<ui-suggest
  value = Pre-set value.
  call = Function or String
    -- When a function is supplied, this will be called when suggestions need to be loaded.
        this function should accept the searched-for term (string) as first argument.
    -- When this is a string, that method of the window.api function will be called
        this function should accept the searched-for term (string) as first argument.
  items = Array -- Manually supply items.
  options = Object -- Configuration for this ui-suggest instance.
  listclass = String -- Class to use for list items.
  show-selected= Boolean -- DIsplay the item that is selected (default: true)
/>

@loader es6
import UiSuggest from 'path/to/ui-suggest.vue';
Vue.component('ui-suggest', UiSuggest);
@endloader

@loader es5
Vue.component('ui-suggest', require('path/to/ui-suggest.vue'));
@endloader 

// test
-->
<template>
    <span class="ui-suggest" :class="{selected: selectedItems.length > 0}" @click="focus()">
        <span v-for="item in selectedItems" v-show="showSelected">
            <slot name="item" v-bind="{...item, remove() { remove(item)}}">
                {{displayItem(item)}}
                <button @click.stop="remove(item)">&times;</button>
            </slot>
        </span>

        <span class="ui-suggest-cursor">
            <input ref="search"
              :value="displayItem(search)"
              @input="search = $event.target.value; focussed=true"
              @focus="value && $refs.search.select(); focussed=true"
              @keyup.esc="abortSearch()"
              @keydown.enter.prevent.stop="selectFirst()"
              @blur="potentiallyLoseFocus()"
              :placeholder="$attrs.placeholder"
            >
            <div class="suggestions" v-if="search && focussed && (searching || suggestions)">
                <div v-if="searching" >Searching...</div>
                <div v-if="search.length > 0 && !searching && suggestions">
                    <div v-if="suggestions.length === 0">
                      <div v-if="$listeners['create']">
                        Add `{{ search }}`
                      </div>
                      <div v-else>
                        Found nothing..
                      </div>
                    </div>
                    <ul v-else :class="listclass" ref="suggestionsContainer">
                        <li class="suggestion" v-for="item in suggestions"  @click.prevent.stop="select(item);">
                            <slot name="item" v-bind="item">
                                {{displayItem(item)}}
                            </slot>
                        </li>
                    </ul>
                </div>
            </div>
        </span>
    </span>
</template>
<style scoped>
.ui-suggest-cursor {
    position: relative;
    display: inline-block;
}

.ui-suggest-cursor .suggestions {
    position: absolute;
    border: 1px solid #ccc;
    background: white;
    padding: 10px;
    width: 100%;
    box-shadow: 5px 5px 10px rgba(0,0,0,0.2);
    z-index: 100000;
}

.ui-suggest-cursor .suggestions ul {
    padding: 0;
    margin: 0;
    margin: -10px;
}

.ui-suggest-cursor .suggestions li {
    padding: 0;
    margin: 0;
    list-style-type: none;
    display: block;
    border-bottom: 1px solid #ccc;
    padding: 5px 10px;
}
.ui-suggest-cursor .suggestions li.selected {
  background: yellow;
}
.ui-suggest-cursor input {
    border: 1px solid black;
    width: auto;
    display: inline-block;
}

</style>
<script>

export default {
    props: {
      value: { type: [String, Array] },
      call: { type: [String, Function] },
      items: { },
      options: {
        type: Object,
        default() {
          return {
            primaryKeyField: 'id',
            selectLeftRight: false
          }
        }
      },
      listclass: {},
      showSelected: {
        type: Boolean,
        default: false
      },
      alwaysEmit:  {
        type: Boolean,
        default: false
      }
    },
    //props: ['value','call', 'items', 'options', 'listclass'],
    data() {
        return {
            search: '',
            searchIndex: 0,
            searching: false,
            suggestions: null,
            focussed: false
        }
    },
    computed: {
      multiMode() {
        var isMultiOptionSet = this.options && this.options.multi;
        var isArraySupplied = Array.isArray(this.value);

        return isMultiOptionSet || isArraySupplied;
      },
      selectedItems() {
        if (this.multiMode) {
          return this.value;
        } else if (this.value) {
          return [this.value];
        } else {
          return [];
        }
      },
      primaryKeyField() {
        return this.options && this.options.primaryKey || 'id';
      },
      displayField() {
        return this.options && this.options.display;
      }
    },
    watch: {
        search() {
            this.tleSearch();

            if (this.alwaysEmit) {
              this.$emit('input', this.search);
            }
        },
        value() {
          if (typeof this.value == 'String') { 
            this.search = this.value;
          }
          // prevent the suggestions from popping up.

          if (!this.focussed) {
            setTimeout(() => clearTimeout(this.timeout), 5);
          }
        }
    },
    mounted() {
      this.enableUpDownSelect();

      if (this.value && typeof this.value == 'String') {
        this.search = this.value;
        // prevent the suggestions from popping up.
        setTimeout(() => clearTimeout(this.timeout), 5);

      }
    },
    methods: {
        focus() {
          this.$refs.search.focus();
          this.$refs.search.select();
        },
        tleSearch() {
            clearTimeout(this.timeout);
            this.timeout = setTimeout(() => {
                this.performSearch(this.search);
            }, 160);
        },
        abortSearch() {
            this.searchIndex++;
            this.suggestions = false;
            this.searching = false;
            clearTimeout(this.timeout);
            this.search = '';
        },
        displayItem(item) {
          if (typeof item !== 'object') {
            return item;
          } else if (!item) {
            return '';
          } else if (typeof this.displayField === 'function') {
            return item && this.displayField(item);
          } else if (item[this.displayField]) {
            return item[this.displayField];
          } else if(typeof item === 'object') {
            // some intelligent guesses
            if ('name' in item) return item.name;
            if ('title' in item) return item.title;
            if ('caption' in item) return item.caption;
            if (this.primaryKey in item) return '[item #' + item[this.primaryKey] + ']';

            return '[item]';
          }
        },

        getTermScoreFor(searchable,term) {
          var score = 0;

          // ensure both arguments are strings.
          searchable = '' + searchable;
          term = '' + term;

          if (term) {
              if (searchable.match(new RegExp('^' + term))) {
                  score += 100;
              }
              if (searchable.match(term)) {
                  score += 50;
              }
              if (term.length < 6) {
                  var fuzzy = term.split('').join('*.*');
                  if (searchable.match(new RegExp(fuzzy))) {
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

        // From Joshua's smart-cursor.js
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

        async performSearch(term) {
            if (!term) {
                return;
            }
            var currentSearch = (++this.searchIndex);

            this.searching = true;

            if (typeof this.call === 'function') {
                this.searchPromise = Promise.resolve(this.call(term));
            } else if (typeof this.call === 'string') {
                this.searchPromise = eval('api.' + this.call)(term, {exclude: [].concat(this.selectedItems).map(item => {
                   return item[this.primaryKey];
                })});
            } else if (this.items) {
              this.searchPromise = Promise.resolve(this.items)
              .then(items => {
                return this._getMatches(items, term);
              })
              .then(items => {
                return items.map(item => {
                  if (typeof item != 'object') {
                    var obj = { name: item };
                    obj[this.primaryKeyField] = item;
                    obj.__resolve = item;
                    return obj;
                  }
                  return item;
                })
              })
            }

            var suggestions = await this.searchPromise;


            this.searching = false;
            if (currentSearch !== this.searchIndex) {
                // abort;
                return;
            }

            // Ensure suggestiosn is an array.
            if (!Array.isArray(suggestions)) {
              suggestions = Object.values(suggestions);
            } 
            this.suggestions = [...suggestions];

            if (this.updatedSuggestions) {
              this.updatedSuggestions();

            }
        },
        remove(item) {
          if (this.multiMode) {
            return this.$emit('input', this.selectedItems.filter(i => i !== item));
          } else {
            return this.$emit('input',null);
          }
        },
        select(item) {
          if (!this.alwaysEmit) { 
            this.search = '';
          }
          this.potentiallyLoseFocus();

          this.suggestions = null;

          item = item && item.__resolve || item;

          var emitValue;
          
          if (this.multiMode) {
            emitValue = [...this.value, item];
          } else {
            emitValue = item;
          }
          this.$emit('input', emitValue);
          this.$listeners['select'] && this.$emit('select', emitValue);

        },
        selectFirst() {
          alert("Selectfirst?");

          // warning: This function may be overwritten if initKeydownhandler is called.
            if (this.searchPromise) {
              this.searchPromise.then(x => {
                alert("Deze?");

                if (this.suggestions.length > 0) {
                  alert(this.suggestions.length);
                  this.select(this.suggestions[0]);
                } else if (this.$listeners['create']) {
                  this.$emit('create', this.search);
                }
              })
            }
        },
        enableUpDownSelect() {
          var selectedIndex = 0;


          var selectOption = index => {
            if (!this.$refs.suggestionsContainer) {
              return;
            }
            var selected = this.$refs.suggestionsContainer.querySelector('.selected');
            if (selected) {
              selected.classList.remove('selected');
            }

            selectedIndex = (index + Math.max(1,this.suggestions.length)) % Math.max(1,this.suggestions.length);

            if (this.suggestions.length > 0) {
              this.$refs.suggestionsContainer.childNodes[selectedIndex].classList.add('selected');
            }
          }


          // Make sure `[enter]` selects the highlighted option.
          this.selectFirst = () => {
            if (this.suggestions.length > 0) { 
              this.select(this.suggestions[selectedIndex]);
            } else if (this.$listeners['create']) {
              this.$emit('create', this.search);
            }
          };

          // When suggestions are renewed we need to redraw
          // the highlighted option.
          this.updatedSuggestions = () => {
            // Timing: Just after DOM has updated.
            setTimeout(() => selectOption(selectedIndex), 25);
          };

          this.$refs.search.addEventListener("keydown", event => {

            var isPrevious = event.key === 'ArrowUp' || (this.options.selectLeftRight && event.key == 'ArrowLeft');
            var isNext = event.key === 'ArrowDown' || (this.options.selectLeftRight && event.key === 'ArrowRight');

            if (isPrevious) {
                event.preventDefault();
                selectOption(--selectedIndex);
                return;
            } else if (isNext) {
                event.preventDefault();
                selectOption(++selectedIndex);

                return;
            }
          });


        },

        potentiallyLoseFocus() {
          setTimeout(() => {
            this.focussed = false;
          },200);
        }
    }

}
</script>
