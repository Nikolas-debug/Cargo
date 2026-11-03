import api from './api'

/**
 * Una foto de celular pesa 3-5 MB y PHP solo recibe 2 MB. Se reduce a 1800 px
 * de lado y JPEG: el número del comprobante se sigue leyendo y queda en ~400 KB.
 * Los PDF pasan tal cual.
 */
export async function prepararArchivo(archivo) {
    if (!archivo.type.startsWith('image/')) return archivo

    const imagen = await createImageBitmap(archivo)
    const escala = Math.min(1, 1800 / Math.max(imagen.width, imagen.height))
    const lienzo = document.createElement('canvas')
    lienzo.width = Math.round(imagen.width * escala)
    lienzo.height = Math.round(imagen.height * escala)
    lienzo.getContext('2d').drawImage(imagen, 0, 0, lienzo.width, lienzo.height)

    const blob = await new Promise((ok) => lienzo.toBlob(ok, 'image/jpeg', 0.82))
    return new File([blob], archivo.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' })
}

/** El comprobante guardado, como URL local: la ruta exige el token, un <img src> no lo manda. */
export async function urlDelComprobante(anticipoId) {
    const { data } = await api.get(`anticipos/${anticipoId}/comprobante`, { responseType: 'blob' })
    return { url: URL.createObjectURL(data), tipo: data.type }
}

/** Arma el FormData de un pago: los campos vacíos no se mandan. */
export function formularioDePago(campos, archivo) {
    const datos = new FormData()

    for (const [clave, valor] of Object.entries(campos)) {
        if (valor !== '' && valor !== null && valor !== undefined) datos.append(clave, valor)
    }

    if (archivo) datos.append('archivo', archivo)

    return datos
}
