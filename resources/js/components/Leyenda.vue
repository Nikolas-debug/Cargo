<script setup>
/**
 * Siempre presente cuando hay dos o más series: la identidad no puede
 * depender de que el lector recuerde qué color era cuál. El texto va en tinta,
 * nunca del color de la serie; el punto de color es el que lleva la identidad.
 */
defineProps({
    series: { type: Array, required: true }, // [{ nombre, color }]
})
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-3.5 gap-y-1">
        <span v-for="serie in series" :key="serie.nombre" class="flex items-center gap-1.5 text-[11.5px] text-[var(--color-apagado)]">
            <span class="size-2.5 rounded-full" :style="{ background: serie.color }"></span>
            {{ serie.nombre }}
        </span>
    </div>
</template>
