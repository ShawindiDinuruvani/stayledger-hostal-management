import { defineConfig, loadEnv } from 'vite';

// Monthly Fees uses port 8001; User Accounts uses port 8002 by default.
// Override only the User Accounts proxy with VITE_USER_ACCESS_API_URL.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  const authBackend = env.VITE_USER_ACCESS_API_URL || 'http://127.0.0.1:8002';
  const feesBackend = env.VITE_FEES_BACKEND_URL || 'http://127.0.0.1:8001';
  const nativeBackend = env.VITE_NATIVE_BACKEND_URL || 'http://127.0.0.1:8000';

  return {
    server: {
      host: 'localhost',
      port: 5173,
      proxy: {
        '/native-api': {
          target: nativeBackend,
          changeOrigin: true,
          rewrite: path => path.replace(/^\/native-api/, '/api'),
        },
        '/fees-api': {
          target: feesBackend,
          changeOrigin: true,
          rewrite: path => path.replace(/^\/fees-api/, '/api'),
        },
        '/laravel': {
          target: authBackend,
          changeOrigin: true,
          rewrite: path => path.replace(/^\/laravel/, ''),
        },
      },
    },
  };
});
