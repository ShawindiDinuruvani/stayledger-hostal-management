import { defineConfig } from 'vite';

export default defineConfig({
  server: {
    host: 'localhost',
    port: 5173,
    proxy: {
      '/laravel': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true,
        rewrite: path => path.replace(/^\/laravel/, ''),
      },
    },
  },
});
