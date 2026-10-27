/**
 * Calendario de reservas: tarjetas de detalle de una reserva y de un día.
 * Los datos vienen ya cargados en la página (#datos-calendario); aquí no se
 * consulta la base. Todo el texto se inserta con textContent para evitar
 * que un nombre con caracteres especiales rompa el HTML.
 */
(function () {
    'use strict';

    const datos = JSON.parse(document.getElementById('datos-calendario').textContent);
    const dialogo = document.getElementById('detalle');
    const contenido = document.getElementById('detalle-contenido');
    const TIPOS = { vecino: 'Vecino', comercio: 'Comercio', institucion: 'Institución' };

    // --- Utilidades ---------------------------------------------------------

    /** el('div', {class: 'x'}, 'texto', otroNodo) */
    function el(tag, attrs, ...hijos) {
        const nodo = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (v === null || v === undefined || v === false) continue;
            if (k === 'style') nodo.style.cssText = v;
            else if (k.startsWith('on')) nodo.addEventListener(k.slice(2), v);
            else nodo.setAttribute(k, v);
        }
        for (const h of hijos.flat()) {
            if (h === null || h === undefined || h === false) continue;
            nodo.append(h instanceof Node ? h : document.createTextNode(String(h)));
        }
        return nodo;
    }

    function crearUrl(params) {
        const q = new URLSearchParams({ volver: 'calendario', mes: datos.mes, filtro: datos.filtro, ...params });
        return 'crear.php?' + q.toString();
    }

    /** Formulario POST con confirmación (confirmar pago / cancelar), con el cuadro de layout.php. */
    function formAccion(accion, id, texto, clase, pregunta, peligro) {
        return el('form', {
            action: accion, method: 'POST', class: 'd-inline',
            onsubmit: (ev) => {
                ev.preventDefault();
                const form = ev.currentTarget;
                adiConfirmar(pregunta, { titulo: texto, boton: texto, peligro: !!peligro })
                    .then((ok) => { if (ok) form.submit(); });
            },
        },
            el('input', { type: 'hidden', name: 'csrf', value: datos.csrf }),
            el('input', { type: 'hidden', name: 'id', value: id }),
            el('input', { type: 'hidden', name: 'volver', value: 'calendario' }),
            el('input', { type: 'hidden', name: 'mes', value: datos.mes }),
            el('input', { type: 'hidden', name: 'filtro', value: datos.filtro }),
            el('button', { type: 'submit', class: 'btn btn-sm ' + clase }, texto),
        );
    }

    function cabecera(titulo, extra) {
        return el('div', { class: 'adi-dialog-head' },
            el('div', {}, extra || null, el('h2', { id: 'detalle-titulo', class: 'adi-dialog-title' }, titulo)),
            el('button', { type: 'button', class: 'adi-dialog-close', 'aria-label': 'Cerrar', onclick: () => dialogo.close() }, '×'),
        );
    }

    function fila(etiqueta, valor) {
        return el('div', { class: 'adi-dl-row' }, el('dt', {}, etiqueta), el('dd', {}, valor));
    }

    function pillEstado(r) {
        return r.estado === 'apartado'
            ? el('span', { class: 'adi-pill adi-pill--warn' }, 'Apartado')
            : el('span', { class: 'adi-pill adi-pill--ok' }, 'Confirmado');
    }

    function mostrar(nodos) {
        contenido.replaceChildren(...nodos);
        if (!dialogo.open) dialogo.showModal();
        const foco = contenido.querySelector('.adi-dialog-close');
        if (foco) foco.focus();
    }

    // --- Tarjeta de una reserva --------------------------------------------

    function verReserva(id, desdeDia) {
        const r = datos.reservas[id];
        if (!r) return;
        const c = r.contacto;

        const etiquetaEspacio = el('div', { class: 'adi-dialog-eyebrow' },
            el('span', { class: 'adi-dot', style: 'background:' + r.color }), r.espacio);

        const lista = el('dl', { class: 'adi-dl' },
            fila('Fecha', datos.dias[r.dia] ? datos.dias[r.dia].titulo : r.dia),
            fila('Horario', r.etFranja + ' · ' + r.horario),
            fila('Monto', r.monto),
            fila('Estado', el('span', {}, pillEstado(r),
                r.vence ? el('span', { class: 'adi-sub ms-2' }, 'vence ' + r.vence.falta + ' (' + r.vence.texto + ')') : null)),
            fila('Contacto', el('span', {}, c.nombre, c.tipo ? el('span', { class: 'adi-tag ms-2' }, TIPOS[c.tipo] || c.tipo) : null)),
            fila('Teléfono', c.telefono ? el('a', { class: 'adi-link-quiet', href: 'tel:' + c.telefono }, c.telefono) : '—'),
            fila('Correo', c.correo ? el('a', { class: 'adi-link-quiet', href: 'mailto:' + c.correo }, c.correo) : '—'),
        );

        const acciones = el('div', { class: 'adi-dialog-actions' },
            desdeDia ? el('button', { type: 'button', class: 'btn btn-sm adi-btn-ghost me-auto', onclick: () => verDia(r.dia) }, '← Volver al día') : null,
            formAccion('cancelar.php', r.id, 'Cancelar reserva', 'adi-btn-quiet-danger', 'El horario queda libre para que otra persona lo reserve.', true),
            // Las reservas de días pasados quedan como historial: no se editan
            r.dia >= datos.hoy
                ? el('button', { type: 'button', class: 'btn btn-sm adi-btn-ghost', onclick: () => editarReserva(id, desdeDia) }, 'Editar')
                : null,
            r.estado === 'apartado'
                ? formAccion('confirmar.php', r.id, 'Confirmar pago', 'adi-btn-primary', 'La reserva queda permanente y ya no vence.')
                : null,
        );

        mostrar([cabecera(r.etFranja + ' · ' + r.inicio, etiquetaEspacio), lista, acciones]);
    }

    // --- Formulario de edición dentro de la tarjeta -------------------------

    function editarReserva(id, desdeDia) {
        const r = datos.reservas[id];
        const op = datos.opciones;

        // Si el espacio o el contacto actual ya no está activo, igual se ofrece para dejarlo como está
        const espacios = op.espacios.some((e) => e.id === r.espacioId)
            ? op.espacios
            : [{ id: r.espacioId, nombre: r.espacio + ' (inactivo)', franjas: [r.franja] }, ...op.espacios];
        const contactos = op.contactos.some((c) => c.id === r.contactoId)
            ? op.contactos
            : [{ id: r.contactoId, nombre: r.contacto.nombre }, ...op.contactos];

        const selEspacio = el('select', { class: 'form-select', id: 'ed-espacio', name: 'espacio', required: true },
            espacios.map((e) => el('option', { value: e.id, selected: e.id === r.espacioId }, e.nombre)));
        const selFranja = el('select', { class: 'form-select', id: 'ed-franja', name: 'franja', required: true },
            Object.keys(datos.franjas).map((f) =>
                el('option', { value: f, selected: f === r.franja }, datos.franjas[f].etiqueta + ' · ' + datos.franjas[f].horario)));
        const inFecha = el('input', { type: 'date', class: 'form-control', id: 'ed-fecha', name: 'fecha', required: true, value: r.dia, min: datos.hoy });
        const selContacto = el('select', { class: 'form-select', id: 'ed-contacto', name: 'contacto', required: true },
            contactos.map((c) => el('option', { value: c.id, selected: c.id === r.contactoId }, c.nombre)));

        // Solo se pueden elegir las franjas que el espacio tiene con tarifa
        function franjasDelEspacio() {
            const esp = espacios.find((e) => e.id === selEspacio.value);
            for (const opcion of selFranja.options) {
                opcion.disabled = !(esp && esp.franjas.includes(opcion.value));
            }
            if (selFranja.selectedOptions[0] && selFranja.selectedOptions[0].disabled) {
                const libre = [...selFranja.options].find((o) => !o.disabled);
                selFranja.value = libre ? libre.value : '';
            }
        }
        selEspacio.addEventListener('change', franjasDelEspacio);
        franjasDelEspacio();

        const campo = (etiqueta, control, col) => el('div', { class: col || 'col-12' },
            el('label', { class: 'form-label', for: control.id }, etiqueta), control);

        const formulario = el('form', { action: 'editar.php', method: 'POST', class: 'adi-dialog-form' },
            el('input', { type: 'hidden', name: 'id', value: r.id }),
            el('input', { type: 'hidden', name: 'volver', value: 'calendario' }),
            el('input', { type: 'hidden', name: 'mes', value: datos.mes }),
            el('input', { type: 'hidden', name: 'filtro', value: datos.filtro }),
            el('div', { class: 'row g-3' },
                campo('Espacio', selEspacio),
                campo('Fecha', inFecha, 'col-sm-6'),
                campo('Franja', selFranja, 'col-sm-6'),
                campo('Contacto', selContacto),
            ),
            el('p', { class: 'adi-sub mt-3 mb-0' },
                r.estado === 'apartado'
                    ? 'Sigue apartada: editarla no cambia el vencimiento (' + (r.vence ? r.vence.texto : '') + ').'
                    : 'Si cambiás el espacio o la franja, el monto se ajusta a la tarifa.'),
            el('div', { class: 'adi-dialog-actions' },
                el('button', { type: 'button', class: 'btn btn-sm adi-btn-ghost me-auto', onclick: () => verReserva(id, desdeDia) }, '← Volver'),
                el('button', { type: 'submit', class: 'btn btn-sm adi-btn-primary' }, 'Guardar cambios'),
            ),
        );

        mostrar([
            cabecera('Editar reserva', el('div', { class: 'adi-dialog-eyebrow' },
                el('span', { class: 'adi-dot', style: 'background:' + r.color }), r.espacio + ' · ' + r.etFranja)),
            formulario,
        ]);
    }

    // --- Tarjeta de un día --------------------------------------------------

    function verDia(dia) {
        const info = datos.dias[dia] || { titulo: dia, pasado: false };
        const ids = datos.porDia[dia] || [];
        const nodos = [cabecera(info.titulo.charAt(0).toUpperCase() + info.titulo.slice(1),
            el('div', { class: 'adi-dialog-eyebrow' }, ids.length === 1 ? '1 reserva' : ids.length + ' reservas'))];

        if (ids.length) {
            nodos.push(el('div', { class: 'adi-day-list' }, ids.map((id) => {
                const r = datos.reservas[id];
                return el('button', {
                    type: 'button', class: 'adi-day-item is-' + r.estado, style: '--esp:' + r.color,
                    onclick: () => verReserva(id, true),
                },
                    el('span', { class: 'adi-day-time' }, r.inicio),
                    el('span', { class: 'adi-day-main' },
                        el('strong', {}, r.espacio),
                        el('small', {}, r.contacto.nombre + ' · ' + r.etFranja)),
                    pillEstado(r));
            })));
        }

        // Franjas libres: solo para hoy en adelante y espacios activos con tarifa
        if (!info.pasado) {
            const ocupadas = new Set(ids.map((id) => datos.reservas[id].espacioId + '|' + datos.reservas[id].franja));
            const bloques = datos.espacios.map((esp) => {
                const libres = Object.keys(datos.franjas)
                    .filter((f) => esp.tarifas[f] && !ocupadas.has(esp.id + '|' + f));
                return el('div', { class: 'adi-free' },
                    el('div', { class: 'adi-free-name' }, el('span', { class: 'adi-dot', style: 'background:' + esp.color }), esp.nombre),
                    libres.length
                        ? el('div', { class: 'adi-free-slots' }, libres.map((f) =>
                            el('a', { class: 'adi-free-slot', href: crearUrl({ espacio: esp.id, fecha: dia, franja: f }) },
                                el('strong', {}, datos.franjas[f].etiqueta),
                                el('span', {}, datos.franjas[f].horario),
                                el('span', { class: 'adi-free-price' }, esp.tarifas[f] + ' · Reservar'))))
                        : el('div', { class: 'adi-sub' }, 'Todo ocupado'));
            });
            nodos.push(el('h3', { class: 'adi-section-title mt-4' }, 'Franjas libres'));
            nodos.push(bloques.length ? el('div', {}, bloques) : el('p', { class: 'adi-sub' }, 'No hay espacios activos.'));
        } else if (!ids.length) {
            nodos.push(el('p', { class: 'adi-sub mb-0' }, 'No hubo reservas este día.'));
        }

        mostrar(nodos);
    }

    // --- Eventos ------------------------------------------------------------

    document.addEventListener('click', (ev) => {
        const reserva = ev.target.closest('[data-reserva]');
        if (reserva) { verReserva(reserva.dataset.reserva, false); return; }

        const dia = ev.target.closest('[data-dia]');
        if (dia && !ev.target.closest('dialog')) verDia(dia.dataset.dia);
    });

    // Cerrar al hacer clic fuera de la tarjeta
    dialogo.addEventListener('click', (ev) => {
        if (ev.target === dialogo) dialogo.close();
    });
})();
