<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api, { mensajeDeError } from '../../lib/api'
import { pesos, numero, hoy } from '../../lib/formato'
import { formularioDePago } from '../../lib/comprobante'
import { useAuth } from '../../stores/auth'
import { useAvisos } from '../../stores/avisos'
import Estado from '../../components/Estado.vue'
import Cargando from '../../components/Cargando.vue'
import Icono from '../../components/Icono.vue'
import SubirComprobante from '../../components/SubirComprobante.vue'
import VisorComprobante from '../../components/VisorComprobante.vue'

const props = defineProps({ id: { type: [String, Number], required: true } })

const auth = useAuth()
const avisos = useAvisos()
const router = useRouter()

const cuenta = ref(null)
const cargando = ref(true)

const anticipo = reactive({ abierto: false, fecha: hoy(), valor: '', numero_comprobante: '', observacion: '', archivo: null, guardando: false })
const viendo = ref(null)
const renumerar = reactive({ editando: false, valor: '' })

const esCobro = computed(() => cuenta.value?.tipo === 'cobro')
const esMaterial = computed(() => cuenta.value?.tipo === 'material')
const abierta = computed(() => cuenta.value?.estado === 'abierta')

const TEXTOS = {
    cobro: { tipo: 'Cobro al consorcio', abonos: 'Pagos del consorcio', saldo: 'Saldo por cobrar', vacio: 'Cada pago o anticipo del consorcio que se registre aquí baja el saldo.' },
    pago: { tipo: 'Pago a dueño o conductor', abonos: 'Anticipos', saldo: 'Saldo por pagar', vacio: 'Cada abono que se registre aquí baja el saldo de la cuenta.' },
    material: { tipo: 'Crédito de material con la cantera', abonos: 'Abonos a la cantera', saldo: 'Se le debe a la cantera', vacio: 'Registra aquí cada abono o el pago total a la cantera.' },
}
const textos = computed(() => TEXTOS[cuenta.value?.tipo] ?? TEXTOS.pago)

/**
 * Los viajes agrupados por ruta y material, como en las cuentas del Excel:
 * una línea por combinación, con el total de viajes y m³.
 */
const porRuta = computed(() => {
    if (!cuenta.value?.viajes) return []

    const mapa = new Map()

    for (const viaje of cuenta.value.viajes) {
        // En la de material, el unitario ES el precio del material por m³.
        const unitario = esMaterial.value ? viaje.valor_material_m3 : esCobro.value ? viaje.valor_cobro_unitario : viaje.valor_pago_unitario
        const materialM3 = esCobro.value ? viaje.valor_material_m3 : null
        // En la de pago, el material que puso el conductor va en su propia línea.
        const reembolso = !esCobro.value && !esMaterial.value && viaje.material_por_conductor
        // Los precios van en la clave: si la arena subió a mitad del periodo, son dos filas.
        const clave = `${viaje.ruta}|${viaje.material ?? 'retiro'}|${unitario}|${materialM3}|${reembolso}`
        const total = esMaterial.value ? viaje.total_material : esCobro.value ? viaje.total_cobro : viaje.total_pago

        const fila = mapa.get(clave) ?? {
            ruta: viaje.ruta,
            material: viaje.material,
            viajes: 0,
            m3: 0,
            unitario,
            materialM3,
            reembolso: reembolso ? 0 : null,
            unidad: esMaterial.value ? 'm3' : esCobro.value ? viaje.unidad_cobro : viaje.unidad_pago,
            subtotal: 0,
        }

        fila.viajes += 1
        fila.m3 += viaje.m3_confirmado ?? viaje.m3_registrado
        fila.subtotal += total ?? 0
        if (reembolso) fila.reembolso += viaje.total_reembolso_material

        mapa.set(clave, fila)
    }

    return [...mapa.values()]
})

/**
 * La relación de viajes que acompaña la cuenta de cobro ("13. R. VIAJES
 * CONSORCIO…"): un bloque por cantera (o botadero), viaje por viaje con su
 * vale, subtotal por bloque y el total a pagar.
 */
const relacion = computed(() => {
    if (!esCobro.value || !cuenta.value?.viajes) return []

    const bloques = new Map()
    const viajes = [...cuenta.value.viajes].sort((a, b) => a.fecha_viaje.localeCompare(b.fecha_viaje) || (Number(a.vale) || 0) - (Number(b.vale) || 0))

    for (const v of viajes) {
        const origen = (v.tipo_viaje === 'retiro' ? `RETIRO A ${v.botadero ?? ''}` : `CANTERA ${v.cantera ?? ''}`).toUpperCase()
        const bloque = bloques.get(origen) ?? { origen, materiales: new Set(), filas: [], viajes: 0, m3: 0, total: 0, desde: v.fecha_viaje, hasta: v.fecha_viaje }
        const m3 = v.m3_confirmado ?? v.m3_registrado

        bloque.materiales.add((v.material ?? 'escombro').toUpperCase())
        bloque.filas.push({
            fecha: v.fecha_viaje,
            placa: v.placa,
            vale: v.vale,
            material: v.tipo_viaje === 'retiro' ? `ESCOMBRO ${origen}` : `${(v.material ?? '').toUpperCase()} ${origen}`,
            // Por m³, el valor es flete + material por m³, como en la relación del Excel.
            valor: v.unidad_cobro === 'm3' ? v.valor_cobro_unitario + (v.valor_material_m3 ?? 0) : v.total_cobro,
            m3,
            total: v.total_cobro,
        })
        bloque.viajes += 1
        bloque.m3 += m3
        bloque.total += v.total_cobro
        bloque.hasta = v.fecha_viaje
        bloques.set(origen, bloque)
    }

    return [...bloques.values()].map((b) => ({
        ...b,
        titulo: `${[...b.materiales].join(' Y ')} ${b.origen} DEL ${rango(b.desde, b.hasta).toUpperCase()}`,
    }))
})

// Quien cobra: va igual en todas las cuentas de cobro.
const EMPRESA = { nombre: 'GRUP MULTIPURPOSE S.A.S', nit: '901.744.396-2', representante: 'KARINA PATERNINA', ciudad: 'Montería' }

function diaMes(iso, conAnio = false) {
    return new Date(`${iso}T12:00:00`).toLocaleDateString('es-CO', {
        day: 'numeric', month: 'long', ...(conAnio && { year: 'numeric' }),
    })
}

/** "25 al 27 de agosto de 2026", sin repetir mes ni año cuando coinciden. */
function rango(desde, hasta) {
    if (desde === hasta) return diaMes(desde, true)
    if (desde.slice(0, 7) === hasta.slice(0, 7)) return `${Number(desde.slice(8))} al ${diaMes(hasta, true)}`
    return `${diaMes(desde, desde.slice(0, 4) !== hasta.slice(0, 4))} al ${diaMes(hasta, true)}`
}

const periodoEnTexto = computed(() => rango(cuenta.value.periodo.desde, cuenta.value.periodo.hasta))

/** Qué se imprime: la cuenta de cobro o su relación de viajes. */
const impresion = ref('cuenta')

async function descargarPdf(que) {
    impresion.value = que
    await nextTick()

    const titulo = document.title
    // El navegador propone el título como nombre del archivo PDF.
    document.title = que === 'relacion'
        ? `${cuenta.value.numero_cuenta}. R. VIAJES ${cuenta.value.destinatario}`
        : `Cuenta de cobro N°${cuenta.value.numero_cuenta} ${cuenta.value.destinatario}`
    window.print()
    document.title = titulo
}

async function cargar() {
    cargando.value = true

    try {
        const { data } = await api.get(`liquidaciones/${props.id}`)
        cuenta.value = data.data ?? data
    } finally {
        cargando.value = false
    }
}

function pagarSaldo() {
    anticipo.abierto = true
    anticipo.valor = cuenta.value.saldo
    anticipo.observacion = 'Pago total'
}

async function agregarAnticipo() {
    anticipo.guardando = true

    try {
        await api.post(`liquidaciones/${props.id}/anticipos`, formularioDePago({
            fecha: anticipo.fecha,
            valor: Number(anticipo.valor),
            numero_comprobante: anticipo.numero_comprobante,
            observacion: anticipo.observacion,
        }, anticipo.archivo))

        avisos.ok(esMaterial.value ? 'Abono a la cantera registrado.' : 'Anticipo registrado.')
        Object.assign(anticipo, { abierto: false, valor: '', numero_comprobante: '', observacion: '', archivo: null })
        await cargar()
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo registrar el anticipo.'))
    } finally {
        anticipo.guardando = false
    }
}

async function quitarAnticipo(a) {
    if (!confirm(`¿Quitar el abono de ${pesos(a.valor)} del ${a.fecha}?`)) return

    try {
        await api.delete(`anticipos/${a.id}`)
        avisos.ok('Anticipo eliminado.')
        await cargar()
    } catch (e) {
        avisos.error(mensajeDeError(e))
    }
}

async function guardarNumero() {
    try {
        await api.patch(`liquidaciones/${props.id}/numero`, { numero_cuenta: Number(renumerar.valor) })
        renumerar.editando = false
        avisos.ok('Número de la cuenta actualizado.')
        await cargar()
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo cambiar el número.'))
    }
}

onMounted(cargar)

async function cerrar() {
    try {
        await api.post(`liquidaciones/${props.id}/cerrar`)
        avisos.ok('Cuenta cerrada. Sus viajes quedaron bloqueados.')
        await cargar()
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo cerrar la cuenta.'))
    }
}
</script>

<template>
    <Cargando :activo="cargando">
        <template v-if="cuenta">
            <header class="sticky top-14 z-20 flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-[var(--color-borde)] bg-white/95 px-4 py-4 backdrop-blur sm:px-8 sm:py-5 lg:top-0">
                <button class="text-[var(--color-apagado)] hover:text-[var(--color-tinta)]" aria-label="Volver" @click="router.push({ name: 'liquidaciones' })">←</button>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h1 class="text-[24px] font-normal leading-tight tracking-[-0.01em]">
                            <template v-if="!renumerar.editando">
                                Cuenta #{{ cuenta.numero_cuenta }}
                                <button
                                    v-if="abierta"
                                    class="align-middle text-[12px] font-medium text-[var(--color-enlace)]"
                                    @click="renumerar.editando = true; renumerar.valor = cuenta.numero_cuenta"
                                >cambiar</button>
                            </template>
                            <span v-else class="inline-flex items-center gap-1.5 align-middle">
                                Cuenta #
                                <input v-model="renumerar.valor" type="number" min="1" class="campo cifra w-24 py-1" @keyup.enter="guardarNumero">
                                <button class="boton-primario min-h-8 py-1" @click="guardarNumero">Guardar</button>
                                <button class="text-[13px] text-[var(--color-tenue)]" @click="renumerar.editando = false">Cancelar</button>
                            </span>
                            · {{ cuenta.destinatario }}
                        </h1>
                        <Estado :valor="cuenta.estado" />
                    </div>
                    <p class="cifra text-xs text-[var(--color-apagado)]">
                        {{ textos.tipo }} · {{ cuenta.periodo.desde }} — {{ cuenta.periodo.hasta }}
                    </p>
                </div>

                <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                    <template v-if="esCobro">
                        <button class="boton-secundario" @click="descargarPdf('cuenta')">
                            <Icono nombre="imprimir" :tamano="15" /> Cuenta de cobro
                        </button>
                        <button class="boton-secundario" @click="descargarPdf('relacion')">
                            <Icono nombre="imprimir" :tamano="15" /> Relación de viajes
                        </button>
                    </template>
                    <button
                        v-if="abierta && auth.esAdministrador"
                        class="boton-primario"
                        @click="cerrar"
                    >
                        Cerrar cuenta
                    </button>
                    <span v-else-if="abierta" class="text-xs text-[var(--color-tenue)]">
                        Solo el administrador puede cerrar la cuenta
                    </span>
                </div>
            </header>

            <div class="flex flex-col gap-4 p-4 sm:p-6 lg:flex-row">
                <!-- Detalle -->
                <section class="tarjeta min-w-0 flex-1">
                    <div class="flex items-center border-b border-[var(--color-borde)] px-5 py-3.5">
                        <h2 class="text-[15px] font-medium">Viajes confirmados en el periodo</h2>
                        <span class="cifra ml-auto text-[12.5px] text-[var(--color-apagado)]">
                            {{ numero(cuenta.total_viajes) }} viajes
                        </span>
                    </div>

                    <div class="tabla-scroll">
                    <table class="w-full text-[12.5px]">
                        <thead>
                            <tr class="bg-[var(--color-superficie-sutil)] text-left text-[11px] font-medium text-[var(--color-apagado)]">
                                <th class="py-2.5 pl-5 pr-2.5">RUTA</th>
                                <th class="w-32 px-2.5 py-2.5">MATERIAL</th>
                                <th class="w-20 px-2.5 py-2.5 text-right">VIAJES</th>
                                <th class="w-20 px-2.5 py-2.5 text-right">M³</th>
                                <th class="w-32 px-2.5 py-2.5 text-right">UNITARIO</th>
                                <th class="w-36 py-2.5 pl-2.5 pr-5 text-right">SUBTOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(fila, i) in porRuta" :key="i" class="border-b border-[var(--color-borde)]">
                                <td class="py-2.5 pl-5 pr-2.5">{{ fila.ruta }}</td>
                                <td class="px-2.5 py-2.5 text-[var(--color-cuerpo)]">
                                    <span v-if="fila.material">{{ fila.material }}</span>
                                    <span v-else class="italic text-[var(--color-tenue)]">retiro</span>
                                </td>
                                <td class="cifra px-2.5 py-2.5 text-right">{{ fila.viajes }}</td>
                                <td class="cifra px-2.5 py-2.5 text-right text-[var(--color-apagado)]">{{ numero(fila.m3) }}</td>
                                <td class="cifra px-2.5 py-2.5 text-right">
                                    {{ pesos(fila.unitario) }}
                                    <span class="text-[var(--color-tenue)]">/{{ fila.unidad === 'm3' ? 'm³' : 'viaje' }}</span>
                                    <span v-if="fila.materialM3" class="block text-[11.5px] text-[var(--color-tenue)]">+ {{ pesos(fila.materialM3) }}/m³ material</span>
                                    <span v-if="fila.reembolso" class="block text-[11.5px] text-[var(--color-pendiente)]">+ {{ pesos(fila.reembolso) }} material que puso el conductor</span>
                                </td>
                                <td class="cifra py-2.5 pl-2.5 pr-5 text-right font-medium">{{ pesos(fila.subtotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>

                    <p v-if="abierta" class="border-t border-[var(--color-borde)] bg-[var(--color-superficie-sutil)] px-5 py-3 text-[12.5px] text-[var(--color-apagado)]">
                        Al cerrar la cuenta, estos viajes quedan bloqueados y no se pueden editar.
                    </p>
                </section>

                <!-- Resumen y abonos -->
                <div class="flex w-full shrink-0 flex-col gap-3.5 lg:max-w-[372px]">
                    <div class="tarjeta space-y-3 p-5">
                        <div class="flex items-baseline">
                            <span class="text-[13px] text-[var(--color-apagado)]">Total bruto</span>
                            <span class="cifra ml-auto text-base font-medium">{{ pesos(cuenta.total_bruto) }}</span>
                        </div>
                        <div class="flex items-baseline">
                            <span class="text-[13px] text-[var(--color-apagado)]">{{ esMaterial ? 'Abonado' : 'Anticipos' }}</span>
                            <span class="cifra ml-auto text-base font-medium text-[var(--color-pendiente)]">
                                {{ cuenta.total_anticipos ? `− ${pesos(cuenta.total_anticipos)}` : '—' }}
                            </span>
                        </div>
                        <div class="h-px bg-[var(--color-borde)]"></div>
                        <div class="flex items-baseline">
                            <span class="text-sm font-medium">{{ textos.saldo }}</span>
                            <span class="cifra ml-auto text-[22px] font-medium tracking-tight">{{ pesos(cuenta.saldo) }}</span>
                        </div>
                    </div>

                    <div class="tarjeta flex-1">
                        <div class="flex items-center gap-3 border-b border-[var(--color-borde)] px-5 py-3.5">
                            <h2 class="text-[15px] font-medium">{{ textos.abonos }}</h2>
                            <template v-if="abierta">
                                <button
                                    v-if="cuenta.saldo > 0"
                                    class="ml-auto text-[12.5px] font-medium text-[var(--color-enlace)]"
                                    @click="pagarSaldo"
                                >
                                    Pagar el saldo
                                </button>
                                <button
                                    class="text-[12.5px] font-medium text-[var(--color-enlace)]"
                                    :class="cuenta.saldo > 0 ? '' : 'ml-auto'"
                                    @click="anticipo.abierto = !anticipo.abierto"
                                >
                                    + Agregar
                                </button>
                            </template>
                        </div>

                        <!-- Alta rápida -->
                        <form v-if="anticipo.abierto" class="space-y-3 border-b border-[var(--color-borde)] bg-[var(--color-superficie-sutil)] p-4" @submit.prevent="agregarAnticipo">
                            <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                                <div>
                                    <label class="etiqueta">Fecha</label>
                                    <input v-model="anticipo.fecha" type="date" class="campo py-1.5 text-[12.5px]" required>
                                </div>
                                <div>
                                    <label class="etiqueta">Valor</label>
                                    <input v-model="anticipo.valor" type="number" min="1" inputmode="numeric" class="campo cifra py-1.5 text-[12.5px]" required>
                                </div>
                            </div>
                            <div>
                                <label class="etiqueta">N° de comprobante</label>
                                <input v-model="anticipo.numero_comprobante" class="campo py-1.5 text-[12.5px]" placeholder="Comprobante de ingreso o egreso">
                            </div>
                            <input v-model="anticipo.observacion" class="campo py-1.5 text-[12.5px]" placeholder="Observación (opcional)">
                            <SubirComprobante v-model="anticipo.archivo" />
                            <button class="boton-primario w-full justify-center py-1.5" :disabled="anticipo.guardando">
                                {{ anticipo.guardando ? 'Guardando…' : 'Registrar abono' }}
                            </button>
                        </form>

                        <div
                            v-for="a in cuenta.anticipos"
                            :key="a.id"
                            class="flex items-center gap-3 border-b border-[var(--color-borde)] px-5 py-3 last:border-0"
                        >
                            <div class="min-w-0">
                                <p class="cifra text-[13px] font-medium">{{ a.fecha }}</p>
                                <p class="truncate text-[11.5px] text-[var(--color-tenue)]">
                                    <template v-if="a.numero_comprobante">Comp. {{ a.numero_comprobante }} · </template>{{ a.observacion ?? 'Abono' }}
                                </p>
                            </div>
                            <span class="cifra ml-auto text-sm font-medium">{{ pesos(a.valor) }}</span>
                            <button v-if="a.tiene_comprobante" class="text-[var(--color-enlace)]" aria-label="Ver comprobante" @click="viendo = a.id">
                                <Icono nombre="adjunto" :tamano="16" />
                            </button>
                            <button
                                v-if="abierta"
                                class="text-[var(--color-tenue)] hover:text-[var(--color-critico)]"
                                aria-label="Quitar abono"
                                @click="quitarAnticipo(a)"
                            ><Icono nombre="x" :tamano="16" /></button>
                        </div>

                        <p v-if="!cuenta.anticipos?.length && !anticipo.abierto" class="px-5 py-8 text-center text-[12.5px] text-[var(--color-tenue)]">
                            {{ textos.vacio }}
                        </p>
                    </div>
                </div>
            </div>

            <VisorComprobante v-model="viendo" />

            <!-- Lo impreso: la cuenta de cobro (formato del Word) o su relación de viajes. -->
            <Teleport to="body">
                <article v-if="esCobro && impresion === 'cuenta'" class="cuenta-impresa">
                    <p>{{ EMPRESA.ciudad }}, {{ diaMes(hoy(), true) }}.</p>

                    <div class="my-12 text-center font-bold uppercase">
                        <p class="mb-6">Cuenta de cobro N°{{ cuenta.numero_cuenta }}</p>
                        <p>{{ cuenta.destinatario }}</p>
                        <p>NIT: {{ cuenta.nit_destinatario }}</p>
                        <p class="mt-6">Debe a:</p>
                        <p>{{ EMPRESA.nombre }}</p>
                        <p>NIT: {{ EMPRESA.nit }}</p>
                    </div>

                    <div class="space-y-4 text-justify">
                        <p>La suma de: {{ cuenta.total_en_letras }}. M/C (${{ pesos(cuenta.total_bruto) }}=)</p>
                        <p>Por concepto de: Transporte de material a obra.</p>
                        <p>El periodo relacionado es del {{ periodoEnTexto.toUpperCase() }}.</p>
                        <p>Se anexan los ({{ cuenta.total_viajes }}) recibos originales que fueron entregados en obra.</p>
                        <p class="pt-8">Cordialmente,</p>
                    </div>

                    <div class="mt-24 font-bold">
                        <p>{{ EMPRESA.representante }}.</p>
                        <p>R.L {{ EMPRESA.nombre }}</p>
                    </div>
                </article>

                <article v-if="esCobro && impresion === 'relacion'" class="cuenta-impresa relacion-impresa">
                    <p class="text-center font-bold">CUENTA N° {{ cuenta.numero_cuenta }}</p>

                    <section v-for="bloque in relacion" :key="bloque.origen">
                        <p class="text-center font-bold">{{ cuenta.destinatario.toUpperCase() }}</p>
                        <p class="mb-2 text-center font-bold">{{ bloque.titulo }}</p>
                        <table>
                            <thead>
                                <tr><th></th><th>FECHA</th><th>PLACA</th><th># VALE</th><th># VIAJES</th><th>MATERIAL</th><th>VALOR</th><th>M3</th><th>VALOR TOTAL</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="(f, i) in bloque.filas" :key="i">
                                    <td>{{ i + 1 }}</td>
                                    <td>{{ f.fecha }}</td>
                                    <td>{{ f.placa }}</td>
                                    <td>{{ f.vale }}</td>
                                    <td>1</td>
                                    <td class="izquierda">{{ f.material }}</td>
                                    <td>{{ pesos(f.valor) }}</td>
                                    <td>{{ f.m3 }}</td>
                                    <td>{{ pesos(f.total) }}</td>
                                </tr>
                                <tr class="subtotal">
                                    <td colspan="4"></td>
                                    <td>{{ bloque.viajes }}</td>
                                    <td colspan="2"></td>
                                    <td>{{ numero(bloque.m3) }}</td>
                                    <td>{{ pesos(bloque.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <p class="mt-4 text-right font-bold">VALOR TOTAL A PAGAR: ${{ pesos(cuenta.total_bruto) }}</p>
                </article>
            </Teleport>
        </template>
    </Cargando>
</template>
