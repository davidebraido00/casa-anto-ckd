<?php
/**
 * Allinea il database a config/tables.json senza distruggere niente.
 *
 * Crea le tabelle mancanti e aggiunge le colonne nuove. Non modifica le colonne
 * esistenti e non ne rimuove nessuna, nemmeno quelle sparite dal json: per
 * quelle serve intervenire a mano con un client MySQL.
 */
require __DIR__ . '/../_ui.php';

$settings = ckd_settings();
$dbName   = $settings['db']['name'] ?? '';
$tables   = ckd_tables();

ckd_head('Aggiorna il database', '../index.php');
?>

<h1>Aggiorna il database</h1>
<p class="sub">
    <?= $dbName ? 'Database <code>' . ckd_e($dbName) . '</code>' : 'Database non configurato' ?>
    · <?= count($tables) ?> entità in <code>tables.json</code>
</p>

<?php
try {
    $conn = ckd_db();
} catch (PDOException $e) {
    echo '<div class="note danger"><b>Connessione al database fallita.</b><br>' . ckd_e($e->getMessage()) . '</div>';
    ckd_foot();
    exit;
}

$defaultKeys   = ['id', 'date', 'lastUpdate', 'deleted'];
$addedTotal    = 0;
$tablesTouched = [];
$failures      = [];
$warnings      = [];

$exec = function (string $sql, string $where) use ($conn, &$failures): bool {
    try {
        $conn->prepare($sql)->execute();
        return true;
    } catch (PDOException $e) {
        $failures[] = $where . ' — ' . $e->getMessage();
        return false;
    }
};

/** La colonna esiste già nel database? */
$columnExists = function (string $entity, string $column) use ($conn, $dbName): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column"
    );
    $stmt->execute([':schema' => $dbName, ':table' => $entity, ':column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
};

foreach ($tables as $entity => $definition):
    $columns = $definition['columns'] ?? [];
    $isView  = ckd_is_view($entity);
    $added   = 0;

    if (!$isView) {
        $exec(
            "CREATE TABLE IF NOT EXISTS `{$entity}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `date` timestamp NOT NULL DEFAULT current_timestamp(),
                `lastUpdate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                `deleted` int(2) NOT NULL DEFAULT 0,
                PRIMARY KEY(id)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
            $entity
        );
    }

    // Le righe vengono raccolte prima di stamparle, così l'intestazione può
    // dire subito quante colonne sono state aggiunte.
    $rows = [];
    foreach ($columns as $name => $field) {
        $c = ckd_column($field);
        $outcome = '<span class="tag">di sistema</span>';

        if ($isView) {
            $outcome = '—';
        } elseif (!in_array($name, $defaultKeys, true) && $c['type'] !== 'NONE') {
            if ($columnExists($entity, $name)) {
                $outcome = '<span class="tag">già presente</span>';
            } else {
                $result = ckd_add_column($conn, $entity, $name, $c, $failures, $warnings);
                if ($result === 'error') {
                    $outcome = '<span class="tag danger">errore</span>';
                } else {
                    $outcome = $result === 'ok'
                        ? '<span class="tag ok">aggiunta</span>'
                        : '<span class="tag warn">aggiunta senza default</span>';
                    $added++;
                    $addedTotal++;
                }
            }
        }

        $rows[] = [$name, $c, $outcome];
    }

    if ($added > 0) {
        $tablesTouched[] = $entity;
    }
    ?>
    <div class="card">
        <header>
            <h3><?= ckd_e($entity) ?></h3>
            <span class="tag"><?= count($columns) ?> camp<?= count($columns) === 1 ? 'o' : 'i' ?></span>
            <span class="spacer"></span>
            <?php if ($isView): ?>
                <span class="tag warn">vista, non toccata</span>
            <?php elseif ($added > 0): ?>
                <span class="tag ok"><?= $added ?> colonn<?= $added === 1 ? 'a aggiunta' : 'e aggiunte' ?></span>
            <?php else: ?>
                <span class="tag">nessuna modifica</span>
            <?php endif; ?>
        </header>
        <div class="table-scroll">
            <table class="grid">
                <thead>
                    <tr><th>Campo</th><th>Tipo</th><th>Lunghezza</th><th>Null</th><th>Unique</th><th>Default</th><th>Esito</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as [$name, $c, $outcome]): ?>
                    <tr>
                        <th><?= ckd_e($name) ?></th>
                        <td><?= ckd_e($c['type']) ?></td>
                        <td><?= ckd_e($c['length']) ?></td>
                        <td><?= ckd_e($c['null']) ?></td>
                        <td><?= $c['unique'] ? 'sì' : '' ?></td>
                        <td><?= ckd_e($c['default']) ?></td>
                        <td><?= $outcome ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
endforeach;
?>

<h2>Riepilogo</h2>
<div class="note <?= $failures ? 'danger' : 'ok' ?>">
    <?php if ($addedTotal === 0 && !$failures): ?>
        <b>Database già allineato.</b> Nessuna colonna da aggiungere.
    <?php else: ?>
        <b><?= $addedTotal ?></b> colonn<?= $addedTotal === 1 ? 'a aggiunta' : 'e aggiunte' ?><?=
            $tablesTouched ? ' in ' . ckd_e(implode(', ', $tablesTouched)) : '' ?>.
    <?php endif; ?>
    <?php if ($failures): ?>
        <ul>
            <?php foreach ($failures as $f): ?>
                <li><?= ckd_e($f) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php if ($warnings): ?>
    <div class="note warn">
        <b>Default non applicati.</b> Le colonne esistono ma senza il default
        dichiarato in <code>tables.json</code>: correggi il valore nel json.
        <ul>
            <?php foreach ($warnings as $w): ?>
                <li><?= ckd_e($w) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<p class="hint">Le colonne rimosse da <code>tables.json</code> restano nel
database: se serve vanno eliminate a mano con un client MySQL.</p>
<a class="btn" href="../index.php">Torna a Utils</a>

<?php ckd_foot(); ?>
