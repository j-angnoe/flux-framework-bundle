<template>

  <div>
        <table v-if="table" class="table">
            <tbody><tr v-for="(value, name) in data" v-if="isString(value) || $scopedSlots[name]">
                <th>{{name}}</th>
                <td><slot :name="name" v-bind="{value,name}">{{value}}</slot></td>
            </tr>
        </tbody></table>
        <template v-else="" v-for="(value, name) in data">
            <div v-if="isString(value) || $scopedSlots[name]">
                <div><b>{{name}}</b></div>
                <slot :name="name" v-bind="{value,name}">
                    <div>{{value}}</div>
                </slot>
            </div>
            
        </template>
  </div>
  

</template>
<script>
export default {
    props: ["data", "table"],
    data() {
        return {

        }
    },
    methods: {
        isString(v) {
            return typeof v == 'string';
        }
    }
};
</script>