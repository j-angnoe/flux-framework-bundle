<template url="/io-with-controller">
    <div>
        <i class="fa fa-home"></i>

        Calling myMethod on controller with arguments 1,2,3

        Result:
        <pre v-text="result"></pre>
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        result = null;
        async mounted() {
            this.result = await server.myMethod(1,2,3);
        }
    }
    </script>
</template>
