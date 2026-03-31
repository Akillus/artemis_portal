<template>
  <footer class="portal-footer">
    <div class="portal-footer__content">
      <div>
        <p>
          © {{ new Date().getFullYear() }} ARTEMIS Data Infrastructure. Built on OpenSearch and the ARIADNE GraphDB federation.
        </p>
        <p>
          Powered by the ARTEMIS and ARIADNE collaboration for archaeological and heritage science data discovery.
        </p>
      </div>
      <div class="portal-footer__links">
        <b-link href="https://www.artemis-twin.eu/" target="_blank">ARTEMIS site</b-link>
        <b-link href="https://ariadne-graphdb.cloud.d4science.org/" target="_blank">GraphDB</b-link>
      </div>
    </div>
  </footer>
</template>

<script setup lang="ts">
import BLink from '@/component/Base/Link.vue';
</script>
