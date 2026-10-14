<?php
/**
 * Ricrea da zero tutte le tabelle definite in config/tables.json.
 *
 * Operazione distruttiva: fa DROP di ogni tabella prima di ricrearla, quindi
 * cancella tutti i dati. Per questo richiede la password del database, chiesta
 * con un POST e non passata in query string: un ?psw=... finirebbe nella
 * cronologia del browser, nei log di Apache e nell'header Referer.
 */
require __DIR__ . '/../_ui.php';

$settings   = ckd_settings();
$dbPassword = (string) ($settings['db']['password'] ?? '');
$dbName     = $settings['db']['name'] ?? '';
$tables     = ckd_tables();

$submitted = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$confirmed = $submitted && isset($_POST['confirm']);
$given     = (string) ($_POST['password'] ?? '');

// hash_equals: confronto a tempo costante, non rivela la password un carattere
// alla volta. Una password vuota in configurazione non autorizza nulla.
$authorized = $submitted && $dbPassword !== '' && hash_equals($dbPassword, $given);

$error = null;
if ($submitted && !$authorized) {
    $error = $dbPassword === ''
        ? "Nessuna password configurata in config/settings.json: impossibile autorizzare l'operazione."
        : 'Password errata.';
} elseif ($submitted && !$confirmed) {
    $error = 'Devi confermare di aver capito che tutti i dati verranno cancellati.';
}

$run = $authorized && $confirmed;

ckd_head('Ricrea il database', '../index.php');
?>

<h1>Ricrea il database</h1>
<p class="sub">
    <?= $dbName ? 'Database <code>' . ckd_e($dbName) . '</code>' : 'Database non configurato' ?>
    · <?= count($tables) ?> entità in <code>tables.json</code>
</p>

<?php if (!$run): ?>

    <div class="note danger">
        <b>Tutti i dati vengono persi.</b> Ogni tabella definita in
        <code>tables.json</code> viene eliminata e ricreata vuota.
        <ul>
            <li>Le viste (<code>gyv_*</code>) non vengono toccate.</li>
            <li>Al termine vengono inseriti il gruppo e l'utente di esempio.</li>
            <li>Non c'è modo di annullare: se ti serve, fai prima un dump.</li>
        </ul>
    </div>

    <?php if ($dbPassword === ''): ?>

        <div class="note danger">
            <b>Operazione non disponibile: <code>db.password</code> è vuota in
            <code>config/settings.json</code>.</b>
            Senza una password non c'è nulla con cui autorizzare un'operazione che
            cancella tutti i dati, quindi la pagina si ferma qui. Imposta la
            password del database nella configurazione e ricarica.
        </div>
        <a class="btn" href="../index.php">Torna a Utils</a>

    <?php else: ?>

    <?php if ($error): ?>
        <div class="note danger"><b><?= ckd_e($error) ?></b></div>
    <?php endif; ?>

    <div class="card danger">
        <header><h3>Conferma</h3></header>
        <div class="body">
            <form method="post" autocomplete="off">
                <label for="password">Password del database</label>
                <input type="password" id="password" name="password" autocomplete="new-password" required autofocus>
                <p class="hint">È la password in <code>config/settings.json</code>, sotto <code>db.password</code>.</p>

                <div class="check">
                    <input type="checkbox" id="confirm" name="confirm" value="1" required>
                    <label for="confirm" style="font-weight:400;margin:0">
                        Ho capito che tutte le tabelle vengono eliminate e che i dati non sono recuperabili.
                    </label>
                </div>

                <button type="submit" class="btn danger">Elimina e ricrea le tabelle</button>
                <a class="btn" href="../index.php">Annulla</a>
            </form>
        </div>
    </div>

    <?php endif; ?>

<?php else: ?>

    <?php
    try {
        $conn = ckd_db();
    } catch (PDOException $e) {
        echo '<div class="note danger"><b>Connessione al database fallita.</b><br>' . ckd_e($e->getMessage()) . '</div>';
        ckd_foot();
        exit;
    }

    $defaultKeys = ['id', 'date', 'lastUpdate', 'deleted'];
    $created = 0;
    $skipped = 0;
    $failures = [];
    $warnings = [];

    /** Esegue una istruzione e registra l'eventuale errore senza fermare la pagina. */
    $exec = function (string $sql, string $where) use ($conn, &$failures): bool {
        try {
            $conn->prepare($sql)->execute();
            return true;
        } catch (PDOException $e) {
            $failures[] = $where . ' — ' . $e->getMessage();
            return false;
        }
    };

    foreach ($tables as $entity => $definition) {
        $columns = $definition['columns'] ?? [];
        $isView  = ckd_is_view($entity);

        if (!$isView) {
            $exec("DROP TABLE IF EXISTS `{$entity}`;", $entity);
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
            $created++;
        } else {
            $skipped++;
        }
        ?>
        <div class="card">
            <header>
                <h3><?= ckd_e($entity) ?></h3>
                <span class="tag"><?= count($columns) ?> camp<?= count($columns) === 1 ? 'o' : 'i' ?></span>
                <span class="spacer"></span>
                <span class="tag <?= $isView ? 'warn' : 'ok' ?>"><?= $isView ? 'vista, non toccata' : 'ricreata' ?></span>
            </header>
            <div class="table-scroll">
                <table class="grid">
                    <thead>
                        <tr><th>Campo</th><th>Tipo</th><th>Lunghezza</th><th>Null</th><th>Unique</th><th>Default</th><th>Esito</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($columns as $name => $field): ?>
                        <?php
                        $c = ckd_column($field);
                        $outcome = '—';

                        if (!$isView && !in_array($name, $defaultKeys, true) && $c['type'] !== 'NONE') {
                            $outcome = [
                                'ok'        => '<span class="tag ok">creata</span>',
                                'nodefault' => '<span class="tag warn">creata senza default</span>',
                                'error'     => '<span class="tag danger">errore</span>',
                            ][ckd_add_column($conn, $entity, $name, $c, $failures, $warnings)];
                        } elseif (in_array($name, $defaultKeys, true)) {
                            $outcome = '<span class="tag">di sistema</span>';
                        }
                        ?>
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
    }

    /** Gruppo e utente di esempio, per poter entrare subito nel backoffice. */
    $seeded = $exec(
        "INSERT INTO `user_group` (`id`, `date`, `lastUpdate`, `name`) "
        . "VALUES (1, '2023-06-15 08:13:25', '2023-06-15 13:47:16', 'YEC');",
        'seed user_group'
    );
    $seeded = $exec(
        "INSERT INTO `user` (`id`, `date`, `lastUpdate`, `name`, `surname`, `username`, `email`, `avatar`, `password`, `level`, `idUserGroup`) "
        . "VALUES (1, '2023-06-15 08:13:25', '2023-06-15 13:47:16', 'marco', 'yec', 'marco.yec', 'marco@gruppoyec.com', 6, '37c0c054e5635618ff25daa593db7e3399ad0031', 0, 1);",
        'seed user'
    ) && $seeded;
    ?>

    <h2>Riepilogo</h2>
    <div class="note <?= $failures ? 'danger' : 'ok' ?>">
        <b><?= $created ?></b> tabell<?= $created === 1 ? 'a ricreata' : 'e ricreate' ?>,
        <b><?= $skipped ?></b> vist<?= $skipped === 1 ? 'a lasciata' : 'e lasciate' ?> intatt<?= $skipped === 1 ? 'a' : 'e' ?>,
        dati di esempio <?= $seeded ? 'inseriti' : '<b>non inseriti</b>' ?>.
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
    <a class="btn" href="../index.php">Torna a Utils</a>

<?php endif; ?>

<?php ckd_foot(); ?>
