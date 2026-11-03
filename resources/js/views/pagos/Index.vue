<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import api, { mensajeDeError } from '../../lib/api'
import { pesos, hoy } from '../../lib/formato'
import { formularioDePago } from '../../lib/comprobante'
import { useAvisos } from '../../stores/avisos'
import Encabezado from '../../components/Encabezado.vue'
import Cargando from '../../components/Cargando.vue'
import Icono from '../../components/Icono.vue'
import SubirComprobante from '../../components/SubirComprobante.vue'
import VisorComprobante from '../../components/VisorComprobante.vue'

/**
 * Los pagos y anticipos que hace el consorcio, con su comprobante de ingreso.
 * El anticipo al iniciar la obra llega antes que la cuenta de cobro: se
 * registra suelto y se aplica a la cuenta cuando exista.
 */
const avisos = useAvisos()

const pagos = ref([])
const total = ref(0)
const cargando = ref(true)
const consorcios = ref([])
const cuentas = ref([])
const viendo = ref(null)

const filtro = reactive({ consorcio_id: '', desde: '', hasta: '' })

const nuevo = reactive({ abierto: false, guardando: false, consorcio_id: '', liquidacion_id: '', fecha: hoy(), valor: '', numero_comprobante: '', observacion: '', archivo: null })

const cuentasDe = (consorcioId) => cuentas.value.filter((c) => c.consorcio_id === Number(consorcioId))
const consorcioFiltrado = computed(() => consorcios.value.find((c) => c.id === Number(filtro.consorcio_id)))

async function cargar() {
    cargando.value = true

    try {
        const { data } = await api.get('pagos', {
            params: { consorcio_id: filtro.consorcio_id || undefined, desde: filtro.desde || undefined, hasta: filtro.hasta || undefined },
        })
        pagos.value = data.data
        total.value = data.total
    } finally {
        cargando.value = false
    }
}

async function cargarCuentas() {
    const { data } = await api.get('liquidaciones', { params: { tipo: 'cobro', estado: 'abierta', por_pagina: 100 } })
    cuentas.value = data.data
}

function abrirNuevo() {
    Object.assign(nuevo, { abierto: true, consorcio_id: filtro.consorcio_id, liquidacion_id: '', fecha: hoy(), valor: '', numero_comprobante: '', observacion: '', archivo: null })
}

async function guardar() {
    nuevo.guardando = true

    try {
        await api.post('pagos', formularioDePago({
            consorcio_id: nuevo.consorcio_id,
            liquidacion_id: nuevo.liquidacion_id,
            fecha: nuevo.fecha,
            valor: Number(nuevo.valor),
            numero_comprobante: nuevo.numero_comprobante,
            observacion: nuevo.observacion,
        }, nuevo.archivo))

        avisos.ok('Pago registrado.')
        nuevo.abierto = false
        await Promise.all([cargar(), cargarCuentas()])
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo registrar el pago.'))
    } finally {
        nuevo.guardando = false
    }
}

async function aplicar(pago, liquidacionId) {
    try {
        await api.put(`anticipos/${pago.id}/cuenta`, { liquidacion_id: liquidacionId ? Number(liquidacionId) : null })
        avisos.ok(liquidacionId ? 'Pago aplicado a la cuenta: su saldo bajó.' : 'El pago quedó sin cuenta.')
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo aplicar el pago.'))
    }

    await Promise.all([cargar(), cargarCuentas()])
}

async function eliminar(pago) {
    if (!confirm(`¿Eliminar el pago de ${pesos(pago.valor)} del ${pago.fecha}?`)) return

    try {
        await api.delete(`anticipos/${pago.id}`)
        avisos.ok('Pago eliminado.')
        await cargar()
    } catch (e) {
        avisos.error(mensajeDeError(e))
    }
}

const imprimiendo = ref(false)

async function imprimir() {
    imprimiendo.value = true
    await nextTick()
    const titulo = document.title
    document.title = `Relación de pagos ${consorcioFiltrado.value?.razon_social ?? ''}`.trim()
    window.print()
    document.title = titulo
    imprimiendo.value = false
}

watch(filtro, cargar)

onMounted(async () => {
    const { data } = await api.get('consorcios', { params: { por_pagina: 200 } })
    consorcios.value = data.data
    await Promise.all([cargar(), cargarCuentas()])
})
</script>

<template>
    <Encabezado titulo="Pagos del consorcio" subtitulo="Pagos y anticipos con su comprobante">
        <template #acciones>
            <button class="boton-secundario" :disabled="!pagos.length" @click="imprimir">
                <Icono nombre="imprimir" :tamano="15" /> Relación
            </button>
            <button class="boton-primario" @click="abrirNuevo">
                <Icono nombre="mas" :tamano="16" /> Registrar pago
            </button>
        </template>
    </Encabezado>

    <div class="flex flex-wrap items-end gap-3 border-b border-[var(--color-borde)] bg-white px-4 py-3 sm:px-8">
        <div class="w-full sm:w-72">
            <label class="etiqueta">Consorcio</label>
            <select v-model="filtro.consorcio_id" class="campo">
                <option value="">Todos</option>
                <option v-for="c in consorcios" :key="c.id" :value="c.id">{{ c.razon_social }}</option>
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
    </div>

    <Cargando :activo="cargando" :vacio="!pagos.length" mensaje-vacio="No hay pagos registrados con esos filtros.">
        <div class="px-4 py-6 sm:px-8">
            <div class="tarjeta overflow-hidden">
                <div class="tabla-scroll">
                <table class="w-full text-[12.5px]">
                    <thead>
                        <tr class="bg-[var(--color-superficie-sutil)] text-left text-[11px] font-medium text-[var(--color-apagado)]">
                            <th class="w-28 py-2.5 pl-5 pr-2.5">FECHA</th>
                            <th class="px-2.5 py-2.5">CONSORCIO</th>
                            <th class="w-36 px-2.5 py-2.5">COMPROBANTE</th>
                            <th class="w-36 px-2.5 py-2.5 text-right">VALOR</th>
                            <th class="w-48 px-2.5 py-2.5">APLICADO A</th>
                            <th class="w-24 py-2.5 pl-2.5 pr-5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="pago in pagos" :key="pago.id" class="border-b border-[var(--color-borde)]">
                            <td class="cifra py-2.5 pl-5 pr-2.5">{{ pago.fecha }}</td>
                            <td class="px-2.5 py-2.5">
                                {{ pago.consorcio }}
                                <span v-if="pago.observacion" class="block text-[11.5px] text-[var(--color-tenue)]">{{ pago.observacion }}</span>
                            </td>
                            <td class="cifra px-2.5 py-2.5">
                                <button v-if="pago.tiene_comprobante" class="inline-flex items-center gap-1 font-medium text-[var(--color-enlace)]" @click="viendo = pago.id">
                                    <Icono nombre="adjunto" :tamano="14" /> {{ pago.numero_comprobante ?? 'Ver' }}
                                </button>
                                <span v-else>{{ pago.numero_comprobante ?? '—' }}</span>
                            </td>
                            <td class="cifra px-2.5 py-2.5 text-right font-medium">{{ pesos(pago.valor) }}</td>
                            <td class="px-2.5 py-2.5">
                                <span v-if="pago.cuenta?.estado && pago.cuenta.estado !== 'abierta'">Cuenta #{{ pago.cuenta.numero }}</span>
                                <select
                                    v-else
                                    :value="pago.cuenta?.id ?? ''"
                                    class="campo min-h-8 py-1 text-[12.5px]"
                                    @change="aplicar(pago, $event.target.value)"
                                >
                                    <option value="">Sin aplicar (anticipo)</option>
                                    <option v-for="c in cuentasDe(pago.consorcio_id)" :key="c.id" :value="c.id">Cuenta #{{ c.numero_cuenta }}</option>
                                </select>
                            </td>
                            <td class="py-2.5 pl-2.5 pr-5 text-right">
                                <button
                                    v-if="!pago.cuenta || pago.cuenta.estado === 'abierta'"
                                    class="text-[12.5px] text-[var(--color-tenue)] hover:text-[var(--color-critico)]"
                                    @click="eliminar(pago)"
                                >Eliminar</button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="bg-[var(--color-superficie-sutil)]">
                            <td colspan="3" class="py-3 pl-5 pr-2.5 text-[12.5px] font-medium">Total pagado</td>
                            <td class="cifra px-2.5 py-3 text-right text-[15px] font-medium">{{ pesos(total, true) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
        </div>
    </Cargando>

    <!-- Registrar pago -->
    <div v-if="nuevo.abierto" class="velo fixed inset-0 z-40 flex items-end justify-center sm:items-center sm:p-6" @click.self="nuevo.abierto = false">
        <form class="flotante dialogo flex max-h-[92dvh] w-full max-w-[480px] flex-col" @submit.prevent="guardar">
            <div class="flex items-start border-b border-[var(--color-borde)] px-5 py-4">
                <div>
                    <h2 class="text-[18px] font-normal">Registrar pago</h2>
                    <p class="mt-0.5 text-[12.5px] text-[var(--color-apagado)]">Un pago o anticipo del consorcio, con su comprobante.</p>
                </div>
                <button type="button" class="-mr-1 ml-auto rounded-full p-1 text-[var(--color-tenue)]" aria-label="Cerrar" @click="nuevo.abierto = false">
                    <Icono nombre="x" :tamano="16" />
                </button>
            </div>

            <div class="space-y-4 overflow-y-auto p-5" style="overscroll-behavior: contain">
                <div>
                    <label class="etiqueta">Consorcio <span class="text-[var(--color-critico)]">*</span></label>
                    <select v-model="nuevo.consorcio_id" class="campo" required @change="nuevo.liquidacion_id = ''">
                        <option value="">Seleccionar…</option>
                        <option v-for="c in consorcios" :key="c.id" :value="c.id">{{ c.razon_social }}</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="etiqueta">Fecha de pago <span class="text-[var(--color-critico)]">*</span></label>
                        <input v-model="nuevo.fecha" type="date" class="campo" required>
                    </div>
                    <div>
                        <label class="etiqueta">Valor <span class="text-[var(--color-critico)]">*</span></label>
                        <input v-model="nuevo.valor" type="number" min="1" inputmode="numeric" class="campo cifra" required>
                    </div>
                </div>

                <div>
                    <label class="etiqueta">N° comprobante de ingreso</label>
                    <input v-model="nuevo.numero_comprobante" class="campo cifra" autocapitalize="characters">
                </div>

                <div>
                    <label class="etiqueta">Aplicar a la cuenta de cobro</label>
                    <select v-model="nuevo.liquidacion_id" class="campo" :disabled="!nuevo.consorcio_id">
                        <option value="">Ninguna todavía (anticipo)</option>
                        <option v-for="c in cuentasDe(nuevo.consorcio_id)" :key="c.id" :value="c.id">
                            Cuenta #{{ c.numero_cuenta }} · saldo {{ pesos(c.saldo, true) }}
                        </option>
                    </select>
                </div>

                <input v-model="nuevo.observacion" class="campo" placeholder="Observación (opcional)">

                <SubirComprobante v-model="nuevo.archivo" />
            </div>

            <div class="flex gap-2 border-t border-[var(--color-borde)] px-5 py-4" style="padding-bottom: calc(1rem + env(safe-area-inset-bottom))">
                <button type="button" class="boton-secundario flex-1" @click="nuevo.abierto = false">Cancelar</button>
                <button type="submit" class="boton-primario flex-1" :disabled="nuevo.guardando">
                    {{ nuevo.guardando ? 'Guardando…' : 'Registrar pago' }}
                </button>
            </div>
        </form>
    </div>

    <VisorComprobante v-model="viendo" />

    <!-- La relación de pagos impresa -->
    <Teleport to="body">
        <article v-if="imprimiendo" class="cuenta-impresa relacion-impresa">
            <p class="text-center font-bold">RELACIÓN DE PAGOS Y ANTICIPOS</p>
            <p class="text-center font-bold">{{ consorcioFiltrado?.razon_social?.toUpperCase() ?? 'TODOS LOS CONSORCIOS' }}</p>
            <p v-if="filtro.desde || filtro.hasta" class="mb-2 text-center">Del {{ filtro.desde || '—' }} al {{ filtro.hasta || '—' }}</p>
            <table>
                <thead>
                    <tr><th></th><th>FECHA DE PAGO</th><th v-if="!consorcioFiltrado">CONSORCIO</th><th>N° COMPROBANTE</th><th>CUENTA</th><th>OBSERVACIÓN</th><th>VALOR</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(p, i) in pagos" :key="p.id">
                        <td>{{ i + 1 }}</td>
                        <td>{{ p.fecha }}</td>
                        <td v-if="!consorcioFiltrado" class="izquierda">{{ p.consorcio }}</td>
                        <td>{{ p.numero_comprobante ?? '—' }}</td>
                        <td>{{ p.cuenta ? `#${p.cuenta.numero}` : 'Anticipo' }}</td>
                        <td class="izquierda">{{ p.observacion ?? '' }}</td>
                        <td>{{ pesos(p.valor) }}</td>
                    </tr>
                    <tr class="subtotal">
                        <td :colspan="consorcioFiltrado ? 5 : 6">TOTAL</td>
                        <td>{{ pesos(total) }}</td>
                    </tr>
                </tbody>
            </table>
        </article>
    </Teleport>
</template>
