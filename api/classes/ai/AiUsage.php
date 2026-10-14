<?php

    /**
     * Registro dei consumi dell'assistente AI: una riga per chiamata al modello.
     *
     * Serve a tre cose che arrivano dopo (pannello di utilizzo, tetto di spesa,
     * costo per utente): i token li restituisce l'API a ogni risposta, e
     * scriverli costa una INSERT. Il costo in euro NON si calcola qui: il
     * prezzo cambia col modello e col tempo, e si applica in lettura sui token.
     *
     * La tabella `ai_usage`, come `ai_history`, NON sta in tables.json: e' un
     * dato interno del framework, creata da AiStore e nascosta al modello.
     * Scrive con la connessione normale del framework, non con l'utente
     * read-only: a scrivere e' il framework, non il modello.
     *
     * Una scrittura fallita non deve mai far fallire la risposta: l'errore si
     * ignora, il registro e' accessorio.
     *
     * PREZZI. Il costo si calcola in lettura dai token, con un listino per
     * modello in dollari per milione di token: input, output, cache_read,
     * cache_write. Il listino di default e' quello pubblico di Anthropic al
     * momento in cui e' stato scritto (settembre 2026); il progetto lo
     * corregge o lo estende con `ai_assistant.prices` in settings.json:
     *
     *   "prices": { "claude-sonnet-4-6": { "input": 3, "output": 15, "cache_read": 0.3, "cache_write": 3.75 } }
     *
     * Le chiavi sono confrontate per prefisso (claude-sonnet-4-6 copre anche
     * claude-sonnet-4-6-20260101). Se cache_read/cache_write mancano si
     * derivano dall'input (10% e 125%, la regola generale). Un modello senza
     * prezzo costa 0 e viene segnalato nel pannello.
     *
     * TETTO DI SPESA. `ai_assistant.monthly_budget` (stessa valuta del
     * listino, 0 o assente = nessun tetto): AiAssistant::message() rifiuta la
     * chiamata quando la spesa del mese corrente lo raggiunge.
     */
    class AiUsage {

        const TABLE = AiStore::USAGE;

        /** Dollari per milione di token. Chiave = prefisso dell'id modello. */
        const DEFAULT_PRICES = [
            'claude-opus-5-5'   => ['input' => 4,  'output' => 20],
            'claude-opus-5'     => ['input' => 5,  'output' => 25],
            'claude-opus-4'     => ['input' => 5,  'output' => 25],
            'claude-sonnet-5'   => ['input' => 2,  'output' => 10],
            'claude-sonnet-4'   => ['input' => 3,  'output' => 15],
            'claude-haiku-4'    => ['input' => 1,  'output' => 5],
        ];

        protected $db;
        protected $userId;
        protected $prices;
        protected $budget;
        protected $currency;

        function __construct($db, $userInfo, $aiConfig = []) {
            $this->db = $db;
            $info = is_array($userInfo) ? $userInfo : json_decode((string)$userInfo, true);
            $this->userId = isset($info['id']) ? (int)$info['id'] : 0;

            $this->prices = self::DEFAULT_PRICES;
            if (isset($aiConfig['prices']) && is_array($aiConfig['prices'])) {
                foreach ($aiConfig['prices'] as $model => $p) {
                    if (is_array($p)) $this->prices[(string)$model] = $p;
                }
            }
            $this->budget = isset($aiConfig['monthly_budget']) ? max(0, (float)$aiConfig['monthly_budget']) : 0;
            $this->currency = isset($aiConfig['currency']) && trim((string)$aiConfig['currency']) !== ''
                ? trim((string)$aiConfig['currency']) : 'USD';
        }

        public function budget() {
            return $this->budget;
        }

        public function currency() {
            return $this->currency;
        }

        /**
         * Listino di un modello: [input, output, cache_read, cache_write] per
         * milione di token, o null se il modello non e' nel listino. Vince il
         * prefisso piu' lungo che combacia.
         */
        public function price($model) {
            $best = null;
            $bestLen = -1;
            foreach ($this->prices as $prefix => $p) {
                if (strpos((string)$model, (string)$prefix) === 0 && strlen($prefix) > $bestLen) {
                    $best = $p;
                    $bestLen = strlen($prefix);
                }
            }
            if ($best === null) return null;
            $input = isset($best['input']) ? (float)$best['input'] : 0;
            return [
                'input' => $input,
                'output' => isset($best['output']) ? (float)$best['output'] : 0,
                'cache_read' => isset($best['cache_read']) ? (float)$best['cache_read'] : $input * 0.1,
                'cache_write' => isset($best['cache_write']) ? (float)$best['cache_write'] : $input * 1.25,
            ];
        }

        /** Costo di una riga di ai_usage (o di qualsiasi array con le 4 colonne token). */
        public function cost($model, $row) {
            $p = $this->price($model);
            if ($p === null) return 0.0;
            $n = function ($k) use ($row) { return isset($row[$k]) ? (float)$row[$k] : 0; };
            return ($n('input_tokens') * $p['input']
                + $n('output_tokens') * $p['output']
                + $n('cache_read_tokens') * $p['cache_read']
                + $n('cache_write_tokens') * $p['cache_write']) / 1000000;
        }

        /**
         * Espressione SQL del costo di una riga, per le aggregazioni del
         * pannello: un CASE sui prefissi del listino. Solo costanti numeriche
         * e prefissi passati come parametri, mai testo dell'utente.
         * Torna [sql, params].
         */
        public function costSql($alias = '') {
            $col = function ($c) use ($alias) { return ($alias !== '' ? $alias . '.' : '') . $c; };
            $cases = '';
            $params = [];
            $i = 0;
            /* prefissi piu' lunghi prima: il CASE prende il primo che combacia */
            $prefixes = array_keys($this->prices);
            usort($prefixes, function ($a, $b) { return strlen($b) - strlen($a); });
            foreach ($prefixes as $prefix) {
                $p = $this->price($prefix);
                $k = ':pfx' . $i++;
                $params[$k] = $prefix . '%';
                $cases .= " WHEN " . $col('model') . " LIKE {$k} THEN ("
                    . $col('input_tokens') . " * " . sprintf('%.6F', $p['input'])
                    . " + " . $col('output_tokens') . " * " . sprintf('%.6F', $p['output'])
                    . " + " . $col('cache_read_tokens') . " * " . sprintf('%.6F', $p['cache_read'])
                    . " + " . $col('cache_write_tokens') . " * " . sprintf('%.6F', $p['cache_write'])
                    . ") / 1000000";
            }
            return ['(CASE' . $cases . ' ELSE 0 END)', $params];
        }

        /** Spesa del mese corrente (per il tetto). 0 se la tabella non c'e'. */
        public function spentThisMonth() {
            try {
                if (!AiStore::exists($this->db, self::TABLE)) return 0.0;
                list($costSql, $params) = $this->costSql();
                $stmt = $this->db->prepare(
                    "SELECT COALESCE(SUM({$costSql}), 0) FROM `" . self::TABLE . "`
                      WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
                );
                $stmt->execute($params);
                return (float)$stmt->fetchColumn();
            } catch (Exception $e) {
                return 0.0;
            }
        }

        /**
         * Il tetto mensile e' raggiunto? Torna null se si puo' chiamare,
         * altrimenti [spent, budget] per il messaggio all'utente.
         */
        public function budgetExceeded() {
            if ($this->budget <= 0) return null;
            $spent = $this->spentThisMonth();
            return $spent >= $this->budget ? ['spent' => $spent, 'budget' => $this->budget] : null;
        }

        /**
         * Registra una chiamata. `$usage` e' il blocco `usage` della risposta
         * dell'API (input_tokens, output_tokens, cache_read_input_tokens,
         * cache_creation_input_tokens).
         */
        public function log($model, $usage, $stopReason = null, $conversationId = null, $route = null) {
            if ($this->userId <= 0 || !is_array($usage)) return;
            try {
                AiStore::ensure($this->db);
                $stmt = $this->db->prepare(
                    "INSERT INTO `" . self::TABLE . "`
                        (user_id, conversation_id, route, model, input_tokens, output_tokens,
                         cache_read_tokens, cache_write_tokens, stop_reason, created_at)
                     VALUES (:u, :c, :r, :m, :i, :o, :cr, :cw, :s, NOW())"
                );
                $stmt->execute([
                    ':u'  => $this->userId,
                    ':c'  => $conversationId !== null ? substr(preg_replace('/[^a-z0-9]/i', '', (string)$conversationId), 0, 32) : null,
                    ':r'  => $route !== null ? mb_substr((string)$route, 0, 255) : null,
                    ':m'  => mb_substr((string)$model, 0, 80),
                    ':i'  => isset($usage['input_tokens']) ? (int)$usage['input_tokens'] : 0,
                    ':o'  => isset($usage['output_tokens']) ? (int)$usage['output_tokens'] : 0,
                    ':cr' => isset($usage['cache_read_input_tokens']) ? (int)$usage['cache_read_input_tokens'] : 0,
                    ':cw' => isset($usage['cache_creation_input_tokens']) ? (int)$usage['cache_creation_input_tokens'] : 0,
                    ':s'  => $stopReason !== null ? substr((string)$stopReason, 0, 40) : null,
                ]);
            } catch (Exception $e) {
                /* Il registro non deve mai rompere la risposta. */
            }
        }
    }
?>
