<?php

    /**
     * Tool di progetto dell'assistente AI.
     *
     * I tre tool generici (run_select, search_schema, distinct_values) bastano
     * per interrogare qualsiasi database, ma i numeri "ufficiali" di un
     * progetto (fatturato, margine, cashflow) nascono spesso da una funzione
     * PHP gia' scritta per una pagina di analisi. Rifarli in SQL libero
     * significa avere due definizioni. Qui il progetto registra quelle
     * funzioni come tool: il modello le chiama per nome, e il numero e' lo
     * stesso della pagina, per costruzione.
     *
     * Il file `custom/ai_tools.php` (DEL PROGETTO, mai toccato
     * dall'allineamento) ritorna un array di tool, ognuno:
     *
     *   [
     *     'name'         => 'crm_cashflow',            // ^[a-z][a-z0-9_]{2,40}$, non ui_/run_/search_/distinct_
     *     'description'  => 'Cosa fa, quando usarlo', // e' quello che legge il modello
     *     'input_schema' => [ JSON Schema ],           // opzionale: default oggetto vuoto
     *     'levels'       => [0, 1],                    // opzionale: chi puo' chiamarlo (default: chi usa l'assistente)
     *     'handler'      => function (array $input, PDO $db, array $userInfo) { return [...]; },
     *   ]
     *
     * L'handler ritorna un array (o una stringa) che finisce, in JSON, nel
     * tool_result. Se lancia un'eccezione il messaggio torna al modello come
     * errore, non come errore HTTP: il modello lo legge e si regola.
     *
     * L'handler gira con la connessione normale del framework: e' codice del
     * progetto, di cui il progetto risponde. Deve leggere e basta.
     */
    class AiTools {

        const FILE = '/../../../custom/ai_tools.php';
        const RESERVED_PREFIXES = ['ui_', 'run_', 'search_', 'distinct_'];
        const MAX_RESULT_BYTES = 200000;

        protected $db;
        protected $userInfo;
        protected $tools = [];   /* [name => definizione] */
        protected $loadError = null;

        function __construct($db, $userInfo) {
            $this->db = $db;
            $this->userInfo = is_array($userInfo) ? $userInfo : json_decode((string)$userInfo, true);
            $this->load();
        }

        private function load() {
            $file = __DIR__ . self::FILE;
            if (!file_exists($file)) return;

            try {
                $list = include $file;
            } catch (Throwable $e) {
                $this->loadError = $e->getMessage();
                return;
            }
            if (!is_array($list)) return;

            $level = isset($this->userInfo['level']) ? (int)$this->userInfo['level'] : 99;

            foreach ($list as $def) {
                if (!is_array($def) || !isset($def['name'], $def['description'], $def['handler'])) continue;
                $name = (string)$def['name'];
                if (!preg_match('/^[a-z][a-z0-9_]{2,40}$/', $name)) continue;
                foreach (self::RESERVED_PREFIXES as $p) {
                    if (strpos($name, $p) === 0) continue 2;
                }
                if (!is_callable($def['handler'])) continue;
                if (isset($def['levels']) && is_array($def['levels']) && count($def['levels'])
                    && !in_array($level, array_map('intval', $def['levels']), true)) {
                    continue;
                }
                $this->tools[$name] = $def;
            }
        }

        /** Errore di caricamento del file di progetto, se c'e' (per i log). */
        public function loadError() {
            return $this->loadError;
        }

        public function has($name) {
            return isset($this->tools[$name]);
        }

        /** Definizioni nel formato dell'API, da accodare al catalogo. */
        public function catalog() {
            $out = [];
            foreach ($this->tools as $name => $def) {
                $schema = isset($def['input_schema']) && is_array($def['input_schema'])
                    ? $def['input_schema']
                    : ['type' => 'object', 'properties' => new stdClass()];
                $out[] = [
                    'name' => $name,
                    'description' => (string)$def['description'],
                    'input_schema' => $schema,
                ];
            }
            return $out;
        }

        /** Nomi e descrizioni in una riga, per il system prompt. */
        public function summary() {
            $lines = [];
            foreach ($this->tools as $name => $def) {
                $first = trim(strtok((string)$def['description'], "\n"));
                $lines[] = "- `{$name}`: {$first}";
            }
            return implode("\n", $lines);
        }

        /**
         * Esegue un tool. Ritorna sempre un array pronto per il tool_result:
         * gli errori sono dentro `error`, mai eccezioni verso l'alto.
         */
        public function run($name, $input) {
            if (!$this->has($name)) return ['error' => "Tool sconosciuto: {$name}"];
            if (!is_array($input)) $input = [];

            try {
                $result = call_user_func($this->tools[$name]['handler'], $input, $this->db, $this->userInfo);
            } catch (Throwable $e) {
                return ['error' => $e->getMessage(), 'hint' => 'Controlla i parametri e riprova.'];
            }

            if (is_string($result)) $result = ['text' => $result];
            if (!is_array($result)) $result = ['result' => $result];

            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false && strlen($json) > self::MAX_RESULT_BYTES) {
                return ['error' => 'Risultato troppo grande: restringi il periodo o il filtro.'];
            }
            return $result;
        }
    }
?>
