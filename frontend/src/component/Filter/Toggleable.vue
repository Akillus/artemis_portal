<template>
  <div>
    <div
      v-if="showView && lockBodyOnActive"
      class="bg-black opacity-60 fixed top-0 left-0 w-full h-full z-30"
      @click="showView = false"
    >
    </div>

    <div
      class="fixed left-0 z-20 w-none"
      :class="{ 'z-30 w-auto': showView }"
    >
      <div
        class="mt-2xl w-toolbar transition-all ease-out transform duration-300"
        :class="{
          '-translate-x-toolbar': !showView,
          'shadow-full': showView,
        }"
        :style="[showView ? { 'max-width': `${ style.maxWidth }px` } : {}]"
      >
        <div class="relative">
          <ul
            class="bg-white text-blue inline-block absolute top-0 left-full cursor-pointer transition-color duration-300"
            style="border:1px solid rgba(10,31,58,.08); box-shadow: 0 10px 30px rgba(10,31,58,.08);"
          >
            <li
              @click="toggleView('filters')"
              class="px-md py-sm cursor-pointer text-md text-center"
            >
              <i class="fa fa-filter"></i><span v-if="showView">Hide filters</span><span v-else>Show filters</span>
            </li>
          </ul>

          <div
            v-if="view === 'filters'"
            :id="toggleId"
            class="p-sm overflow-y-auto overflow-x-hidden"
            @scroll.passive="setScrollPosition"
            :style="{ 'max-height': `${ style.maxHeight }px` }"
          >
            <slot />

            <div
              v-if="scrollPosition && style.maxHeight"
              class="absolute top-0 left-0 w-full h-lg"
              style="background-image: linear-gradient(to top, transparent, #fff);"
            >
            </div>

            <div v-if="style.maxHeight"
              class="absolute bottom-0 left-0 w-full h-lg"
              style="background-image: linear-gradient(to bottom, transparent, #fff);"
            >
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { watch, onUnmounted, onMounted } from 'vue';
import { $ref, $computed, $$ } from 'vue/macros';
import { generalModule } from "@/store/modules";
import utils from '@/utils/utils';

interface iStyle {
  maxWidth: number,
  maxHeight: number,
}

const props = defineProps<{
  lockBodyOnActive?: Boolean,
  defaultView?: Boolean
}>();

const toggleId: string = 'toggleable-' + utils.getUniqueId();
let view: string = $ref('filters');
let showView: Boolean = $ref(false);
let scrollPosition: number = $ref(0);
let style: iStyle = $ref({
  maxWidth: 0,
  maxHeight: 0,
});

const window = $computed(() => generalModule.getWindow);

// unlock scroll behind overlay if user resizes window without closing it first
onUnmounted(() => (document.body as any).style.overflowY = null);

const toggleView = (newView: any) => {
  showView = view !== newView || !showView;
  view = newView;
}

const setScrollPosition = () => {
  scrollPosition = document.getElementById(toggleId)?.scrollTop || 0;
}

const resize = () => {
  const el = document.getElementById(toggleId);
  if (el) {
    const rect = el.getBoundingClientRect();

    style = {
      // stretches to right side of window and leaves a "5x" padding at end
      maxWidth: window.innerWidth - 70,

      // stretches to bottom of window and leaves a "2xl" padding at end
      maxHeight: window.innerHeight - rect.top - 17.5,
    };
  }
}

onMounted(() => {
  if(props.defaultView) {
    showView = props.defaultView;
  }
});

watch($$(window), resize);
watch($$(showView), () => {
  if (showView) {
    // lock scroll behind overlay
    document.body.style.overflowY = 'hidden';
    resize();

  } else {
    // unlock scroll behind overlay
    (document.body as any).style.overflowY = null;
  }
});

</script>
