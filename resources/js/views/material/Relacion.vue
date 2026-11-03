<script setup>
import { nextTick, onMounted, reactive, ref, watch } from 'vue'
import api, { mensajeDeError } from '../../lib/api'
import { pesos, numero, hoy } from '../../lib/formato'
import { useAvisos } from '../../stores/avisos'
import Encabezado from '../../components/Encabezado.vue'
import Cargando from '../../components/Cargando.vue'
import Icono from '../../components/Icono.vue'
import VisorComprobante from '../../components/VisorComprobante.vue'

/**
 * El crédito con la cantera día por día, como la hoja "VIAJES RELLENO
 * RELACION": filas por día y material, columnas por obra. Verde: todos los
 * viajes tienen vale y cuadran. Rojo: hay viajes sin vale o el vale dijo
 * otros m³ — los viajes "embolatados".
 */
const avisos = useAvisos()

const canteras = ref([])
const datos = ref(null)
const cargando = ref(false)
const viendo = ref(null)

const inicioDeMes = () => `${hoy().slice(0, 8)}01`
const filtro = reactive({ cantera_id: '', desde: inicioDeMes(), hasta: hoy() })

async function cargar() {
    if (!filtro.cantera_id || !filtro.desde || !filtro.hasta) return

    cargando.value = true

    try {
        const { data } = await api.get('reportes/material', { params: filtro })
        datos.value = data
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo armar la relación.'))
    } finally {
        cargando.value = false
    }
}

function titulo(celda) {
    if (!celda) return ''
    const partes = [`${celda.viajes} viajes`]
    if (celda.sin_vale) partes.push(`${celda.sin_vale} sin vale`)
    if (celda.ajustados) partes.push(`${celda.ajustados} con m³ distintos en el vale`)
    return partes.join(' · ')
}

const imprimiendo = ref(false)

async function imprimir() {
    imprimiendo.value = true
    await nextTick()
    const anterior = document.title
    document.title = `Relación material ${datos.value.cantera} ${filtro.desde} a ${filtro.hasta}`
    window.print()
    document.title = anterior
    imprimiendo.value = false
}

watch(filtro, cargar)

onMounted(async () => {
    const { data } = await api.get('canteras', { params: { por_pagina: 200 } })
    canteras.value = data.data
    if (canteras.value.length === 1) filtro.cantera_id = canteras.value[0].id
})
</script>

<template>
    <Encabezado titulo="Material por cantera" subtitulo="El crédito con la cantera, día por día y por obra">
        <template #acciones>
            <button class="boton-secundario" :disabled="!datos?.filas.length" @click="imprimir">
                <Icono nombre="imprimir" :tamano="15" /> Imprimir
            </button>
        </template>
    </Encabezado>

    <div class="flex flex-wrap items-end gap-3 border-b border-[var(--color-borde)] bg-white px-4 py-3 sm:px-8">
        <div class="w-full sm:w-64">
            <label class="etiqueta">Cantera</label>
            <select v-model="filtro.cantera_id" class="campo">
                <option value="">Seleccionar…</option>
                <option v-for="c in canteras" :key="c.id" :value="c.id">{{ c.nombre }}</option>
            </select>
        </div>
        <div class="min-w-0 flex-1 sm:w-40 sm:flex-none">
            <label class="etiqueta">Desde</label>
            <input v-model="filtro.desde" type="date" class="campo">
        </div>
        <div class="min-w-0 flex-1 sm:w-40 sm:flex-none">
            <label class="etiqueta">Hasta</label>
            <input v-model="filtro.hasta" type="date" class="campo">
        </div>
        <p class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-[var(--color-apagado)] sm:ml-auto sm:w-auto">
            <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-[var(--color-confirmado-fondo)] ring-1 ring-[var(--color-confirmado)]"></span>Cuadra con los vales</span>
            <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-[var(--color-critico-fondo)] ring-1 ring-[var(--color-critico)]"></span>Viajes sin vale o con diferencia</span>
        </p>
    </div>

    <p v-if="!filtro.cantera_id" class="px-4 py-16 text-center text-[13px] text-[var(--color-tenue)] sm:px-8">Elige una cantera para ver su relación.</p>

    <Cargando v-else :activo="cargando" :vacio="!datos?.filas.length" mensaje-vacio="Esa cantera no tiene viajes en el periodo.">
        <div v-if="datos" class="space-y-5 px-4 py-6 sm:px-8">
            <!-- Resumen -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="tarjeta p-4">
                    <p class="text-[12.5px] text-[var(--color-apagado)]">Viajes</p>
                    <p class="cifra mt-1 text-[22px]">{{ numero(datos.totales.viajes) }}</p>
                    <p v-if="datos.totales.sin_vale" class="mt-0.5 text-[11.5px] font-medium text-[var(--color-critico)]">{{ datos.totales.sin_vale }} sin vale</p>
                </div>
                <div class="tarjeta p-4">
                    <p class="text-[12.5px] text-[var(--color-apagado)]">Compra con vale</p>
                    <p class="cifra mt-1 text-[22px]">{{ pesos(datos.totales.compra, true) }}</p>
                </div>
                <div class="tarjeta p-4">
                    <p class="text-[12.5px] text-[var(--color-apagado)]">Abonado</p>
                    <p class="cifra mt-1 text-[22px] text-[var(--color-pendiente)]">{{ pesos(datos.totales.abonado, true) }}</p>
                </div>
                <div class="firma-tinta p-4">
                    <p class="text-[12.5px] text-white/75">Saldo con la cantera</p>
                    <p class="cifra mt-1 text-[22px]">{{ pesos(datos.totales.saldo, true) }}</p>
                </div>
            </div>

            <!-- Día por día -->
            <section class="tarjeta overflow-hidden">
                <div class="tabla-scroll">
                <table class="w-full text-[12.5px]">
                    <thead>
                        <tr class="bg-[var(--color-superficie-sutil)] text-left text-[11px] font-medium text-[var(--color-apagado)]">
                            <th class="w-28 py-2.5 pl-5 pr-2.5">FECHA</th>
                            <th class="px-2.5 py-2.5">MATERIAL</th>
                            <th class="px-2.5 py-2.5 text-right">PRECIO M³</th>
                            <th v-for="obra in datos.obras" :key="obra.id" class="px-2.5 py-2.5 text-center">{{ obra.nombre.toUpperCase() }}</th>
                            <th class="px-2.5 py-2.5 text-right">VIAJES</th>
                            <th class="py-2.5 pl-2.5 pr-5 text-right">VALOR COMPRA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(fila, i) in datos.filas" :key="i" class="border-b border-[var(--color-borde)]">
                            <td class="cifra whitespace-nowrap py-2 pl-5 pr-2.5">{{ fila.fecha }}</td>
                            <td class="px-2.5 py-2">
                                {{ fila.material }}
                                <span v-if="fila.por_conductor" class="block text-[11px] text-[var(--color-tenue)]">{{ fila.por_conductor }} los puso el conductor</span>
                            </td>
                            <td class="cifra px-2.5 py-2 text-right">{{ pesos(fila.precio_m3) }}</td>
                            <td v-for="obra in datos.obras" :key="obra.id" class="px-1.5 py-1.5 text-center">
                                <span
                                    v-if="fila.celdas[obra.id]"
                                    class="cifra inline-flex min-w-10 items-center justify-center rounded-md px-2 py-1 font-medium"
                                    :class="fila.celdas[obra.id].cuadra
                                        ? 'bg-[var(--color-confirmado-fondo)] text-[var(--color-confirmado)]'
                                        : 'bg-[var(--color-critico-fondo)] text-[var(--color-critico)]'"
                                    :title="titulo(fila.celdas[obra.id])"
                                >
                                    {{ fila.celdas[obra.id].viajes }}
                                    <span v-if="fila.celdas[obra.id].sin_vale" class="ml-1 text-[10.5px] font-normal">({{ fila.celdas[obra.id].sin_vale }} sin vale)</span>
                                </span>
                            </td>
                            <td class="cifra px-2.5 py-2 text-right">{{ fila.viajes }}</td>
                            <td class="cifra py-2 pl-2.5 pr-5 text-right font-medium">{{ pesos(fila.compra) }}</td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </section>

            <!-- Abonos -->
            <section class="tarjeta">
                <div class="flex flex-wrap items-center gap-2 border-b border-[var(--color-borde)] px-5 py-3.5">
                    <h2 class="text-[15px] font-medium">Abonos a la cantera en el periodo</h2>
                    <p class="text-[12px] text-[var(--color-tenue)] sm:ml-auto">Se registran desde la cuenta de material de la cantera, en Liquidaciones.</p>
                </div>
                <p v-if="!datos.pagos.length" class="px-5 py-6 text-center text-[12.5px] text-[var(--color-tenue)]">Sin abonos en el periodo.</p>
                <div v-for="p in datos.pagos" :key="p.id" class="flex items-center gap-3 border-b border-[var(--color-borde)] px-5 py-3 last:border-0">
                    <span class="cifra text-[13px]">{{ p.fecha }}</span>
                    <span class="text-[12px] text-[var(--color-tenue)]">Cuenta #{{ p.cuenta }}<template v-if="p.numero_comprobante"> · Comp. {{ p.numero_comprobante }}</template></span>
                    <span class="cifra ml-auto font-medium">{{ pesos(p.valor) }}</span>
                    <button v-if="p.tiene_comprobante" class="text-[var(--color-enlace)]" aria-label="Ver comprobante" @click="viendo = p.id">
                        <Icono nombre="adjunto" :tamano="16" />
                    </button>
                </div>
            </section>
        </div>
    </Cargando>

    <VisorComprobante v-model="viendo" />

    <Teleport to="body">
        <article v-if="imprimiendo && datos" class="cuenta-impresa relacion-impresa">
            <p class="text-center font-bold">RELACIÓN DE MATERIAL · CANTERA {{ datos.cantera.toUpperCase() }}</p>
            <p class="mb-2 text-center">Del {{ datos.desde }} al {{ datos.hasta }}</p>
            <table>
                <thead>
                    <tr>
                        <th>FECHA</th><th>MATERIAL</th><th>PRECIO M³</th>
                        <th v-for="obra in datos.obras" :key="obra.id">{{ obra.nombre.toUpperCase() }}</th>
                        <th>VIAJES</th><th>VALOR COMPRA</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(fila, i) in datos.filas" :key="i">
                        <td>{{ fila.fecha }}</td>
                        <td class="izquierda">{{ fila.material.toUpperCase() }}</td>
                        <td>{{ pesos(fila.precio_m3) }}</td>
                        <td
                            v-for="obra in datos.obras"
                            :key="obra.id"
                            :class="fila.celdas[obra.id] ? (fila.celdas[obra.id].cuadra ? 'celda-cuadra' : 'celda-difiere') : ''"
                        >{{ fila.celdas[obra.id]?.viajes ?? '' }}</td>
                        <td>{{ fila.viajes }}</td>
                        <td>{{ pesos(fila.compra) }}</td>
                    </tr>
                    <tr class="subtotal">
                        <td :colspan="3 + datos.obras.length">TOTAL COMPRA</td>
                        <td>{{ datos.totales.viajes }}</td>
                        <td>{{ pesos(datos.totales.compra) }}</td>
                    </tr>
                    <tr v-for="p in datos.pagos" :key="p.id">
                        <td>{{ p.fecha }}</td>
                        <td :colspan="3 + datos.obras.length" class="izquierda">ABONO A LA CANTERA{{ p.numero_comprobante ? ` · COMP. ${p.numero_comprobante}` : '' }}</td>
                        <td>− {{ pesos(p.valor) }}</td>
                    </tr>
                    <tr class="subtotal">
                        <td :colspan="4 + datos.obras.length">SALDO</td>
                        <td>{{ pesos(datos.totales.saldo) }}</td>
                    </tr>
                </tbody>
            </table>
        </article>
    </Teleport>
</template>
