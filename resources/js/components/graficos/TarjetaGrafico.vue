<script setup>
import { ref } from 'vue'

/**
 * El marco común: título, alternar gráfico/tabla y el cuerpo. Tenerlo aparte
 * evita que cada gráfico invente su propia cabecera.
 */
defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },
    conTabla: { type: Boolean, default: true },
})

const mostrandoTabla = ref(false)
</script>

<template>
    <section class="tarjeta flex flex-col">
        <header class="flex flex-wrap items-baseline gap-x-3 gap-y-2 px-5 pb-3 pt-4">
            <div>
                <h2 class="text-[13px] font-medium">{{ titulo }}</h2>
                <p v-if="subtitulo" class="mt-0.5 text-[11.5px] text-[var(--color-tenue)]">{{ subtitulo }}</p>
            </div>

            <div class="ml-auto flex items-center gap-3">
                <slot name="leyenda" />

                <button
                    v-if="conTabla"
                    class="shrink-0 whitespace-nowrap text-[11.5px] font-medium text-[var(--color-enlace)]"
                    @click="mostrandoTabla = !mostrandoTabla"
                >
                    {{ mostrandoTabla ? 'Ver gráfico' : 'Ver tabla' }}
                </button>
            </div>
        </header>

        <div v-show="!mostrandoTabla" class="min-h-0 flex-1">
            <slot />
        </div>

        <div v-show="mostrandoTabla">
            <slot name="tabla" />
        </div>
    </section>
</template>
