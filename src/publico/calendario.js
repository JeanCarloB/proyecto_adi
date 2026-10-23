/**
 * Calendario público: tarjeta de un día con lo ocupado y las franjas libres.
 * Solo usa los datos de la página (sin información personal).
 */
(function () {
    'use strict';

    const datos = JSON.parse(document.getElementById('datos-calendario').textContent);
    const dialogo = document.getElementById('detalle');
    const contenido = document.getElementById('detalle-contenido');

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

    function verDia(dia) {
        const info = datos.dias[dia] || { titulo: dia, pedible: false, pasado: false };
        const ocupadas = datos.ocupadas[dia] || [];
        const tomadas = new Set(ocupadas.map((o) => o.espacio + '|' + o.franja));
        const titulo = info.titulo.charAt(0).toUpperCase() + info.titulo.slice(1);

        const nodos = [
            el('div', { class: 'adi-dialog-head' },
                el('div', {},
                    el('div', { class: 'adi-dialog-eyebrow' }, ocupadas.length === 1 ? '1 franja ocupada' : ocupadas.length + ' franjas ocupadas'),
                    el('h2', { id: 'detalle-titulo', class: 'adi-dialog-title' }, titulo)),
                el('button', { type: 'button', class: 'adi-dialog-close', 'aria-label': 'Cerrar', onclick: () => dialogo.close() }, '×')),
        ];

        if (info.pasado) {
            nodos.push(el('p', { class: 'adi-sub mb-0' }, 'Este día ya pasó.'));
        } else {
            datos.espacios.forEach((esp) => {
                const franjas = Object.keys(datos.franjas).filter((f) => esp.tarifas[f]);
                nodos.push(el('div', { class: 'adi-free' },
                    el('div', { class: 'adi-free-name' }, el('span', { class: 'adi-dot', style: 'background:' + esp.color }), esp.nombre),
                    el('div', { class: 'adi-free-slots' }, franjas.map((f) => {
                        const fr = datos.franjas[f];
                        if (tomadas.has(esp.id + '|' + f)) {
                            return el('div', { class: 'adi-free-slot is-taken' },
                                el('strong', {}, fr.etiqueta), el('span', {}, fr.horario), el('span', { class: 'adi-free-price' }, 'Ocupado'));
                        }
                        if (!info.pedible) {
                            return el('div', { class: 'adi-free-slot is-taken' },
                                el('strong', {}, fr.etiqueta), el('span', {}, fr.horario), el('span', { class: 'adi-free-price' }, 'Libre · aún no se reciben pedidos'));
                        }
                        const url = 'solicitar.php?' + new URLSearchParams({ espacio: esp.id, fecha: dia, franja: f });
                        return el('a', { class: 'adi-free-slot', href: url },
                            el('strong', {}, fr.etiqueta), el('span', {}, fr.horario),
                            el('span', { class: 'adi-free-price' }, esp.tarifas[f] + ' · Solicitar'));
                    }))));
            });
        }

        contenido.replaceChildren(...nodos);
        if (!dialogo.open) dialogo.showModal();
        contenido.querySelector('.adi-dialog-close').focus();
    }

    document.addEventListener('click', (ev) => {
        const dia = ev.target.closest('[data-dia]');
        if (dia && !ev.target.closest('dialog')) verDia(dia.dataset.dia);
    });
    dialogo.addEventListener('click', (ev) => { if (ev.target === dialogo) dialogo.close(); });
})();
