<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api, { mensajeDeError } from '../../lib/api'
import { pesos, hoy } from '../../lib/formato'
import { useAvisos } from '../../stores/avisos'
import Encabezado from '../../components/Encabezado.vue'
import Estado from '../../components/Estado.vue'
import Cargando from '../../components/Cargando.vue'
import Paginacion from '../../components/Paginacion.vue'

const router = useRouter()
const avisos = useAvisos()

const liquidaciones = ref([])
const meta = ref(null)
const cargando = ref(true)
const filtroTipo = ref('')

const consorcios = ref([])
const terceros = ref([])
const canteras = ref([])

const nueva = reactive({
    abierta: false,
    tipo: 'pago',
    consorcio_id: '',
    tercero_id: '',
    cantera_id: '',
    desde: '',
    hasta: hoy(),
    numero_cuenta: '',
    guardando: false,
})

async function cargar(page = 1) {
    cargando.value = true

    try {
        const { data } = await api.get('liquidaciones', {
            params: { page, tipo: filtroTipo.value || undefined, por_pagina: 25 },
        })
        liquidaciones.value = data.data
        meta.value = data.meta
    } finally {
        cargando.value = false
    }
}

async function generar() {
    nueva.guardando = true

    try {
        const { data } = await api.post('liquidaciones', {
            tipo: nueva.tipo,
            consorcio_id: nueva.tipo === 'cobro' ? nueva.consorcio_id : null,
            tercero_id: nueva.tipo === 'pago' ? nueva.tercero_id : null,
            cantera_id: nueva.tipo === 'material' ? nueva.cantera_id : null,
            desde: nueva.desde,
            hasta: nueva.hasta,
            numero_cuenta: nueva.numero_cuenta ? Number(nueva.numero_cuenta) : null,
        })

        avisos.ok(`Cuenta #${data.data?.numero_cuenta ?? ''} generada.`)
        nueva.abierta = false
        router.push({ name: 'liquidaciones.detalle', params: { id: data.data?.id ?? data.id } })
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo generar la cuenta.'))
    } finally {
        nueva.guardando = false
    }
}

/**
 * Atajos del periodo: la semana (lunes a domingo), la quincena (1-15 o 16-fin)
 * o el mes que contiene la fecha "Desde" (o "Hasta", si no hay "Desde").
 */
function periodo(tipo) {
    const base = new Date(`${nueva.desde || nueva.hasta || hoy()}T12:00:00`)
    const [a, m, d] = [base.getFullYear(), base.getMonth(), base.getDate()]
    const fin = new Date(a, m + 1, 0).getDate()

    const [desde, hasta] = {
        semana: [d - ((base.getDay() + 6) % 7), d - ((base.getDay() + 6) % 7) + 6],
        quincena: d <= 15 ? [1, 15] : [16, fin],
        mes: [1, fin],
    }[tipo]

    // A mediodía, para que toISOString no cambie de día por la zona horaria.
    nueva.desde = new Date(a, m, desde, 12).toISOString().slice(0, 10)
    nueva.hasta = new Date(a, m, hasta, 12).toISOString().slice(0, 10)
}

watch(filtroTipo, () => cargar())

onMounted(async () => {
    const [c, t, ca] = await Promise.all([
        api.get('consorcios', { params: { por_pagina: 100 } }),
        api.get('terceros', { params: { por_pagina: 200 } }),
        api.get('canteras', { params: { por_pagina: 100 } }),
    ])

    consorcios.value = c.data.data
    terceros.value = t.data.data
    canteras.value = ca.data.data
    await cargar()
})
</script>

<template>
    <Encabezado titulo="Liquidaciones" subtitulo="Las cuentas de cobro y de pago">
        <template #acciones>
            <select v-model="filtroTipo" class="campo min-h-9 w-full py-1.5 text-[13px] sm:w-40">
                <option value="">Todas</option>
                <option value="cobro">De cobro</option>
                <option value="pago">De pago</option>
                <option value="material">De material</option>
            </select>
            <button class="boton-primario" @click="nueva.abierta = true">Generar cuenta</button>
        </template>
    </Encabezado>

    <Cargando :activo="cargando" :vacio="!liquidaciones.length" mensaje-vacio="Todavía no se ha generado ninguna cuenta.">
        <div class="bg-white">
            <div class="tabla-scroll">
            <table class="w-full text-[12.5px]">
                <thead>
                    <tr class="bg-[var(--color-superficie-sutil)] text-left text-[11px] font-medium text-[var(--color-apagado)]">
                        <th class="w-24 py-2 pl-4 sm:pl-8 pr-2.5">CUENTA</th>
                        <th class="w-28 px-2.5 py-2">TIPO</th>
                        <th class="px-2.5 py-2">DESTINATARIO</th>
                        <th class="w-52 px-2.5 py-2">PERIODO</th>
                        <th class="w-32 px-2.5 py-2 text-right">BRUTO</th>
                        <th class="w-32 px-2.5 py-2 text-right">ANTICIPOS</th>
                        <th class="w-32 px-2.5 py-2 text-right">SALDO</th>
                        <th class="w-28 py-2 pl-2.5 pr-4 sm:pr-8">ESTADO</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="cuenta in liquidaciones"
                        :key="cuenta.id"
                        class="cursor-pointer border-b border-[var(--color-borde)] hover:bg-[var(--color-superficie-sutil)]"
                        @click="router.push({ name: 'liquidaciones.detalle', params: { id: cuenta.id } })"
                    >
                        <td class="cifra py-2.5 pl-4 sm:pl-8 pr-2.5 font-medium">#{{ cuenta.numero_cuenta }}</td>
                        <td class="px-2.5 py-2.5">
                            <span class="rounded px-2 py-0.5 text-[11px] font-medium"
                                  :class="cuenta.tipo === 'cobro' ? 'bg-[var(--color-info-fondo)] text-[var(--color-info)]' : 'bg-[var(--color-neutro-fondo)] text-[var(--color-apagado)]'">
                                {{ { cobro: 'Cobro', pago: 'Pago', material: 'Material' }[cuenta.tipo] }}
                            </span>
                        </td>
                        <td class="px-2.5 py-2.5">{{ cuenta.destinatario }}</td>
                        <td class="cifra px-2.5 py-2.5 text-[var(--color-apagado)]">
                            {{ cuenta.periodo.desde }} — {{ cuenta.periodo.hasta }}
                        </td>
                        <td class="cifra px-2.5 py-2.5 text-right">{{ pesos(cuenta.total_bruto) }}</td>
                        <td class="cifra px-2.5 py-2.5 text-right text-[var(--color-pendiente)]">
                            {{ cuenta.total_anticipos ? `− ${pesos(cuenta.total_anticipos)}` : '—' }}
                        </td>
                        <td class="cifra px-2.5 py-2.5 text-right font-medium">{{ pesos(cuenta.saldo) }}</td>
                        <td class="py-2.5 pl-2.5 pr-4 sm:pr-8"><Estado :valor="cuenta.estado" /></td>
                    </tr>
                </tbody>
            </table>
            </div>

            <Paginacion :meta="meta" @ir="cargar" />
        </div>
    </Cargando>

    <!-- Generar cuenta -->
    <div v-if="nueva.abierta" class="fixed inset-0 z-40 flex items-center justify-center velo p-6" @click.self="nueva.abierta = false">
        <div class="w-full max-w-[460px] flotante dialogo">
            <div class="border-b border-[var(--color-borde)] px-5 py-4">
                <h2 class="text-[18px] font-normal">Generar cuenta</h2>
                <p class="mt-0.5 text-xs text-[var(--color-apagado)]">
                    Toma los viajes confirmados del periodo que no estén en otra cuenta.
                </p>
            </div>

            <div class="space-y-4 p-5">
                <div>
                    <label class="etiqueta">Tipo de cuenta</label>
                    <div class="flex overflow-hidden rounded-md border border-[var(--color-borde-fuerte)]">
                        <button
                            class="flex-1 py-2 text-[13px]"
                            :class="nueva.tipo === 'pago' ? 'bg-[var(--color-marca)] font-medium text-white' : ''"
                            @click="nueva.tipo = 'pago'"
                        >
                            Pago a un dueño
                        </button>
                        <button
                            class="flex-1 py-2 text-[13px]"
                            :class="nueva.tipo === 'cobro' ? 'bg-[var(--color-marca)] font-medium text-white' : ''"
                            @click="nueva.tipo = 'cobro'"
                        >
                            Cobro a un consorcio
                        </button>
                        <button
                            class="flex-1 py-2 text-[13px]"
                            :class="nueva.tipo === 'material' ? 'bg-[var(--color-marca)] font-medium text-white' : ''"
                            @click="nueva.tipo = 'material'"
                        >
                            Material a una cantera
                        </button>
                    </div>
                </div>

                <div v-if="nueva.tipo === 'pago'">
                    <label class="etiqueta">Dueño o conductor</label>
                    <select v-model="nueva.tercero_id" class="campo">
                        <option value="">Seleccionar…</option>
                        <option v-for="t in terceros" :key="t.id" :value="t.id">
                            {{ [t.nombre, t.apellido].filter(Boolean).join(' ') }}
                        </option>
                    </select>
                </div>

                <div v-else-if="nueva.tipo === 'material'">
                    <label class="etiqueta">Cantera</label>
                    <select v-model="nueva.cantera_id" class="campo">
                        <option value="">Seleccionar…</option>
                        <option v-for="c in canteras" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                    </select>
                    <p class="mt-1 text-[11.5px] text-[var(--color-tenue)]">
                        Lo que se le debe a la cantera por el material con vale. Se le abona desde la cuenta hasta saldarla.
                        No entran los viajes donde el material lo puso el conductor: esos van en su cuenta de pago.
                    </p>
                </div>

                <div v-else>
                    <label class="etiqueta">Consorcio</label>
                    <select v-model="nueva.consorcio_id" class="campo">
                        <option value="">Seleccionar…</option>
                        <option v-for="c in consorcios" :key="c.id" :value="c.id">{{ c.razon_social }}</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="etiqueta">Desde</label>
                        <input v-model="nueva.desde" type="date" class="campo">
                    </div>
                    <div>
                        <label class="etiqueta">Hasta</label>
                        <input v-model="nueva.hasta" type="date" class="campo">
                    </div>
                </div>

                <div>
                    <label class="etiqueta">Número de la cuenta</label>
                    <input v-model="nueva.numero_cuenta" type="number" min="1" inputmode="numeric" class="campo cifra" placeholder="Automático (el siguiente)">
                    <p class="mt-1 text-[11.5px] text-[var(--color-tenue)]">Si la numeración viene del Excel, escribe el que sigue (por ejemplo 14).</p>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
                    <span class="text-[var(--color-apagado)]">Periodo:</span>
                    <button class="boton-secundario py-1" @click="periodo('semana')">Semana</button>
                    <button class="boton-secundario py-1" @click="periodo('quincena')">Quincena</button>
                    <button class="boton-secundario py-1" @click="periodo('mes')">Mes</button>
                </div>

                <p class="rounded-md bg-[var(--color-superficie-sutil)] px-3 py-2.5 text-[12px] text-[var(--color-apagado)]">
                    Solo entran viajes confirmados. Los que esperan vale se quedan por fuera y podrán liquidarse después.
                </p>
            </div>

            <div class="flex gap-2 border-t border-[var(--color-borde)] px-5 py-4">
                <button class="boton-secundario flex-1 justify-center" @click="nueva.abierta = false">Cancelar</button>
                <button class="boton-primario flex-1 justify-center" :disabled="nueva.guardando" @click="generar">
                    {{ nueva.guardando ? 'Generando…' : 'Generar' }}
                </button>
            </div>
        </div>
    </div>
</template>
