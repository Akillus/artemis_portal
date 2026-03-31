<!-- Front page -->
<template>
  <div class="pt-3x max-w-screen-xl mx-auto">
    <div class="px-base pb-4x pt-4x lg:pt-8x 2xl:pt-11x relative z-10">
      <div class="frontpage-hero rounded-3xl px-2x py-3x lg:px-4x lg:py-4x">
      <div class="text-center mb-2x">
        <div class="catalogue-kicker mb-base">Research Catalogue</div>
        <div class="brand-chip mb-lg">ARTEMIS</div>
        <h1 class="catalogue-heading text-2x lg:text-4x mb-base">Discover heritage data through the ARTEMIS portal.</h1>
        <p class="catalogue-copy max-w-2xl mx-auto text-hg">
          A refined catalogue for exploring datasets, services, places, periods and publishers with the visual language of ARTEMIS: precise, luminous and research-led.
        </p>
      </div>
      <div class="flex items-center justify-center relative">
        <div
          class="text-blue font-bold whitespace-nowrap logo-text">
          Cultural Heritage Digital Twins
        </div>
      </div>

      <div class="frontpage-search-panel rounded-3xl max-w-3xl m-auto px-base py-lg">
      <filter-search
        class="mt-sm max-w-2xl m-auto"
        color="blue"
        hoverStyle="hover:bg-blue-80"
        focusStyle="focus:border-blue"
        :veryBig="true"
        :autoFocus="true"
        :showFields="fieldsType"
        :useCurrentSearch="false"
        :hasAutocomplete="true"
      />
      </div>

      <div class="p-base text-darkGray text-mmd w-full mt-mmd max-w-3xl m-auto rounded-2xl app-panel"
        :class="(imageText ? 'opacity-1' : 'opacity-0') + (bgCounter > 0 ? ' transition-opacity duration-500 ease-linear' : '')">
        <div class="font-bold mb-sm flex justify-between" v-if="imageText?.title">
          <div>{{ imageText.title }}</div>
          <div class="whitespace-nowrap">
            <i class="fas fa-chevron-left cursor-pointer px-sm hover:text-blue transition-color duration-300" @click="setSlideDir(-2)"></i>
            <span>{{ bgData ? bgData.count + 1 : 0 }} / {{ bgTotalPics }}</span>
            <i class="fas fa-chevron-right cursor-pointer pl-sm hover:text-blue transition-color duration-300 mr-sm" @click="setSlideDir(0)"></i>

            <i class="cursor-pointer pl-sm hover:text-blue transition-color duration-300"
              :class="'fas fa-' + (bgIsPaused ? 'play' : 'pause')"
              @click="togglePaused()"></i>
          </div>
        </div>
        <p>{{ imageText?.text }}</p>
      </div>
      </div>
    </div>

    <div class="fixed top-0 left-0 w-screen h-screen z-neg10" style="background:linear-gradient(rgba(255,255,255,.74),rgba(255,255,255,.9))"></div>
    <div
      class="fixed top-0 left-0 w-screen h-screen z-neg20 bg-cover bg-no-repeat bg-center"
      :style="bgStyle1" :class="activeBg > 0 ? 'transition-opacity duration-500 ease-linear' : ''"></div>
    <div
      class="fixed top-0 left-0 w-screen h-screen z-neg20 bg-cover bg-no-repeat bg-center transition-opacity duration-500 ease-linear"
      :style="bgStyle2"></div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import { $ref, $computed } from 'vue/macros';
import { searchModule, generalModule } from "@/store/modules";
import utils from '@/utils/utils';
import BLink from '@/component/Base/Link.vue';
import HelpTooltip from '@/component/Help/Tooltip.vue';
import FilterSearch from '@/component/Filter/Search.vue';

const window = $computed(() => generalModule.getWindow);
const assets: string = $computed(() => generalModule.getAssetsDir);
const fieldsType: string = $computed(() => window.innerWidth < 568 ? 'select' : 'radio');

const bgTime: number = 20000;
let bgData: any = $ref(null);
let bgTimeout: any = 0;
let bgCounter: number = 0;
let bgIsPaused: boolean = $ref(false);
let activeBg: number = $ref(0);
let bg1: number = $ref(0);
let bg2: number = $ref(0);
let imageText: any = $ref(null);
const storageId = 'frontBgDataV2-';
const bgTotalPics: number = $computed(() => generalModule.getFrontPageImageTotal);
const frontPageImageTexts = $computed(() => generalModule.getFrontPageImageTexts);
const bgStyle1 = $computed(() => ({ 'background-image': bg1 ? `url(${ assets }/frontpage/bg-${ bg1 }.jpg)` : '', opacity: activeBg !== 2 ? 1 : 0 }));
const bgStyle2 = $computed(() => ({ 'background-image': bg2 ? `url(${ assets }/frontpage/bg-${ bg2 }.jpg)` : '', opacity: activeBg === 2 ? 1 : 0 }));

// Simple slideshow - Cycle through all bg images - try catch since localstorage can crash if disabled cookies etc
const setBgSlide = (callback?: Function) => {
  if (bgIsPaused) {
    bgTimeout = setTimeout(setBgSlide, bgTime);
    return;
  }

  if (!bgData) {
    try {
      bgData = JSON.parse(window.localStorage.getItem(storageId + bgTotalPics));
    } catch (ex) {}
  }

  if (!bgData) {
    bgData = {
      arr: utils.shuffle(Array.from(new Array(bgTotalPics)).map((n, i) => i + 1)),
      paused: false,
      count: 0
    };
  } else if (bgData.count >= bgTotalPics - 1) {
    utils.shuffle(bgData.arr);
    bgData.count = 0;
  } else if (bgData.count < -1) {
    bgData.count = bgTotalPics - 1;
  } else {
    bgData.count++;
  }

  try {
    window.localStorage.setItem(storageId + bgTotalPics, JSON.stringify(bgData));
  } catch (ex) {}

  const num = bgData.arr[bgData.count];
  const img = new Image();
  img.onload = () => {
    img.onload = null;
    if (!bgIsPaused) {
      imageText = frontPageImageTexts[num];
      activeBg = activeBg === 1 ? 2 : 1;
      if (activeBg === 1) {
        bg1 = num;
      } else {
        bg2 = num;
      }
      if (bgData.paused) {
        bgIsPaused = true;
      } else {
        bgCounter++;
      }
    }
    if (typeof callback === 'function') {
      callback();
    }
    bgTimeout = setTimeout(setBgSlide, bgTime);
  }
  img.src = `${ assets }/frontpage/bg-${ num }.jpg`;
};

// prev / next bg image from click
const setSlideDir = (dir: number) => {
  bgData.count += dir;
  bgIsPaused = false;
  bgData.paused = true;
  clearTimeout(bgTimeout);
  setBgSlide(() => bgIsPaused = true);
};

// toggles paused / playing
const togglePaused = () => {
  bgIsPaused = !bgIsPaused;
  bgData.paused = bgIsPaused;
  try {
    window.localStorage.setItem(storageId + bgTotalPics, JSON.stringify(bgData));
  } catch (ex) {}
};

onMounted(() => {
  // Start slideshow
  setBgSlide();

  // Reset main sore states, if any.
  searchModule.actionResetResultState();

  // Auto focus search field on init
  const input: HTMLInputElement | null = document.querySelector('.auto-focus');
  if (input && !utils.isMobile()) {
    input.focus();
  }
});

onUnmounted(() => clearTimeout(bgTimeout));
</script>
