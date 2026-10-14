<?php

    /**
     * Schema del database per l'assistente AI, derivato da config/tables.json.
     *
     * tables.json descrive gia' tutto quello che serve al modello — tabelle,
     * colonne, tipi, FK (externalEntity), enum (option), descrizioni — ma pesa
     * centinaia di KB: qui viene compresso in una descrizione testuale che sta
     * interamente nel system prompt (con prompt caching costa ~1/10 dal secondo
     * messaggio). La scheda completa di una singola tabella resta disponibile
     * on-demand tramite il tool search_schema.
     *
     * Tabelle e colonne nascoste: i default coprono credenziali e token del
     * framework; un progetto li estende da config/settings.json
     * (ai_assistant.hidden_tables / hidden_columns). Tenerli al minimo: una
     * tabella nascosta non compare nello schema e il modello risponde "non
     * esiste" anche quando c'e'.
     *
     * Livelli: lo schema rispetta i permessi di tables.json con la stessa
     * regola di GetSimple. Una tabella con permissions[livello].view assente
     * o false non e' visibile per quell'utente: non compare nello schema e
     * una query che la tocca viene rifiutata come "non accessibile". I valori
     * "user" e "group" contano come visibile: il filtro per riga NON viene
     * applicato alle query dell'assistente (scelta esplicita, vedi CLAUDE.md).
     */
    class AiSchema {

        /** Tabelle del framework mai esposte al modello: token e PIN. */
        /** Credenziali del framework; le tabelle ai_* arrivano da AiStore::TABLES. */
        const DEFAULT_HIDDEN_TABLES = ['auth_token', 'auth_pin'];

        /** Colonne sensibili per tabella: escluse dallo schema e vietate in query. */
        const DEFAULT_HIDDEN_COLUMNS = ['user' => ['password']];

        /** Colonne tecniche presenti in tutte le tabelle: documentate una volta sola. */
        const BOILERPLATE_COLUMNS = ['date', 'lastUpdate', 'deleted'];

        private $tables;
        private $hiddenTables;   /* [nome => true] */
        private $hiddenColumns;  /* [tabella => [colonna => true]] */
        private $forbiddenTables; /* [nome => true]: view assente o false per il livello */

        function __construct($aiConfig = [], $userInfo = null) {
            $decoded = json_decode(file_get_contents(__DIR__ . '/../../../config/tables.json'), true);
            $this->tables = is_array($decoded) ? $decoded : [];

            /* Senza livello (o livello non numerico) nessuna tabella e' visibile:
               e' il comportamento piu' sicuro e non capita mai dagli endpoint,
               che passano sempre l'utente autenticato. */
            $level = is_array($userInfo) && isset($userInfo['level']) && is_numeric($userInfo['level'])
                ? (string)(int)$userInfo['level'] : null;
            $this->forbiddenTables = [];
            foreach ($this->tables as $table => $def) {
                $view = ($level !== null && isset($def['permissions'][$level]['view']))
                    ? $def['permissions'][$level]['view'] : false;
                if ($view === false) $this->forbiddenTables[$table] = true;
            }

            $this->hiddenTables = [];
            foreach (self::DEFAULT_HIDDEN_TABLES as $t) $this->hiddenTables[$t] = true;
            foreach (AiStore::TABLES as $t) $this->hiddenTables[$t] = true;
            if (isset($aiConfig['hidden_tables']) && is_array($aiConfig['hidden_tables'])) {
                foreach ($aiConfig['hidden_tables'] as $t) $this->hiddenTables[(string)$t] = true;
            }

            $this->hiddenColumns = [];
            foreach (self::DEFAULT_HIDDEN_COLUMNS as $t => $cols) {
                foreach ($cols as $c) $this->hiddenColumns[$t][$c] = true;
            }
            if (isset($aiConfig['hidden_columns']) && is_array($aiConfig['hidden_columns'])) {
                foreach ($aiConfig['hidden_columns'] as $t => $cols) {
                    if (!is_array($cols)) continue;
                    foreach ($cols as $c) $this->hiddenColumns[(string)$t][(string)$c] = true;
                }
            }
        }

        /** tables.json cosi' com'e': serve ad AiManual per i permessi. */
        public function tables() {
            return $this->tables;
        }

        public function tableExists($table) {
            return isset($this->tables[$table]);
        }

        public function isHiddenTable($table) {
            return isset($this->hiddenTables[$table]);
        }

        /** Esiste ma il livello dell'utente non ha view su di essa. */
        public function isForbiddenTable($table) {
            return isset($this->forbiddenTables[$table]);
        }

        /** Visibile al modello: esiste, non e' riservata, il livello ha view. */
        private function isVisibleTable($table) {
            return !$this->isHiddenTable($table) && !$this->isForbiddenTable($table);
        }

        public function isAllowedTable($table) {
            return $this->tableExists($table) && $this->isVisibleTable($table);
        }

        public function isHiddenColumn($table, $column) {
            return isset($this->hiddenColumns[$table][$column]);
        }

        public function columnExists($table, $column) {
            return isset($this->tables[$table]['columns'][$column]);
        }

        public function primaryKey($table) {
            return isset($this->tables[$table]['primary_key']) ? $this->tables[$table]['primary_key'] : 'id';
        }

        public function hiddenTableNames() {
            return array_keys($this->hiddenTables);
        }

        /** Nomi di colonna vietati in qualsiasi query, per la blacklist di AiSql. */
        public function forbiddenIdentifiers() {
            $out = [];
            foreach ($this->hiddenColumns as $cols) {
                foreach ($cols as $c => $_) $out[$c] = true;
            }
            return array_keys($out);
        }

        // ------------------------------------------------------------------
        // Compressione
        // ------------------------------------------------------------------

        /** Colonna virtuale: non esiste in SQL (deep e multiple external). */
        private function isVirtual($col) {
            if (isset($col['type']) && $col['type'] === 'NONE') return true;
            $it = isset($col['input_type']) ? $col['input_type'] : '';
            return $it === 'deepExternalEntity' || $it === 'multipleExternalEntity';
        }

        private function enumValues($col) {
            if (!isset($col['options']['options']) || !is_array($col['options']['options'])) return null;
            $vals = [];
            foreach ($col['options']['options'] as $o) {
                $v = isset($o['value']) ? trim((string)$o['value']) : '';
                if ($v !== '') $vals[] = $v;
            }
            return count($vals) ? $vals : null;
        }

        private function typeOf($col) {
            if (isset($col['input_type']) && $col['input_type'] === 'boolean') return 'INT[0|1]';
            /* Campo allegato: contiene l'hash del file, che read_file sa leggere. */
            if (isset($col['input_type']) && $col['input_type'] === 'file') return 'FILE(hash)';
            return isset($col['type']) ? $col['type'] : '?';
        }

        /** Firma compatta di una colonna: nome, tipo, FK o enum. */
        private function signature($name, $col) {
            if (isset($col['input_type']) && $col['input_type'] === 'externalEntity' && isset($col['options']['entity'])) {
                $label = isset($col['options']['labelField']) ? '.' . $col['options']['labelField'] : '';
                return $name . ' ' . $this->typeOf($col) . '>' . $col['options']['entity'] . $label;
            }
            $en = $this->enumValues($col);
            return $name . ' ' . $this->typeOf($col) . ($en !== null ? '[' . implode('|', $en) . ']' : '');
        }

        /** Le descrizioni-fotocopia dei campi tecnici non aggiungono niente. */
        private function usefulDescription($col) {
            $d = isset($col['description']) ? trim((string)$col['description']) : '';
            if ($d === '') return '';
            $noise = [
                'Identificativo univoco del record',
                'Data e ora di creazione del record',
                "Data e ora dell'ultima modifica",
                'Flag di cancellazione logica',
            ];
            foreach ($noise as $n) {
                if (strpos($d, $n) === 0) return '';
            }
            return $d;
        }

        /** Schema completo compresso: stabile, va nel blocco system con cache. */
        public function compactSchema() {
            $out = [];
            foreach ($this->tables as $table => $def) {
                if (!$this->isVisibleTable($table)) continue;
                if (!isset($def['columns']) || !is_array($def['columns'])) continue;

                $cols = [];
                $children = [];
                foreach ($def['columns'] as $name => $col) {
                    if (in_array($name, self::BOILERPLATE_COLUMNS, true)) continue;
                    if ($this->isHiddenColumn($table, $name)) continue;
                    if ($this->isVirtual($col)) {
                        $child = isset($col['options']['entity']) ? $col['options']['entity'] : null;
                        if ($child !== null && $this->isVisibleTable($child)) {
                            $children[] = isset($col['options']['source_field'])
                                ? $child . '.' . $col['options']['source_field']
                                : $child;
                        }
                        continue;
                    }
                    $desc = $this->usefulDescription($col);
                    $cols[] = '- ' . $this->signature($name, $col) . ($desc !== '' ? ' — ' . $desc : '');
                }

                $out[] = '## ' . $table . ' — ' . (isset($def['description']) ? $def['description'] : '');
                $out[] = implode("\n", $cols);
                if (count($children)) $out[] = 'figli: ' . implode(', ', $children);
            }
            return implode("\n", $out);
        }

        /** Scheda dettagliata di una tabella: descrizioni colonna per colonna. */
        public function describeTable($table) {
            if (!$this->tableExists($table)) return "Tabella \"{$table}\" non trovata.";
            if ($this->isHiddenTable($table)) return "Tabella \"{$table}\" esistente ma riservata: non interrogabile.";
            if ($this->isForbiddenTable($table)) return "Tabella \"{$table}\" esistente ma non accessibile al livello di questo utente.";

            $def = $this->tables[$table];
            $lines = [
                '## ' . $table . ' — ' . (isset($def['description']) ? $def['description'] : ''),
                'primary key: ' . $this->primaryKey($table)
                    . (isset($def['default_key']) ? ' · campo etichetta: ' . $def['default_key'] : ''),
            ];
            if (isset($def['authorization'])) {
                $g = isset($def['authorization']['groupField']) ? $def['authorization']['groupField'] : '—';
                $u = isset($def['authorization']['userField']) ? $def['authorization']['userField'] : '—';
                $lines[] = "autorizzazione per riga: gruppo={$g}, utente={$u}";
            }
            foreach ($def['columns'] as $name => $col) {
                if ($this->isHiddenColumn($table, $name)) continue;
                $flags = [];
                if (isset($col['validation']['required']) && $col['validation']['required'] === true) $flags[] = 'obbligatorio';
                if (isset($col['options']['disabled']) && $col['options']['disabled']) $flags[] = 'calcolato dal sistema';
                if ($this->isVirtual($col)) {
                    $flags[] = 'NON e\' una colonna SQL: dati in ' . (isset($col['options']['entity']) ? $col['options']['entity'] : '?');
                }
                $en = $this->enumValues($col);
                if ($en !== null) $flags[] = 'valori: ' . implode(', ', $en);
                if (isset($col['input_type']) && $col['input_type'] === 'externalEntity' && isset($col['options']['entity'])) {
                    $flags[] = 'FK -> ' . $col['options']['entity'] . '.' . (isset($col['options']['labelField']) ? $col['options']['labelField'] : 'id');
                }
                $lines[] = '- ' . $name . ' (' . $this->typeOf($col) . ')'
                    . (count($flags) ? ' [' . implode('; ', $flags) . ']' : '')
                    . ': ' . (isset($col['description']) ? $col['description'] : '');
            }
            return implode("\n", $lines);
        }

        /** Ricerca full-text su nomi e descrizioni di tabelle e colonne. */
        public function search($query, $limit = 6) {
            $q = strtolower(trim((string)$query));
            if ($q === '') return 'Query vuota.';
            $scored = [];

            foreach ($this->tables as $table => $def) {
                if (!$this->isVisibleTable($table)) continue;
                $score = 0;
                if (strpos(strtolower($table), $q) !== false) $score += 10;
                if (isset($def['description']) && strpos(strtolower($def['description']), $q) !== false) $score += 5;
                if (isset($def['columns']) && is_array($def['columns'])) {
                    foreach ($def['columns'] as $name => $col) {
                        if (strpos(strtolower($name), $q) !== false) $score += 2;
                        if (isset($col['description']) && strpos(strtolower($col['description']), $q) !== false) $score += 1;
                    }
                }
                if ($score > 0) $scored[$table] = $score;
            }

            arsort($scored);
            $hits = array_slice(array_keys($scored), 0, $limit);
            if (!count($hits)) return "Nessuna tabella corrisponde a \"{$query}\".";
            $out = [];
            foreach ($hits as $t) $out[] = $this->describeTable($t);
            return implode("\n\n", $out);
        }
    }
?>
