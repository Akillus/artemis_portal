<!--
  App.vue - main component
-->
<template>
  <div class="app-shell">
    <div class="app-shell__bg"></div>
    <div class="app-shell__grain"></div>
    <div class="app-shell__content">
      <layout-mobile-header />
      <layout-desktop-header />

      <div class="pb-11x app-shell__main">
        <router-view />
      </div>

      <layout-footer />
    </div>

    <help-loader />
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue';
import { generalModule } from "@/store/modules";
import LayoutMobileHeader from './component/Layout/MobileHeader.vue';
import LayoutDesktopHeader from './component/Layout/DesktopHeader.vue';
import LayoutFooter from './component/Layout/Footer.vue';
import HelpLoader from './component/Help/Loader.vue';
import '../static/fa/css/all.min.css';
import 'leaflet/dist/leaflet.css';
import "leaflet.markercluster/dist/MarkerCluster.css";
import "leaflet.markercluster/dist/MarkerCluster.Default.css";
import "leaflet-draw/dist/leaflet.draw.css";

onMounted(() => {
  generalModule.setServicesAndPublishers();
  generalModule.setWindow();
  window.addEventListener('resize', () => generalModule.setWindow());
});
</script>
