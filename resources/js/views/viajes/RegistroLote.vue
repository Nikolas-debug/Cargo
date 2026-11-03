<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api, { mensajeDeError } from '../../lib/api'
import { pesos, hoy } from '../../lib/formato'
import { useAvisos } from '../../stores/avisos'
import Icono from '../../components/Icono.vue'

const router = useRouter()
const avisos = useAvisos()

const obras = ref([])
const canterasMateriales = ref([])
const botaderos = ref([])
const volquetas = ref([])
const guardando = ref(false)

/** Lo que se repite en todo el lote: se escribe una sola vez. */
const cabecera = reactive({
    obra_id: '',
    tipo_viaje: 'suministro',
    fecha_viaje: hoy(),
    cantera_material_id: '',
    botadero_id: '',
})

const filas = ref([nuevaFila()])
const tarifa = ref(null)
const buscandoTarifa = ref(false)
const errorTarifa = ref('')

function nuevaFila() {
    return { volqueta_id: '', m3: '', cantidad: 1, conductor_id: '', material_por_conductor: false }
}

/** Una fila puede valer varios viajes: la misma placa con los mismos m³. */
const cantidadDe = (f) => Math.max(1, Number(f.cantidad) || 1)

const esRetiro = computed(() => cabecera.tipo_viaje === 'retiro')

const rutaCompleta = computed(() =>
    cabecera.obra_id && (esRetiro.value ? cabecera.botadero_id : cabecera.cantera_material_id),
)

const filasValidas = computed(() =>
    filas.value.filter((f) => f.volqueta_id && Number(f.m3) > 0),
)

const totalViajes = computed(() => filasValidas.value.reduce((s, f) => s + cantidadDe(f), 0))

/** El par cantera–material elegido: trae el precio del material por m³. */
const canteraMaterial = computed(() =>
    esRetiro.value ? null : canterasMateriales.value.find((c) => c.id === Number(cabecera.cantera_material_id)),
)

// Un suministro sin precio de material se cobraría sin el material: el
// backend lo rechaza, aquí se avisa antes de llenar las filas.
const faltaPrecioMaterial = computed(() => canteraMaterial.value && canteraMaterial.value.precio_compra_m3 == null)

const totales = computed(() => {
    if (!tarifa.value) return { m3: 0, flete: 0, material: 0, cobro: 0 }

    const m3 = filasValidas.value.reduce((s, f) => s + Number(f.m3) * cantidadDe(f), 0)
    const flete = tarifa.value.unidad === 'm3'
        ? tarifa.value.valor * m3
        : tarifa.value.valor * totalViajes.value
    const material = (canteraMaterial.value?.precio_compra_m3 ?? 0) * m3

    return { m3, flete, material, cobro: flete + material }
})

function volquetaDe(id) {
    return volquetas.value.find((v) => v.id === Number(id))
}

/**
 * El valor se consulta mientras se llena la cabecera, no al guardar: si falta
 * la tarifa de esa ruta, el digitador se entera antes de escribir 40 filas.
 */
async function consultarTarifa() {
    tarifa.value = null
    errorTarifa.value = ''

    if (!rutaCompleta.value) return

    buscandoTarifa.value = true

    try {
        const cm = canterasMateriales.value.find((c) => c.id === Number(cabecera.cantera_material_id))

        const { data } = await api.get('tarifas/resolver', {
            params: {
                tipo: 'cobro',
                tipo_viaje: cabecera.tipo_viaje,
                obra_id: cabecera.obra_id,
                cantera_id: esRetiro.value ? null : cm?.cantera_id,
                material_id: esRetiro.value ? null : cm?.material_id,
                botadero_id: esRetiro.value ? cabecera.botadero_id : null,
                fecha: cabecera.fecha_viaje,
            },
        })

        if (data.encontrada) {
            tarifa.value = data.tarifa
        } else {
            errorTarifa.value = 'Esta ruta no tiene tarifa vigente. Hay que pactarla antes de registrar viajes.'
        }
    } catch (e) {
        errorTarifa.value = mensajeDeError(e)
    } finally {
        buscandoTarifa.value = false
    }
}

function agregarFila() {
    filas.value.push(nuevaFila())
}

function quitarFila(indice) {
    filas.value.splice(indice, 1)
    if (!filas.value.length) agregarFila()
}

/** Enter en la última fila agrega otra: así se digita sin soltar el teclado. */
function alPresionarEnter(indice) {
    if (indice === filas.value.length - 1) agregarFila()
}

async function guardar() {
    if (!filasValidas.value.length) {
        avisos.error('Agrega al menos un viaje con placa y metros cúbicos.')
        return
    }

    guardando.value = true

    try {
        const { data } = await api.post('viajes', {
            obra_id: cabecera.obra_id,
            tipo_viaje: cabecera.tipo_viaje,
            fecha_viaje: cabecera.fecha_viaje,
            cantera_material_id: esRetiro.value ? null : cabecera.cantera_material_id,
            botadero_id: esRetiro.value ? cabecera.botadero_id : null,
            viajes: filasValidas.value.map((f) => ({
                volqueta_id: f.volqueta_id,
                m3: Number(f.m3),
                cantidad: cantidadDe(f),
                conductor_id: f.conductor_id || null,
                material_por_conductor: !esRetiro.value && f.material_por_conductor,
            })),
        })

        avisos.ok(data.message)
        router.push({ name: 'viajes' })
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudieron guardar los viajes.'))
    } finally {
        guardando.value = false
    }
}

watch(
    () => [cabecera.obra_id, cabecera.tipo_viaje, cabecera.cantera_material_id, cabecera.botadero_id, cabecera.fecha_viaje],
    consultarTarifa,
)

onMounted(async () => {
    const [o, cm, b, v] = await Promise.all([
        api.get('obras', { params: { por_pagina: 100, solo_activos: 1 } }),
        api.get('cantera-material', { params: { por_pagina: 200, solo_activos: 1 } }),
        api.get('botaderos', { params: { por_pagina: 100, solo_activos: 1 } }),
        api.get('volquetas', { params: { por_pagina: 200, solo_activos: 1 } }),
    ])

    obras.value = o.data.data
    canterasMateriales.value = cm.data.data
    botaderos.value = b.data.data
    volquetas.value = v.data.data
})
</script>

<template>
    <header class="sticky top-14 z-20 flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-[var(--color-borde)] bg-white/95 px-4 py-4 backdrop-blur sm:px-8 sm:py-5 lg:top-0">
        <button class="text-[var(--color-apagado)] hover:text-[var(--color-tinta)]" @click="router.back()"><Icono nombre="x" :tamano="16" /></button>
        <h1 class="text-[24px] font-normal leading-tight tracking-[-0.01em]">Registrar viajes</h1>
        <span class="text-[13px] text-[var(--color-tenue)]">en lote</span>

        <div class="ml-auto flex items-center gap-2">
            <button class="boton-secundario" @click="router.back()">Cancelar</button>
            <button class="boton-primario" :disabled="guardando || !tarifa || faltaPrecioMaterial || !filasValidas.length" @click="guardar">
                {{ guardando ? 'Guardando…' : `Guardar ${totalViajes} viajes` }}
            </button>
        </div>
    </header>

    <div class="mx-auto max-w-[1360px] space-y-6 px-4 py-6 sm:px-8">
        <!-- Cabecera -->
        <section class="tarjeta">
            <div class="flex items-center gap-2 border-b border-[var(--color-borde)] px-5 py-3.5">
                <h2 class="text-[15px] font-medium">Datos que se repiten en todo el lote</h2>
                <span class="text-[12.5px] text-[var(--color-tenue)]">— se escriben una sola vez</span>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3.5 p-5">
                <div>
                    <label class="etiqueta">Obra</label>
                    <select v-model="cabecera.obra_id" class="campo">
                        <option value="">Seleccionar…</option>
                        <option v-for="obra in obras" :key="obra.id" :value="obra.id">{{ obra.nombre }}</option>
                    </select>
                </div>

                <div>
                    <label class="etiqueta">Fecha</label>
                    <input v-model="cabecera.fecha_viaje" type="date" class="campo">
                </div>

                <div>
                    <label class="etiqueta">Tipo</label>
                    <div class="flex overflow-hidden rounded-md border border-[var(--color-borde-fuerte)]">
                        <button
                            class="flex-1 py-2 text-[12.5px] transition"
                            :class="!esRetiro ? 'bg-[var(--color-marca)] font-medium text-white' : 'text-[var(--color-apagado)]'"
                            @click="cabecera.tipo_viaje = 'suministro'"
                        >
                            Suministro
                        </button>
                        <button
                            class="flex-1 py-2 text-[12.5px] transition"
                            :class="esRetiro ? 'bg-[var(--color-marca)] font-medium text-white' : 'text-[var(--color-apagado)]'"
                            @click="cabecera.tipo_viaje = 'retiro'"
                        >
                            Retiro
                        </button>
                    </div>
                </div>

                <template v-if="!esRetiro">
                    <div class="col-span-2">
                        <label class="etiqueta">Cantera y material</label>
                        <select v-model="cabecera.cantera_material_id" class="campo">
                            <option value="">Seleccionar…</option>
                            <option v-for="cm in canterasMateriales" :key="cm.id" :value="cm.id">
                                {{ cm.cantera?.nombre }} — {{ cm.material?.nombre }}
                            </option>
                        </select>
                    </div>
                </template>

                <template v-else>
                    <div class="col-span-2">
                        <label class="etiqueta">Botadero (a dónde va el escombro)</label>
                        <select v-model="cabecera.botadero_id" class="campo">
                            <option value="">Seleccionar…</option>
                            <option v-for="b in botaderos" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                        </select>
                    </div>
                </template>
            </div>

            <!-- La tarifa resuelta, antes de escribir una sola fila -->
            <div v-if="tarifa" class="mx-5 mb-5 flex items-center gap-3.5 rounded-md border border-[var(--color-confirmado)]/30 bg-[var(--color-confirmado-fondo)] px-4 py-3">
                <p class="text-[13px] text-[var(--color-confirmado)]">
                    <span class="font-medium">Tarifa vigente encontrada</span> para {{ tarifa.ruta }}
                </p>
                <p class="ml-auto text-xs text-[var(--color-confirmado)]">
                    Flete
                    <span class="cifra ml-1.5 text-[13.5px] font-medium text-[var(--color-tinta)]">
                        {{ pesos(tarifa.valor) }} / {{ tarifa.unidad === 'm3' ? 'm³' : 'viaje' }}
                    </span>
                    <template v-if="canteraMaterial?.precio_compra_m3 != null">
                        <span class="mx-1.5">+</span>{{ canteraMaterial.material?.nombre }}
                        <span class="cifra ml-1.5 text-[13.5px] font-medium text-[var(--color-tinta)]">
                            {{ pesos(canteraMaterial.precio_compra_m3) }} / m³
                        </span>
                    </template>
                </p>
            </div>

            <div v-if="faltaPrecioMaterial" class="mx-5 mb-5 flex items-center gap-3.5 rounded-md border border-[var(--color-pendiente)]/35 bg-[var(--color-pendiente-fondo)] px-4 py-3">
                <p class="text-[13px] text-[var(--color-pendiente)]">
                    {{ canteraMaterial.material?.nombre }} en {{ canteraMaterial.cantera?.nombre }} no tiene precio por m³. Se le suma al cobro, así que hay que ponérselo primero.
                </p>
                <router-link :to="{ name: 'catalogos', params: { catalogo: 'canteras' } }" class="ml-auto shrink-0 rounded border border-[var(--color-pendiente)]/45 bg-white px-3 py-1.5 text-[12.5px] font-medium text-[var(--color-pendiente)]">
                    Ir a Canteras
                </router-link>
            </div>

            <div v-else-if="errorTarifa" class="mx-5 mb-5 flex items-center gap-3.5 rounded-md border border-[var(--color-pendiente)]/35 bg-[var(--color-pendiente-fondo)] px-4 py-3">
                <p class="text-[13px] text-[var(--color-pendiente)]">{{ errorTarifa }}</p>
                <router-link :to="{ name: 'tarifas' }" class="ml-auto shrink-0 rounded border border-[var(--color-pendiente)]/45 bg-white px-3 py-1.5 text-[12.5px] font-medium text-[var(--color-pendiente)]">
                    Pactar tarifa
                </router-link>
            </div>

            <p v-else-if="buscandoTarifa" class="px-5 pb-[18px] text-[13px] text-[var(--color-apagado)]">Buscando tarifa…</p>
        </section>

        <!-- Filas -->
        <section class="tarjeta">
            <div class="flex items-center gap-2 border-b border-[var(--color-borde)] px-5 py-3.5">
                <h2 class="text-[15px] font-medium">Viajes del lote</h2>
                <span class="text-[12.5px] text-[var(--color-tenue)]">
                    — escribe la placa y los m³;
                    <kbd class="cifra rounded bg-[var(--color-superficie-sutil)] px-1.5 py-px">Enter</kbd> agrega la siguiente fila
                </span>
            </div>

            <div class="tabla-scroll">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="bg-[var(--color-superficie-sutil)] text-left text-[11px] font-medium text-[var(--color-apagado)]">
                        <th class="w-11 py-2 pl-5 pr-2.5">#</th>
                        <th class="w-56 px-2.5 py-2">PLACA</th>
                        <th class="px-2.5 py-2">DUEÑO</th>
                        <th class="w-28 px-2.5 py-2">M³ POR VIAJE</th>
                        <th class="w-24 px-2.5 py-2">VIAJES</th>
                        <th v-if="!esRetiro" class="w-32 px-2.5 py-2" title="El conductor consiguió o compró el material: se le debe a él, no a la cantera">MATERIAL LO PUSO</th>
                        <th class="w-11 py-2 pl-2.5 pr-5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(fila, i) in filas" :key="i" class="border-b border-[var(--color-borde)]">
                        <td class="cifra py-2 pl-5 pr-2.5 text-[var(--color-tenue)]">{{ i + 1 }}</td>
                        <td class="px-2.5 py-2">
                            <select v-model="fila.volqueta_id" class="campo py-1.5">
                                <option value="">Placa…</option>
                                <option v-for="v in volquetas" :key="v.id" :value="v.id">{{ v.placa }}</option>
                            </select>
                        </td>
                        <td class="px-2.5 py-2 text-[var(--color-apagado)]">
                            <template v-if="volquetaDe(fila.volqueta_id)">
                                {{ volquetaDe(fila.volqueta_id).propietario?.nombre }}
                                {{ volquetaDe(fila.volqueta_id).propietario?.apellido }}
                                <span v-if="volquetaDe(fila.volqueta_id).es_propia" class="ml-1.5 rounded bg-[var(--color-info-fondo)] px-1.5 py-0.5 text-[10.5px] font-medium text-[var(--color-enlace)]">
                                    volqueta propia
                                </span>
                            </template>
                        </td>
                        <td class="px-2.5 py-2">
                            <input
                                v-model="fila.m3"
                                type="number"
                                min="1"
                                max="60"
                                class="campo py-1.5"
                                @keyup.enter="alPresionarEnter(i)"
                            >
                        </td>
                        <td class="px-2.5 py-2">
                            <input
                                v-model="fila.cantidad"
                                type="number"
                                min="1"
                                max="30"
                                class="campo py-1.5"
                                @keyup.enter="alPresionarEnter(i)"
                            >
                        </td>
                        <td v-if="!esRetiro" class="px-2.5 py-2">
                            <label class="flex items-center gap-2 text-[12.5px] text-[var(--color-apagado)]">
                                <input v-model="fila.material_por_conductor" type="checkbox" class="size-4">
                                El conductor
                            </label>
                        </td>
                        <td class="py-2 pl-2.5 pr-5">
                            <button class="text-[var(--color-tenue)] hover:text-[var(--color-critico)]" @click="quitarFila(i)"><Icono nombre="x" :tamano="16" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>

            <div class="border-t border-[var(--color-borde)] px-5 py-2.5">
                <button class="text-[12.5px] font-medium text-[var(--color-enlace)]" @click="agregarFila">+ Agregar fila</button>
            </div>

            <div class="flex flex-wrap items-center gap-x-7 gap-y-2 border-t border-[var(--color-borde)] bg-[var(--color-superficie-sutil)] px-5 py-3.5">
                <p class="text-[12.5px] text-[var(--color-apagado)]">
                    Viajes <span class="cifra ml-1 text-[15px] font-medium text-[var(--color-tinta)]">{{ totalViajes }}</span>
                </p>
                <p class="text-[12.5px] text-[var(--color-apagado)]">
                    Metros cúbicos <span class="cifra ml-1 text-[15px] font-medium text-[var(--color-tinta)]">{{ totales.m3 }}</span>
                </p>
                <p v-if="totales.material" class="ml-auto text-[12.5px] text-[var(--color-apagado)]">
                    Flete <span class="cifra ml-1 text-[var(--color-tinta)]">{{ pesos(totales.flete, true) }}</span>
                    <span class="mx-1.5">+</span>
                    Material <span class="cifra ml-1 text-[var(--color-tinta)]">{{ pesos(totales.material, true) }}</span>
                </p>
                <p class="text-[12.5px] text-[var(--color-apagado)]" :class="totales.material ? '' : 'ml-auto'">
                    A cobrar <span class="cifra ml-1 text-[15px] font-medium text-[var(--color-tinta)]">{{ pesos(totales.cobro, true) }}</span>
                </p>
            </div>
        </section>

        <p class="text-[12.5px] text-[var(--color-apagado)]">
            Los viajes se guardan como <span class="font-medium text-[var(--color-pendiente)]">por confirmar</span>.
            Se confirman cuando llegue el vale firmado.
        </p>
    </div>
</template>
