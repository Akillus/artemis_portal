<template>
  <button
    v-if="hasParams"
    class="filter-clear-button text-md focus:outline-none transition-all duration-200 block"
    @click.prevent="clearFilters"
  >
    Clear all filters
    <i class="fas fa-times ml-sm text-midGray"></i>
  </button>
</template>

<script setup lang="ts">
import { $computed } from 'vue/macros';
import { searchModule, aggregationModule } from "@/store/modules";
import router from '@/router';
import utils from '@/utils/utils';

const props = defineProps<{
  ignoreParams: Array<string>,
}>();

const params = $computed(() => searchModule.getParams);
const hasParams: boolean = $computed(() => !utils.objectEquals(params, { q: ''}, props.ignoreParams?.concat(router.currentRoute.value.path === '/browse/where' ? ['bbox'] : [])))

const clearFilters = () => {
  const clearParams: any = { clear: true };
  if (params?.mapq) {
    clearParams.mapq = true;
  }
  if (params?.bbox && router.currentRoute.value.path === '/browse/where') {
    clearParams.bbox = params.bbox;
  }
  searchModule.setSearch(clearParams);
  aggregationModule.setOptionsToDefault();
}
</script>
