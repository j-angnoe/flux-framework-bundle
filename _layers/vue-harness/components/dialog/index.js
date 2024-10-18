/**
@loader
require('/path/to/index')
require('/path/to/dialog.css')
@endloader

@common e11e3d-98d579-b5c5c1-28784a/index.js
**/
module.exports = {};

window.dialog = {
  launch: launchDialog,
  dialog: launchDialogPromise
};

/**
 * Use case one:
 *
 * @usage - use case 1:

			var dlg = dialog.launch(`
				<h1>Hoe is het?</h1>
				<p>Volgens onze berekeningen gaat het
				allemaal wel fantastisch!
				</p>

				<example-component></example-component>
			`);

			dlg.title = 'Awesome unit';
			dlg.closable = true;

 * @usage - usage case 2
 *
 * // open a file dialog:

		// ideeen:
		// quick-search
		// navigeren met pijltjes

		dialog.launch({
			title?: string,
			width?: number,
			height?: number,
			fullscreen?: boolean|{css},
            centered?: boolean,
            modal?: boolean,
			// just like vue router:
			component: {
				template: `<div style="padding:20px;">
					<input v-model="search">

					<table>
						<tr v-for="file in files | filterBy search" @click="resolve(file)">
							<td>{{file}}</td>
						</tr>
					</table>
				</div>`,
				data: {
					files: [
					..
					..
					]
				}
      },
      component: String (component name)
      params: Params for the component
      listeners: Listeners for component
      on: alias for listeners.
		});

 * @param  {[type]} content [description]
 * @return {[type]}         [description]
 */

function launchDialog(...opts) {
  var data = makeDialogOptions(...opts);

  // `on` is an alias for `listeners`.
  data.listeners = {
    ...(data.listeners || {}),
    ...(data.on || {}),
    ...(data.connect ? { connect: data.connect} : {})
  };

  // @fixme - nalopen, wat gebeurt als ik een close via listeners aanlever.
  var vueObject = {
    template: `
      <flux-dialog
        v-bind="data"
        ref="flx"
        @connect="data.listeners.connect && data.listeners.connect($dialog)"
			>
        <div 
          :is="data.component" 
          v-bind="data.params || {}"
          v-on="{
            'close': () => { 
              $refs.flx.close(); 
            },
            ...(data.listeners || {}),
          }"
        ></div>
			</flux-dialog>
    `,
    data: { 
      '$dialog': null,
      data
    }
  };

  var newElement = document.createElement("div");

  document.body.appendChild(newElement);

  vueObject.el = newElement;

  var vue = new Vue(vueObject);
  var dlg = vue.$children[0];

  vue.$dialog = dlg;

  dlg.visible = true;
  for (let key in data) {
    dlg[key] = data[key];
  }

  if (data.modal) {
    dlg.doModal();
  }

  dlg.activateDialog();

  return dlg;
}

var mousePosition = {
  pos: {
    x: 0,
    y: 0
  },
  init() {
    document.addEventListener("mousemove", function(event) {
      mousePosition.pos.x = event.pageX;
      mousePosition.pos.y = event.pageY;

    });
  }
};
mousePosition.init();

var dialogIds = 0;

Vue.component("flux-dialog", {
  template: `<div
        class="dialog-container"
        v-bind:class="{'with-title': !!title}"
        v-bind:style="styles"
        @click="activateDialog()"
    >
        <div v-if="!title && closable" @click.stop.prevent="close" class="dialog-close">
            &times;
        </div>
		<div v-if="title" class="dialog-title" >
            <div v-if="closable" @click.stop.prevent="close" class="dialog-close">
                &times;
            </div>
      <span v-html="title" :style="titleStyle"></span>
		</div>
    <div class="dialog-content">
      <slot ></slot>
		</div>
    <div class="dialog-resize">
    </div>
	</div>
	`,

  computed: {
    styles() {
      var res = {};
      res.display = this.display;

      // console.log(this.$attrs);
      // this.fullscreen === true or
      // this.fullscreen = {left?, top?, right?, bottom?, width?, height?, position?}
      if (this.fullscreen) {
        res.left = this.fullscreen.left || 0;
        res.top = this.fullscreen.top || 0;
        res.bottom = this.fullscreen.bottom || 0;
        res.right = this.fullscreen.right || 0;
        res.width = this.fullscreen.width || "100%";
        res.height = this.fullscreen.height || "100%";
        res.position = this.fullscreen.position || "fixed";

        if (this.fullscreen < 0) {
          res.left = res.top = res.bottom = res.height = Math.abs(
            this.fullscreen
          );
        }
      } else {
        if (this.width) {
          res.width = 'calc(min(100vw, ' + parseFloat(this.width) + "px))";
        }

        if (this.height) {
          res.height = 'calc(min(100vh, ' + parseFloat(this.height) + "px))";
        }

        if (this.centered) {
          // At center, regardless of scrolling position.
          // fixed window dragging bug when centered.
          res.position = "fixed"; // @todo testen:

          var ml = (parseFloat(this.width) / 2 || $(this.$el.width() / 2)) + "px";
          var mt = (parseFloat(this.height) / 2 || $(this.$el.height() / 2)) + "px";

          res.left = "calc(50vw - min(50vw, " + ml + "))";
          res.top = "calc(50vh - min(50vh, " + mt + "))";

          console.log('set centered');
        }
      }

      var checkSupplied = ['left','right','bottom','top'];
      checkSupplied.map(attr => { 
        if (attr in this.$attrs) { 
          console.log('set ' + attr + ' to ' + this.$attrs[attr]);
          res[attr] = this.$attrs[attr];
        }
      });

      console.log('style',res);
      return res;
    },
    display: function() {
      if (this.visible) {
        return "block";
      }
      return "none";
    }
  },

  data() {
    return {
      dialogId: null,
      closable: true,
      title: null,
      titleStyle: "",
      height: 400,
      width: 400,
      centered: false,
      fullscreen: false,
      modal: false,
      visible: false,
      overlay: null
    };
  },

  async mounted() {
    await this.init();  
    this.$emit('connect');
  },
  // vue 1 compat: 
  ready() {
    this.init();
  },

  methods: {
    init() {
      dialogIds++;
      this.dialogId = dialogIds;

      var main = this.$el.querySelector('.dialog-content > *');
      if (main) { 
        Object.entries(main.attributes).map(([key, item]) => {
            if (item.nodeName in this.$data) {
              this[item.nodeName] = item.nodeValue;
              main.removeAttribute(item.nodeName);
            }
        })
      }

      if (this.$el.querySelector('title')) {
        var titleEl = this.$el.querySelector('title');
        this.title = titleEl.innerHTML;
        titleEl.parentNode.removeChild(titleEl);
      }

      this.width = Math.min(window.innerWidth - 24, this.width);
      this.height = Math.min(window.innerHeight - 24, this.height);
      
      this.registerEscapeListener();
      this.setInitialPosition();

      this.captureInitialFocus();
      
      if (this.modal || this.$el.querySelector('modal')) {
          this.doModal();
      }
      
      var timeout = null;
      var operation = null;
      
      var mousemoveHandler = event => {
        if (operation) { 
          var vector = {
            x: operation.pageX - event.pageX,
            y: operation.pageY - event.pageY
          };

          if (operation.type == 'translate') { 
            this.$el.style.left = window.pageXOffset + operation.left - vector.x + 'px';
            this.$el.style.top = window.pageYOffset + operation.top - vector.y + 'px';
          } else if (operation.type == 'resize') {
            this.$el.style.width = operation.width - vector.x + 'px';
            this.$el.style.height = operation.height - vector.y + 'px';
          }
        }
      };

      var mouseupHandler = event => {
        clearTimeout(timeout);

        
        if (operation) { 
          clearMouseHandlers();
          
          this.$el.style.userSelect = '';
          this.$el.style.cursor = '';
          operation = null
        }
      }

      var registerMouseHandlers = () => {
        document.body.addEventListener('mousemove', mousemoveHandler);
        document.body.addEventListener('mouseup', mouseupHandler);
      }

      var clearMouseHandlers = () => {
        document.body.removeEventListener('mousemove', mousemoveHandler);
        document.body.removeEventListener('mouseup', mouseupHandler);
      }

      this.$el.addEventListener('mousedown', event => {
        
        if (event.target.matches('input,select,textarea, input *,select *,textarea *')) {
          return;
        }
        
        clearTimeout(timeout);
        if (event.button !== 0) {
          return;
        }
        

        var operationType = null;
        var bb = this.$el.getBoundingClientRect();

        var relX = event.pageX - bb.left;
        var relY = event.pageY - bb.top;

        if (event.target.matches('.dialog-title, .dialog-title *')) {
          operationType = 'translate';
        } else if (relY < 30) {
          operationType = 'translate';
        } else if (event.target.matches('.dialog-resize')) {
          operationType = 'resize';
        } else {
          return;
        }

        if (operationType) { 
          
          document.body.addEventListener('mouseup', event => {
            clearTimeout(timeout);
          }, {once: true});

          timeout = setTimeout(() => {
            registerMouseHandlers();

            this.$el.style.userSelect = 'none';
            this.$el.style.cursor = operationType == 'translate' ? 'move' : 'nw-resize';
            operation = {
              pageX: event.pageX,
              pageY: event.pageY,
              left: bb.left,
              top: bb.top,
              width: bb.width,
              height: bb.height,
              type: operationType
            }
          }, 200)

          // event.preventDefault();
        }
      });


    },

    /**
     * init method - the first input should be focussed.
     * only if this thing is active / visible though! (todo)
     * todo - test if it will select input, select and textarea first...
     * wait about 1/2 second to let the positioner do its thing.
     */
    captureInitialFocus() {
      
      setTimeout(() => {
        var firstInput = this.$el.querySelector("input,select,textarea");

        if (firstInput) {
          firstInput.focus();
        }
      }, 500);
    },

    /**
     * init method: Set dialog to mouse position. uses the mousePosition service.
     */
    async setInitialPosition() {

      // if (this.styles.left) { 
      //   console.info('setInitialPosition(): left/right/bottom/top was already set.');
      //   return;
      // }

      for(i=0; i<20 && this.$el.offsetWidth == 0; i++) {
        await wait(50);
      }
      var width = parseInt(this.$el.offsetWidth) + 10;
      var height = parseInt(this.$el.offsetHeight) + 10;

      var box = {
        left: this.styles.left || mousePosition.pos.x,
        top: this.styles.top || mousePosition.pos.y,
        width: width,
        height: height,
        bottom: this.styles.bottom || (mousePosition.pos.y + height),
        right: this.styles.right || (mousePosition.pos.x + width)
      }
      
      mousePosition.pos.x += 20;
      mousePosition.pos.y += 20;

      box.left += window.pageXOffset;
      box.top += window.pageYOffset;

      // requires mouse position to be stored locally.
      
      
      /**
       * to be improved:
       * - bounding box protection.
       */
      
      /*

        1A-----------

        2A-----
        1B-----------

        2B-----

      */
      
      var overflow = {
        // right point 
        vertical: Math.max(0, box.bottom - (window.pageYOffset + window.innerHeight - 16) ),
        horizontal: Math.max(0, box.right - (window.pageXOffset + window.innerWidth - 16)) 
      }
      
      var offset = {
        x: 0,
        y: 0  
      }
      
      if (overflow.vertical > 0) {
        offset.y = -1 * overflow.vertical;
      }
      if (overflow.horizontal > 0) {
        offset.x = -1 * overflow.horizontal;
      }
      
      if (!(this.fullscreen || this.centered || this.styles.left || this.overlay )) {
        this.$el.style.left = (mousePosition.pos.x + offset.x) + "px";
        this.$el.style.top = (mousePosition.pos.y + offset.y) + "px";

        console.log('setInitialPosition: adjust left to ', this.$el.style.left);
        console.log('setInitialPosition: adjust top to ', this.$el.style.top);
      }
      
      console.log(this.$el.style);
      
      
    },
    
    /**
     * init method: register a listener for escape key. Close this (floating)
     * dialog when escape is hit.
     *
     * to be improved:
     * - dont close when you are in an input / textarea.
     * - select from visible/active/closable dialoges and close the most recent(ly used) one.
     *
     */
    registerEscapeListener() {
      // @todo 1 listener per page, last active dialog will be closed.
      var listener = event => {
        if (event.which === 27 && this.closable && !event.defaultPrevented) {
          // this does the trick of 1 close per escape,
          // but in the wrong order FIFO instead of LIFO.
          // try to fix this with an isLastFlux check.

          if (!isLastActiveFlux()) {
            // pass to another handler.
            return;
          }
          event.stopImmediatePropagation();

          this.close();
          document.removeEventListener("keydown", listener);
          event.preventDefault();

          return false;
        }
      };

      var self = this;

      function isLastActiveFlux() {
        // either the last/active and/or closab
        // le.
        var all = document.querySelectorAll(".dialog-container");

        // @todo active...
        return self.$el === all[all.length - 1];
      }

      document.addEventListener("keydown", listener);
    },
    isLastActiveFlux() {
      // either the last/active and/or closab
      // le.
      var all = document.querySelectorAll(".dialog-container");

      // @todo active...
      return this.$el === all[all.length - 1];
    },
    activateDialog() {

      // When one dialog `launches` a new dialog
      // this new dialog will be hidden (double activation)
      // Here we fix that. 
      if (window.didActivateDialogRecently) {
        return;
      }
      window.didActivateDialogRecently = true;
      setTimeout(() => {
        window.didActivateDialogRecently = false
      }, 100);
      // end fix

      var all = document.querySelectorAll(".dialog-container");

      if (this.$el !== all[all.length -1]) {
        if (document.body.contains(this.$el)) { 
          console.log('activateDialog: Moving dialog to top of the stack');
          document.body.appendChild(this.$el);
        }
      }
    },
    /**
     * Closes this dialog instance.
     *
     * to be improved: the dialog vue container element should be destroyed as well.
     */
    close() {
      this.$emit("close");
      this.$el.remove();
      this.$destroy();  
    },


    doModal() {
      var modalElement = document.createElement("div");
    
      modalElement.classList.add("dialog-modal-overlay");
      modalElement.addEventListener("click", event => {
        if (this.closable) {
          this.close();
        }
      });
      document.body.insertBefore(modalElement, document.body.firstChild);
      document.body.classList.add("dialog-modal-overlay-active");
      this.$on("close", event => {
        document.body.removeChild(modalElement);
        document.body.classList.remove("dialog-modal-overlay-active");
      });
    }
  }
});


function makeDialogOptions(content, component) {
  if (typeof content === "string") {
    content = {
      component: {
        ...component,
        template: content
      }
    };
  }


  if (content.component.data && typeof content.component.data !== "function") {
    let dataCopy = Object.assign({}, content.component.data);
    content.component.data = function() {
      return dataCopy;
    };
  }
  content.component.methods = content.component.methods || {};

  if (content.overlay) {
    try { 
      var el = content.overlay.getBoundingClientRect ? content.overlay : document.querySelector(content.overlay);
      var bb = el.getBoundingClientRect();
      // when bb.height exceeds doc height, probably do it fullscreen 
      // if the element is scrolled out of screen, probably do it fullscreen as well.
      if (el == document.body || bb.height > window.innerHeight || bb.top < 0) { 

        content.fullscreen = true;
      } else { 

        
        // alert(bb.height);
        Object.assign(content, {
            left: (bb.left + window.scrollX) + 'px',
            right: bb.right + 'px',
            top: (bb.top +window.scrollY) + 'px',
            bottom: null,
            width: bb.width + 'px',
            height: bb.height + 'px' 
        });
      }
    } catch (ignore) { 
      console.error(ignore);

    }
  }
  return content;
}

function launchDialogPromise(...opts) {
  var data = makeDialogOptions(...opts);

  var resolve, reject;
  
  var promise = new Promise((_resolve, _reject) => {
    resolve = _resolve;
    reject = _reject;
  });

  var fns = {
    resolve: function(...args) {
      if (false !== resolve(...args)) {
        promise.dialog.close();
      }
    },
    reject: function (...args) { 
      if (false !== reject(...args)) {
        promise.dialog.close();
      }
    }
  }

  // Resolve/reject are supplied as functions via component props.
  data.params = {
    ...fns,
    ...(data.params||{})
  }
  // We set up listeners
  data.listeners = {
    ...fns,
    ...(data.listeners || {})
  }

  // Oldskool: We inject $resolve/$reject functions into the function.
  if (data.component && data.component.methods) { 
    data.component.methods.$resolve = fns['resolve'];
    data.component.methods.$reject = fns['reject'];
  }
  
  promise.dialog = launchDialog(data);
  
  promise.dialog.$on('close', () => reject('user closed dialog'));
  return promise;
}
