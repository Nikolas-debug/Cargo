<script setup>
import { computed, reactive, ref, watch } from 'vue'
import api, { mensajeDeError } from '../../lib/api'
import { numero } from '../../lib/formato'
import { useAvisos } from '../../stores/avisos'
import { CATALOGOS, estaInactivo, leer } from './definiciones'
import Encabezado from '../../components/Encabezado.vue'
import Cargando from '../../components/Cargando.vue'
import Paginacion from '../../components/Paginacion.vue'
import Icono from '../../components/Icono.vue'
import MaterialesCantera from '../../components/MaterialesCantera.vue'

const props = defineProps({ catalogo: { type: String, required: true } })

const avisos = useAvisos()

const config = computed(() => CATALOGOS[props.catalogo])

const registros = ref([])
const meta = ref(null)
const cargando = ref(true)
const busqueda = ref('')
const opciones = reactive({})

const panel = reactive({ abierto: false, editando: null, datos: {} })

async function cargar(page = 1) {
    cargando.value = true

    try {
        const { data } = await api.get(config.value.endpoint, {
            params: { page, q: busqueda.value || undefined, por_pagina: 25 },
        })
        registros.value = data.data
        meta.value = data.meta
    } finally {
        cargando.value = false
    }
}

/** Los select del formulario necesitan sus listas. */
async function cargarOpciones() {
    const fuentes = [...new Set(config.value.campos.filter((c) => c.fuente).map((c) => c.fuente))]

    for (const fuente of fuentes) {
        if (opciones[fuente]) continue
        const { data } = await api.get(fuente, { params: { por_pagina: 200 } })
        opciones[fuente] = data.data
    }
}

function abrir(registro = null) {
    panel.abierto = true
    panel.editando = registro

    panel.datos = Object.fromEntries(
        config.value.campos.map((campo) => [
            campo.clave,
            registro ? (registro[campo.clave] ?? '') : (campo.tipo === 'booleano' ? false : ''),
        ]),
    )

    cargarOpciones()
}

async function guardar() {
    const payload = { ...panel.datos }

    // Los campos numéricos vacíos van como null, no como "".
    for (const campo of config.value.campos) {
        if (campo.tipo === 'numero' && payload[campo.clave] === '') payload[campo.clave] = null
        if (campo.tipo === 'select' && payload[campo.clave] === '') payload[campo.clave] = null
    }

    try {
        if (panel.editando) {
            await api.put(`${config.value.endpoint}/${panel.editando.id}`, payload)
            avisos.ok('Cambios guardados.')
        } else {
            await api.post(config.value.endpoint, payload)
            avisos.ok(`Se creó el ${config.value.singular}.`)
        }

        panel.abierto = false
        await cargar(meta.value?.current_page ?? 1)
    } catch (e) {
        avisos.error(mensajeDeError(e, 'No se pudo guardar.'))
    }
}

async function desactivar(registro) {
    const nombre = valorCelda(registro, config.value.columnas[0])
    if (!confirm(`¿Desactivar ${config.value.singular} "${nombre}"? Dejará de salir en los formularios.`)) return

    try {
        await api.delete(`${config.value.endpoint}/${registro.id}`)
        avisos.ok('Registro desactivado. No se borra, para no dejar viajes huérfanos.')
        await cargar(meta.value?.current_page ?? 1)
    } catch (e) {
        avisos.error(mensajeDeError(e))
    }
}

async function reactivar(registro) {
    try {
        await api.post(`${config.value.endpoint}/${registro.id}/reactivar`)
        avisos.ok('Registro reactivado. Ya vuelve a salir en los formularios.')
        await cargar(meta.value?.current_page ?? 1)
    } catch (e) {
        avisos.error(mensajeDeError(e))
    }
}

function textoOpcion(campo, opcion) {
    if (campo.etiquetaOpcion) return opcion[campo.etiquetaOpcion]
    return opcion.nombre_completo ?? [opcion.nombre, opcion.apellido].filter(Boolean).join(' ')
}

function valorCelda(registro, columna) {
    const valor = leer(registro, columna.clave)

    if (columna.tipo === 'booleano') return valor ? 'Sí' : 'No'
    if (valor === null || valor === undefined || valor === '') return '—'
    if (columna.cifra && typeof valor === 'number') return numero(valor)

    return valor
}

watch(() => props.catalogo, () => {
    busqueda.value = ''
    panel.abierto = false
    cargar()
}, { immediate: true })

let temporizador
watch(busqueda, () => {
    clearTimeout(temporizador)
    temporizador = setTimeout(() => cargar(), 300)
})
</script>

<template>
    <Encabezado :titulo="config.titulo" :subtitulo="config.descripcion">
        <template #acciones>
            <input v-model="busqueda" class="campo min-h-9 w-full py-1.5 text-[13px] sm:w-56" placeholder="Buscar…">
            <button class="boton-primario" @click="abrir()">Nuevo</button>
        </template>
    </Encabezado>

    <div class="flex">
        <div class="min-w-0 flex-1">
            <Cargando :activo="cargando" :vacio="!registros.length" mensaje-vacio="No hay registros todavía.">
                <div class="bg-white">
                    <div class="tabla-scroll">
                    <table class="w-full text-[12.5px]">
                        <thead>
                            <tr class="bg-[var(--color-superficie-sutil)] text-left text-[11px] font-medium text-[var(--color-apagado)]">
                                <th
                                    v-for="(columna, i) in config.columnas"
                                    :key="columna.clave"
                                    class="px-2.5 py-2.5"
                                    :class="[columna.ancho, columna.derecha ? 'text-right' : '', i === 0 ? 'pl-4 sm:pl-8' : '']"
                                >
                                    {{ columna.texto }}
                                </th>
                                <th class="w-32 py-2.5 pl-2.5 pr-4 sm:pr-8"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="registro in registros"
                                :key="registro.id"
                                class="border-b border-[var(--color-borde)] hover:bg-[var(--color-superficie-sutil)]"
                                :class="estaInactivo(config, registro) ? 'text-[var(--color-tenue)]' : ''"
                            >
                                <td
                                    v-for="(columna, i) in config.columnas"
                                    :key="columna.clave"
                                    class="px-2.5 py-2.5"
                                    :class="[columna.cifra ? 'cifra' : '', columna.derecha ? 'text-right' : '', i === 0 ? 'pl-4 sm:pl-8 font-medium' : estaInactivo(config, registro) ? '' : 'text-[var(--color-cuerpo)]']"
                                >
                                    {{ valorCelda(registro, columna) }}
                                    <span
                                        v-if="i === 0 && estaInactivo(config, registro)"
                                        class="ml-1.5 rounded bg-[var(--color-neutro-fondo)] px-1.5 py-0.5 text-[10.5px] font-medium text-[var(--color-apagado)]"
                                    >Inactivo</span>
                                </td>
                                <td class="whitespace-nowrap py-2.5 pl-2.5 pr-4 sm:pr-8 text-right">
                                    <button class="text-[12.5px] font-medium text-[var(--color-enlace)]" @click="abrir(registro)">Editar</button>
                                    <template v-if="!config.sinBorrar">
                                        <button
                                            v-if="estaInactivo(config, registro)"
                                            class="ml-3 text-[12.5px] font-medium text-[var(--color-confirmado)]"
                                            @click="reactivar(registro)"
                                        >
                                            Reactivar
                                        </button>
                                        <button
                                            v-else
                                            class="ml-3 text-[12.5px] text-[var(--color-tenue)] hover:text-[var(--color-critico)]"
                                            @click="desactivar(registro)"
                                        >
                                            Desactivar
                                        </button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>

                    <Paginacion :meta="meta" @ir="cargar" />
                </div>
            </Cargando>
        </div>

        <!-- Formulario lateral -->
        <aside v-if="panel.abierto" class="dialogo fixed inset-y-0 right-0 z-50 w-full max-w-[400px] shrink-0 overflow-y-auto border-l border-[var(--color-borde)] bg-white shadow-[0_16px_40px_rgb(24_29_38/0.14)] lg:sticky lg:top-[89px] lg:z-0 lg:h-[calc(100dvh-89px)] lg:shadow-none">
            <div class="flex items-center border-b border-[var(--color-borde)] px-5 py-4">
                <h2 class="text-[18px] font-normal">
                    {{ panel.editando ? 'Editar' : 'Nuevo' }} {{ config.singular }}
                </h2>
                <button class="ml-auto text-[var(--color-tenue)] hover:text-[var(--color-tinta)]" @click="panel.abierto = false"><Icono nombre="x" :tamano="16" /></button>
            </div>

            <div class="space-y-4 p-5">
                <div v-for="campo in config.campos" :key="campo.clave">
                    <template v-if="campo.tipo === 'booleano'">
                        <label class="flex items-start gap-3 rounded-md bg-[var(--color-superficie-sutil)] p-3">
                            <input v-model="panel.datos[campo.clave]" type="checkbox" class="mt-0.5 size-4">
                            <span>
                                <span class="block text-[13px] font-medium">{{ campo.etiqueta }}</span>
                                <span v-if="campo.ayuda" class="mt-0.5 block text-[11.5px] text-[var(--color-tenue)]">{{ campo.ayuda }}</span>
                            </span>
                        </label>
                    </template>

                    <template v-else>
                        <label class="etiqueta">
                            {{ campo.etiqueta }}
                            <span v-if="campo.requerido" class="text-[var(--color-critico)]">*</span>
                        </label>

                        <select v-if="campo.tipo === 'select'" v-model="panel.datos[campo.clave]" class="campo">
                            <option value="">Seleccionar…</option>
                            <template v-if="campo.opciones">
                                <option v-for="o in campo.opciones" :key="o.id" :value="o.id">{{ o.texto }}</option>
                            </template>
                            <template v-else>
                                <option v-for="o in (opciones[campo.fuente] ?? [])" :key="o.id" :value="o.id">
                                    {{ textoOpcion(campo, o) }}
                                </option>
                            </template>
                        </select>

                        <input
                            v-else
                            v-model="panel.datos[campo.clave]"
                            :type="campo.tipo === 'numero' ? 'number' : 'text'"
                            class="campo"
                            :class="campo.tipo === 'numero' ? 'cifra' : ''"
                        >

                        <p v-if="campo.ayuda" class="mt-1 text-[11.5px] text-[var(--color-tenue)]">{{ campo.ayuda }}</p>
                    </template>
                </div>
            </div>

            <div v-if="catalogo === 'canteras'" class="px-5 pb-5">
                <MaterialesCantera v-if="panel.editando" :key="panel.editando.id" :cantera-id="panel.editando.id" />
                <p v-else class="rounded-[10px] bg-[var(--color-superficie-sutil)] p-4 text-[13px] text-[var(--color-apagado)]">
                    Guarda la cantera y luego, desde Editar, le relacionas los materiales que vende.
                </p>
            </div>

            <div class="flex gap-2 border-t border-[var(--color-borde)] px-5 py-4">
                <button class="boton-secundario flex-1 justify-center" @click="panel.abierto = false">Cancelar</button>
                <button class="boton-primario flex-1 justify-center" @click="guardar">Guardar</button>
            </div>
        </aside>
    </div>
</template>
