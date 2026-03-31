<template>
  <header class="portal-header hidden md:block">
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
      <nav class="portal-nav">
        <b-link
          v-for="item in generalModule.getMainNavigation"
          :key="item.path"
          :to="item.path"
          :class="{ active: isActive(item.path) }"
        >
          {{ item.name }}
        </b-link>
      </nav>
    </div>
  </header>
</template>

<script setup lang="ts">
import { watch, onMounted } from 'vue';
import { $ref, $computed } from 'vue/macros';
import { useRoute } from 'vue-router'
import { generalModule } from "@/store/modules";
import BLink from '@/component/Base/Link.vue';

const route = useRoute();
let path: string = $ref('');
const assets: string = $computed(() => generalModule.getAssetsDir);
const logoSrc: string = $computed(() => `${assets}/artemis-logo.png`);

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
