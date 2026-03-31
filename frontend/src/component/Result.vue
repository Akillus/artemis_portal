<template>
  <div class="page-main">
    <div class="content-grid">
      <aside>
        <template v-if="window.innerWidth < 1000">
          <filter-toggleable class="mb-lg">
            <filter-list :show="['search', 'miniMap', 'timeLine', 'aggregations']" />
          </filter-toggleable>
        </template>
        <template v-else>
          <filter-list :show="['search', 'miniMap', 'timeLine', 'aggregations']" />
        </template>
      </aside>

      <section class="result-panel">
        <div class="result-layout flex flex-col">
          <div class="result-toolbar result-toolbar--aligned flex flex-col gap-4">
            <h2 class="catalogue-heading text-2xl">Results</h2>
            <result-info />
            <div class="result-toolbar__row flex flex-wrap items-center justify-between gap-4">
              <div class="result-toolbar__controls flex flex-wrap items-center">
                <result-sort-order />
                <div class="result-toolbar__per-page">
                  <result-per-page />
                </div>
              </div>
              <result-paginator />
            </div>
          </div>
          <div class="result-list-wrap">
            <result-list />
          </div>
          <result-paginator :scrollTop="true" />
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import { watch } from 'vue';
import { $computed } from 'vue/macros';
import { useRoute, onBeforeRouteLeave } from 'vue-router'
import { generalModule, searchModule } from "@/store/modules";

// base & utils
import utils from '@/utils/utils';

// general
import FilterList from '@/component/Filter/List.vue';
import FilterToggleable from '@/component/Filter/Toggleable.vue';

// unique
import ResultList from './Result/List.vue';
import ResultInfo from './Result/Info.vue';
import ResultPaginator from './Result/Paginator.vue';
import ResultSortOrder from './Result/SortOrder.vue';
import ResultPerPage from './Result/PerPage.vue';

let first: boolean = true;

const route = useRoute();
const window = $computed(() => generalModule.getWindow);
const params = $computed(() => searchModule.getParams);

const setMeta = () => {
  let title = 'Search';

  if (params.q) {
    title = `Search results: ${ params.q }`;
  }
  if (parseInt(params.page) > 1) {
    title += ` (page ${ params.page })`;
  }

  generalModule.setMeta({
    title: title,
    description: title,
  });
}

const unwatch = watch(route, async (path: any) => {
  if (first) {
    searchModule.actionResetResultState();
    first = false;
  }
  await searchModule.setSearch({ fromRoute: true });
  setMeta();

}, { immediate: true });

onBeforeRouteLeave(unwatch);
</script>
