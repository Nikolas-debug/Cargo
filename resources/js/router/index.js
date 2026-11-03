import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '../stores/auth'
import { alPerderSesion } from '../lib/api'

const rutas = [
    {
        path: '/entrar',
        name: 'login',
        component: () => import('../views/Login.vue'),
        meta: { publica: true },
    },
    {
        path: '/',
        component: () => import('../layouts/AppLayout.vue'),
        children: [
            { path: '', name: 'inicio', component: () => import('../views/Dashboard.vue') },

            { path: 'viajes', name: 'viajes', component: () => import('../views/viajes/Index.vue') },
            { path: 'viajes/registrar', name: 'viajes.registrar', component: () => import('../views/viajes/RegistroLote.vue') },

            { path: 'vales', name: 'vales', component: () => import('../views/vales/Index.vue') },
            { path: 'vales/registrar', name: 'vales.registrar', component: () => import('../views/vales/Confirmar.vue') },

            { path: 'tarifas', name: 'tarifas', component: () => import('../views/tarifas/Index.vue') },

            { path: 'liquidaciones', name: 'liquidaciones', component: () => import('../views/liquidaciones/Index.vue') },
            { path: 'liquidaciones/:id', name: 'liquidaciones.detalle', component: () => import('../views/liquidaciones/Detalle.vue'), props: true },
            { path: 'pagos', name: 'pagos', component: () => import('../views/pagos/Index.vue') },
            { path: 'material', name: 'material', component: () => import('../views/material/Relacion.vue') },

            // Los ocho catálogos son la misma pantalla con distinta
            // configuración: una sola vista los sirve a todos.
            { path: 'catalogos/:catalogo', name: 'catalogos', component: () => import('../views/catalogos/Index.vue'), props: true },

            // Solo administrador. El backend lo vuelve a verificar: esta
            // guardia es comodidad de navegación, no seguridad.
            { path: 'panel', name: 'panel', component: () => import('../views/admin/Panel.vue'), meta: { admin: true } },
            { path: 'consorcios/nuevo', name: 'consorcios.nuevo', component: () => import('../views/admin/NuevoConsorcio.vue'), meta: { admin: true } },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: { name: 'inicio' } },
]

const router = createRouter({
    history: createWebHistory(),
    routes: rutas,
})

router.beforeEach((destino) => {
    const auth = useAuth()

    if (!destino.meta.publica && !auth.autenticado) {
        return { name: 'login', query: { volverA: destino.fullPath } }
    }

    if (destino.name === 'login' && auth.autenticado) {
        return { name: 'inicio' }
    }

    if (destino.meta.admin && !auth.esAdministrador) {
        return { name: 'inicio' }
    }
})

// Si el backend invalida el token a mitad de sesión, se vuelve al login sin
// dejar al usuario mirando una pantalla que ya no carga nada.
alPerderSesion(() => {
    const auth = useAuth()
    auth.limpiar()
    router.push({ name: 'login' })
})

export default router
