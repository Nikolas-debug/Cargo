<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { mensajeDeError } from '../lib/api'
import { urlDelComprobante } from '../lib/comprobante'
import Icono from './Icono.vue'

/** El comprobante de un pago ya guardado, en un diálogo. v-model = id del anticipo o null. */
const anticipoId = defineModel({ type: [Number, null], default: null })

const archivo = ref(null)
const error = ref('')

watch(anticipoId, async (id) => {
    if (archivo.value) URL.revokeObjectURL(archivo.value.url)
    archivo.value = null
    error.value = ''
    if (!id) return

    try {
        archivo.value = await urlDelComprobante(id)
    } catch (e) {
        error.value = mensajeDeError(e, 'No se pudo abrir el comprobante.')
    }
})

onBeforeUnmount(() => archivo.value && URL.revokeObjectURL(archivo.value.url))
</script>

<template>
    <div v-if="anticipoId" class="velo fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-6" @click.self="anticipoId = null">
        <div class="flotante dialogo flex max-h-[92dvh] w-full max-w-[720px] flex-col">
            <div class="flex items-center border-b border-[var(--color-borde)] px-5 py-3.5">
                <h2 class="text-[16px] font-normal">Comprobante</h2>
                <a v-if="archivo" :href="archivo.url" target="_blank" rel="noopener" class="ml-auto text-[12.5px] font-medium text-[var(--color-enlace)]">Abrir aparte</a>
                <button class="ml-4 text-[var(--color-tenue)]" :class="archivo ? '' : 'ml-auto'" aria-label="Cerrar" @click="anticipoId = null">
                    <Icono nombre="x" :tamano="16" />
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-auto bg-[var(--color-superficie-sutil)] p-3" style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom))">
                <p v-if="error" class="p-6 text-center text-[13px] text-[var(--color-critico)]">{{ error }}</p>
                <p v-else-if="!archivo" class="p-6 text-center text-[13px] text-[var(--color-tenue)]">Cargando…</p>
                <img v-else-if="archivo.tipo.startsWith('image/')" :src="archivo.url" alt="Comprobante" class="mx-auto max-h-[75dvh] object-contain">
                <iframe v-else :src="archivo.url" title="Comprobante" class="h-[75dvh] w-full rounded bg-white"></iframe>
            </div>
        </div>
    </div>
</template>
