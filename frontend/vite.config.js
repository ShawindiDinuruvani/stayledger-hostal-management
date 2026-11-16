import { defineConfig, loadEnv } from 'vite';

// Monthly Fees uses port 8001; User Accounts uses port 8002 by default.
// Override only the User Accounts proxy with VITE_USER_ACCESS_API_URL.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  const authBackend = env.VITE_USER_ACCESS_API_URL || 'http://127.0.0.1:8002';

  return {
    server: {
      host: 'localhost',
      port: 5173,
      proxy: {
        '/laravel': {
          target: authBackend,
          changeOrigin: true,
          rewrite: path => path.replace(/^\/laravel/, ''),
        },
      },
    },
  };
});
