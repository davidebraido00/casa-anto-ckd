<?php

    /**
     * Esecutore SQL di sola lettura per l'assistente AI.
     *
     * TRE LIVELLI DI PROTEZIONE — servono tutti e tre:
     *   1. Validazione sintattica (guardSelect): solo SELECT/WITH, un solo
     *      statement, blacklist di keyword fuori dalle stringhe letterali,
     *      whitelist di tabelle da tables.json, colonne riservate rifiutate,
     *      LIMIT iniettato se manca.
     *   2. Utente MySQL dedicato in SOLA LETTURA — OBBLIGATORIO, e' l'unica
     *      garanzia reale: la validazione con regex riduce la superficie, un
     *      GRANT SELECT la elimina. Credenziali in config/settings.json sotto
     *      ai_assistant.db_readonly (host/porta/nome db restano quelli di `db`).
     *   3. max_execution_time + tetto di righe: una query senza limiti su una
     *      tabella grossa non deve bloccare la pagina ne' esplodere il contesto
     *      del modello.
     *
     * Gli errori di guardia escono come AiSqlGuardError con messaggi pensati per
     * tornare al modello come tool_result: leggendoli si corregge e riprova.
     */

    class AiSqlGuardError extends Exception {}

    class AiSql {

        const DEFAULT_MAX_ROWS = 200;
        const DEFAULT_MAX_EXECUTION_MS = 8000;

        /** Keyword vietate: scritture, DDL, metadati, funzioni di stallo. */
        const FORBIDDEN_KEYWORDS = [
            'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate', 'replace',
            'rename', 'grant', 'revoke', 'call', 'handler', 'lock', 'unlock', 'prepare',
            'execute', 'deallocate', 'load', 'outfile', 'dumpfile', 'infile', 'sleep',
            'benchmark', 'information_schema', 'performance_schema', 'mysql', 'sys',
            'into', 'set', 'flush', 'kill', 'reset', 'shutdown', 'user()', 'version()',
            'load_file', '@@',
        ];

        private $schema;
        private $maxRows;
        private $maxExecutionMs;
        private $readonlyUser;
        private $readonlyPassword;
        private $conn = null;

        function __construct($aiSchema, $aiConfig = []) {
            $this->schema = $aiSchema;
            $this->maxRows = isset($aiConfig['max_rows']) ? max(1, (int)$aiConfig['max_rows']) : self::DEFAULT_MAX_ROWS;
            $this->maxExecutionMs = isset($aiConfig['max_execution_ms']) ? max(500, (int)$aiConfig['max_execution_ms']) : self::DEFAULT_MAX_EXECUTION_MS;
            $this->readonlyUser = isset($aiConfig['db_readonly']['username']) ? trim((string)$aiConfig['db_readonly']['username']) : '';
            $this->readonlyPassword = isset($aiConfig['db_readonly']['password']) ? (string)$aiConfig['db_readonly']['password'] : '';
        }

        public function maxRows() {
            return $this->maxRows;
        }

        /** L'utente read-only e' obbligatorio: senza, l'assistente non parte. */
        public function readonlyConfigured() {
            return $this->readonlyUser !== '';
        }

        // ------------------------------------------------------------------
        // Guardia sintattica
        // ------------------------------------------------------------------

        /** Contenuto delle stringhe letterali via: un LIKE '%set%' non deve
         *  far scattare la blacklist di keyword. */
        private function stripStringLiterals($sql) {
            $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql);
            $sql = preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', '""', $sql);
            return $sql;
        }

        private function stripComments($sql) {
            $sql = preg_replace('/\/\*[\s\S]*?\*\//', ' ', $sql);
            $sql = preg_replace('/--[^\n\r]*/', ' ', $sql);
            $sql = preg_replace('/#[^\n\r]*/', ' ', $sql);
            return $sql;
        }

        /** Nomi dei CTE definiti in una WITH: sono FROM legittimi. */
        private function cteNames($sql) {
            $names = [];
            if (preg_match_all('/(?:\bwith\b|,)\s*`?([a-z_][a-z0-9_]*)`?\s+as\s*\(/i', $sql, $m)) {
                foreach ($m[1] as $n) $names[strtolower($n)] = true;
            }
            return $names;
        }

        public function referencedTables($sql) {
            $out = [];
            /* Niente ':' o lettere prima: il segnaposto `:from` dei widget
               (":from AND ...") non e' la keyword FROM. */
            if (preg_match_all('/(?<![:\w])(?:from|join)\s+`?([a-z_][a-z0-9_]*)`?/i', $sql, $m)) {
                foreach ($m[1] as $t) $out[] = $t;
            }
            return $out;
        }

        /**
         * Valida e normalizza una SELECT: torna la stringa esatta che verra'
         * eseguita, LIMIT incluso. Lancia AiSqlGuardError se non passa.
         */
        public function guardSelect($rawSql, $maxRows = null) {
            $maxRows = $maxRows !== null ? $maxRows : $this->maxRows;
            $clean = trim(preg_replace('/\s+/', ' ', $this->stripComments((string)$rawSql)));
            $clean = trim(preg_replace('/;+$/', '', $clean));

            if ($clean === '') throw new AiSqlGuardError('Query vuota.');
            if (strpos($clean, ';') !== false) {
                throw new AiSqlGuardError("Vietato eseguire piu' statement: invia una sola SELECT senza ';'.");
            }
            if (!preg_match('/^(select|with)\b/i', $clean)) {
                throw new AiSqlGuardError('Sono ammesse solo query che iniziano con SELECT o WITH.');
            }

            $lower = strtolower($this->stripStringLiterals($clean));
            foreach (self::FORBIDDEN_KEYWORDS as $kw) {
                $pattern = preg_match('/^[a-z_]+$/', $kw)
                    ? '/\b' . $kw . '\b/i'
                    : '/' . preg_quote($kw, '/') . '/i';
                if (preg_match($pattern, $lower)) {
                    throw new AiSqlGuardError("Keyword non consentita nella query: \"{$kw}\".");
                }
            }
            foreach ($this->schema->forbiddenIdentifiers() as $id) {
                if (preg_match('/\b' . preg_quote($id, '/') . '\b/i', $lower)) {
                    throw new AiSqlGuardError("Colonna riservata non interrogabile: \"{$id}\".");
                }
            }

            $ctes = $this->cteNames($clean);
            foreach ($this->referencedTables($clean) as $table) {
                if (isset($ctes[strtolower($table)])) continue;
                if (!$this->schema->isAllowedTable($table)) {
                    /* "riservata" non e' la stessa cosa di "inesistente". */
                    if ($this->schema->isHiddenTable($table)) {
                        throw new AiSqlGuardError(
                            "La tabella \"{$table}\" esiste ma e' riservata e non e' interrogabile dall'assistente. "
                            . "Dillo all'utente invece di cercare altrove.");
                    }
                    if ($this->schema->isForbiddenTable($table)) {
                        throw new AiSqlGuardError(
                            "La tabella \"{$table}\" esiste ma il livello di questo utente non e' autorizzato a consultarla. "
                            . "Dillo all'utente invece di cercare altrove.");
                    }
                    throw new AiSqlGuardError(
                        "Tabella inesistente: \"{$table}\". Usa search_schema per trovare il nome corretto.");
                }
            }

            $hasLimit = preg_match('/\blimit\s+\d+/i', $clean);
            return $hasLimit ? $clean : $clean . ' LIMIT ' . $maxRows;
        }

        // ------------------------------------------------------------------
        // Esecuzione
        // ------------------------------------------------------------------

        /** Connessione PDO con le credenziali read-only dedicate. */
        private function connection() {
            if ($this->conn !== null) return $this->conn;
            if (!$this->readonlyConfigured()) {
                throw new AiSqlGuardError(
                    "Utente database read-only non configurato: imposta ai_assistant.db_readonly in config/settings.json.");
            }

            $settings = json_decode(file_get_contents(__DIR__ . '/../../../config/settings.json'), true);
            $host = isset($settings['db']['server']) ? $settings['db']['server'] : 'localhost';
            $name = isset($settings['db']['name']) ? $settings['db']['name'] : '';
            $port = isset($settings['db']['port']) ? $settings['db']['port'] : '3306';

            $this->conn = new PDO(
                'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
                $this->readonlyUser,
                $this->readonlyPassword
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            /* Tetto lato server sui soli SELECT: una query pesante si ferma da
               sola invece di tenere occupata la richiesta. La variabile dipende
               dal server — MySQL 5.7+ usa max_execution_time (millisecondi),
               MariaDB max_statement_time (secondi): si prova nell'ordine, e se
               il server non ne conosce nessuna si prosegue senza tetto (restano
               la guardia, il LIMIT e l'utente read-only). */
            try {
                $this->conn->exec('SET SESSION max_execution_time = ' . (int)$this->maxExecutionMs);
            } catch (PDOException $e) {
                try {
                    $this->conn->exec('SET SESSION max_statement_time = ' . ((int)$this->maxExecutionMs / 1000));
                } catch (PDOException $e2) {
                    error_log('[ai-sql] nessun tetto di esecuzione disponibile su questo server: ' . $e2->getMessage());
                }
            }
            return $this->conn;
        }

        /**
         * Valida, esegue, tronca.
         * Torna: [sql, row_count, truncated, duration_ms, rows]
         */
        public function runSelect($rawSql, $maxRows = null) {
            $maxRows = $maxRows !== null ? $maxRows : $this->maxRows;
            $sql = $this->guardSelect($rawSql, $maxRows);
            $conn = $this->connection();

            $started = microtime(true);
            $stmt = $conn->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $durationMs = (int)round((microtime(true) - $started) * 1000);

            error_log('[ai-sql] ' . $durationMs . 'ms ' . count($rows) . ' righe :: ' . $sql);

            $truncated = count($rows) > $maxRows;
            return [
                'sql' => $sql,
                'row_count' => count($rows),
                'truncated' => $truncated,
                'duration_ms' => $durationMs,
                'rows' => $truncated ? array_slice($rows, 0, $maxRows) : $rows,
            ];
        }

        /**
         * Come runSelect, ma con parametri PDO (:from, :to dei widget): la
         * guardia vede il testo con i segnaposto, i valori non entrano mai
         * nel SQL. Torna la stessa struttura di runSelect.
         */
        public function runSelectParams($rawSql, $params, $maxRows = null) {
            $maxRows = $maxRows !== null ? $maxRows : $this->maxRows;
            $sql = $this->guardSelect($rawSql, $maxRows);
            $conn = $this->connection();

            $started = microtime(true);
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $durationMs = (int)round((microtime(true) - $started) * 1000);

            error_log('[ai-sql] ' . $durationMs . 'ms ' . count($rows) . ' righe (widget) :: ' . $sql);

            $truncated = count($rows) > $maxRows;
            return [
                'sql' => $sql,
                'row_count' => count($rows),
                'truncated' => $truncated,
                'duration_ms' => $durationMs,
                'rows' => $truncated ? array_slice($rows, 0, $maxRows) : $rows,
            ];
        }

        /** Identificatore SQL sicuro (per distinct_values, dove i nomi li scrive il modello). */
        public function safeIdentifier($name) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$name)) {
                throw new AiSqlGuardError("Identificatore non valido: \"{$name}\".");
            }
            return '`' . $name . '`';
        }

        /** Literal SQL per un LIKE %valore%, con escape di apici e wildcard. */
        public function likeLiteral($value) {
            $escaped = str_replace(
                ['\\', "'", '%', '_'],
                ['\\\\', "''", '\\%', '\\_'],
                (string)$value
            );
            return "'%" . $escaped . "%'";
        }
    }
?>
