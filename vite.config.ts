import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/**
 * The admin app is a standalone React application, deliberately built with no
 * `@wordpress/*` packages of any kind. It is served from `assets/admin/` and
 * mounted into a bare WordPress admin page.
 *
 * React and ReactDOM are bundled rather than taken from WordPress's registered
 * copies: WordPress ships whichever React version its current release pins, and
 * a plugin that borrows it inherits every future core upgrade as a breaking
 * change it cannot test against.
 */
export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    outDir: 'assets/admin',
    emptyOutDir: true,
    manifest: false,
    rollupOptions: {
      input: 'admin-app/src/main.tsx',
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'app-[name].js',
        assetFileNames: 'app.[ext]',
      },
    },
  },
});
