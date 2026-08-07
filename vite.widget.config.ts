import { defineConfig } from 'vite';

/**
 * The storefront widget is built separately from the admin application, and
 * shares nothing with it.
 *
 * Separate because the constraints are opposite. The admin app is a React SPA
 * behind a login, where 300 KB is fine; the widget loads on every product page
 * of a store whose owner is judged on Core Web Vitals, and has a 45 KB gzipped
 * budget (FR-CHAT-01). One build producing both would drag the widget toward
 * the admin's dependencies.
 *
 * `iife` rather than an ES module: the script is enqueued through
 * `wp_enqueue_script` with `async`, and a `type="module"` tag would need
 * WordPress's module registry and would be deferred until after the document is
 * parsed anyway.
 */
export default defineConfig({
  build: {
    outDir: 'assets/widget',
    emptyOutDir: true,
    // The stylesheet is imported with `?inline` and injected into the shadow
    // root, so no CSS file should be emitted or requested separately.
    cssCodeSplit: false,
    target: 'es2019',
    rollupOptions: {
      input: 'widget-app/src/main.ts',
      output: {
        format: 'iife',
        entryFileNames: 'widget.js',
        assetFileNames: 'widget.[ext]',
      },
    },
  },
});
