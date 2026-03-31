<template>
  <div class="md:hidden">
    <header class="portal-header">
      <div class="portal-header__content">
        <b-link to="/" class="portal-logo" aria-label="Visit ARTEMIS">
          <img
            :src="logoSrc"
            alt="ARTEMIS logo"
            width="240"
            height="68"
            loading="eager"
            fetchpriority="high"
          />
          <span class="portal-logo__tagline">Data Infrastructure</span>
        </b-link>
        <button
          type="button"
          class="text-xl focus:outline-none"
          @click="toggle"
          :aria-expanded="show"
          aria-label="Toggle navigation menu"
        >
          <i v-if="show" class="fas fa-times"></i>
          <i v-else class="fas fa-bars"></i>
        </button>
      </div>
    </header>

    <transition name="fade">
      <div v-show="show" class="mobile-menu">
        <div class="mobile-menu__backdrop" @click="toggle"></div>
        <div class="mobile-menu__inner">
          <b-link
            v-for="item in generalModule.getMainNavigation"
            :key="item.path"
            :to="item.path"
            class="mobile-menu-link"
            :class="{ active: isActive(item.path) }"
            @click="navigate(item.path)"
          >
            {{ item.name }}
          </b-link>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup lang="ts">
import { watch, onMounted } from 'vue';
import { $ref, $computed } from 'vue/macros';
import { useRoute, useRouter } from 'vue-router'
import { generalModule } from "@/store/modules";
import BLink from '@/component/Base/Link.vue';

const router = useRouter();
const route = useRoute();
let show: boolean = $ref(false);
let path: string = $ref('');
const assets: string = $computed(() => generalModule.getAssetsDir);
const logoSrc: string = $computed(() => `${assets}/artemis-logo.png`);

const toggle = () => {
  show = !show;
}

const navigate = (path: string) => {
  router.push(path);
  show = false;
}

const isActive = (itemPath: string): boolean => {
  return path.includes(itemPath) ||
    (itemPath.includes('search') && path.includes('resource'));
}

const updateMenuPath = (): void => {
  path = route.fullPath;
}

onMounted(updateMenuPath);
watch(route, updateMenuPath);
</script>
