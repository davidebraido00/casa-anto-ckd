<?php
/**
 * Documentazione delle API del framework CKD.
 *
 * Pagina autonoma: nessuna dipendenza esterna, nessuna CDN, nessun accesso al
 * database. Funziona anche su un server senza rete in uscita.
 *
 * Le parti variabili sono lette dalla configurazione del progetto, così la
 * pagina non invecchia: dominio da config/settings.json, versione da
 * version.json, entità e permessi da config/tables.json.
 */

$settings = @json_decode(@file_get_contents(__DIR__ . '/../config/settings.json'), true) ?: [];
/** rtrim: alcuni progetti hanno il dominio con la barra finale. */
$domain   = rtrim($settings['host']['domain'] ?? '', '/');

$versionData = @json_decode(@file_get_contents(__DIR__ . '/../version.json'), true) ?: [];
$version     = $versionData['version'] ?? '';

$tables = @json_decode(@file_get_contents(__DIR__ . '/../config/tables.json'), true) ?: [];

/** Entità di sistema: non fanno parte della documentazione del progetto. */
$systemTables = ['user_group', 'auth_token', 'auth_pin', 'resource'];
$entities = array_diff_key($tables, array_flip($systemTables));

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Trasforma un titolo in un id utilizzabile come ancora. */
function slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/**
 * Ogni endpoint è una voce di questa struttura invece che una tabella HTML
 * scritta a mano: aggiungerne uno è una riga, non venti.
 *
 *   path   percorso relativo all'endpoint base
 *   body   corpo della richiesta, mostrato come blocco di codice
 *   rows   righe aggiuntive: etichetta => HTML
 */
$groups = [
    'Autenticazione' => [
        [
            'title' => 'Login',
            'path'  => '/auth/login',
            'body'  => "let body = {\n    rememberme: rememberme,\n    password: Utils.encrypt(password),\n    username: username\n}",
            'rows'  => [
                'Encrypt'  => 'SHA1',
                'Response' => 'AUTH_RESPONSE',
            ],
        ],
        [
            'title' => 'Check login',
            'path'  => '/auth/checkLogin',
            'body'  => "let body = {\n    ltsid: ltsid\n}",
            'rows'  => ['Response' => 'AUTH_RESPONSE'],
        ],
        [
            'title' => 'Refresh sid',
            'path'  => '/auth/refreshSid',
            'body'  => "let body = {\n    ltsid: ltsid\n}",
            'rows'  => ['Response' => 'AUTH_RESPONSE'],
        ],
        [
            'title' => 'Logout',
            'path'  => '/auth/logout',
            'body'  => "let body = {\n    ltsid: ltsid\n}",
        ],
        [
            'title' => 'Recupero password',
            'path'  => '/auth/forgot',
            'body'  => "let body = {\n    email: username\n}",
        ],
        [
            'title' => 'Reset password',
            'path'  => '/auth/reset',
            'body'  => "let body = {\n    pin: pin,\n    newPassword: Utils.encrypt(newPassword),\n    confirmPassword: Utils.encrypt(confirmPassword)\n}",
        ],
    ],

    'Dati' => [
        [
            'title' => 'Get item',
            'path'  => '/get_item/{entity_name}',
            'body'  => "let body = {\n    id: id\n}",
        ],
        [
            'title' => 'Get items',
            'path'  => '/get_items/{entity_name}',
            'body'  => "let body = {\n    order: 'id DESC',\n    page: '1',\n    limit: 100,\n    offset: 0\n}",
        ],
        [
            'title' => 'Search items',
            'path'  => '/search_items/{entity_name}',
            'body'  => "let body = {\n    order: 'id DESC',\n    page: '1',\n    limit: 100,\n    offset: 0,\n    query: 'test',\n    fields: ['id', 'name', 'code'],\n    constraints: {\n        \"name\": {\n            \"type\": \"value\",\n            \"value\": \"mario\"\n        }\n    }\n}",
            'rows'  => [
                'query' => "La stringa viene cercata con <code>LIKE %stringa%</code> nei campi elencati in <code>fields</code>.",
                'constraints' => "Oggetto che ha come chiave il campo in cui cercare. Ogni chiave contiene a sua volta un oggetto con:"
                    . "<ul>"
                    . "<li><code>type</code> — <b>value</b> uguale a, <b>greater</b> maggiore di, <b>minor</b> minore di, <b>different_from_value</b> diverso da</li>"
                    . "<li><code>value</code> — il valore da cercare</li>"
                    . "</ul>",
            ],
        ],
        [
            'title' => 'Create item',
            'path'  => '/post/{entity_name}',
            'body'  => "let body = {\n    action: 'create',\n    body: pData\n}",
            'rows'  => ['pData' => 'PDATA'],
        ],
        [
            'title' => 'Update item',
            'path'  => '/post/{entity_name}',
            'body'  => "let body = {\n    id: id,\n    action: 'edit',\n    body: pData\n}",
            'rows'  => ['pData' => 'PDATA'],
        ],
        [
            'title' => 'Delete item',
            'path'  => '/delete/{entity_name}',
            'body'  => "let body = {\n    id: id\n}",
        ],
    ],

    'File' => [
        [
            'title' => 'Upload file',
            'path'  => '/resource/uploadFile',
            'body'  => "let body = {\n    entity: 'entità per la quale si sta facendo un upload',\n    filename: 'nomefile.estensione',\n    filedata: base64\n}",
            'rows'  => [
                'Esempio' => "<pre class=\"code\"><code>{\n    \"entity\": \"resource\",\n    \"filename\": \"test.pdf\",\n    \"filedata\": \"data:application/pdf;base64,JVBERi0xLjUKJb/3o…\"\n}</code></pre>",
            ],
        ],
        [
            'title' => 'Get file',
            'path'  => '/resource/getFile',
            'body'  => "let body = {\n    entity: 'entity',\n    hash: 'hash'\n}",
        ],
    ],
];

/** Blocchi ripetuti, definiti una volta sola. */
$AUTH_RESPONSE = "<ul>"
    . "<li><code>body</code> — json con le informazioni dell'utente</li>"
    . "<li><code>sid</code> — token di autenticazione</li>"
    . "<li><code>ltsid</code> — token per il rinnovo del sid</li>"
    . "<li><code>auth_map</code> — json dei permessi autorizzativi sulle entità</li>"
    . "<li><code>props</code> — json delle proprietà delle entità</li>"
    . "</ul>";

$PDATA = "JSON con tutti gli elementi dell'entità, <b>esclusi</b> "
    . "<code>id</code>, <code>date</code>, <code>lastUpdate</code>, "
    . "<code>deleted</code>, <code>author</code>, <code>idUserGroup</code>.";

$PLACEHOLDERS = ['AUTH_RESPONSE' => $AUTH_RESPONSE, 'PDATA' => $PDATA];

/** Etichetta e colore di un permesso, dal valore grezzo in tables.json. */
function permissionLabel($value): array
{
    if ($value === 1 || $value === '1') return ['tutti i record', 'ok'];
    if ($value === 'group')             return ['record del gruppo', 'ok'];
    if ($value === 'user')              return ['record dell\'utente', 'ok'];
    return ['assente', 'no'];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>API<?= $version ? ' · v' . e($version) : '' ?></title>
<style>
    :root {
        --bg: #ffffff;
        --bg-soft: #f6f7f9;
        --bg-code: #f2f4f7;
        --text: #1a1d21;
        --text-soft: #5c6570;
        --border: #e3e6ea;
        --accent: #2f6df6;
        --ok: #1a7f4b;
        --no: #c0392b;
        --radius: 10px;
        --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    }

    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #14171a;
            --bg-soft: #1b1f24;
            --bg-code: #1e2329;
            --text: #e6e9ec;
            --text-soft: #98a2ad;
            --border: #2a3038;
            --accent: #6ea0ff;
            --ok: #4ec98a;
            --no: #ff7a6b;
        }
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
    }

    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }
    code { font-family: var(--mono); font-size: 0.9em; }

    .layout { display: flex; align-items: flex-start; max-width: 1200px; margin: 0 auto; }

    /* ---- navigazione laterale ---- */
    .nav {
        position: sticky; top: 0;
        width: 240px; flex: 0 0 240px;
        height: 100vh; overflow-y: auto;
        padding: 28px 16px 40px;
        border-right: 1px solid var(--border);
    }
    .nav-title { font-weight: 700; font-size: 15px; margin: 0 0 2px; }
    .nav-version { font-size: 12px; color: var(--text-soft); margin: 0 0 20px; }
    .nav h3 {
        font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
        color: var(--text-soft); margin: 22px 0 8px; font-weight: 600;
    }
    .nav a {
        display: block; padding: 5px 10px; margin: 0 -10px;
        border-radius: 6px; color: var(--text-soft); font-size: 13.5px;
    }
    .nav a:hover { background: var(--bg-soft); color: var(--text); text-decoration: none; }
    .nav a.on { background: var(--bg-soft); color: var(--accent); font-weight: 600; }

    #entity-filter {
        width: 100%; padding: 6px 9px; margin-bottom: 6px;
        border: 1px solid var(--border); border-radius: 6px;
        background: var(--bg); color: var(--text);
        font: inherit; font-size: 13px;
    }
    #entity-filter:focus { outline: 2px solid var(--accent); outline-offset: -1px; }

    /* ---- contenuto ---- */
    main { flex: 1 1 auto; min-width: 0; padding: 28px 32px 96px; }

    h1 { font-size: 27px; margin: 0 0 4px; letter-spacing: -0.02em; }
    .sub { color: var(--text-soft); margin: 0 0 32px; font-size: 14px; }

    h2 {
        font-size: 19px; margin: 48px 0 16px; padding-top: 14px;
        border-top: 1px solid var(--border); letter-spacing: -0.01em;
        scroll-margin-top: 20px;
    }
    h2:first-of-type { border-top: 0; padding-top: 0; margin-top: 0; }

    /* ---- blocco endpoint ---- */
    .endpoint {
        border: 1px solid var(--border); border-radius: var(--radius);
        margin-bottom: 16px; overflow: hidden; scroll-margin-top: 20px;
    }
    .endpoint > header {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        padding: 11px 14px; background: var(--bg-soft);
        border-bottom: 1px solid var(--border);
    }
    .endpoint h4 { margin: 0; font-size: 14px; font-weight: 600; }
    .method {
        font: 600 10.5px/1 var(--mono); letter-spacing: .06em;
        padding: 4px 7px; border-radius: 4px;
        background: var(--accent); color: #fff;
    }
    .path { font-family: var(--mono); font-size: 13px; color: var(--text-soft); }
    .path b { color: var(--text); font-weight: 600; }
    .copy {
        margin-left: auto; border: 1px solid var(--border); border-radius: 6px;
        background: var(--bg); color: var(--text-soft);
        font: inherit; font-size: 12px; padding: 3px 9px; cursor: pointer;
    }
    .copy:hover { color: var(--text); border-color: var(--text-soft); }

    .fields { width: 100%; border-collapse: collapse; }
    .fields th, .fields td {
        padding: 10px 14px; text-align: left; vertical-align: top;
        border-top: 1px solid var(--border); font-size: 14px;
    }
    .fields tr:first-child th, .fields tr:first-child td { border-top: 0; }
    .fields th {
        width: 150px; font-weight: 600; color: var(--text-soft);
        font-size: 13px; white-space: nowrap;
    }
    .fields ul { margin: 6px 0 0; padding-left: 20px; }
    .fields li { margin: 3px 0; }

    pre.code {
        margin: 0; padding: 12px 14px; overflow-x: auto;
        background: var(--bg-code); border-radius: 8px;
        font-family: var(--mono); font-size: 12.5px; line-height: 1.55;
    }
    .fields td > pre.code:first-child { margin-top: 0; }

    /* ---- entità ---- */
    .entity {
        border: 1px solid var(--border); border-radius: var(--radius);
        margin-bottom: 16px; overflow: hidden; scroll-margin-top: 20px;
    }
    .entity > header {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        padding: 11px 14px; background: var(--bg-soft);
        border-bottom: 1px solid var(--border);
    }
    .entity h4 { margin: 0; font-family: var(--mono); font-size: 14px; font-weight: 600; }
    .count { font-size: 12px; color: var(--text-soft); }
    .perms { margin-left: auto; display: flex; gap: 6px; flex-wrap: wrap; }
    .perm {
        font-size: 11.5px; padding: 3px 8px; border-radius: 20px;
        border: 1px solid var(--border); color: var(--text-soft);
        white-space: nowrap;
    }
    .perm b { font-weight: 600; }
    .perm.ok { color: var(--ok); border-color: color-mix(in srgb, var(--ok) 35%, transparent); }
    .perm.no { color: var(--no); border-color: color-mix(in srgb, var(--no) 35%, transparent); }

    .cols { width: 100%; border-collapse: collapse; font-size: 13px; }
    .cols th, .cols td {
        padding: 7px 14px; text-align: left; border-top: 1px solid var(--border);
        white-space: nowrap;
    }
    .cols thead th {
        border-top: 0; font-size: 11px; text-transform: uppercase;
        letter-spacing: .06em; color: var(--text-soft); font-weight: 600;
    }
    .cols tbody th { font-family: var(--mono); font-weight: 600; }
    .cols td { color: var(--text-soft); font-family: var(--mono); font-size: 12.5px; }
    .cols td.opts { white-space: normal; word-break: break-word; max-width: 320px; }
    .table-scroll { overflow-x: auto; }

    .empty { color: var(--text-soft); font-style: italic; }

    /* ---- mobile ---- */
    @media (max-width: 860px) {
        .layout { display: block; }
        .nav {
            position: static; width: auto; height: auto;
            border-right: 0; border-bottom: 1px solid var(--border);
            padding: 20px;
        }
        main { padding: 24px 20px 64px; }
        .fields th { width: auto; }
    }
</style>
</head>
<body>
<div class="layout">

    <nav class="nav">
        <p class="nav-title">API</p>
        <p class="nav-version"><?= $version ? 'Framework v' . e($version) : 'Framework' ?></p>

        <a href="#generali">Generali</a>
        <?php foreach ($groups as $groupName => $endpoints): ?>
            <h3><?= e($groupName) ?></h3>
            <?php foreach ($endpoints as $ep): ?>
                <a href="#<?= e(slug($groupName . '-' . $ep['title'])) ?>"><?= e($ep['title']) ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if ($entities): ?>
            <h3>Entità</h3>
            <input type="search" id="entity-filter" placeholder="Filtra entità…" autocomplete="off">
            <div id="entity-links">
                <?php foreach ($entities as $name => $_): ?>
                    <a href="#entity-<?= e(slug($name)) ?>" data-name="<?= e(strtolower($name)) ?>"><?= e($name) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </nav>

    <main>
        <h1>Documentazione API</h1>
        <p class="sub">
            <?php if ($domain): ?>
                Endpoint base <code><?= e($domain) ?>/api</code>
            <?php else: ?>
                Endpoint base non configurato in <code>config/settings.json</code>
            <?php endif; ?>
            <?= $version ? ' · framework v' . e($version) : '' ?>
        </p>

        <h2 id="generali">Generali</h2>
        <div class="endpoint">
            <header>
                <span class="method">POST</span>
                <span class="path"><b><?= e($domain) ?>/api</b></span>
                <button class="copy" data-copy="<?= e($domain) ?>/api">Copia</button>
            </header>
            <table class="fields">
                <tr>
                    <th>Headers</th>
                    <td><pre class="code"><code>'Content-Type': 'application/json'
'Accept': 'application/json'</code></pre></td>
                </tr>
                <tr>
                    <th>Headers se autenticato</th>
                    <td><pre class="code"><code>'Content-Type': 'application/json'
'Accept': 'application/json'
'Authorization': `Bearer ${sid}`</code></pre></td>
                </tr>
                <tr>
                    <th>Response</th>
                    <td>
                        <b>JSON</b> con:
                        <ul>
                            <li><code>success</code> — 0 oppure 1</li>
                            <li><code>error_code</code> — presente in caso di errore, vedi sotto</li>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th>Error code</th>
                    <td>
                        <ul>
                            <li><code>not.logged</code> — utente non loggato, effettuare il login</li>
                            <li><code>refresh.sid</code> — inviare la chiamata di refresh sid, vedi Autenticazione</li>
                        </ul>
                    </td>
                </tr>
            </table>
        </div>

        <?php foreach ($groups as $groupName => $endpoints): ?>
            <h2 id="<?= e(slug($groupName)) ?>"><?= e($groupName) ?></h2>

            <?php foreach ($endpoints as $ep): ?>
                <div class="endpoint" id="<?= e(slug($groupName . '-' . $ep['title'])) ?>">
                    <header>
                        <span class="method">POST</span>
                        <h4><?= e($ep['title']) ?></h4>
                        <span class="path"><b><?= e($ep['path']) ?></b></span>
                        <button class="copy" data-copy="<?= e($domain) ?>/api<?= e($ep['path']) ?>">Copia</button>
                    </header>
                    <table class="fields">
                        <?php if (!empty($ep['body'])): ?>
                            <tr>
                                <th>Body</th>
                                <td><pre class="code"><code><?= e($ep['body']) ?></code></pre></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($ep['rows'] ?? [] as $label => $html): ?>
                            <tr>
                                <th><?= e($label) ?></th>
                                <td><?= $PLACEHOLDERS[$html] ?? $html ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <h2 id="entita">Entità e permessi</h2>

        <?php if (!$entities): ?>
            <p class="empty">Nessuna entità definita in <code>config/tables.json</code>.</p>
        <?php endif; ?>

        <?php foreach ($entities as $name => $table): ?>
            <?php $columns = $table['columns'] ?? []; ?>
            <div class="entity" id="entity-<?= e(slug($name)) ?>" data-name="<?= e(strtolower($name)) ?>">
                <header>
                    <h4><?= e($name) ?></h4>
                    <span class="count"><?= count($columns) ?> camp<?= count($columns) === 1 ? 'o' : 'i' ?></span>
                    <div class="perms">
                        <?php
                        $perms = $table['permissions']['1'] ?? [];
                        foreach (['view' => 'lettura', 'edit' => 'scrittura', 'delete' => 'rimozione'] as $key => $label) {
                            if (!isset($perms[$key])) continue;
                            [$text, $state] = permissionLabel($perms[$key]);
                            echo '<span class="perm ' . $state . '">' . e($label) . ': <b>' . e($text) . '</b></span>';
                        }
                        ?>
                    </div>
                </header>

                <div class="table-scroll">
                    <table class="cols">
                        <thead>
                            <tr>
                                <th>Campo</th><th>Tipo</th><th>Input</th>
                                <th>Lunghezza</th><th>Null</th><th>Default</th><th>Opzioni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($columns as $field => $def): ?>
                            <?php
                            $type      = $def['type'] ?? 'VARCHAR';
                            $inputType = $def['input_type'] ?? 'string';
                            $length    = (isset($def['length']) && $def['length'] !== 'null') ? $def['length'] : '';
                            $null      = (($def['null'] ?? true) === true) ? 'NULL' : 'NOT NULL';
                            $default   = $def['default'] ?? '';
                            $options   = ($inputType === 'option' && isset($def['options']['options']))
                                ? json_encode($def['options']['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : '';
                            ?>
                            <tr>
                                <th><?= e($field) ?></th>
                                <td><?= e($type) ?></td>
                                <td><?= e($inputType) ?></td>
                                <td><?= e($length) ?></td>
                                <td><?= e($null) ?></td>
                                <td><?= e(is_scalar($default) ? (string) $default : '') ?></td>
                                <td class="opts"><?= e($options) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </main>
</div>

<script>
    // Copia il percorso completo dell'endpoint.
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.copy');
        if (!btn) return;
        navigator.clipboard.writeText(btn.dataset.copy).then(function () {
            var old = btn.textContent;
            btn.textContent = 'Copiato';
            setTimeout(function () { btn.textContent = old; }, 1200);
        });
    });

    // Filtra l'elenco delle entità, nella nav e nella pagina.
    var filter = document.getElementById('entity-filter');
    if (filter) {
        filter.addEventListener('input', function () {
            var q = filter.value.trim().toLowerCase();
            document.querySelectorAll('#entity-links a, .entity').forEach(function (el) {
                el.style.display = (!q || el.dataset.name.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    // Evidenzia nella nav una sola voce: la sezione in cima alla pagina.
    // Ogni link della nav ha la sua sezione; si sceglie l'ultima che ha
    // superato il bordo alto, saltando le entità nascoste dal filtro.
    var navLinks = [].slice.call(document.querySelectorAll('.nav a[href^="#"]'));
    var sections = navLinks.map(function (a) {
        return { link: a, el: document.getElementById(a.getAttribute('href').slice(1)) };
    }).filter(function (s) { return s.el; });

    var active = null;
    function updateActive() {
        var current = null;
        for (var i = 0; i < sections.length; i++) {
            var s = sections[i];
            if (s.el.offsetParent === null) continue;           // nascosta dal filtro
            if (s.el.getBoundingClientRect().top <= 90) current = s;
            else break;
        }
        if (!current) {
            current = sections.find(function (s) { return s.el.offsetParent !== null; });
        }
        if (!current || current === active) return;
        if (active) active.link.classList.remove('on');
        current.link.classList.add('on');
        current.link.scrollIntoView({ block: 'nearest' });
        active = current;
    }

    var pending = false;
    function onScroll() {
        if (pending) return;
        pending = true;
        requestAnimationFrame(function () { pending = false; updateActive(); });
    }
    addEventListener('scroll', onScroll, { passive: true });
    addEventListener('resize', onScroll);
    if (filter) filter.addEventListener('input', updateActive);
    updateActive();
</script>
</body>
</html>
