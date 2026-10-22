  # 🌎 Portal de Países

Aplicação web em **PHP, HTML, CSS e JavaScript** que consulta a [REST Countries API](https://restcountries.com) e mostra informações de qualquer país: dados gerais, bandeira, mapa interativo (Leaflet.js) e países vizinhos.

## Objetivo

Demonstrar, de forma simples e sem frameworks, o fluxo completo de uma aplicação que consome uma API externa:

```
Usuário → Formulário → PHP → Requisição GET → REST Countries → JSON
        → PHP processa o JSON → HTML apresenta os dados → JavaScript adiciona interatividade
```

## Funcionalidades

- Pesquisa de país pelo nome (em inglês ou português, ex.: `Brazil` ou `Brasil`).
- Exibição de nome comum, nome oficial, capital, população, continente, moeda(s) e bandeira.
- Mapa interativo com marcador e zoom suave até o país pesquisado.
- Lista de países vizinhos como botões.
- Card flutuante ao passar o mouse sobre um vizinho (nome, fusos horários, moeda principal e bandeira).
- Clique em um vizinho para pesquisá-lo (Brasil → Argentina → Chile → Peru…).
- Pesquisa sem recarregar a página, com indicador de carregamento.
- Tratamento de erros e de dados ausentes.
- Layout moderno e responsivo (desktop e celular).

## Tecnologias utilizadas

- PHP 7.4+ (recomendado 8.x) com cURL e mbstring
- HTML5 e CSS3 (Grid e Flexbox)
- JavaScript puro (sem frameworks)
- [Leaflet.js](https://leafletjs.com) 1.9.4 (via CDN)
- Sem banco de dados

## API utilizada

[REST Countries v5](https://restcountries.com/docs/countries) — base `https://api.restcountries.com/countries/v5`.

> A versão v3.1 (`restcountries.com/v3.1`) foi descontinuada e responde apenas com um aviso de migração. A v5 exige uma **chave de API gratuita** (veja a seção "Chave de API") e usa outro formato de JSON.

| Uso | Endpoint |
|-----|----------|
| Busca por nome comum exato | `/names.common/{nome}` |
| Busca por nome parcial | `/name?q={nome}` |
| Busca por tradução (ex.: "Brasil") | `/names.translations?q={nome}` |
| Dados de cada vizinho | `/codes.alpha_3/{código}` (uma requisição por vizinho, feitas em paralelo) |

A resposta vem como `{"data": {"objects": [ ...países... ]}}`. Correspondência com o projeto original (v3.1):

| Dado | v3.1 | v5 |
|------|------|----|
| País pesquisado | `data[0]` | `data.objects[0]` |
| Nome comum / oficial | `name.common` / `name.official` | `names.common` / `names.official` |
| Capital | `capital` | `capitals[].name` |
| Coordenadas | `latlng` | `coordinates.lat` / `coordinates.lng` |
| Bandeira | `flags.svg` | `flag.url_svg` |
| Área | `area` | `area.kilometers` |
| Fronteiras | `borders` | `borders` (códigos alpha-3) |

## Chave de API

1. Crie uma conta gratuita em <https://restcountries.com/sign-up> e copie sua chave.
2. Copie `config.local.example.php` para `config.local.php` e cole a chave:

```php
<?php
define('RESTCOUNTRIES_API_KEY', 'sua_chave_aqui');
```

Alternativa: definir a variável de ambiente `RESTCOUNTRIES_API_KEY`.

O `config.local.php` está no `.gitignore`, então a chave **não vai para o GitHub**. Nunca coloque a chave diretamente no `index.php`. A chave é usada somente no servidor (PHP), nunca no navegador.

## Estrutura de pastas

```
meu-app-paises/
├── index.php               # formulário, consulta à API, processamento do JSON e HTML
├── README.md
├── config.local.example.php  # modelo do arquivo de configuração da chave
├── .gitignore                # ignora o config.local.php (chave da API)
├── css/
│   └── estilo.css     # estilos, layout, responsividade e animações
└── js/
    ├── mapa.js        # mapa Leaflet
    └── interacao.js   # busca sem recarregar, hover e clique nas fronteiras
```

## Como executar localmente

Informações necessárias:

- PHP 7.4 ou superior com as extensões **cURL** e **mbstring** (ativas por padrão na maioria das instalações);
- conexão com a internet (API, Leaflet e tiles do mapa);
- uma chave gratuita da REST Countries API v5 (veja "Chave de API").

Passos:

```bash
git clone <url-do-repositorio>
cd meu-app-paises
cp config.local.example.php config.local.php   # edite e coloque sua chave
php -S localhost:8000
```

Abra <http://localhost:8000> no navegador. Também funciona em XAMPP/WAMP/Laragon, copiando a pasta para `htdocs`/`www`.

> **Diagnóstico:** abra `index.php?pais=Brazil&debug=1` para ver a URL chamada, o código HTTP e o início da resposta da API (remova esse bloco do código antes de publicar).
>
> **Windows/XAMPP:** se aparecer a mensagem de erro da API mesmo com internet, verifique se `extension=curl` está ativa no `php.ini` e se o `curl.cainfo` aponta para um arquivo de certificados válido.

## Como realizar uma pesquisa

1. Digite o nome de um país no campo de busca (ex.: `Japan`, `Portugal`, `Brasil`).
2. Clique em **Pesquisar** ou pressione Enter.
3. Os resultados substituem os anteriores, sem recarregar a página.

A URL também aceita a busca direta: `index.php?pais=Brazil`.

## Como funciona o mapa

1. O PHP lê `data.objects[0].coordinates` do JSON (`lat` e `lng`; na v3.1 era `latlng`).
2. Os valores são colocados no elemento `#mapa` como atributos `data-lat`, `data-lng`, `data-area` e `data-nome`.
3. O `js/mapa.js` lê esses atributos, cria o mapa com Leaflet, adiciona um marcador e usa `flyTo` para aproximar suavemente (o zoom final depende da área do país).
4. Se não houver coordenadas (ou o Leaflet não carregar), o mapa mostra "A localização não pôde ser carregada." e o resto da página continua funcionando.

## Como funciona a interação das fronteiras

1. O PHP lê `borders` (códigos como `ARG`, `BOL`) e consulta `/codes.alpha_3/{código}` de cada vizinho para obter os dados.
2. Cada vizinho vira um botão `.btn-fronteira`, com o nome em `data-pais` e um resumo em JSON em `data-info`.
3. No `js/interacao.js`:
   - `mouseenter` cria e exibe o card flutuante com nome, fusos, moeda e bandeira;
   - `mouseleave` remove a classe de visibilidade (fade-out) e depois remove o card;
   - `click` executa uma nova pesquisa com o nome do vizinho, usando o mesmo formulário e a mesma página.

## Como tratar erros

| Situação | Mensagem exibida |
|----------|------------------|
| Campo vazio | Digite o nome de um país para realizar a pesquisa. |
| País inexistente | País não encontrado. Verifique o nome informado e tente novamente. |
| API indisponível | Não foi possível consultar a API no momento. Tente novamente mais tarde. |
| Chave ausente | Chave da API não configurada. Veja a seção "Chave de API" do README. |
| Chave inválida | A chave da API é inválida ou expirou. Verifique o config.local.php. |
| Dado ausente | Informação não disponível. |
| País sem fronteiras | Este país não possui fronteiras terrestres. |
| Sem coordenadas | A localização não pôde ser carregada. |

Boas práticas aplicadas: chave da API fora do repositório; entrada do usuário filtrada (`limparEntrada`) e codificada com `rawurlencode`; toda saída em HTML escapada com `htmlspecialchars`; no JavaScript, o conteúdo dos cards é criado com `textContent`; só URLs `https` são aceitas para bandeiras; nenhuma credencial no código.

## Exemplo de utilização

1. Pesquise `Brasil` → aparecem Brazil, capital Brasília, população, moeda e bandeira.
2. O mapa aproxima do Brasil com um marcador.
3. Em "Fronteiras", passe o mouse em **Argentina** para ver o card com fuso e moeda.
4. Clique em **Argentina** → a página mostra os dados da Argentina; continue navegando por Chile, Peru etc.

## Créditos e referências

- [REST Countries](https://restcountries.com) — dados dos países (API v5).
- [Leaflet](https://leafletjs.com) — biblioteca de mapas (licença BSD-2-Clause).
- [OpenStreetMap](https://www.openstreetmap.org/copyright) — tiles do mapa © colaboradores do OpenStreetMap.

- https://youtu.be/OAq2c0bKm9o

## Licença

Distribuído sob a licença **MIT**. Você pode usar, copiar, modificar e distribuir este projeto livremente, mantendo o aviso de copyright.
