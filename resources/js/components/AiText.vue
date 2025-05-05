<template>
  <AiInputWrapper :value="value" :inputType="'input'" @input="update"  />
</template>
<script>
import AiInputWrapper from './AiInputWrapper.vue';

export default {
  mixins: [Fieldtype],
  components: {
    AiInputWrapper
  }
}
</script>

