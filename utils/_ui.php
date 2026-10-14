<?php
/**
 * Stile e helper condivisi dalle pagine di utils/.
 *
 * Autonomo: nessuna CDN, nessuna dipendenza. Stessi token di docs/api.php, così
 * le due sezioni si assomigliano. I path della configurazione sono risolti con
 * __DIR__, quindi valgono sia per utils/index.php sia per utils/db/*.php.
 */

function ckd_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function ckd_settings(): array
{
    return @json_decode(@file_get_contents(__DIR__ . '/../config/settings.json'), true) ?: [];
}

function ckd_tables(): array
{
    return @json_decode(@file_get_contents(__DIR__ . '/../config/tables.json'), true) ?: [];
}

function ckd_version(): string
{
    $v = @json_decode(@file_get_contents(__DIR__ . '/../version.json'), true) ?: [];
    return $v['version'] ?? '';
}

/** Connessione al database del progetto. Lancia PDOException se fallisce. */
function ckd_db(): PDO
{
    $db = ckd_settings()['db'] ?? [];
    $conn = new PDO(
        'mysql:host=' . ($db['server'] ?? '') . ';dbname=' . ($db['name'] ?? '') . ';charset=utf8mb4',
        $db['username'] ?? '',
        $db['password'] ?? '',
        [PDO::MYSQL_ATTR_LOCAL_INFILE => true]
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $conn;
}

/**
 * Le viste non vanno create né alterate: nel framework sono le entità il cui
 * nome inizia con gyv_.
 */
function ckd_is_view(string $entity): bool
{
    return strpos($entity, 'gyv_') === 0;
}

/** Descrizione di una colonna a partire dalla sua definizione in tables.json. */
function ckd_column(array $field): array
{
    $length = (isset($field['length']) && $field['length'] != 'null') ? '(' . $field['length'] . ')' : '';
    $type   = $field['type'] ?? 'VARCHAR';
    // i tipi carattere portano la codifica sulla singola colonna: così la
    // colonna è utf8mb4 anche se la tabella o il database hanno un default
    // vecchio (latin1/utf8mb3)
    $charTypes = ['CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'ENUM', 'SET'];
    $charset = in_array(strtoupper($type), $charTypes, true)
        ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        : '';
    $defaultSql = ckd_default_sql($type, $field['default'] ?? null);
    return [
        'type'       => $type,
        'length'     => $length,
        'charset'    => $charset,
        'null'       => (($field['null'] ?? true) === true) ? 'NULL' : 'NOT NULL',
        // il letterale come finisce nella SQL: CURRENT_TIMESTAMP, 0, '2'...
        'default'    => $defaultSql === '' ? '' : substr($defaultSql, strlen(' DEFAULT ')),
        'defaultSql' => $defaultSql,
        // "unique": false non deve rendere la colonna unica: si guarda il
        // valore, non la sola presenza della chiave. Valgono true, 1, "true", "1".
        'unique'     => filter_var($field['unique'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ];
}

/**
 * Clausola DEFAULT per la colonna, stringa vuota se non va emessa.
 *
 * - null o valore non scalare: nessun default (la colonna nullable ha già
 *   DEFAULT NULL implicito).
 * - TEXT/BLOB/JSON: MySQL non ammette un default, la clausola viene omessa.
 * - CURRENT_TIMESTAMP, NOW(), CURRENT_DATE e simili: passati come parola
 *   chiave, senza apici.
 * - numeri e booleani: senza apici (true/false diventano 1/0).
 * - tutto il resto: stringa quotata ed escapata.
 */
function ckd_default_sql(string $type, $default): string
{
    if ($default === null || !is_scalar($default)) {
        return '';
    }
    $noDefaultTypes = ['TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB', 'JSON', 'GEOMETRY'];
    if (in_array(strtoupper($type), $noDefaultTypes, true)) {
        return '';
    }
    if (is_bool($default)) {
        return ' DEFAULT ' . ($default ? '1' : '0');
    }
    if (is_int($default) || is_float($default)) {
        return ' DEFAULT ' . $default;
    }
    $value = (string) $default;
    if (preg_match('/^(CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|LOCALTIME|LOCALTIMESTAMP|NOW|UTC_TIMESTAMP)(\(\d*\))?$/i', $value)
        || strcasecmp($value, 'NULL') === 0) {
        return ' DEFAULT ' . strtoupper($value);
    }
    return " DEFAULT '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
}

/**
 * Definizione SQL della colonna (quello che segue il nome in ADD COLUMN).
 * Con $withDefault a false la clausola DEFAULT viene lasciata fuori: serve al
 * secondo tentativo quando il default dichiarato nel json non è accettato dal
 * database.
 */
function ckd_column_definition(array $c, bool $withDefault = true): string
{
    return $c['type'] . $c['length'] . $c['charset']
        . ' ' . $c['null']
        . ($c['unique'] ? ' UNIQUE' : '')
        . ($withDefault ? $c['defaultSql'] : '');
}

/**
 * Aggiunge la colonna alla tabella. Se il DEFAULT viene rifiutato riprova
 * senza: meglio una colonna senza default che nessuna colonna.
 *
 * Ritorna 'ok', 'nodefault' (creata al secondo tentativo, il messaggio del
 * primo errore finisce in $warnings) oppure 'error' (messaggio in $failures).
 */
function ckd_add_column(PDO $conn, string $entity, string $name, array $c, array &$failures, array &$warnings): string
{
    $where = "{$entity}.{$name}";
    try {
        $conn->prepare("ALTER TABLE `{$entity}` ADD COLUMN `{$name}` " . ckd_column_definition($c) . ';')->execute();
        return 'ok';
    } catch (PDOException $e) {
        if ($c['defaultSql'] === '') {
            $failures[] = $where . ' — ' . $e->getMessage();
            return 'error';
        }
        $firstError = $e->getMessage();
    }
    try {
        $conn->prepare("ALTER TABLE `{$entity}` ADD COLUMN `{$name}` " . ckd_column_definition($c, false) . ';')->execute();
        $warnings[] = $where . ' — creata senza default, il valore ' . $c['default'] . ' è stato rifiutato: ' . $firstError;
        return 'nodefault';
    } catch (PDOException $e) {
        $failures[] = $where . ' — ' . $e->getMessage();
        return 'error';
    }
}

/**
 * Apre la pagina. $back è il link della freccia indietro, null per la home.
 */
function ckd_head(string $title, ?string $back = 'index.php'): void
{
    $version = ckd_version();
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= ckd_e($title) ?> · Utils</title>
<style>
    :root {
        --bg: #ffffff; --bg-soft: #f6f7f9; --bg-code: #f2f4f7;
        --text: #1a1d21; --text-soft: #5c6570; --border: #e3e6ea;
        --accent: #2f6df6; --ok: #1a7f4b; --warn: #b26a00; --danger: #c0392b;
        --radius: 10px;
        --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #14171a; --bg-soft: #1b1f24; --bg-code: #1e2329;
            --text: #e6e9ec; --text-soft: #98a2ad; --border: #2a3038;
            --accent: #6ea0ff; --ok: #4ec98a; --warn: #e0a458; --danger: #ff7a6b;
        }
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; background: var(--bg); color: var(--text);
        font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
    }
    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }
    code { font-family: var(--mono); font-size: 0.9em; }

    .wrap { max-width: 940px; margin: 0 auto; padding: 28px 24px 96px; }
    .back { font-size: 13.5px; color: var(--text-soft); display: inline-block; margin-bottom: 18px; }
    .back:hover { color: var(--text); text-decoration: none; }
    h1 { font-size: 26px; margin: 0 0 4px; letter-spacing: -0.02em; }
    .sub { color: var(--text-soft); margin: 0 0 30px; font-size: 14px; }
    h2 { font-size: 17px; margin: 34px 0 14px; letter-spacing: -0.01em; }

    /* card */
    .card {
        border: 1px solid var(--border); border-radius: var(--radius);
        margin-bottom: 14px; overflow: hidden;
    }
    .card > header {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        padding: 12px 16px; background: var(--bg-soft); border-bottom: 1px solid var(--border);
    }
    .card h3 { margin: 0; font-size: 15px; font-weight: 600; }
    .card .body { padding: 16px; }
    .card p { margin: 0 0 14px; color: var(--text-soft); font-size: 14px; }
    .card p:last-child { margin-bottom: 0; }
    .card.danger > header { border-bottom-color: color-mix(in srgb, var(--danger) 30%, var(--border)); }

    /* bottoni */
    .btn {
        display: inline-block; border: 1px solid var(--border); border-radius: 8px;
        padding: 8px 15px; font: inherit; font-size: 14px; font-weight: 500;
        background: var(--bg); color: var(--text); cursor: pointer; text-decoration: none;
    }
    .btn:hover { background: var(--bg-soft); text-decoration: none; }
    .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    .btn.primary:hover { filter: brightness(1.08); background: var(--accent); }
    .btn.danger { background: var(--danger); border-color: var(--danger); color: #fff; }
    .btn.danger:hover { filter: brightness(1.08); background: var(--danger); }
    .btn:disabled { opacity: .5; cursor: not-allowed; }

    /* avvisi */
    .note {
        border: 1px solid var(--border); border-left-width: 3px;
        border-radius: 8px; padding: 12px 15px; margin-bottom: 16px; font-size: 14px;
    }
    .note.ok { border-left-color: var(--ok); }
    .note.warn { border-left-color: var(--warn); }
    .note.danger { border-left-color: var(--danger); }
    .note b { font-weight: 600; }
    .note ul { margin: 8px 0 0; padding-left: 20px; }

    /* form */
    label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
    input[type=password] {
        width: 100%; max-width: 340px; padding: 9px 11px;
        border: 1px solid var(--border); border-radius: 8px;
        background: var(--bg); color: var(--text); font: inherit;
    }
    input[type=password]:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
    .check { display: flex; align-items: flex-start; gap: 9px; margin: 16px 0; font-size: 14px; }
    .check input { margin-top: 3px; }
    .hint { font-size: 12.5px; color: var(--text-soft); margin-top: 6px; }

    /* tabelle */
    table.grid { width: 100%; border-collapse: collapse; font-size: 13px; }
    table.grid th, table.grid td {
        padding: 7px 14px; text-align: left; border-top: 1px solid var(--border); white-space: nowrap;
    }
    table.grid thead th {
        border-top: 0; font-size: 11px; text-transform: uppercase;
        letter-spacing: .06em; color: var(--text-soft); font-weight: 600;
    }
    table.grid tbody th { font-family: var(--mono); font-weight: 600; }
    table.grid td { color: var(--text-soft); font-family: var(--mono); font-size: 12.5px; }
    .table-scroll { overflow-x: auto; }

    .tag {
        font-size: 11.5px; padding: 2px 8px; border-radius: 20px;
        border: 1px solid var(--border); color: var(--text-soft);
        font-family: inherit; white-space: nowrap;
    }
    .tag.ok { color: var(--ok); border-color: color-mix(in srgb, var(--ok) 35%, transparent); }
    .tag.warn { color: var(--warn); border-color: color-mix(in srgb, var(--warn) 35%, transparent); }
    .tag.danger { color: var(--danger); border-color: color-mix(in srgb, var(--danger) 35%, transparent); }
    .spacer { margin-left: auto; }

    @media (max-width: 640px) { .wrap { padding: 20px 16px 64px; } }
</style>
</head>
<body>
<div class="wrap">
<?php if ($back !== null): ?>
    <a class="back" href="<?= ckd_e($back) ?>">&larr; Utils</a>
<?php endif; ?>
<?php
}

function ckd_foot(): void
{
    ?>
</div>
</body>
</html>
<?php
}
