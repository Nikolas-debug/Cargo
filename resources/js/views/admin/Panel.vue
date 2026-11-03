<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import api, { mensajeDeError } from '../../lib/api'
import { pesos, numero, fecha } from '../../lib/formato'
import { useAvisos } from '../../stores/avisos'
import { SERIES } from '../../components/graficos/paleta'
import Cargando from '../../components/Cargando.vue'
import TarjetaCifra from '../../components/TarjetaCifra.vue'
import Leyenda from '../../components/Leyenda.vue'
import TarjetaGrafico from '../../components/graficos/TarjetaGrafico.vue'
import GraficoLineas from '../../components/graficos/GraficoLineas.vue'
import GraficoBarras from '../../components/graficos/GraficoBarras.vue'
import VistaTabla from '../../components/graficos/VistaTabla.vue'

const avisos = useAvisos()

const datos = ref(null)
const cargando = ref(true)
const recargando = ref(false)

const rangos = [
    { texto: 'Últimos 30 días', dias: 30 },
    { texto: 'Últimos 90 días', dias: 90 },
    { texto: 'Este año', dias: 365 },
]

const filtro = reactive({ dias: 30, desde: '', hasta: '' })

/** El filtro va arriba de todo y alcanza a todos los gráficos a la vez. */
function aplicarRango(dias) {
    filtro.dias = dias
    const hasta = new Date()
    const desde = new Date(Date.now() - (dias - 1) * 86400000)
    filtro.hasta = hasta.toISOString().slice(0, 10)
    filtro.desde = desde.toISOString().slice(0, 10)
}

const seriesDinero = [
    { nombre: 'Cobrado al consorcio', color: SERIES[0] },
    { nombre: 'Pagado a dueños', color: SERIES[1] },
]

const puntosSerie = computed(() =>
    (datos.value?.serie ?? []).map((s) => ({
        etiqueta: fecha(s.semana),
        valores: [s.cobrado, s.pagado],
    })),
)

/** La chispa de cada KPI: la forma de esa misma métrica, semana a semana. */
function chispaDe(clave) {
    return (datos.value?.serie ?? []).map((s) => s[clave])
}

const filasMargen = computed(() =>
    (datos.value?.margen_por_obra ?? []).map((o) => ({
        etiqueta: o.obra,
        valor: o.margen,
        detalle: `${numero(o.viajes)} viajes · cobrado ${pesos(o.cobrado, true)}${o.material ? ` · material ${pesos(o.material, true)}` : ''} · pagado ${pesos(o.pagado, true)}`,
    })),
)

const filasRendimiento = computed(() =>
    (datos.value?.rendimiento ?? []).map((v) => ({
        etiqueta: `${v.placa}${v.es_propia ? ' (propia)' : ''}`,
        valor: v.viajes,
        detalle: `${v.propietario || 'sin dueño'} · ${numero(v.m3)} m³ · pagado ${pesos(v.pagado, true)}`,
    })),
)

/** El margen porcentual dice más que el absoluto cuando se comparan obras. */
const margenPorcentual = computed(() => {
    const r = datos.value?.resumen
    if (!r?.cobrado) return null
    return ((r.margen / r.cobrado) * 100).toLocaleString('es-CO', { maximumFractionDigits: 1 })
})

async function cargar() {
    if (datos.value) recargando.value = true

    try {
        const { data } = await api.get('panel', {
            params: { desde: filtro.desde, hasta: filtro.hasta },
        })
        datos.value = data
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo cargar el panel.'))
    } finally {
        cargando.value = false
        recargando.value = false
    }
}

watch(() => [filtro.desde, filtro.hasta], cargar)

onMounted(() => {
    aplicarRango(30)
    cargar()
})
</script>

<template>
    <header class="sticky top-14 z-20 flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-[var(--color-borde)] bg-white/95 px-4 py-4 backdrop-blur sm:px-8 sm:py-5 lg:top-0">
        <div>
            <h1 class="text-[24px] font-normal leading-tight tracking-[-0.01em]">Panel</h1>
            <p class="text-xs text-[var(--color-apagado)]">
                Solo cuenta lo confirmado: un viaje sin vale todavía no es plata.
            </p>
        </div>

        <!-- Un solo filtro para todo el tablero -->
        <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
            <button
                v-for="rango in rangos"
                :key="rango.dias"
                class="rounded-md px-3 py-1.5 text-[12.5px] transition"
                :class="filtro.dias === rango.dias
                    ? 'bg-[var(--color-marca)] font-medium text-white'
                    : 'border border-[var(--color-borde-fuerte)] bg-white text-[var(--color-apagado)]'"
                @click="aplicarRango(rango.dias)"
            >
                {{ rango.texto }}
            </button>

            <input v-model="filtro.desde" type="date" class="campo min-h-9 min-w-0 flex-1 py-1.5 text-[13px] sm:w-36 sm:flex-none" @change="filtro.dias = 0">
            <input v-model="filtro.hasta" type="date" class="campo min-h-9 min-w-0 flex-1 py-1.5 text-[13px] sm:w-36 sm:flex-none" @change="filtro.dias = 0">
        </div>
    </header>

    <Cargando :activo="cargando">
        <!-- Al recargar se mantiene lo anterior atenuado: sin saltos de layout -->
        <div v-if="datos" class="mx-auto max-w-[1360px] space-y-6 px-4 py-6 sm:px-8 transition-opacity" :class="recargando ? 'opacity-60' : ''">

            <!-- La cifra que encabeza el tablero, y el resto alrededor -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1.2fr_1fr_1fr_1fr] gap-3.5">
                <TarjetaCifra
                    etiqueta="Margen del periodo"
                    :valor="pesos(datos.resumen.margen, true)"
                    :actual="datos.resumen.margen"
                    :anterior="datos.resumen_anterior.margen"
                    :puntos="chispaDe('margen')"
                    destacado
                />
                <TarjetaCifra
                    etiqueta="Cobrado a consorcios"
                    :valor="pesos(datos.resumen.cobrado, true)"
                    :actual="datos.resumen.cobrado"
                    :anterior="datos.resumen_anterior.cobrado"
                    :puntos="chispaDe('cobrado')"
                />
                <TarjetaCifra
                    etiqueta="Pagado a dueños"
                    :valor="pesos(datos.resumen.pagado, true)"
                    :actual="datos.resumen.pagado"
                    :anterior="datos.resumen_anterior.pagado"
                    :puntos="chispaDe('pagado')"
                    :subir-es-bueno="false"
                />
                <TarjetaCifra
                    etiqueta="Viajes confirmados"
                    :valor="numero(datos.resumen.viajes)"
                    :actual="datos.resumen.viajes"
                    :anterior="datos.resumen_anterior.viajes"
                    :puntos="chispaDe('viajes')"
                />
            </div>

            <p v-if="margenPorcentual" class="text-[12.5px] text-[var(--color-apagado)]">
                El margen es el <span class="font-medium text-[var(--color-tinta)]">{{ margenPorcentual }}%</span>
                de lo facturado, sobre {{ numero(datos.resumen.m3) }} m³ movidos.
            </p>

            <!-- Tendencia -->
            <TarjetaGrafico
                titulo="Cobrado y pagado por semana"
                subtitulo="El área entre las dos líneas es el margen"
            >
                <template #leyenda>
                    <Leyenda :series="seriesDinero" />
                </template>

                <GraficoLineas
                    :puntos="puntosSerie"
                    :series="seriesDinero"
                    area-entre-series
                    :alto="240"
                />

                <template #tabla>
                    <VistaTabla
                        :columnas="[
                            { clave: 'semana', texto: 'SEMANA' },
                            { clave: 'viajes', texto: 'VIAJES', derecha: true },
                            { clave: 'cobrado', texto: 'COBRADO', derecha: true },
                            { clave: 'pagado', texto: 'PAGADO', derecha: true },
                            { clave: 'margen', texto: 'MARGEN', derecha: true },
                        ]"
                        :filas="datos.serie.map((s) => ({
                            semana: fecha(s.semana, 'larga'),
                            viajes: numero(s.viajes),
                            cobrado: pesos(s.cobrado),
                            pagado: pesos(s.pagado),
                            margen: pesos(s.margen),
                        }))"
                    />
                </template>
            </TarjetaGrafico>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Margen por obra -->
                <TarjetaGrafico titulo="Margen por obra" subtitulo="Dónde se está ganando">
                    <GraficoBarras :filas="filasMargen" />

                    <template #tabla>
                        <VistaTabla
                            :columnas="[
                                { clave: 'obra', texto: 'OBRA' },
                                { clave: 'viajes', texto: 'VIAJES', derecha: true },
                                { clave: 'cobrado', texto: 'COBRADO', derecha: true },
                                { clave: 'margen', texto: 'MARGEN', derecha: true },
                            ]"
                            :filas="datos.margen_por_obra.map((o) => ({
                                obra: o.obra,
                                viajes: numero(o.viajes),
                                cobrado: pesos(o.cobrado),
                                margen: pesos(o.margen),
                            }))"
                        />
                    </template>
                </TarjetaGrafico>

                <!-- Rendimiento -->
                <TarjetaGrafico titulo="Viajes por volqueta" subtitulo="Quién está moviendo el trabajo">
                    <GraficoBarras :filas="filasRendimiento" :color="SERIES[0]" />

                    <template #tabla>
                        <VistaTabla
                            :columnas="[
                                { clave: 'placa', texto: 'PLACA' },
                                { clave: 'propietario', texto: 'DUEÑO' },
                                { clave: 'viajes', texto: 'VIAJES', derecha: true },
                                { clave: 'm3', texto: 'M³', derecha: true },
                                { clave: 'pagado', texto: 'PAGADO', derecha: true },
                            ]"
                            :filas="datos.rendimiento.map((v) => ({
                                placa: v.placa,
                                propietario: v.propietario || '—',
                                viajes: numero(v.viajes),
                                m3: numero(v.m3),
                                pagado: pesos(v.pagado),
                            }))"
                        />
                    </template>
                </TarjetaGrafico>
            </div>

            <!-- Cartera -->
            <section class="tarjeta">
                <header class="flex flex-wrap items-baseline gap-x-3 gap-y-2 px-5 pb-3 pt-4">
                    <div>
                        <h2 class="text-[15px] font-medium">Cartera</h2>
                        <p class="mt-0.5 text-[11.5px] text-[var(--color-tenue)]">
                            Lo que está en cuentas abiertas y lo que todavía no se ha liquidado
                        </p>
                    </div>
                </header>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 px-5 pb-4">
                    <div class="rounded-md bg-[var(--color-superficie-sutil)] p-3.5">
                        <p class="text-[12px] text-[var(--color-apagado)]">Por cobrar, en cuentas</p>
                        <p class="cifra mt-1 text-[19px] font-medium">{{ pesos(datos.cartera.por_cobrar_en_cuentas, true) }}</p>
                    </div>
                    <div class="rounded-md bg-[var(--color-superficie-sutil)] p-3.5">
                        <p class="text-[12px] text-[var(--color-apagado)]">Por pagar, en cuentas</p>
                        <p class="cifra mt-1 text-[19px] font-medium">{{ pesos(datos.cartera.por_pagar_en_cuentas, true) }}</p>
                    </div>
                    <div class="rounded-md bg-[var(--color-pendiente-fondo)] p-3.5">
                        <p class="text-[12px] text-[var(--color-pendiente)]">Confirmado sin cobrar</p>
                        <p class="cifra mt-1 text-[19px] font-medium text-[var(--color-pendiente)]">{{ pesos(datos.cartera.sin_liquidar.por_cobrar, true) }}</p>
                        <p class="mt-0.5 text-[11px] text-[var(--color-pendiente)]/70">{{ numero(datos.cartera.sin_liquidar.viajes_sin_cobrar) }} viajes sin liquidar</p>
                    </div>
                    <div class="rounded-md bg-[var(--color-pendiente-fondo)] p-3.5">
                        <p class="text-[12px] text-[var(--color-pendiente)]">Confirmado sin pagar</p>
                        <p class="cifra mt-1 text-[19px] font-medium text-[var(--color-pendiente)]">{{ pesos(datos.cartera.sin_liquidar.por_pagar, true) }}</p>
                        <p class="mt-0.5 text-[11px] text-[var(--color-pendiente)]/70">{{ numero(datos.cartera.sin_liquidar.viajes_sin_pagar) }} viajes sin liquidar</p>
                    </div>
                </div>

                <div class="tabla-scroll">
                <table v-if="datos.cartera.cuentas.length" class="w-full text-[12.5px]">
                    <thead>
                        <tr class="bg-[var(--color-superficie-sutil)] text-left text-[11px] font-medium text-[var(--color-apagado)]">
                            <th class="w-24 py-2 pl-5 pr-2.5">CUENTA</th>
                            <th class="w-24 px-2.5 py-2">TIPO</th>
                            <th class="px-2.5 py-2">DESTINATARIO</th>
                            <th class="w-28 px-2.5 py-2">ESTADO</th>
                            <th class="w-36 py-2 pl-2.5 pr-5 text-right">SALDO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="cuenta in datos.cartera.cuentas" :key="cuenta.id" class="border-b border-[var(--color-borde)] last:border-0">
                            <td class="cifra py-2 pl-5 pr-2.5 font-medium">#{{ cuenta.numero_cuenta }}</td>
                            <td class="px-2.5 py-2">{{ cuenta.tipo === 'cobro' ? 'Cobro' : 'Pago' }}</td>
                            <td class="px-2.5 py-2 text-[var(--color-cuerpo)]">{{ cuenta.destinatario }}</td>
                            <td class="px-2.5 py-2 capitalize text-[var(--color-apagado)]">{{ cuenta.estado }}</td>
                            <td class="cifra py-2 pl-2.5 pr-5 text-right font-medium">{{ pesos(cuenta.saldo) }}</td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </section>

            <!-- Control -->
            <section class="tarjeta">
                <header class="flex flex-wrap items-baseline gap-x-3 gap-y-2 px-5 pb-3 pt-4">
                    <div>
                        <h2 class="text-[15px] font-medium">Control y desviaciones</h2>
                        <p class="mt-0.5 text-[11.5px] text-[var(--color-tenue)]">
                            Dónde el vale dijo algo distinto a lo que se registró
                        </p>
                    </div>
                </header>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5 px-5 pb-4">
                    <div class="rounded-md bg-[var(--color-superficie-sutil)] p-3.5">
                        <p class="text-[12px] text-[var(--color-apagado)]">Viajes ajustados por el vale</p>
                        <p class="cifra mt-1 text-[19px] font-medium">{{ numero(datos.desviaciones.totales.ajustados) }}</p>
                    </div>
                    <div class="rounded-md bg-[var(--color-superficie-sutil)] p-3.5">
                        <p class="text-[12px] text-[var(--color-apagado)]">Anulados</p>
                        <p class="cifra mt-1 text-[19px] font-medium">{{ numero(datos.desviaciones.totales.anulados) }}</p>
                    </div>
                    <div class="rounded-md bg-[var(--color-superficie-sutil)] p-3.5">
                        <p class="text-[12px] text-[var(--color-apagado)]">Esperando vale</p>
                        <p class="cifra mt-1 text-[19px] font-medium">{{ numero(datos.desviaciones.totales.por_confirmar) }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 px-5 pb-5">
                    <div>
                        <h3 class="mb-2 text-[12px] font-medium text-[var(--color-apagado)]">Ajustes más grandes</h3>
                        <div class="tabla-scroll">
                        <table class="w-full text-[12px]">
                            <thead>
                                <tr class="text-left text-[10.5px] font-medium text-[var(--color-apagado)]">
                                    <th class="py-1.5">FECHA</th>
                                    <th class="py-1.5">PLACA</th>
                                    <th class="py-1.5">VALE</th>
                                    <th class="py-1.5 text-right">REGISTRÓ</th>
                                    <th class="py-1.5 text-right">VALE DIJO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="v in datos.desviaciones.ajustados.slice(0, 8)" :key="v.id" class="border-t border-[var(--color-borde)]">
                                    <td class="cifra py-1.5 text-[var(--color-apagado)]">{{ v.fecha }}</td>
                                    <td class="cifra py-1.5">{{ v.placa }}</td>
                                    <td class="cifra py-1.5 text-[var(--color-enlace)]">{{ v.vale }}</td>
                                    <td class="cifra py-1.5 text-right text-[var(--color-apagado)]">{{ v.m3_registrado }}</td>
                                    <td class="cifra py-1.5 text-right font-medium" :class="v.diferencia < 0 ? 'text-[var(--color-critico)]' : 'text-[var(--color-bueno)]'">
                                        {{ v.m3_confirmado }}
                                        <span class="text-[10.5px]">({{ v.diferencia > 0 ? '+' : '' }}{{ v.diferencia }})</span>
                                    </td>
                                </tr>
                                <tr v-if="!datos.desviaciones.ajustados.length">
                                    <td colspan="5" class="py-4 text-center text-[var(--color-tenue)]">
                                        Ningún vale ajustó los m³ en este periodo.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>

                    <div>
                        <h3 class="mb-2 text-[12px] font-medium text-[var(--color-apagado)]">Vales que no han llegado</h3>
                        <div class="tabla-scroll">
                        <table class="w-full text-[12px]">
                            <thead>
                                <tr class="text-left text-[10.5px] font-medium text-[var(--color-apagado)]">
                                    <th class="py-1.5">OBRA</th>
                                    <th class="py-1.5 text-right">VIAJES</th>
                                    <th class="py-1.5 text-right">MÁS ANTIGUO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="fila in datos.desviaciones.espera_de_vales" :key="fila.obra" class="border-t border-[var(--color-borde)]">
                                    <td class="py-1.5">{{ fila.obra }}</td>
                                    <td class="cifra py-1.5 text-right">{{ fila.viajes }}</td>
                                    <td class="cifra py-1.5 text-right" :class="fila.dias > 7 ? 'font-medium text-[var(--color-critico)]' : 'text-[var(--color-apagado)]'">
                                        {{ fila.dias }} días
                                    </td>
                                </tr>
                                <tr v-if="!datos.desviaciones.espera_de_vales.length">
                                    <td colspan="3" class="py-4 text-center text-[var(--color-tenue)]">
                                        Todo confirmado.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </Cargando>
</template>
