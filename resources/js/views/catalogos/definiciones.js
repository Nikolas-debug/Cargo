/**
 * Los ocho catálogos son la misma pantalla: una tabla y un formulario lateral.
 * Lo único que cambia es qué columnas se muestran y qué campos se piden, así
 * que eso vive aquí y la vista es una sola.
 *
 * tipo: texto | numero | booleano | select
 * fuente: para los select, el endpoint de donde salen las opciones
 * inactivo: [columna, valor] que deja "Desactivar" (por defecto activo = false)
 */
export const CATALOGOS = {
    terceros: {
        titulo: 'Terceros',
        singular: 'tercero',
        endpoint: 'terceros',
        descripcion: 'Dueños de volquetas y conductores. El rol no se escribe: sale de las relaciones.',
        columnas: [
            { clave: 'nombre', texto: 'NOMBRE' },
            { clave: 'apellido', texto: 'APELLIDO' },
            { clave: 'cedula', texto: 'CÉDULA', cifra: true, ancho: 'w-36' },
            { clave: 'telefono', texto: 'TELÉFONO', ancho: 'w-36' },
        ],
        campos: [
            { clave: 'nombre', etiqueta: 'Nombre', tipo: 'texto', requerido: true },
            { clave: 'apellido', etiqueta: 'Apellido', tipo: 'texto' },
            { clave: 'cedula', etiqueta: 'Cédula', tipo: 'texto', requerido: true },
            { clave: 'telefono', etiqueta: 'Teléfono', tipo: 'texto' },
        ],
    },

    volquetas: {
        titulo: 'Volquetas',
        singular: 'volqueta',
        endpoint: 'volquetas',
        descripcion: 'A quien se le liquidan los viajes es al dueño de la volqueta.',
        inactivo: ['estado', 'inactiva'],
        columnas: [
            { clave: 'placa', texto: 'PLACA', cifra: true, ancho: 'w-28' },
            { clave: 'propietario.nombre_completo', texto: 'DUEÑO' },
            { clave: 'conductor_habitual.nombre_completo', texto: 'CONDUCTOR HABITUAL' },
            { clave: 'capacidad_m3', texto: 'CAP. M³', cifra: true, ancho: 'w-24', derecha: true },
            { clave: 'es_propia', texto: 'PROPIA', tipo: 'booleano', ancho: 'w-24' },
        ],
        campos: [
            { clave: 'placa', etiqueta: 'Placa', tipo: 'texto', requerido: true },
            { clave: 'propietario_id', etiqueta: 'Dueño', tipo: 'select', fuente: 'terceros', requerido: true, ayuda: 'A él se le liquidan los viajes de esta volqueta' },
            { clave: 'conductor_habitual_id', etiqueta: 'Conductor habitual', tipo: 'select', fuente: 'terceros', ayuda: 'Solo para autocompletar al registrar viajes' },
            { clave: 'tipo', etiqueta: 'Tipo', tipo: 'texto' },
            { clave: 'capacidad_m3', etiqueta: 'Capacidad m³', tipo: 'numero' },
            { clave: 'es_propia', etiqueta: 'Volqueta propia de Grup Tous', tipo: 'booleano', ayuda: 'Si se activa, el pago va al conductor por porcentaje en vez de al dueño por viaje' },
        ],
    },

    canteras: {
        titulo: 'Canteras',
        singular: 'cantera',
        endpoint: 'canteras',
        descripcion: 'El origen de los viajes de suministro.',
        columnas: [
            { clave: 'nombre', texto: 'NOMBRE' },
            { clave: 'municipio', texto: 'MUNICIPIO', ancho: 'w-48' },
        ],
        campos: [
            { clave: 'nombre', etiqueta: 'Nombre', tipo: 'texto', requerido: true },
            { clave: 'municipio', etiqueta: 'Municipio', tipo: 'texto' },
        ],
    },

    botaderos: {
        titulo: 'Botaderos',
        singular: 'botadero',
        endpoint: 'botaderos',
        descripcion: 'A dónde va el escombro. El precio del retiro depende de qué tan lejos quede.',
        columnas: [
            { clave: 'nombre', texto: 'NOMBRE' },
            { clave: 'municipio', texto: 'MUNICIPIO', ancho: 'w-44' },
            { clave: 'precio_disposicion_m3', texto: 'COBRA POR M³', cifra: true, derecha: true, ancho: 'w-36' },
        ],
        campos: [
            { clave: 'nombre', etiqueta: 'Nombre', tipo: 'texto', requerido: true },
            { clave: 'municipio', etiqueta: 'Municipio', tipo: 'texto' },
            { clave: 'direccion', etiqueta: 'Dirección', tipo: 'texto' },
            { clave: 'precio_disposicion_m3', etiqueta: 'Precio de disposición por m³', tipo: 'numero', ayuda: 'Déjalo vacío si el sitio no cobra por recibir' },
        ],
    },

    materiales: {
        titulo: 'Materiales',
        singular: 'material',
        endpoint: 'materiales',
        descripcion: 'Nombres limpios: la cantera va en su propia tabla, no dentro del nombre.',
        columnas: [
            { clave: 'nombre', texto: 'NOMBRE' },
            { clave: 'unidad', texto: 'UNIDAD', ancho: 'w-28' },
        ],
        campos: [
            { clave: 'nombre', etiqueta: 'Nombre', tipo: 'texto', requerido: true, ayuda: 'Ejemplo: "relleno", no "relleno cantera Villa Carmen"' },
            { clave: 'unidad', etiqueta: 'Unidad', tipo: 'texto' },
        ],
    },

    consorcios: {
        titulo: 'Consorcios',
        singular: 'consorcio',
        endpoint: 'consorcios',
        descripcion: 'Quien contrata a Grup Tous. Cada consorcio tiene sus obras.',
        inactivo: ['estado_contrato', 'terminado'],
        columnas: [
            { clave: 'razon_social', texto: 'RAZÓN SOCIAL' },
            { clave: 'nit', texto: 'NIT', cifra: true, ancho: 'w-40' },
            { clave: 'municipio', texto: 'MUNICIPIO', ancho: 'w-40' },
            { clave: 'telefono', texto: 'TELÉFONO', ancho: 'w-36' },
        ],
        campos: [
            { clave: 'razon_social', etiqueta: 'Razón social', tipo: 'texto', requerido: true },
            { clave: 'nit', etiqueta: 'NIT', tipo: 'texto', requerido: true },
            { clave: 'departamento', etiqueta: 'Departamento', tipo: 'texto' },
            { clave: 'municipio', etiqueta: 'Municipio', tipo: 'texto' },
            { clave: 'direccion_principal', etiqueta: 'Dirección', tipo: 'texto' },
            { clave: 'correo', etiqueta: 'Correo', tipo: 'texto' },
            { clave: 'telefono', etiqueta: 'Teléfono', tipo: 'texto' },
        ],
        etiquetaBusqueda: 'razon_social',
    },

    obras: {
        titulo: 'Obras',
        singular: 'obra',
        endpoint: 'obras',
        descripcion: 'El destino de los viajes de suministro y el origen de los retiros.',
        sinBorrar: true,
        columnas: [
            { clave: 'nombre', texto: 'NOMBRE' },
            { clave: 'consorcio.razon_social', texto: 'CONSORCIO' },
            { clave: 'municipio', texto: 'MUNICIPIO', ancho: 'w-40' },
            { clave: 'estado', texto: 'ESTADO', ancho: 'w-32' },
        ],
        campos: [
            { clave: 'consorcio_id', etiqueta: 'Consorcio', tipo: 'select', fuente: 'consorcios', requerido: true, etiquetaOpcion: 'razon_social' },
            { clave: 'nombre', etiqueta: 'Nombre de la obra', tipo: 'texto', requerido: true },
            { clave: 'direccion', etiqueta: 'Dirección', tipo: 'texto' },
            { clave: 'municipio', etiqueta: 'Municipio', tipo: 'texto' },
            { clave: 'estado', etiqueta: 'Estado', tipo: 'select', opciones: [
                { id: 'activa', texto: 'Activa' },
                { id: 'terminada', texto: 'Terminada' },
            ] },
        ],
    },
}

/** Si el registro quedó desactivado (se sigue listando, pero no sale en los select). */
export function estaInactivo(config, registro) {
    const [columna, valor] = config.inactivo ?? ['activo', false]
    return registro[columna] === valor
}

/** Lee "propietario.nombre_completo" de un objeto anidado. */
export function leer(objeto, ruta) {
    return ruta.split('.').reduce((valor, parte) => valor?.[parte], objeto)
}
