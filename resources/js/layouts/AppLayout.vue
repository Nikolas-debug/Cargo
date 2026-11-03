<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '../stores/auth'
import api from '../lib/api'
import Icono from '../components/Icono.vue'

const auth = useAuth()
const router = useRouter()
const route = useRoute()

// El contador de pendientes es el que le dice al digitador qué le falta.
const pendientes = ref(0)

// En el celular la barra lateral es un cajón; se cierra al navegar.
const menuAbierto = ref(false)
watch(() => route.fullPath, () => (menuAbierto.value = false))

// El administrador ve el panel primero: es su pantalla de trabajo.
const modulos = computed(() => [
    ...(auth.esAdministrador ? [{ nombre: 'Panel', icono: 'panel', ruta: { name: 'panel' } }] : []),
    { nombre: 'Inicio', icono: 'inicio', ruta: { name: 'inicio' } },
    { nombre: 'Viajes', icono: 'viajes', ruta: { name: 'viajes' }, contador: true },
    { nombre: 'Vales', icono: 'vales', ruta: { name: 'vales' } },
    { nombre: 'Liquidaciones', icono: 'liquidaciones', ruta: { name: 'liquidaciones' } },
    { nombre: 'Pagos', icono: 'pagos', ruta: { name: 'pagos' } },
    { nombre: 'Material', icono: 'canteras', ruta: { name: 'material' } },
    { nombre: 'Tarifas', icono: 'tarifas', ruta: { name: 'tarifas' } },
])

const catalogos = [
    { nombre: 'Terceros', clave: 'terceros' },
    { nombre: 'Volquetas', clave: 'volquetas' },
    { nombre: 'Canteras', clave: 'canteras' },
    { nombre: 'Botaderos', clave: 'botaderos' },
    { nombre: 'Materiales', clave: 'materiales' },
    { nombre: 'Consorcios', clave: 'consorcios' },
    { nombre: 'Obras', clave: 'obras' },
]

const iniciales = computed(() => {
    const nombre = auth.usuario?.nombre ?? ''
    return nombre.split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase()
})

async function contarPendientes() {
    try {
        const { data } = await api.get('viajes', { params: { estado: 'por_confirmar', por_pagina: 1 } })
        pendientes.value = data.meta?.total ?? 0
    } catch {
        pendientes.value = 0
    }
}

async function salir() {
    await auth.salir()
    router.push({ name: 'login' })
}

onMounted(contarPendientes)
</script>

<template>
    <div class="flex min-h-dvh">
        <!-- Barra superior: solo en pantallas angostas -->
        <div class="fixed inset-x-0 top-0 z-30 flex h-14 items-center gap-3 border-b border-[var(--color-borde)] bg-white/90 px-4 backdrop-blur lg:hidden" style="padding-top: env(safe-area-inset-top)">
            <button class="-ml-2 flex size-10 items-center justify-center rounded-full text-[var(--color-tinta)]" aria-label="Abrir menú" @click="menuAbierto = true">
                <Icono nombre="menu" :tamano="20" />
            </button>
            <span class="text-[15px] font-medium">Cargo</span>
            <router-link
                v-if="pendientes"
                :to="{ name: 'viajes', query: { estado: 'por_confirmar' } }"
                class="chip cifra ml-auto bg-[var(--color-coral)] text-white"
            >
                {{ pendientes }} por confirmar
            </router-link>
        </div>

        <Transition name="velo">
            <div v-if="menuAbierto" class="velo fixed inset-0 z-40 lg:hidden" @click="menuAbierto = false" />
        </Transition>

        <!-- Barra lateral -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-[248px] shrink-0 flex-col overflow-y-auto border-r border-[var(--color-borde)] bg-[var(--color-fondo)] transition-transform duration-300 [transition-timing-function:var(--ease-cajon)] lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0"
            :class="menuAbierto ? 'translate-x-0' : '-translate-x-full'"
            style="overscroll-behavior: contain"
        >
            <div class="flex items-center gap-3 px-5 pb-6 pt-5">
                <div class="flex size-9 items-center justify-center rounded-[10px] bg-[var(--color-tinta)] text-white">
                    <Icono nombre="viajes" :tamano="18" />
                </div>
                <div class="leading-tight">
                    <div class="text-[15px] font-medium">Cargo</div>
                    <div class="text-[12px] text-[var(--color-tenue)]">Logística de viajes</div>
                </div>
                <button class="ml-auto flex size-9 items-center justify-center rounded-full text-[var(--color-apagado)] lg:hidden" aria-label="Cerrar menú" @click="menuAbierto = false">
                    <Icono nombre="x" />
                </button>
            </div>

            <nav class="flex flex-col gap-0.5 px-3" aria-label="Módulos">
                <router-link
                    v-for="modulo in modulos"
                    :key="modulo.nombre"
                    :to="modulo.ruta"
                    class="enlace-nav"
                    :active-class="modulo.icono === 'inicio' ? '' : 'enlace-nav-activo'"
                    exact-active-class="enlace-nav-activo"
                >
                    <Icono :nombre="modulo.icono" />
                    {{ modulo.nombre }}
                    <span
                        v-if="modulo.contador && pendientes"
                        class="cifra ml-auto rounded-md bg-[var(--color-coral)] px-1.5 text-[11px] font-medium leading-5 text-white"
                        :title="`${pendientes} viajes esperando vale`"
                    >
                        {{ pendientes }}
                    </span>
                </router-link>
            </nav>

            <div class="flex items-center px-6 pb-2 pt-7">
                <span class="text-[12px] font-medium text-[var(--color-tenue)]">Catálogos</span>
                <router-link
                    v-if="auth.esAdministrador"
                    :to="{ name: 'consorcios.nuevo' }"
                    class="ml-auto flex items-center gap-1 text-[12px] font-medium text-[var(--color-enlace)]"
                    title="Alta guiada de consorcio con sus obras y tarifas"
                >
                    <Icono nombre="mas" :tamano="14" /> Consorcio
                </router-link>
            </div>

            <nav class="flex flex-col gap-0.5 px-3" aria-label="Catálogos">
                <router-link
                    v-for="catalogo in catalogos"
                    :key="catalogo.clave"
                    :to="{ name: 'catalogos', params: { catalogo: catalogo.clave } }"
                    class="enlace-nav"
                    active-class="enlace-nav-activo"
                >
                    <Icono :nombre="catalogo.clave" />
                    {{ catalogo.nombre }}
                </router-link>
            </nav>

            <div class="mt-auto flex items-end gap-2 pb-3 pl-6 pr-4 pt-6">
                <p class="text-[11px] leading-snug text-[var(--color-tenue)]">
                    Cargo para Grup Tous<br>Desarrollado por Stonemills
                </p>
                <!-- Etapa de pruebas: chat directo con el desarrollador. Quitar al pasar a producción. -->
                <a
                    href="https://wa.me/573012562445?text=Hola%2C%20necesito%20soporte%20con%20Cargo"
                    target="_blank"
                    rel="noopener"
                    class="ml-auto flex h-8 shrink-0 items-center gap-1.5 rounded-full bg-[#25d366] px-3 text-[12.5px] font-medium text-[var(--color-bosque)] shadow-[0_1px_3px_rgb(24_29_38/0.18)] hover:brightness-95"
                    title="Escribir por WhatsApp al 301 256 2445"
                >
                    <Icono nombre="soporte" :tamano="15" />
                    Soporte
                </a>
            </div>

            <div class="flex items-center gap-3 border-t border-[var(--color-borde)] px-5 py-4" style="padding-bottom: calc(1rem + env(safe-area-inset-bottom))">
                <div class="flex size-8 items-center justify-center rounded-full bg-[var(--color-menta)] text-[12px] font-medium text-[var(--color-bosque)]">
                    {{ iniciales }}
                </div>
                <div class="min-w-0 flex-1 leading-tight">
                    <div class="truncate text-[13px] font-medium">{{ auth.usuario?.nombre }}</div>
                    <div class="text-[12px] capitalize text-[var(--color-tenue)]">{{ auth.usuario?.rol }}</div>
                </div>
                <button class="flex size-9 items-center justify-center rounded-full text-[var(--color-apagado)] hover:bg-[var(--color-superficie-fuerte)]" title="Salir" aria-label="Salir" @click="salir">
                    <Icono nombre="salir" :tamano="17" />
                </button>
            </div>
        </aside>

        <main class="min-w-0 flex-1 pt-14 lg:pt-0">
            <router-view v-slot="{ Component, route: actual }">
                <Transition name="vista" mode="out-in">
                    <div :key="actual.path">
                        <component :is="Component" />
                    </div>
                </Transition>
            </router-view>
        </main>
    </div>
</template>

<style scoped>
.enlace-nav {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-height: 38px;
    border-radius: 8px;
    padding: 0 0.75rem;
    font-size: 14px;
    color: var(--color-apagado);
    transition: background-color 120ms ease, color 120ms ease;
}

.enlace-nav:active {
    background-color: var(--color-superficie-fuerte);
}

.enlace-nav-activo {
    background-color: var(--color-lienzo);
    color: var(--color-tinta);
    font-weight: 500;
    box-shadow: 0 0 0 1px var(--color-borde), 0 1px 2px rgb(24 29 38 / 0.06);
}

@media (hover: hover) and (pointer: fine) {
    .enlace-nav:not(.enlace-nav-activo):hover {
        background-color: #eef0f3;
        color: var(--color-tinta);
    }
}

.velo-leave-active {
    transition: opacity 150ms ease;
}

.velo-leave-to {
    opacity: 0;
}
</style>
