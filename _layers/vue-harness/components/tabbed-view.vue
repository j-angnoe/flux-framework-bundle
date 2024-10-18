<template>

	<div>
		<nav class="nav nav-tabs">
			<a class="nav-link" v-for="(t, name) in tabs" @click="hideAll(); t.show()" :class="{active: activeTabTitle == name}">{{name}}</a>
		</nav>
		<div ref="tabContainer" class="active-tab-container">
			<slot></slot>
		</div>
	</div>
	

</template>
<script>
export default {
    props: [
        'localStorage', 'sessionStorage', 'value'
    ],
    data() {
        return {
            activeTabTitle: null,
            tabs: null
        }
    },
    mounted() {
        var map = new WeakMap();
        var isFirst = true;

        var isSelected = child => {
            if (isFirst) {
                isFirst = false;
                return true;
            }
            return false;
        };

        if (this.value) {
            this.activeTabTitle = this.value;
        }

        if (this.sessionStorage) {
            this.link('activeTabTitle').to.sessionStorage(this.sessionStorage);
        } else if (this.localStorage) {
            this.link('activeTabTitle').to.localStorage(this.localStorage);
        }

        if (this.activeTabTitle) {
            isSelected = child => {
                return child.getAttribute('title') === this.activeTabTitle ||
                    child.getAttribute('value') === this.activeTabTitle;
            };
        }

        this.$refs.tabContainer.childNodes.forEach(child => {
            // console.log(child, 'tab child');
            if (child && child.hasAttribute && child.hasAttribute('title')) {
                var tabTitle = child.getAttribute('title');
                child.show = () => {
                    child.style.display = 'block';
                    if (child.hasAttribute('value')) {
                        this.$emit('input', child.getAttribute('value'));
                    }
                    this.activeTabTitle = tabTitle;
                };
                child.hide = function() {
                    child.style.display = 'none';
                };

                if (isSelected(child)) {
                    child.show();
                } else {
                    child.hide();
                }

                child.removeAttribute('title');

                map[tabTitle] = child;
            }
        });

        this.tabs = map;
    },
    methods: {
        hideAll() {
            Object.keys(this.tabs).map((key) => {
                this.tabs[key].hide();
            });
        }
    }
};
</script>