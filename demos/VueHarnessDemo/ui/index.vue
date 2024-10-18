<template url="/" title="XXX">
    <div>
        <div>Dit is ook een app!</div>

        <button @click="launchDialog()">Click</button>
    </div>
</template>
<style  scoped>
:root {
    color: blue;
}
</style>
<script>
export default { 
    methods: {
        launchDialog() {
            dialog.dialog(`<div>Hoe is het?</div>`);
        }
    }
}
</script>