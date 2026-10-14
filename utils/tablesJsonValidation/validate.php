<?php
/**
 * Validatore di config/tables.json.
 *
 * La pagina è riscritta nello stile di utils/, ma il contenuto delle schede
 * "Analisi" e "Entità orfane" continua a essere prodotto da
 * ValidateTablesJson, che emette HTML con qualche classe Bootstrap. Invece di
 * riscrivere quelle 1350 righe, in fondo al foglio di stile c'è un piccolo
 * livello di compatibilità che ridisegna quelle classi con i colori di qui.
 */
require __DIR__ . '/../_ui.php';
require __DIR__ . '/validation/validate_tables_json.php';

$checker = new ValidateTablesJson();

/**
 * Il checker stampa mentre analizza, quindi il risultato va bufferizzato per
 * poter mostrare il riepilogo prima dell'elenco.
 */
$checker->printTypeHtml();

ob_start();
$checker->validate();
$analysisHtml = ob_get_clean();
$errorCount = $checker->getErrorCount();

ob_start();
$checker->orphansTables();
$orphansHtml = ob_get_clean();
$orphansCount = $checker->getOrphansCount();

$levelsPermissions   = $checker->getLevelsPermissions();
$entitiesPermissions = $checker->getEntitiesPermissions();
$entityLanguage      = $checker->getEntityLanguage();
$unknownStrings      = $checker->getUnknownEntityLanguegesStrings();

/** Simbolo di un permesso. */
function permIcon($value): string
{
    if (!isset($value))      return '<span class="pi no" title="nessun permesso">—</span>';
    if ($value === true)     return '<span class="pi yes" title="tutti i record">●</span>';
    if ($value === 'group')  return '<span class="pi grp" title="record del gruppo">◐</span>';
    if ($value === 'user')   return '<span class="pi usr" title="record dell\'utente">◔</span>';
    return '<span class="pi no" title="nessun permesso">—</span>';
}

/** Righe di una tabella di permessi: [etichetta, [azione => valore]]. */
function permRows(array $rows): string
{
    $out = '';
    foreach ($rows as $label => $permissions) {
        $out .= '<tr><th>' . ckd_e((string) $label) . '</th>';
        foreach ($permissions as $permission) {
            $out .= '<td class="c">' . permIcon(current($permission)) . '</td>';
        }
        $out .= '</tr>';
    }
    return $out;
}

ckd_head('Validatore tables.json', '../index.php');
?>
<style>
    /* schede */
    .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin: 0 0 22px; flex-wrap: wrap; }
    .tabs button {
        border: 0; border-bottom: 2px solid transparent; background: none;
        color: var(--text-soft); font: inherit; font-size: 14px; font-weight: 500;
        padding: 9px 13px; cursor: pointer; margin-bottom: -1px;
    }
    .tabs button:hover { color: var(--text); }
    .tabs button[aria-selected="true"] { color: var(--accent); border-bottom-color: var(--accent); }
    .tabs .n {
        font-size: 11px; padding: 1px 6px; border-radius: 20px; margin-left: 6px;
        border: 1px solid var(--border); color: var(--text-soft);
    }
    .tabs .n.bad { color: var(--danger); border-color: color-mix(in srgb, var(--danger) 35%, transparent); }
    .panel[hidden] { display: none; }

    /* accordion nativo */
    details.acc {
        border: 1px solid var(--border); border-radius: var(--radius);
        margin-bottom: 10px; overflow: hidden;
    }
    details.acc > summary {
        padding: 11px 15px; background: var(--bg-soft); cursor: pointer;
        font-weight: 600; font-size: 14px; list-style: none;
    }
    details.acc > summary::-webkit-details-marker { display: none; }
    details.acc > summary::before { content: '▸'; color: var(--text-soft); margin-right: 9px; }
    details.acc[open] > summary::before { content: '▾'; }
    details.acc > summary:hover { color: var(--accent); }

    td.c, th.c { text-align: center; }
    .pi { font-size: 15px; line-height: 1; }
    .pi.yes { color: var(--ok); }
    .pi.grp { color: var(--accent); }
    .pi.usr { color: var(--warn); }
    .pi.no  { color: var(--text-soft); opacity: .5; }
    .legend { font-size: 12.5px; color: var(--text-soft); margin: 0 0 14px; }
    .legend .pi { margin: 0 3px 0 12px; }
    .legend .pi:first-child { margin-left: 0; }

    /* editor entity_language */
    textarea#jsonEditor {
        width: 100%; min-height: 55vh; padding: 13px 15px;
        border: 1px solid var(--border); border-radius: var(--radius);
        background: var(--bg-code); color: var(--text);
        font-family: var(--mono); font-size: 12.5px; line-height: 1.6;
        resize: vertical; white-space: pre; overflow-wrap: normal; overflow-x: auto;
    }
    textarea#jsonEditor:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
    .replace-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
    .replace-row label { margin: 0; font-family: var(--mono); font-size: 12.5px; min-width: 190px; font-weight: 500; }
    .replace-row input[type=text] {
        flex: 1 1 180px; padding: 6px 10px; border: 1px solid var(--border);
        border-radius: 7px; background: var(--bg); color: var(--text); font: inherit; font-size: 13px;
    }
    .replace-row.done { display: none; }
    .toolbar { display: flex; gap: 8px; justify-content: flex-end; margin-bottom: 12px; flex-wrap: wrap; }

    /* ------------------------------------------------------------------
       Compatibilità con l'HTML prodotto da ValidateTablesJson: quelle poche
       classi Bootstrap ridisegnate con i colori di questa pagina.
       ------------------------------------------------------------------ */
    .list-group { display: block; }
    .list-group-item {
        display: flex; gap: 14px; padding: 13px 15px; align-items: flex-start;
        border: 1px solid var(--border); border-bottom: 0; background: var(--bg);
        font-size: 14px;
    }
    .list-group-item:first-child { border-radius: var(--radius) var(--radius) 0 0; }
    .list-group-item:last-child { border-bottom: 1px solid var(--border); border-radius: 0 0 var(--radius) var(--radius); }
    .list-group-item:only-child { border-radius: var(--radius); }
    .list-group-item.danger { border-left: 3px solid var(--danger); }
    .list-group-item span[style*="uppercase"] { font-weight: 600; letter-spacing: .01em; }
    .list-group-item svg { width: 20px; height: 20px; margin-top: 2px; }
    .d-flex { display: flex; }
    .flex-column { flex-direction: column; }
    .flex-shrink-0 { flex-shrink: 0; }
    .align-items-center { align-items: center; }
    .justify-content-between { justify-content: space-between; }
    .w-100, .w-full { width: 100%; }
    .gap-2 { gap: 8px; }
    .gap-3 { gap: 12px; }
    .py-3 { padding-top: 13px; padding-bottom: 13px; }
    .my-2 { margin: 10px 0; }
    .pt-1 { padding-top: 4px; }

    /* Il checker raggruppa i messaggi per tabella in un accordion Bootstrap.
       Qui viene ridisegnato come quelli nativi della scheda Permessi, e più
       sotto c'è il poco JavaScript che lo apre e chiude. */
    .accordion-item {
        border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden;
    }
    .accordion-header { margin: 0; }
    .accordion-button {
        display: flex; align-items: center; width: 100%;
        padding: 11px 15px; border: 0; background: var(--bg-soft);
        color: var(--text); font: inherit; font-size: 14px; font-weight: 600;
        text-align: left; cursor: pointer;
    }
    .accordion-button:hover { color: var(--accent); }
    .accordion-button::before { content: '▾'; color: var(--text-soft); margin-right: 9px; }
    .accordion-button.collapsed::before { content: '▸'; }
    .accordion-button p { margin: 0 0 0 10px; font-weight: 400; font-size: 13px; color: var(--text-soft); }
    .accordion-collapse { display: none; }
    .accordion-collapse.show { display: block; }
    .accordion-body { padding: 4px 0 0; }
    .accordion-body .list-group-item { border-left: 0; border-right: 0; border-radius: 0; }
    .accordion-body .list-group-item:last-child { border-bottom: 0; }
    /* Gruppo che contiene almeno un errore: intestazione in rosso. */
    .accordion:has(.danger) > .accordion-item { border-color: color-mix(in srgb, var(--danger) 40%, var(--border)); }
    .accordion:has(.danger) .accordion-button { color: var(--danger); }
</style>

<h1>Validatore tables.json</h1>
<p class="sub">Controlla <code>config/tables.json</code>: struttura, permessi, entità orfane e traduzioni.</p>

<div class="tabs" role="tablist">
    <button role="tab" aria-selected="true" data-panel="analisi">
        Analisi<span class="n <?= $errorCount ? 'bad' : '' ?>"><?= (int) $errorCount ?></span>
    </button>
    <button role="tab" aria-selected="false" data-panel="permessi">Permessi</button>
    <button role="tab" aria-selected="false" data-panel="orfane">
        Entità orfane<span class="n"><?= (int) $orphansCount ?></span>
    </button>
    <button role="tab" aria-selected="false" data-panel="lingua">Traduzioni</button>
</div>

<!-- ANALISI -->
<section class="panel" id="panel-analisi">
    <?php if ($errorCount === 0): ?>
        <div class="note ok"><b>Nessun errore.</b> Il file <code>tables.json</code> è formalmente corretto.</div>
    <?php else: ?>
        <div class="note danger"><b><?= (int) $errorCount ?></b> error<?= $errorCount === 1 ? 'e trovato' : 'i trovati' ?>.</div>
    <?php endif; ?>
    <div class="list-group"><?= $analysisHtml ?></div>
</section>

<!-- PERMESSI -->
<section class="panel" id="panel-permessi" hidden>
    <p class="legend">
        <span class="pi yes">●</span> tutti i record
        <span class="pi grp">◐</span> del gruppo
        <span class="pi usr">◔</span> dell'utente
        <span class="pi no">—</span> nessuno
    </p>

    <h2>Per livello</h2>
    <div class="toolbar">
        <button class="btn" data-acc="livelli" data-open="1">Espandi tutto</button>
        <button class="btn" data-acc="livelli" data-open="0">Comprimi tutto</button>
    </div>
    <?php foreach ($levelsPermissions as $level => $permissions): ?>
        <details class="acc" data-group="livelli" open>
            <summary><?= ckd_e((string) $level) ?></summary>
            <div class="table-scroll">
                <table class="grid">
                    <thead><tr><th>Entità</th><th class="c">Lettura</th><th class="c">Creazione</th><th class="c">Modifica</th><th class="c">Rimozione</th></tr></thead>
                    <tbody><?= permRows($permissions) ?></tbody>
                </table>
            </div>
        </details>
    <?php endforeach; ?>

    <h2>Per entità</h2>
    <div class="toolbar">
        <button class="btn" data-acc="entita" data-open="1">Espandi tutto</button>
        <button class="btn" data-acc="entita" data-open="0">Comprimi tutto</button>
    </div>
    <?php foreach ($entitiesPermissions as $entity => $levels): ?>
        <details class="acc" data-group="entita" open>
            <summary><?= ckd_e((string) $entity) ?></summary>
            <div class="table-scroll">
                <table class="grid">
                    <thead><tr><th>Livello</th><th class="c">Lettura</th><th class="c">Creazione</th><th class="c">Modifica</th><th class="c">Rimozione</th></tr></thead>
                    <tbody><?= permRows($levels) ?></tbody>
                </table>
            </div>
        </details>
    <?php endforeach; ?>
</section>

<!-- ORFANE -->
<section class="panel" id="panel-orfane" hidden>
    <?php if ($orphansCount === 0): ?>
        <div class="note ok"><b>Nessuna entità orfana.</b> Ogni entità è collegata ad almeno un'altra.</div>
    <?php else: ?>
        <div class="note warn">
            <b><?= (int) $orphansCount ?></b> entità non collegat<?= $orphansCount === 1 ? 'a' : 'e' ?> a nessun'altra.
            Non è necessariamente un errore: le tabelle di sola configurazione lo sono per natura.
        </div>
    <?php endif; ?>
    <div class="list-group"><?= $orphansHtml ?></div>
</section>

<!-- TRADUZIONI -->
<section class="panel" id="panel-lingua" hidden>
    <p class="sub" style="margin-bottom:14px">
        Contenuto generato per <code>entity_language.js</code> del frontend.
        Le voci non ancora tradotte sono elencate sotto: compila e sostituisci,
        poi copia il risultato nel progetto React.
    </p>

    <div class="toolbar">
        <button class="btn" id="copy-json">Copia</button>
    </div>
    <textarea id="jsonEditor" spellcheck="false"><?= ckd_e($entityLanguage) ?></textarea>

    <h2>Da tradurre<?= $unknownStrings ? ' (' . count($unknownStrings) . ')' : '' ?></h2>
    <?php if (!$unknownStrings): ?>
        <div class="note ok"><b>Niente da tradurre.</b> Tutte le stringhe hanno già una traduzione.</div>
    <?php else: ?>
        <div class="toolbar">
            <button class="btn primary" id="replace-all">Sostituisci tutto</button>
        </div>
        <?php foreach ($unknownStrings as $index => $search): ?>
            <div class="replace-row" data-row="<?= ckd_e($search . '-' . $index) ?>">
                <label for="<?= ckd_e($search . '-' . $index) ?>"><?= ckd_e($search) ?></label>
                <input type="text" id="<?= ckd_e($search . '-' . $index) ?>"
                       data-search="<?= ckd_e($search) ?>" placeholder="<?= ckd_e($search) ?>">
                <button class="btn" data-replace="<?= ckd_e($search . '-' . $index) ?>">Sostituisci</button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<script>
    // schede
    var tabs = [].slice.call(document.querySelectorAll('.tabs button[data-panel]'));
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) {
                var on = t === tab;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                document.getElementById('panel-' + t.dataset.panel).hidden = !on;
            });
        });
    });

    // Accordion prodotti dal checker: Bootstrap li apriva con data-bs-toggle,
    // qui la stessa cosa in una manciata di righe.
    document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function (btn) {
        var target = document.querySelector(btn.dataset.bsTarget);
        if (!target) return;
        btn.addEventListener('click', function () {
            var open = target.classList.toggle('show');
            btn.classList.toggle('collapsed', !open);
        });
    });

    // I gruppi che contengono un errore partono aperti: sono quelli da leggere.
    document.querySelectorAll('.accordion').forEach(function (acc) {
        if (!acc.querySelector('.danger')) return;
        var body = acc.querySelector('.accordion-collapse');
        var head = acc.querySelector('.accordion-button');
        if (body) body.classList.add('show');
        if (head) head.classList.remove('collapsed');
    });

    // espandi / comprimi tutto
    document.querySelectorAll('button[data-acc]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('details.acc[data-group="' + btn.dataset.acc + '"]')
                .forEach(function (d) { d.open = btn.dataset.open === '1'; });
        });
    });

    // entity_language: sostituzioni, con le scelte ricordate nel browser
    var editor = document.getElementById('jsonEditor');
    var STORE = 'entity_language';
    var saved = JSON.parse(localStorage.getItem(STORE) || '{}');

    document.querySelectorAll('.replace-row input[type=text]').forEach(function (input) {
        var previous = saved[input.dataset.search];
        if (previous) input.value = previous;
    });

    function applyReplacement(rowId) {
        var input = document.getElementById(rowId);
        if (!input || !input.value) return false;
        var search = input.dataset.search;
        editor.value = editor.value.split(search).join(input.value);
        document.querySelector('.replace-row[data-row="' + CSS.escape(rowId) + '"]').classList.add('done');
        saved[search] = input.value;
        localStorage.setItem(STORE, JSON.stringify(saved));
        return true;
    }

    document.querySelectorAll('button[data-replace]').forEach(function (btn) {
        btn.addEventListener('click', function () { applyReplacement(btn.dataset.replace); });
    });

    var replaceAll = document.getElementById('replace-all');
    if (replaceAll) {
        replaceAll.addEventListener('click', function () {
            document.querySelectorAll('.replace-row input[type=text]').forEach(function (input) {
                applyReplacement(input.id);
            });
        });
    }

    document.getElementById('copy-json').addEventListener('click', function () {
        var btn = this;
        navigator.clipboard.writeText(editor.value).then(function () {
            btn.textContent = 'Copiato';
            setTimeout(function () { btn.textContent = 'Copia'; }, 1200);
        });
    });
</script>

<?php ckd_foot(); ?>
