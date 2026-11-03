<script setup>
/**
 * El set de iconos de la app: trazos de 1.6 sobre una grilla de 24, todos con
 * el mismo grosor y remate redondo. Se agrega un trazo aquí antes que traer una
 * librería entera para veinte dibujos.
 */
defineProps({
    nombre: { type: String, required: true },
    tamano: { type: Number, default: 18 },
})

const TRAZOS = {
    panel: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    inicio: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
    viajes: 'M2 6h11v10H2zM13 9h4.5L21 12.5V16h-8M6.5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM17.5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
    vales: 'M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2zM9 8h6M9 12h6',
    liquidaciones: 'M3 7h18v12H3zM3 7l2-3h14l2 3M16 13h2',
    tarifas: 'M3 12V4h8l10 10-8 8zM7.5 8.5h.01',
    terceros: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM2 21a7 7 0 0 1 14 0M16 3.5a4 4 0 0 1 0 7.5M22 21a7 7 0 0 0-4-6.3',
    volquetas: 'M2 15V8h9l3 3h8v4M2 15h20M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM18 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM11 8 14 4',
    canteras: 'M2 20 9 7l4 7 3-4 6 10z',
    botaderos: 'M4 7h16M9 7V4h6v3M6 7l1 14h10l1-14',
    materiales: 'M12 3 2 8l10 5 10-5zM2 13l10 5 10-5',
    consorcios: 'M4 21V5l8-2v18M12 8h8v13M2 21h20M7 8v.01M7 12v.01M7 16v.01M16 12v.01M16 16v.01',
    obras: 'M4 20h16M6 20l6-15 6 15M8.5 14h7',
    salir: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
    menu: 'M3 6h18M3 12h18M3 18h18',
    x: 'M6 6l12 12M18 6 6 18',
    mas: 'M12 5v14M5 12h14',
    subir: 'M12 19V5M5 12l7-7 7 7',
    bajar: 'M12 5v14M19 12l-7 7-7-7',
    reloj: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 7v5l3 2',
    alerta: 'M12 3 2 20h20zM12 10v4M12 17v.01',
    check: 'M4 12.5 9 17.5 20 6.5',
    flecha: 'M5 12h14M13 6l6 6-6 6',
    atras: 'M19 12H5M11 6l-6 6 6 6',
    soporte: 'M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-5A8 8 0 1 1 21 12Z',
    pagos: 'M2 6h20v12H2zM2 10h20M6 15h4',
    adjunto: 'M21 11.5 12.5 20a5 5 0 0 1-7-7L14 4.5a3.3 3.3 0 0 1 4.7 4.7L10.2 17.7a1.7 1.7 0 0 1-2.4-2.4L15.5 7.6',
    imprimir: 'M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-2M6 14h12v7H6z',
}
</script>

<template>
    <svg
        :width="tamano"
        :height="tamano"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="shrink-0"
        aria-hidden="true"
    >
        <path :d="TRAZOS[nombre] ?? 'M4 4h16v16H4z'" />
    </svg>
</template>
