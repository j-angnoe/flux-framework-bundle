<template>

    <div>
        <pre v-for="(val, name) in target">{{name}}: {{val}}</pre>
    </div>
    

</template>
<script>

document.addEventListener('DOMContentLoaded', event => {

    Vue.prototype.debug = function (...args) {
        if (args.length == 1) { 
            args = args[0];
        }
        
        dialog.dialog(`<pre 
            xstyle="white-space: pre-wrap;" 
            title="Debug" 
            width=800 
            height=800>{{data}}
        </pre>`, { data: { data: JSON.stringify(args, null, 3).replace(/\\n/g,"\n") } });
    }
})

export default {
    data() {
        return {

        }
    },
    computed: {
        target() {
            if (Object.keys(this.$attrs).length > 0) {
                return {
                    '$attrs': this.$attrs
                };
            } else {
                return {
                    '$data': this.$parent.$data,
                    '$props': this.$parent.$props,
                    '$attrs': this.$parent.$attrs,
                }
            }
        }
    },
    mounted() {
        console.log("debugging", this.$parent);
    },
    methods: {

    }
};
</script>