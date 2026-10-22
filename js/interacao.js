/**
 * js/interacao.js — interatividade da página
 *
 *  - envio do formulário sem recarregar a página (fetch);
 *  - card flutuante ao passar o mouse nas fronteiras (mouseenter / mouseleave);
 *  - clique em um país vizinho para pesquisá-lo.
 */
(function () {
    'use strict';

    const form = document.getElementById('form-busca');
    const campo = document.getElementById('campo-pais');
    const resultados = document.getElementById('resultados');

    const MSG_ERRO_API = 'Não foi possível consultar a API no momento. Tente novamente mais tarde.';

    let card = null;          // card flutuante atualmente na tela
    let controlador = null;   // permite cancelar uma pesquisa anterior

    // -----------------------------------------------------------------------
    // Card flutuante
    // -----------------------------------------------------------------------

    /** Cria uma linha "Rótulo: valor" usando textContent (sem inserir HTML). */
    function criarLinha(rotulo, valor) {
        const p = document.createElement('p');
        const forte = document.createElement('strong');
        forte.textContent = rotulo + ': ';
        p.appendChild(forte);
        p.appendChild(document.createTextNode(valor || 'Informação não disponível.'));
        return p;
    }

    /** Monta o card com nome, fusos, moeda principal e bandeira reduzida. */
    function criarCard(info) {
        const div = document.createElement('div');
        div.className = 'card-flutuante';
        div.setAttribute('role', 'tooltip');

        if (typeof info.bandeira === 'string' && info.bandeira.indexOf('https://') === 0) {
            const img = document.createElement('img');
            img.className = 'flag-mini';
            img.src = info.bandeira;
            img.alt = 'Bandeira de ' + info.nome;
            div.appendChild(img);
        }

        const titulo = document.createElement('h4');
        titulo.textContent = info.nome || 'Informação não disponível.';
        div.appendChild(titulo);

        const fusos = Array.isArray(info.fusos) && info.fusos.length ? info.fusos.join(', ') : '';
        div.appendChild(criarLinha('Fuso(s)', fusos));
        div.appendChild(criarLinha('Moeda', info.moeda));
        return div;
    }

    /** mouseenter: mostra o card perto do botão. */
    function mostrarCard(botao) {
        let info;
        try {
            info = JSON.parse(botao.dataset.info); // JSON gerado pelo PHP
        } catch (erro) {
            return;
        }

        esconderCard(true);
        card = criarCard(info);
        card.style.visibility = 'hidden';
        document.body.appendChild(card);

        // Posição: centralizado abaixo do botão (ou acima, se faltar espaço)
        const r = botao.getBoundingClientRect();
        const largura = card.offsetWidth;
        const altura = card.offsetHeight;
        let esquerda = r.left + r.width / 2 - largura / 2;
        esquerda = Math.max(8, Math.min(esquerda, window.innerWidth - largura - 8));
        let topo = r.bottom + 10;
        if (topo + altura > window.innerHeight && r.top - altura - 10 > 0) {
            topo = r.top - altura - 10;
        }
        card.style.left = (esquerda + window.scrollX) + 'px';
        card.style.top = (topo + window.scrollY) + 'px';
        card.style.visibility = '';

        const atual = card;
        requestAnimationFrame(function () { atual.classList.add('visivel'); });
    }

    /** mouseleave: o card desaparece suavemente (fade) e depois é removido. */
    function esconderCard(imediato) {
        if (!card) return;
        const antigo = card;
        card = null;
        if (imediato) {
            antigo.remove();
            return;
        }
        antigo.classList.remove('visivel');
        setTimeout(function () { antigo.remove(); }, 200);
    }

    // -----------------------------------------------------------------------
    // Botões de fronteira
    // -----------------------------------------------------------------------
    function iniciarFronteiras() {
        document.querySelectorAll('.btn-fronteira').forEach(function (botao) {
            botao.addEventListener('mouseenter', function () { mostrarCard(botao); });
            botao.addEventListener('mouseleave', function () { esconderCard(false); });
            botao.addEventListener('focus', function () { mostrarCard(botao); });   // teclado
            botao.addEventListener('blur', function () { esconderCard(false); });
            botao.addEventListener('click', function () {
                pesquisar(botao.dataset.pais, { rolar: true });
            });
        });
    }

    // -----------------------------------------------------------------------
    // Pesquisa sem recarregar a página
    // -----------------------------------------------------------------------
    function mostrarErroDeRede() {
        const caixa = document.createElement('div');
        caixa.className = 'mensagem mensagem-erro';
        caixa.setAttribute('role', 'alert');
        const p = document.createElement('p');
        p.textContent = '⚠️ ' + MSG_ERRO_API;
        caixa.appendChild(p);
        resultados.replaceChildren(caixa);
    }

    async function pesquisar(nome, opcoes) {
        opcoes = opcoes || {};
        const historico = opcoes.historico !== false;

        esconderCard(true);
        if (controlador) controlador.abort();   // cancela pesquisa anterior em andamento
        const meuControlador = new AbortController();
        controlador = meuControlador;

        campo.value = nome;
        resultados.classList.add('carregando');

        try {
            // O PHP devolve só o trecho de resultados (ajax=1)
            const url = 'index.php?ajax=1&pais=' + encodeURIComponent(nome);
            const resposta = await fetch(url, { signal: meuControlador.signal });
            if (!resposta.ok) throw new Error('Falha HTTP');

            resultados.innerHTML = await resposta.text();   // HTML já escapado pelo PHP
            if (historico) {
                history.pushState({ pais: nome }, '', '?pais=' + encodeURIComponent(nome));
            }
            iniciar();
            if (opcoes.rolar) resultados.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (erro) {
            if (erro.name === 'AbortError') return;
            mostrarErroDeRede();
        } finally {
            if (controlador === meuControlador) resultados.classList.remove('carregando');
        }
    }

    /** Liga o mapa e os botões de fronteira ao HTML atual. */
    function iniciar() {
        if (window.PortalMapa) window.PortalMapa.iniciar();
        iniciarFronteiras();
    }

    // -----------------------------------------------------------------------
    // Eventos globais
    // -----------------------------------------------------------------------
    form.addEventListener('submit', function (evento) {
        evento.preventDefault();               // evita recarregar a página
        pesquisar(campo.value.trim());
    });

    // Botões voltar/avançar do navegador
    window.addEventListener('popstate', function (evento) {
        if (evento.state && typeof evento.state.pais === 'string') {
            pesquisar(evento.state.pais, { historico: false });
        } else {
            window.location.reload();
        }
    });

    // Estado inicial do histórico
    const paisNaUrl = new URLSearchParams(window.location.search).get('pais');
    history.replaceState(paisNaUrl !== null ? { pais: paisNaUrl } : { inicial: true }, '');

    iniciar();
})();
