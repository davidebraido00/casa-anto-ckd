<?php
require __DIR__ . '/_ui.php';

$settings = ckd_settings();
$dbName   = $settings['db']['name']   ?? '';
$dbHost   = $settings['db']['server'] ?? '';
$tables   = ckd_tables();
$version  = ckd_version();

$tableCount = 0;
$viewCount  = 0;
foreach (array_keys($tables) as $name) {
    ckd_is_view($name) ? $viewCount++ : $tableCount++;
}

ckd_head('Utils', null);
?>

<h1>Utils</h1>
<p class="sub">
    <?= $dbName ? 'Database <code>' . ckd_e($dbName) . '</code>' . ($dbHost ? ' su <code>' . ckd_e($dbHost) . '</code>' : '') : 'Database non configurato' ?>
    <?= $version ? ' · framework v' . ckd_e($version) : '' ?>
</p>

<div class="note warn">
    Questa cartella è del framework: viene <b>sostituita in blocco</b> a ogni
    allineamento. Non modificare nulla qui dentro, <code>.htaccess</code>
    compreso — le modifiche andrebbero perse.
</div>

<div class="card">
    <header>
        <h3>Validatore tables.json</h3>
        <span class="tag"><?= $tableCount ?> tabell<?= $tableCount === 1 ? 'a' : 'e' ?><?= $viewCount ? ', ' . $viewCount . ' vist' . ($viewCount === 1 ? 'a' : 'e') : '' ?></span>
    </header>
    <div class="body">
        <p>Verifica che <code>config/tables.json</code> sia formalmente corretto e
        controlla permessi, entità orfane e traduzioni.</p>
        <a class="btn" href="tablesJsonValidation/validate.php">Verifica</a>
    </div>
</div>

<div class="card">
    <header>
        <h3>Aggiorna il database</h3>
        <span class="tag ok">non distruttivo</span>
    </header>
    <div class="body">
        <p>Crea le tabelle mancanti e aggiunge le colonne nuove definite in
        <code>tables.json</code>. Non tocca i dati esistenti e non rimuove
        colonne, nemmeno quelle sparite dal json.</p>
        <a class="btn primary" href="db/updatedb.php">Aggiorna</a>
    </div>
</div>

<div class="card danger">
    <header>
        <h3>Ricrea il database</h3>
        <span class="tag danger">cancella tutti i dati</span>
    </header>
    <div class="body">
        <p>Elimina e ricrea da zero tutte le tabelle definite in
        <code>tables.json</code>. <b>Tutti i dati esistenti vengono persi.</b>
        Richiede la password del database.</p>
        <a class="btn danger" href="db/createdb.php">Ricrea</a>
    </div>
</div>

<?php ckd_foot(); ?>
