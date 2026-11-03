<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { prepararArchivo } from '../lib/comprobante'
import Icono from './Icono.vue'

/**
 * Elegir la foto o el PDF del comprobante y verlo antes de guardar.
 * En el celular, "Tomar foto" abre la cámara directamente.
 */
const archivo = defineModel({ type: [File, null], default: null })

const vista = ref(null)
const preparando = ref(false)

async function elegir(evento) {
    const elegido = evento.target.files?.[0]
    evento.target.value = ''
    if (!elegido) return

    preparando.value = true

    try {
        archivo.value = await prepararArchivo(elegido)
        if (vista.value) URL.revokeObjectURL(vista.value)
        vista.value = URL.createObjectURL(archivo.value)
    } finally {
        preparando.value = false
    }
}

function quitar() {
    if (vista.value) URL.revokeObjectURL(vista.value)
    vista.value = null
    archivo.value = null
}

onBeforeUnmount(() => vista.value && URL.revokeObjectURL(vista.value))
</script>

<template>
    <div>
        <label class="etiqueta">Comprobante (foto o PDF)</label>

        <div v-if="!archivo" class="flex flex-wrap gap-2">
            <label class="boton-secundario cursor-pointer">
                <Icono nombre="adjunto" :tamano="15" /> {{ preparando ? 'Preparando…' : 'Adjuntar archivo' }}
                <input type="file" accept="image/*,application/pdf" class="sr-only" @change="elegir">
            </label>
            <label class="boton-secundario cursor-pointer sm:hidden">
                Tomar foto
                <input type="file" accept="image/*" capture="environment" class="sr-only" @change="elegir">
            </label>
        </div>

        <div v-else class="overflow-hidden rounded-md border border-[var(--color-borde-fuerte)]">
            <img v-if="archivo.type.startsWith('image/')" :src="vista" alt="Vista previa del comprobante" class="max-h-56 w-full bg-[var(--color-superficie-sutil)] object-contain">
            <iframe v-else :src="vista" title="Vista previa del comprobante" class="h-56 w-full"></iframe>
            <div class="flex items-center gap-2 border-t border-[var(--color-borde)] px-3 py-2 text-[12px]">
                <span class="min-w-0 flex-1 truncate text-[var(--color-apagado)]">{{ archivo.name }} · {{ Math.ceil(archivo.size / 1024) }} KB</span>
                <button type="button" class="font-medium text-[var(--color-critico)]" @click="quitar">Quitar</button>
            </div>
        </div>
    </div>
</template>
