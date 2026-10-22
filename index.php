<?php
/**
 * Portal de Países
 *
 * Fluxo da aplicação:
 * Usuário → Formulário → PHP → Requisição GET → REST Countries (v5) → JSON
 *         → PHP processa o JSON → HTML apresenta os dados → JavaScript adiciona interatividade
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Chave da API (a v5 exige autenticação)
// A chave fica em config.local.php (não vai para o GitHub) ou na variável de
// ambiente RESTCOUNTRIES_API_KEY. Veja a seção "Chave de API" do README.
// ---------------------------------------------------------------------------
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// ---------------------------------------------------------------------------
// Constantes: URL base da API e mensagens exibidas ao usuário
// ---------------------------------------------------------------------------
const API_BASE           = 'https://api.restcountries.com/countries/v5';
const MSG_VAZIO          = 'Digite o nome de um país para realizar a pesquisa.';
const MSG_NAO_ENCONTRADO = 'País não encontrado. Verifique o nome informado e tente novamente.';
const MSG_ERRO_API       = 'Não foi possível consultar a API no momento. Tente novamente mais tarde.';
const MSG_SEM_CHAVE      = 'Chave da API não configurada. Veja a seção "Chave de API" do README.';
const MSG_CHAVE_INVALIDA = 'A chave da API é inválida ou expirou. Verifique o config.local.php.';
const MSG_INDISPONIVEL   = 'Informação não disponível.';

// ---------------------------------------------------------------------------
// Funções auxiliares
// ---------------------------------------------------------------------------

/** Escapa um valor antes de colocá-lo no HTML (evita XSS). */
function e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Lê a chave da API (constante do config.local.php ou variável de ambiente). */
function chaveApi(): string
{
    $chave = defined('RESTCOUNTRIES_API_KEY') ? RESTCOUNTRIES_API_KEY : getenv('RESTCOUNTRIES_API_KEY');
    return trim((string) $chave);
}

/**
 * Tratamento do dado digitado pelo usuário:
 * mantém apenas letras, espaços e alguns sinais comuns em nomes de países
 * e limita o tamanho do texto.
 */
function limparEntrada(string $valor): string
{
    $valor = preg_replace('/[^\p{L}\p{M}\s\-\.\'’(),]/u', '', $valor) ?? '';
    $valor = trim(preg_replace('/\s+/u', ' ', $valor) ?? '');
    return mb_substr($valor, 0, 80);
}

/** Opções comuns das requisições cURL (inclui a chave no cabeçalho Authorization). */
function opcoesCurl(): array
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . chaveApi()],
    ];
}

/**
 * Faz uma requisição HTTP pelo método GET.
 * Retorna [código HTTP, corpo]. Código 0 significa falha de conexão.
 */
function requisicaoGet(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, opcoesCurl());
        $corpo  = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        return $corpo === false ? [0, ''] : [$status, (string) $corpo];
    }

    // Alternativa caso a extensão cURL não esteja habilitada
    $contexto = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => 10,
        'ignore_errors' => true,
        'header'        => "Accept: application/json\r\nAuthorization: Bearer " . chaveApi() . "\r\n",
    ]]);
    $corpo = @file_get_contents($url, false, $contexto);
    if ($corpo === false) {
        return [0, ''];
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    return [$status, $corpo];
}

/** Faz várias requisições GET ao mesmo tempo (usado para buscar todos os vizinhos de uma vez). */
function requisicoesParalelas(array $urls): array
{
    $resultados = [];
    if (!function_exists('curl_multi_init')) {
        foreach ($urls as $chave => $url) {
            $resultados[$chave] = requisicaoGet($url);
        }
        return $resultados;
    }

    $multi    = curl_multi_init();
    $handles  = [];
    foreach ($urls as $chave => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, opcoesCurl());
        curl_multi_add_handle($multi, $ch);
        $handles[$chave] = $ch;
    }

    do {
        $estado = curl_multi_exec($multi, $ativos);
        if ($ativos) {
            curl_multi_select($multi, 1.0);
        }
    } while ($ativos && $estado === CURLM_OK);

    foreach ($handles as $chave => $ch) {
        $corpo  = curl_multi_getcontent($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $resultados[$chave] = ($status === 0 || $corpo === null) ? [0, ''] : [$status, (string) $corpo];
        curl_multi_remove_handle($multi, $ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
    }
    if (PHP_VERSION_ID < 80000) {
        curl_multi_close($multi);
    }
    return $resultados;
}

/**
 * Interpreta a resposta da API v5 e converte o JSON em array PHP (json_decode).
 * O formato é {"data": {"objects": [ ...países... ], "meta": {...}}}.
 * Retorno: ['status' => 'ok' | 'nao_encontrado' | 'chave' | 'erro', 'dados' => lista de países]
 */
function interpretarResposta(int $codigo, string $corpo, string $url = ''): array
{
    // Guarda a última resposta para o modo de diagnóstico (?debug=1)
    $GLOBALS['ultimaResposta'] = ['url' => $url, 'http' => $codigo, 'corpo' => mb_substr($corpo, 0, 800)];

    if ($codigo === 401) {
        return ['status' => 'chave', 'dados' => []];
    }
    if ($codigo === 404) {
        return ['status' => 'nao_encontrado', 'dados' => []];
    }
    if ($codigo !== 200) {
        return ['status' => 'erro', 'dados' => []];
    }

    $json = json_decode($corpo, true);   // true = devolve array associativo
    if (!is_array($json)) {
        return ['status' => 'erro', 'dados' => []];
    }

    // Países em data.objects; mantém só itens que parecem países e reindexa (0, 1, 2...)
    $objetos = $json['data']['objects'] ?? null;
    if (!is_array($objetos)) {
        return ['status' => 'erro', 'dados' => []];
    }
    $paises = array_values(array_filter($objetos, fn($p) => is_array($p) && isset($p['names'])));
    if ($paises === []) {
        return ['status' => 'nao_encontrado', 'dados' => []];
    }
    return ['status' => 'ok', 'dados' => $paises];
}

/** Consulta um caminho da API (GET) e interpreta o JSON. */
function consultarApi(string $caminho): array
{
    $url = API_BASE . $caminho;
    [$codigo, $corpo] = requisicaoGet($url);
    return interpretarResposta($codigo, $corpo, $url);
}

/**
 * Busca o país pelo nome. Tenta, nesta ordem:
 *  1) nome comum exato; 2) nome parcial (comum, oficial, alternativos); 3) tradução (permite digitar "Brasil").
 */
function buscarPais(string $nome): array
{
    $codificado = rawurlencode($nome);   // deixa o texto seguro para a URL
    $tentativas = [
        "/names.common/{$codificado}",
        "/name?q={$codificado}&limit=10",
        "/names.translations?q={$codificado}&limit=10",
    ];

    foreach ($tentativas as $caminho) {
        $resposta = consultarApi($caminho);
        if ($resposta['status'] !== 'nao_encontrado') {
            return $resposta;   // sucesso ou erro de API: não adianta continuar
        }
    }
    return ['status' => 'nao_encontrado', 'dados' => []];
}

/** Aceita somente URLs https (usadas nas bandeiras). */
function urlSegura($url): string
{
    return is_string($url) && str_starts_with($url, 'https://') ? $url : '';
}

/** Moedas: aceita {"BRL": {"name": ..., "symbol": ...}} ou uma lista de objetos. Retorna textos "Nome (símbolo)". */
function listarMoedas($moedas): array
{
    $lista = [];
    foreach (is_array($moedas) ? $moedas : [] as $chave => $m) {
        if (is_array($m)) {
            $nome    = $m['name'] ?? ($m['names']['common'] ?? ($m['code'] ?? (is_string($chave) ? $chave : '')));
            $simbolo = $m['symbol'] ?? '';
        } elseif (is_string($m)) {
            $nome    = $m;
            $simbolo = '';
        } else {
            continue;
        }
        if (is_string($nome) && $nome !== '') {
            $lista[] = $nome . (is_string($simbolo) && $simbolo !== '' ? " ({$simbolo})" : '');
        }
    }
    return $lista;
}

/** Capitais: lista de objetos (com "name") ou de textos. */
function listarCapitais($capitais): array
{
    $lista = [];
    foreach (is_array($capitais) ? $capitais : [] as $c) {
        $nome = is_array($c) ? ($c['name'] ?? ($c['names']['common'] ?? '')) : $c;
        if (is_string($nome) && $nome !== '') {
            $lista[] = $nome;
        }
    }
    return $lista;
}

/** Coordenadas do país: coordinates.lat / coordinates.lng (alternativas: lista [lat, lng]). */
function extrairCoordenadas(array $d): array
{
    $c = $d['coordinates'] ?? ($d['latlng'] ?? null);
    if (is_array($c)) {
        $lat = $c['lat'] ?? ($c['latitude'] ?? ($c[0] ?? null));
        $lng = $c['lng'] ?? ($c['lon'] ?? ($c['longitude'] ?? ($c[1] ?? null)));
        if (is_numeric($lat) && is_numeric($lng)) {
            return [(float) $lat, (float) $lng];
        }
    }
    return [null, null];
}

/** Extrai do JSON da API apenas os campos que a página usa, com tolerância a dados ausentes. */
function montarPais(array $d): array
{
    $nomeComum = is_string($d['names']['common'] ?? null) ? $d['names']['common'] : '';
    [$lat, $lng] = extrairCoordenadas($d);   // coordenadas do país (usadas pelo mapa)

    $area = $d['area']['kilometers'] ?? ($d['area'] ?? null);

    return [
        'nome'       => $nomeComum,
        'oficial'    => is_string($d['names']['official'] ?? null) ? $d['names']['official'] : '',
        'capital'    => implode(', ', listarCapitais($d['capitals'] ?? null)),
        'populacao'  => isset($d['population']) && is_numeric($d['population'])
                        ? number_format((int) $d['population'], 0, ',', '.') : '',
        'continente' => is_array($d['continents'] ?? null) ? implode(', ', array_filter($d['continents'], 'is_string')) : '',
        'moedas'     => implode(', ', listarMoedas($d['currencies'] ?? null)),
        'bandeira'   => urlSegura($d['flag']['url_svg'] ?? ($d['flag']['url_png'] ?? '')),
        'bandeiraAlt' => is_string($d['flag']['description'] ?? null) && $d['flag']['description'] !== ''
                        ? $d['flag']['description'] : 'Bandeira de ' . $nomeComum,
        'lat'        => $lat,
        'lng'        => $lng,
        'area'       => is_numeric($area) ? (float) $area : null,
        'fronteiras' => is_array($d['borders'] ?? null) ? array_values(array_filter($d['borders'], 'is_string')) : [],
    ];
}

/**
 * Retorna os dados dos países vizinhos a partir dos códigos de fronteira (alpha-3).
 * A v5 não consulta vários códigos de uma vez, então faz uma requisição por vizinho (em paralelo).
 */
function buscarVizinhos(array $codigos): array
{
    $urls = [];
    foreach (array_unique($codigos) as $codigo) {
        if (preg_match('/^[A-Za-z]{3}$/', $codigo)) {
            $urls[$codigo] = API_BASE . '/codes.alpha_3/' . rawurlencode($codigo);
        }
    }
    if ($urls === []) {
        return [];
    }

    $vizinhos = [];
    foreach (requisicoesParalelas($urls) as $codigo => [$http, $corpo]) {
        $resposta = interpretarResposta($http, $corpo, $urls[$codigo]);
        if ($resposta['status'] !== 'ok') {
            continue;
        }
        $v    = $resposta['dados'][0];
        $nome = $v['names']['common'] ?? '';
        if (!is_string($nome) || $nome === '') {
            continue;
        }
        $moedas = listarMoedas($v['currencies'] ?? null);

        $vizinhos[] = [
            'nome'     => $nome,
            'fusos'    => array_values(array_filter(is_array($v['timezones'] ?? null) ? $v['timezones'] : [], 'is_string')),
            'moeda'    => $moedas[0] ?? '',   // moeda principal = a primeira
            'bandeira' => urlSegura($v['flag']['url_svg'] ?? ($v['flag']['url_png'] ?? '')),
        ];
    }

    if ($vizinhos === []) {
        return ['erro' => true];   // nenhum vizinho pôde ser carregado
    }
    usort($vizinhos, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    return $vizinhos;
}

/** Mostra o valor ou a mensagem padrão quando o dado não existe. */
function mostrar(string $valor): string
{
    return $valor !== ''
        ? e($valor)
        : '<span class="indisponivel">' . e(MSG_INDISPONIVEL) . '</span>';
}

// ---------------------------------------------------------------------------
// Renderização da área de resultados (também usada nas requisições AJAX)
// ---------------------------------------------------------------------------
function renderizarResultados(array $estado): void
{
    if ($estado['tipo'] === 'inicial') { ?>
        <div class="estado-inicial">
            <div class="estado-icone" aria-hidden="true">🌍</div>
            <h2>Explore o mundo</h2>
            <p>Pesquise um país no campo acima para ver suas informações, localização e fronteiras.</p>
        </div>
    <?php return; }

    if ($estado['tipo'] === 'erro') { ?>
        <div class="mensagem mensagem-erro" role="alert">
            <span aria-hidden="true">⚠️</span>
            <p><?= e($estado['mensagem']) ?></p>
        </div>
    <?php return; }

    $p        = $estado['pais'];
    $vizinhos = $estado['vizinhos'];
    ?>
    <article class="pais">

        <!-- Bloco de informações principais -->
        <section class="card card-info">
            <div class="info-topo">
                <?php if ($p['bandeira'] !== '') { ?>
                    <img class="bandeira" src="<?= e($p['bandeira']) ?>" alt="<?= e($p['bandeiraAlt']) ?>">
                <?php } else { ?>
                    <div class="bandeira bandeira-vazia"><?= e(MSG_INDISPONIVEL) ?></div>
                <?php } ?>
                <div>
                    <h2><?= $p['nome'] !== '' ? e($p['nome']) : e(MSG_INDISPONIVEL) ?></h2>
                    <p class="oficial"><?= mostrar($p['oficial']) ?></p>
                </div>
            </div>
            <dl class="dados">
                <div><dt>Capital</dt><dd><?= mostrar($p['capital']) ?></dd></div>
                <div><dt>População</dt><dd><?= mostrar($p['populacao']) ?></dd></div>
                <div><dt>Continente</dt><dd><?= mostrar($p['continente']) ?></dd></div>
                <div><dt>Moeda(s)</dt><dd><?= mostrar($p['moedas']) ?></dd></div>
            </dl>
        </section>

        <!-- Bloco do mapa: o PHP entrega as coordenadas ao JavaScript via atributos data-* -->
        <section class="card card-mapa">
            <h3>Localização</h3>
            <div id="mapa"
                 data-lat="<?= $p['lat'] !== null ? e((string) $p['lat']) : '' ?>"
                 data-lng="<?= $p['lng'] !== null ? e((string) $p['lng']) : '' ?>"
                 data-area="<?= $p['area'] !== null ? e((string) $p['area']) : '' ?>"
                 data-nome="<?= e($p['nome']) ?>"></div>
        </section>

        <!-- Bloco de fronteiras -->
        <section class="card card-fronteiras">
            <h3>Fronteiras</h3>
            <?php if (isset($vizinhos['erro'])) { ?>
                <p class="indisponivel">Não foi possível carregar os países vizinhos.</p>
            <?php } elseif ($p['fronteiras'] === [] || $vizinhos === []) { ?>
                <p class="sem-fronteiras">Este país não possui fronteiras terrestres.</p>
            <?php } else { ?>
                <p class="dica">Passe o mouse para ver um resumo ou clique para pesquisar o país.</p>
                <div class="lista-fronteiras">
                    <?php foreach ($vizinhos as $v) {
                        // Dados do card flutuante, lidos pelo js/interacao.js
                        $info = json_encode([
                            'nome'     => $v['nome'],
                            'fusos'    => $v['fusos'],
                            'moeda'    => $v['moeda'],
                            'bandeira' => $v['bandeira'],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        ?>
                        <button type="button" class="btn-fronteira"
                                data-pais="<?= e($v['nome']) ?>"
                                data-info="<?= e($info) ?>"><?= e($v['nome']) ?></button>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
    </article>
    <?php
}

// ---------------------------------------------------------------------------
// Lógica principal: recebe o formulário, consulta a API e define o estado da página
// ---------------------------------------------------------------------------
$ajax      = isset($_GET['ajax']);
$pesquisou = isset($_GET['pais']) && is_string($_GET['pais']);
$termo     = $pesquisou ? limparEntrada($_GET['pais']) : '';
$estado    = ['tipo' => 'inicial'];

if ($pesquisou) {
    if ($termo === '') {
        $estado = ['tipo' => 'erro', 'mensagem' => MSG_VAZIO];
    } elseif (chaveApi() === '') {
        $estado = ['tipo' => 'erro', 'mensagem' => MSG_SEM_CHAVE];
    } else {
        $resposta = buscarPais($termo);

        if ($resposta['status'] === 'chave') {
            $estado = ['tipo' => 'erro', 'mensagem' => MSG_CHAVE_INVALIDA];
        } elseif ($resposta['status'] === 'erro') {
            $estado = ['tipo' => 'erro', 'mensagem' => MSG_ERRO_API];
        } elseif ($resposta['status'] === 'nao_encontrado') {
            $estado = ['tipo' => 'erro', 'mensagem' => MSG_NAO_ENCONTRADO];
        } else {
            // Prefere o resultado cujo nome comum é igual ao digitado; senão usa o primeiro: data.objects[0]
            $escolhido = $resposta['dados'][0];
            foreach ($resposta['dados'] as $item) {
                if (mb_strtolower((string) ($item['names']['common'] ?? '')) === mb_strtolower($termo)) {
                    $escolhido = $item;
                    break;
                }
            }
            $pais = montarPais($escolhido);
            $estado = [
                'tipo'     => 'ok',
                'pais'     => $pais,
                'vizinhos' => $pais['fronteiras'] ? buscarVizinhos($pais['fronteiras']) : [],
            ];
        }
    }
}

// Diagnóstico: abra index.php?pais=Brazil&debug=1 para ver o que a API respondeu (remova depois dos testes)
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "curl disponível: " . (function_exists('curl_init') ? 'sim' : 'não') . "\n";
    echo "PHP: " . PHP_VERSION . "\n\n";
    print_r($GLOBALS['ultimaResposta'] ?? 'Nenhuma requisição foi feita.');
    exit;
}

// Requisição feita pelo JavaScript: devolve apenas o trecho de resultados
if ($ajax) {
    header('Content-Type: text/html; charset=utf-8');
    renderizarResultados($estado);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal de Países</title>
    <meta name="description" content="Pesquise um país e veja informações, mapa e fronteiras usando a API REST Countries.">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="css/estilo.css">
</head>
<body>
    <header class="topo">
        <div class="container">
            <h1>🌎 Portal de Países</h1>
            <p>Consulte informações de qualquer país do mundo: dados gerais, localização no mapa e países vizinhos.</p>

            <!-- Formulário: envia o nome do país ao PHP pelo método GET -->
            <form id="form-busca" class="busca" method="get" action="index.php">
                <label for="campo-pais" class="sr-only">Nome do país</label>
                <input type="text" id="campo-pais" name="pais" maxlength="80"
                       placeholder="Ex.: Brasil, Japan, Portugal…"
                       value="<?= e($termo) ?>" autocomplete="off">
                <button type="submit">Pesquisar</button>
            </form>
        </div>
    </header>

    <main class="container">
        <!-- Área de resultados (substituída a cada nova pesquisa) -->
        <div id="resultados" aria-live="polite">
            <?php renderizarResultados($estado); ?>
        </div>
    </main>

    <footer class="rodape">
        <p>Dados: <a href="https://restcountries.com" target="_blank" rel="noopener">REST Countries</a> ·
           Mapa: <a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a> &amp;
           <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a></p>
    </footer>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="js/mapa.js"></script>
    <script src="js/interacao.js"></script>
</body>
</html>
