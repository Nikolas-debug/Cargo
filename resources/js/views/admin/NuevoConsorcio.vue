<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api, { mensajeDeError } from '../../lib/api'
import { pesos, hoy } from '../../lib/formato'
import { useAvisos } from '../../stores/avisos'
import Icono from '../../components/Icono.vue'

const router = useRouter()
const avisos = useAvisos()

const paso = ref(1)
const guardando = ref(false)

const canteras = ref([])
const botaderos = ref([])
const terceros = ref([])

const consorcio = reactive({
    razon_social: '',
    nit: '',
    departamento: '',
    municipio: '',
    direccion_principal: '',
    correo: '',
    telefono: '',
})

const obras = ref([nuevaObra()])

function nuevaObra() {
    return { nombre: '', direccion: '', municipio: '', tarifas: [] }
}

function nuevaTarifa() {
    return {
        tipo: 'cobro',
        tipo_viaje: 'suministro',
        cantera_id: '',
        botadero_id: '',
        tercero_id: '',
        valor: '',
        valor_base: '',
        porcentaje: '',
        unidad: 'm3',
        fecha_inicio: hoy(),
    }
}

const PASOS = [
    { numero: 1, texto: 'Consorcio' },
    { numero: 2, texto: 'Obras' },
    { numero: 3, texto: 'Tarifas' },
    { numero: 4, texto: 'Revisar' },
]

const puedeSeguir = computed(() => {
    if (paso.value === 1) return consorcio.razon_social.trim() && consorcio.nit.trim()
    if (paso.value === 2) return obras.value.some((o) => o.nombre.trim())
    return true
})

/** Las obras que se quedarían sin poder recibir viajes. */
const obrasSinCobro = computed(() =>
    obras.value.filter((o) => o.nombre.trim() && !o.tarifas.some((t) => t.tipo === 'cobro')),
)

const totalTarifas = computed(() => obras.value.reduce((s, o) => s + o.tarifas.length, 0))

function agregarObra() {
    obras.value.push(nuevaObra())
}

function quitarObra(i) {
    obras.value.splice(i, 1)
    if (!obras.value.length) agregarObra()
}

function agregarTarifa(obra) {
    obra.tarifas.push(nuevaTarifa())
}

/** Cuando cambia el tipo, se limpian los campos que ya no aplican. */
function alCambiarTipo(tarifa) {
    if (tarifa.tipo === 'cobro') tarifa.tercero_id = ''

    if (tarifa.tipo === 'pago_conductor') {
        tarifa.unidad = 'porcentaje'
        tarifa.valor = ''
    } else if (tarifa.unidad === 'porcentaje') {
        tarifa.unidad = tarifa.tipo === 'cobro' ? 'm3' : 'viaje'
    }
}

function alCambiarTipoViaje(tarifa) {
    if (tarifa.tipo_viaje === 'retiro') {
        tarifa.cantera_id = ''
        tarifa.unidad = 'viaje'
    } else {
        tarifa.botadero_id = ''
    }
}

function nombreCantera(id) {
    return canteras.value.find((c) => c.id === Number(id))?.nombre ?? '?'
}

function nombreBotadero(id) {
    return botaderos.value.find((b) => b.id === Number(id))?.nombre ?? '?'
}

function rutaDe(obra, tarifa) {
    return tarifa.tipo_viaje === 'retiro'
        ? `${obra.nombre || 'la obra'} → ${nombreBotadero(tarifa.botadero_id)}`
        : `${nombreCantera(tarifa.cantera_id)} → ${obra.nombre || 'la obra'}`
}

async function guardar() {
    guardando.value = true

    const carga = {
        ...consorcio,
        obras: obras.value
            .filter((o) => o.nombre.trim())
            .map((o) => ({
                nombre: o.nombre,
                direccion: o.direccion || null,
                municipio: o.municipio || null,
                tarifas: o.tarifas.map((t) => ({
                    tipo: t.tipo,
                    tipo_viaje: t.tipo_viaje,
                    cantera_id: t.tipo_viaje === 'suministro' ? t.cantera_id : null,
                    botadero_id: t.tipo_viaje === 'retiro' ? t.botadero_id : null,
                    tercero_id: t.tipo === 'cobro' ? null : t.tercero_id,
                    valor: t.tipo === 'pago_conductor' ? null : Number(t.valor),
                    valor_base: t.tipo === 'pago_conductor' ? Number(t.valor_base) : null,
                    porcentaje: t.tipo === 'pago_conductor' ? Number(t.porcentaje) : null,
                    unidad: t.unidad,
                    fecha_inicio: t.fecha_inicio,
                })),
            })),
    }

    try {
        const { data } = await api.post('consorcios/alta-completa', carga)

        avisos.ok(data.message)

        if (data.obras_sin_tarifa_de_cobro?.length) {
            avisos.error(
                `Sin tarifa de cobro: ${data.obras_sin_tarifa_de_cobro.join(', ')}. No se podrán registrar viajes ahí hasta pactarla.`,
            )
        }

        router.push({ name: 'catalogos', params: { catalogo: 'obras' } })
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo crear el consorcio.'))
    } finally {
        guardando.value = false
    }
}

onMounted(async () => {
    const [c, b, t] = await Promise.all([
        api.get('canteras', { params: { por_pagina: 100, solo_activos: 1 } }),
        api.get('botaderos', { params: { por_pagina: 100, solo_activos: 1 } }),
        api.get('terceros', { params: { por_pagina: 200 } }),
    ])

    canteras.value = c.data.data
    botaderos.value = b.data.data
    terceros.value = t.data.data
})
</script>

<template>
    <header class="sticky top-14 z-20 flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-[var(--color-borde)] bg-white/95 px-4 py-4 backdrop-blur sm:px-8 sm:py-5 lg:top-0">
        <button class="text-[var(--color-apagado)] hover:text-[var(--color-tinta)]" @click="router.back()"><Icono nombre="x" :tamano="16" /></button>
        <h1 class="text-[24px] font-normal leading-tight tracking-[-0.01em]">Nuevo consorcio</h1>
        <span class="text-[13px] text-[var(--color-tenue)]">con sus obras y tarifas</span>
    </header>

    <!-- Pasos -->
    <div class="flex items-center gap-1 overflow-x-auto border-b border-[var(--color-borde)] bg-white px-4 pb-3 sm:px-7">
        <template v-for="(p, i) in PASOS" :key="p.numero">
            <button
                class="flex shrink-0 items-center gap-2 rounded-md px-3 py-1.5 text-[13px] transition"
                :class="paso === p.numero ? 'bg-[var(--color-info-fondo)] font-medium text-[var(--color-info)]' : 'text-[var(--color-apagado)]'"
                @click="paso = p.numero"
            >
                <span
                    class="cifra flex size-5 items-center justify-center rounded-full text-[11px]"
                    :class="paso >= p.numero ? 'bg-[var(--color-marca)] text-white' : 'bg-[var(--color-borde)] text-[var(--color-apagado)]'"
                >
                    {{ p.numero }}
                </span>
                {{ p.texto }}
            </button>
            <span v-if="i < PASOS.length - 1" class="text-[var(--color-tenue)]">›</span>
        </template>
    </div>

    <div class="px-4 py-6 sm:px-8">
        <!-- 1. Consorcio -->
        <section v-show="paso === 1" class="tarjeta max-w-3xl">
            <div class="border-b border-[var(--color-borde)] px-5 py-3.5">
                <h2 class="text-[15px] font-medium">Datos del consorcio</h2>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-5">
                <div class="sm:col-span-2">
                    <label class="etiqueta">Razón social <span class="text-[var(--color-critico)]">*</span></label>
                    <input v-model="consorcio.razon_social" class="campo" placeholder="Consorcio …">
                </div>
                <div>
                    <label class="etiqueta">NIT <span class="text-[var(--color-critico)]">*</span></label>
                    <input v-model="consorcio.nit" class="campo cifra">
                </div>
                <div>
                    <label class="etiqueta">Teléfono</label>
                    <input v-model="consorcio.telefono" class="campo">
                </div>
                <div>
                    <label class="etiqueta">Departamento</label>
                    <input v-model="consorcio.departamento" class="campo">
                </div>
                <div>
                    <label class="etiqueta">Municipio</label>
                    <input v-model="consorcio.municipio" class="campo">
                </div>
                <div>
                    <label class="etiqueta">Dirección</label>
                    <input v-model="consorcio.direccion_principal" class="campo">
                </div>
                <div>
                    <label class="etiqueta">Correo</label>
                    <input v-model="consorcio.correo" type="email" class="campo">
                </div>
            </div>
        </section>

        <!-- 2. Obras -->
        <section v-show="paso === 2" class="tarjeta max-w-3xl">
            <div class="flex items-center gap-2 border-b border-[var(--color-borde)] px-5 py-3.5">
                <h2 class="text-[15px] font-medium">Obras del consorcio</h2>
                <span class="text-[12.5px] text-[var(--color-tenue)]">— el destino de los viajes</span>
            </div>

            <div class="space-y-3 p-5">
                <div v-for="(obra, i) in obras" :key="i" class="grid grid-cols-1 md:grid-cols-[1fr_1fr_160px_32px] gap-3">
                    <div>
                        <label v-if="i === 0" class="etiqueta">Nombre</label>
                        <input v-model="obra.nombre" class="campo" placeholder="Parque San Jerónimo">
                    </div>
                    <div>
                        <label v-if="i === 0" class="etiqueta">Dirección</label>
                        <input v-model="obra.direccion" class="campo">
                    </div>
                    <div>
                        <label v-if="i === 0" class="etiqueta">Municipio</label>
                        <input v-model="obra.municipio" class="campo">
                    </div>
                    <div :class="i === 0 ? 'pt-6' : ''">
                        <button class="p-2 text-[var(--color-tenue)] hover:text-[var(--color-critico)]" @click="quitarObra(i)"><Icono nombre="x" :tamano="16" /></button>
                    </div>
                </div>

                <button class="text-[12.5px] font-medium text-[var(--color-enlace)]" @click="agregarObra">+ Agregar obra</button>
            </div>
        </section>

        <!-- 3. Tarifas -->
        <section v-show="paso === 3" class="space-y-4">
            <p class="max-w-3xl text-[12.5px] text-[var(--color-apagado)]">
                La tarifa se pacta por <span class="font-medium text-[var(--color-tinta)]">ruta</span>: lo que manda es la
                distancia, no el material. Con una tarifa de cobro por cada ruta basta; las excepciones por material se
                agregan después, si aparecen.
            </p>

            <div v-for="(obra, i) in obras.filter((o) => o.nombre.trim())" :key="i" class="tarjeta">
                <div class="flex items-center gap-2 border-b border-[var(--color-borde)] px-5 py-3.5">
                    <h2 class="text-[15px] font-medium">{{ obra.nombre }}</h2>
                    <span v-if="!obra.tarifas.some((t) => t.tipo === 'cobro')" class="rounded bg-[var(--color-pendiente-fondo)] px-2 py-0.5 text-[11px] font-medium text-[var(--color-pendiente)]">
                        sin tarifa de cobro
                    </span>
                    <button class="ml-auto text-[12.5px] font-medium text-[var(--color-enlace)]" @click="agregarTarifa(obra)">
                        + Agregar tarifa
                    </button>
                </div>

                <div v-if="!obra.tarifas.length" class="px-5 py-6 text-center text-[12.5px] text-[var(--color-tenue)]">
                    Sin tarifas todavía. Sin al menos una de cobro no se podrán registrar viajes a esta obra.
                </div>

                <div v-for="(tarifa, j) in obra.tarifas" :key="j" class="grid grid-cols-2 lg:grid-cols-[150px_130px_1fr_1fr_120px_110px_32px] items-end gap-2.5 border-b border-[var(--color-borde)] px-5 py-3 last:border-0">
                    <div>
                        <label v-if="j === 0" class="etiqueta">Tipo</label>
                        <select v-model="tarifa.tipo" class="campo py-1.5 text-[12.5px]" @change="alCambiarTipo(tarifa)">
                            <option value="cobro">Cobro</option>
                            <option value="pago_propietario">Pago al dueño</option>
                            <option value="pago_conductor">Pago conductor</option>
                        </select>
                    </div>

                    <div>
                        <label v-if="j === 0" class="etiqueta">Viaje</label>
                        <select v-model="tarifa.tipo_viaje" class="campo py-1.5 text-[12.5px]" @change="alCambiarTipoViaje(tarifa)">
                            <option value="suministro">Suministro</option>
                            <option value="retiro">Retiro</option>
                        </select>
                    </div>

                    <div>
                        <label v-if="j === 0" class="etiqueta">{{ tarifa.tipo_viaje === 'retiro' ? 'Botadero' : 'Cantera' }}</label>
                        <select v-if="tarifa.tipo_viaje === 'retiro'" v-model="tarifa.botadero_id" class="campo py-1.5 text-[12.5px]">
                            <option value="">Seleccionar…</option>
                            <option v-for="b in botaderos" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                        </select>
                        <select v-else v-model="tarifa.cantera_id" class="campo py-1.5 text-[12.5px]">
                            <option value="">Seleccionar…</option>
                            <option v-for="c in canteras" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                        </select>
                    </div>

                    <div>
                        <label v-if="j === 0" class="etiqueta">Con quién</label>
                        <select v-if="tarifa.tipo !== 'cobro'" v-model="tarifa.tercero_id" class="campo py-1.5 text-[12.5px]">
                            <option value="">Seleccionar…</option>
                            <option v-for="t in terceros" :key="t.id" :value="t.id">
                                {{ [t.nombre, t.apellido].filter(Boolean).join(' ') }}
                            </option>
                        </select>
                        <p v-else class="px-1 py-2 text-[12px] text-[var(--color-tenue)]">El consorcio</p>
                    </div>

                    <div>
                        <label v-if="j === 0" class="etiqueta">{{ tarifa.tipo === 'pago_conductor' ? 'Base' : 'Valor' }}</label>
                        <input
                            v-if="tarifa.tipo === 'pago_conductor'"
                            v-model="tarifa.valor_base"
                            type="number"
                            class="campo cifra py-1.5 text-[12.5px]"
                        >
                        <input v-else v-model="tarifa.valor" type="number" class="campo cifra py-1.5 text-[12.5px]">
                    </div>

                    <div>
                        <label v-if="j === 0" class="etiqueta">{{ tarifa.tipo === 'pago_conductor' ? '%' : 'Unidad' }}</label>
                        <input
                            v-if="tarifa.tipo === 'pago_conductor'"
                            v-model="tarifa.porcentaje"
                            type="number"
                            class="campo cifra py-1.5 text-[12.5px]"
                        >
                        <select v-else v-model="tarifa.unidad" class="campo py-1.5 text-[12.5px]">
                            <option value="m3">por m³</option>
                            <option value="viaje">por viaje</option>
                        </select>
                    </div>

                    <div>
                        <button class="p-2 text-[var(--color-tenue)] hover:text-[var(--color-critico)]" @click="obra.tarifas.splice(j, 1)"><Icono nombre="x" :tamano="16" /></button>
                    </div>
                </div>
            </div>
        </section>

        <!-- 4. Revisar -->
        <section v-show="paso === 4" class="tarjeta max-w-3xl">
            <div class="border-b border-[var(--color-borde)] px-5 py-3.5">
                <h2 class="text-[15px] font-medium">Revisar antes de crear</h2>
            </div>

            <div class="space-y-5 p-5">
                <div>
                    <p class="text-[15px] font-medium">{{ consorcio.razon_social || '(sin razón social)' }}</p>
                    <p class="cifra text-[12.5px] text-[var(--color-apagado)]">NIT {{ consorcio.nit || '—' }}</p>
                </div>

                <div v-for="(obra, i) in obras.filter((o) => o.nombre.trim())" :key="i" class="rounded-md border border-[var(--color-borde)] p-3.5">
                    <p class="text-[13px] font-medium">{{ obra.nombre }}</p>

                    <ul v-if="obra.tarifas.length" class="mt-2 space-y-1">
                        <li v-for="(t, j) in obra.tarifas" :key="j" class="flex items-center gap-2 text-[12.5px]">
                            <span class="rounded bg-[var(--color-neutro-fondo)] px-1.5 py-0.5 text-[10.5px] font-medium text-[var(--color-apagado)]">
                                {{ t.tipo === 'cobro' ? 'Cobro' : t.tipo === 'pago_propietario' ? 'Pago dueño' : 'Pago conductor' }}
                            </span>
                            <span class="text-[var(--color-cuerpo)]">{{ rutaDe(obra, t) }}</span>
                            <span class="cifra ml-auto font-medium">
                                <template v-if="t.tipo === 'pago_conductor'">
                                    {{ t.porcentaje || 0 }}% de {{ pesos(Number(t.valor_base) || 0) }}
                                </template>
                                <template v-else>
                                    {{ pesos(Number(t.valor) || 0) }} / {{ t.unidad === 'm3' ? 'm³' : 'viaje' }}
                                </template>
                            </span>
                        </li>
                    </ul>

                    <p v-else class="mt-1.5 text-[12px] text-[var(--color-pendiente)]">
                        Sin tarifas: no se podrán registrar viajes a esta obra.
                    </p>
                </div>

                <div v-if="obrasSinCobro.length" class="rounded-md border border-[var(--color-pendiente)]/35 bg-[var(--color-pendiente-fondo)] px-4 py-3 text-[12.5px] text-[var(--color-pendiente)]">
                    <span class="font-medium">Ojo:</span>
                    {{ obrasSinCobro.map((o) => o.nombre).join(', ') }}
                    {{ obrasSinCobro.length === 1 ? 'queda' : 'quedan' }} sin tarifa de cobro. Se puede crear igual,
                    pero el digitador no podrá registrar viajes ahí hasta pactarla.
                </div>

                <p class="text-[12.5px] text-[var(--color-apagado)]">
                    Se crearán {{ obras.filter((o) => o.nombre.trim()).length }} obras y {{ totalTarifas }} tarifas,
                    todo junto: si algo falla, no queda nada a medias.
                </p>
            </div>
        </section>
    </div>

    <!-- Navegación -->
    <div class="sticky bottom-0 flex items-center gap-2 border-t border-[var(--color-borde)] bg-white px-7 py-3.5">
        <button v-if="paso > 1" class="boton-secundario" @click="paso--">Atrás</button>

        <div class="ml-auto flex items-center gap-2">
            <button v-if="paso < 4" class="boton-primario" :disabled="!puedeSeguir" @click="paso++">
                Continuar
            </button>
            <button v-else class="boton-primario" :disabled="guardando" @click="guardar">
                {{ guardando ? 'Creando…' : 'Crear consorcio' }}
            </button>
        </div>
    </div>
</template>
