import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { Features } from 'lightningcss'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const apiTarget = env.VITE_API_PROXY || 'http://127.0.0.1:8800'
  const backendProxy = () => ({
    target: apiTarget,
    changeOrigin: false,
  })

  return {
    plugins: [
      vue({
        template: {
          // Vue plugin defaultně transformuje absolutní URL (např. <img src="/styles/logo.svg">)
          // na JS importy. To rozbije ESM, protože servr vrátí SVG MIME pro JS module request.
          // /styles/* je u nás mimo Vite — servíruje webserver z repo rootu, takže nech URL být.
          transformAssetUrls: {
            includeAbsolute: false,
          },
        },
      }),
      tailwindcss(),
    ],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    css: {
      transformer: 'lightningcss',
      lightningcss: {
        // Tailwind 4 v dev režimu generuje nativně vnořené @media. Některá
        // Chromium prostředí je nevyhodnotí, proto nesting vždy rozbalíme.
        include: Features.Nesting,
      },
    },
    server: {
      host: 'dev.myportfolio.cz',
      port: 5173,
      strictPort: true,
      // manuál (../manual/*.md) se importuje do bundlu
      fs: { allow: ['..'] },
      proxy: {
        '/api': backendProxy(),
        '/styles': backendProxy(),
      },
    },
    build: {
      outDir: 'dist',
      emptyOutDir: true,
      sourcemap: false,
      target: 'es2022',
      rollupOptions: {
        // /styles/ je mimo web/, servíruje webserver z repo rootu — neřešit při bundle
        external: [/^\/styles\//],
        // @vueuse/core má /* #__PURE__ */ anotace na pozicích, které Rolldown neumí
        // interpretovat — neškodné varování z node_modules, potlačujeme.
        onwarn(warning, defaultHandler) {
          if (warning.code === 'INVALID_ANNOTATION') return
          defaultHandler(warning)
        },
        output: {
          manualChunks(id) {
            if (id.includes('node_modules')) {
              if (/[\\/]node_modules[\\/](vue|vue-router|pinia|@vue)[\\/]/.test(id)) return 'vue'
              if (id.includes('vue-i18n') || id.includes('@intlify')) return 'i18n'
            }
          },
        },
      },
    },
  }
})
