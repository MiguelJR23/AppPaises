/**
 * js/mapa.js — mapa interativo com Leaflet.js
 *
 * O PHP entrega as coordenadas (data[0].latlng da API) no elemento #mapa,
 * por meio dos atributos data-lat, data-lng, data-area e data-nome.
 * Este arquivo lê esses atributos e desenha o mapa.
 */
(function () {
    'use strict';

    let mapa = null; // instância atual do Leaflet (recriada a cada pesquisa)

    /** Mostra uma mensagem no lugar do mapa, sem afetar o resto da página. */
    function mostrarMensagem(elemento, texto) {
        elemento.classList.add('mapa-indisponivel');
        elemento.textContent = texto;
    }

    /** Escolhe um zoom final de acordo com o tamanho (km²) do país. */
    function zoomPelaArea(area) {
        if (!isFinite(area)) return 5;
        if (area > 5000000) return 3;
        if (area > 1000000) return 4;
        if (area > 100000) return 5;
        if (area > 10000) return 6;
        return 7;
    }

    function iniciarMapa() {
        // Descarta o mapa anterior (o HTML antigo já foi substituído)
        if (mapa) {
            try { mapa.remove(); } catch (erro) { /* container antigo já removido */ }
            mapa = null;
        }

        const elemento = document.getElementById('mapa');
        if (!elemento) return; // nenhuma pesquisa com sucesso na tela

        const MENSAGEM = 'A localização não pôde ser carregada.';

        // Coordenadas fornecidas pelo PHP
        const lat = parseFloat(elemento.dataset.lat);
        const lng = parseFloat(elemento.dataset.lng);
        if (!isFinite(lat) || !isFinite(lng)) {
            mostrarMensagem(elemento, MENSAGEM);
            return;
        }

        // Leaflet não carregou (ex.: sem internet para acessar o CDN)
        if (typeof L === 'undefined') {
            mostrarMensagem(elemento, MENSAGEM);
            return;
        }

        try {
            // Começa com uma visão ampla do mundo...
            mapa = L.map(elemento, { scrollWheelZoom: false }).setView([lat, lng], 2);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(mapa);

            // Marcador na localização aproximada do país (nome inserido como texto, sem HTML)
            const rotulo = document.createElement('strong');
            rotulo.textContent = elemento.dataset.nome || 'País';
            L.marker([lat, lng]).addTo(mapa).bindPopup(rotulo);

            // A roda do mouse só dá zoom depois de clicar no mapa (não atrapalha a rolagem da página)
            mapa.on('click', function () { mapa.scrollWheelZoom.enable(); });

            // ...e depois aproxima suavemente até o país
            const instancia = mapa;
            const zoom = zoomPelaArea(parseFloat(elemento.dataset.area));
            setTimeout(function () {
                if (mapa === instancia) {
                    instancia.invalidateSize();
                    instancia.flyTo([lat, lng], zoom, { duration: 1.6 });
                }
            }, 300);
        } catch (erro) {
            mapa = null;
            mostrarMensagem(elemento, MENSAGEM);
        }
    }

    // Função usada pelo interacao.js depois de cada pesquisa
    window.PortalMapa = { iniciar: iniciarMapa };
})();
