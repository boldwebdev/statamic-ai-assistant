<template>
  <AiInputWrapper :value="value" :inputType="'textarea'" @input="update"  />
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

