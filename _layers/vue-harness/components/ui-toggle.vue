<template>

	<span>
		<span v-if="!toggled">
			<span @click="toggled=true">
			<slot name="title">
				<a href="javascript:;">
					{{$attrs.title || $attrs.name || $attrs.label}}
				</a>
			</slot>
		</span>
		</span>
		<div v-else="">
			<slot name="expand" v-bind="{close: () => toggled = false}" @close="toggle=false"></slot>
			<slot :close="() => toggled = false" @close="toggle=false"></slot>
		</div>
	</span>
	

</template>
<script>
export default {
    data() {
        return {
            toggled: false
        }
    },
    props: ['storageKey'],
    mounted() {
        if (this.storageKey) {
            this.link('toggled').to.localStorage(this.storageKey);
        }
    },
    methods: {

    }
};
</script>