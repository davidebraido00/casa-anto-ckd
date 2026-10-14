<?php

    /**
     * Widget in dashboard: un grafico o un numero (KPI) nato da una risposta
     * della chat, salvato con la sua query e rieseguito SENZA modello.
     *
     * DEFINIZIONE (JSON nella colonna `definition`):
     *   {
     *     "kind": "chart" | "kpi",
     *     "sql": "SELECT ... WHERE d >= :from AND d < :to ...",
     *     "uses_dates": true,          // false: il filtro periodo non c'entra
     *     "date_note": "data del pagamento",
     *     "chart": { "type": "bar", "format": "currency", "label": "mese",
     *                "series": [{ "name": "gruppoyec", "column": "totale" }] },
     *     "kpi":   { "format": "currency", "column": "totale", "label": "Fatturato" }
     *   }
     *
     * CREAZIONE: una sola chiamata al modello (AiAssistant::askJson) che
     * riceve la domanda, le query del giro e il grafico, e riscrive il SQL
     * sui segnaposto :from / :to (da incluso, a escluso) togliendo i filtri
     * di data cablati. La definizione viene validata, provata una volta sul
     * mese corrente e salvata. Se la prova fallisce si riprova una volta
     * passando l'errore al modello.
     *
     * ESECUZIONE: stessa guardia SQL della chat, utente read-only, parametri
     * PDO per le date (mai concatenate). Il periodo lo sceglie chi guarda.
     *
     * PER UTENTE: ognuno vede e tocca solo i propri widget. La colonna
     * `shared` e' riservata a una condivisione futura: oggi resta 0 e non
     * si legge. La guardia SQL gira comunque con i permessi di chi esegue.
     */
    class AiWidget {

        const TABLE = AiStore::WIDGET;
        const MAX_WIDGETS = 30;
        const MAX_TITLE = 255;
        const KINDS = ['chart', 'kpi'];
        const CHART_TYPES = ['bar', 'line', 'pie'];
        const FORMATS = ['currency', 'number', 'percent', 'hours'];
        const MAX_SERIES = 4;
        const MAX_POINTS = 60;
        /** Larghezza in dashboard su desktop (da mobile sempre intera). */
        const SIZES = ['third', 'half', 'full'];

        protected $db;
        protected $userId;
        protected $ai;

        function __construct($db, $userInfo, $assistant) {
            $this->db = $db;
            $info = is_array($userInfo) ? $userInfo : json_decode((string)$userInfo, true);
            $this->userId = isset($info['id']) ? (int)$info['id'] : 0;
            $this->ai = $assistant;
        }

        private function err($message, $code = 'ai.widget', $status = 400) {
            return ['success' => 0, 'status' => $status, 'error_code' => $code, 'message' => $message];
        }

        private function ok($data) {
            return ['success' => 1, 'status' => 200, 'data' => $data, 'message' => 'ok'];
        }

        // ------------------------------------------------------------------
        // Creazione
        // ------------------------------------------------------------------

        /** Il LIMIT di chat in coda (vedi AiExport) non ha senso in un widget. */
        private function stripChatLimit($sql) {
            $chatLimit = (int)$this->ai->sqlGuard()->maxRows();
            return preg_replace('/\s+limit\s+' . $chatLimit . '\s*$/i', '', trim((string)$sql));
        }

        private function systemPrompt() {
            return "Trasformi una risposta di un assistente dati in un WIDGET di dashboard: una query SQL (MySQL, sola lettura) rieseguibile senza di te, piu' la mappatura delle colonne su un grafico o su un numero. Rispondi SOLO con un oggetto JSON, senza testo attorno e senza blocco di codice.

FORMATO
{
  \"kind\": \"chart\" | \"kpi\",
  \"title\": \"titolo breve\",
  \"sql\": \"SELECT ...\",
  \"uses_dates\": true | false,
  \"date_note\": \"quale data filtra il periodo (es. data del pagamento)\",
  \"chart\": { \"type\": \"bar\"|\"line\"|\"pie\", \"format\": \"currency\"|\"number\"|\"percent\"|\"hours\", \"label\": \"colonna delle etichette\", \"series\": [ { \"name\": \"nome serie\", \"column\": \"colonna numerica\" } ] },
  \"kpi\": { \"format\": \"currency\"|\"number\"|\"percent\"|\"hours\", \"column\": \"colonna numerica\", \"label\": \"etichetta corta\" }
}
Metti \"chart\" solo se kind=chart, \"kpi\" solo se kind=kpi.

REGOLE SUL SQL
- Il widget ha un filtro periodo scelto dall'utente: due parametri :from e :to (DATE, :to e' il giorno DOPO la fine, escluso). Se la query ha una dimensione temporale sensata (una data dell'evento), riscrivila cosi': `colonna_data >= :from AND colonna_data < :to`, TOGLIENDO i filtri di data cablati (anni, mesi, BETWEEN con date fisse) che il periodo sostituisce. Mantieni tutti gli altri filtri (stato, azienda, cancellati). Usa :from e :to UNA volta ciascuno o non usarli affatto.
- Se non c'e' una data sensata (es. clienti per provincia, anagrafiche), uses_dates=false e NON usare :from/:to.
- Per un grafico: la query deve tornare la colonna delle etichette e una colonna numerica per serie, gia' aggregata e ordinata come va disegnata, massimo 60 righe. Per serie multiple usa colonne separate (SUM(CASE WHEN ...)), non righe.
- Per un KPI: la query torna UNA riga con la colonna numerica (usa COALESCE(SUM(...),0)).
- Niente LIMIT finale, niente ';', una sola SELECT. Alias di colonna semplici, senza spazi.
- Usa esattamente le tabelle e colonne della query di partenza: non inventarne.
- Se la risposta veniva da un TOOL DI PROGETTO e non da una query, scrivi tu la SELECT equivalente sullo SCHEMA fornito, seguendo alla lettera le REGOLE DEL PROGETTO (definizioni di fatturato, stati, aziende): il widget deve riprodurre lo stesso numero che dava il tool.";
        }

        private function userPrompt($question, $queries, $tools, $chart, $kind, $error = null) {
            $out = "KIND RICHIESTO: {$kind}\n\nDOMANDA DELL'UTENTE:\n{$question}\n";
            if (count($queries)) {
                $out .= "\nQUERY ESEGUITE PER RISPONDERE:\n";
                foreach ($queries as $i => $q) $out .= "-- query " . ($i + 1) . "\n" . $q . "\n";
            }
            if (count($tools)) {
                $out .= "\nTOOL DI PROGETTO USATI PER RISPONDERE (non rieseguibili dal widget: serve una SELECT equivalente):\n";
                $catalog = [];
                foreach ($this->ai->projectTools()->catalog() as $c) $catalog[$c['name']] = isset($c['description']) ? $c['description'] : '';
                foreach ($tools as $t) {
                    $out .= "- " . $t['name'] . "(" . json_encode($t['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ")";
                    if (isset($catalog[$t['name']])) $out .= ": " . $catalog[$t['name']];
                    $out .= "\n";
                }
            }
            if ($chart !== null) {
                $out .= "\nGRAFICO MOSTRATO NELLA RISPOSTA (da riprodurre):\n" . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            $notes = $this->ai->projectNotes();
            if ($notes !== '') $out .= "\nREGOLE DEL PROGETTO:\n" . $notes . "\n";

            /* Schema: delle tabelle coinvolte se ci sono query (serve la colonna
               data giusta); tutto lo schema compatto se la risposta veniva da un
               tool e la query va scritta da zero. */
            $tables = [];
            foreach ($queries as $q) {
                foreach ($this->ai->sqlGuard()->referencedTables($q) as $t) $tables[strtolower($t)] = true;
            }
            $schema = [];
            foreach (array_slice(array_keys($tables), 0, 6) as $t) {
                if ($this->ai->schema()->isAllowedTable($t)) $schema[] = $this->ai->schema()->describeTable($t);
            }
            if (count($schema)) $out .= "\nSCHEMA DELLE TABELLE COINVOLTE:\n" . implode("\n\n", $schema) . "\n";
            else if (count($tools)) $out .= "\nSCHEMA DEL DATABASE:\n" . $this->ai->schema()->compactSchema() . "\n";
            if ($error !== null) {
                $out .= "\nIL TENTATIVO PRECEDENTE E' FALLITO CON QUESTO ERRORE, CORREGGI:\n{$error}\n";
            }
            return $out;
        }

        /** Testo del modello -> array, tollerando un eventuale blocco ```json. */
        private function parseJson($text) {
            $t = trim((string)$text);
            $t = preg_replace('/^```[a-z]*\s*/i', '', $t);
            $t = preg_replace('/\s*```$/', '', $t);
            $obj = json_decode($t, true);
            if (!is_array($obj)) {
                $start = strpos($t, '{');
                $end = strrpos($t, '}');
                if ($start !== false && $end !== false && $end > $start) $obj = json_decode(substr($t, $start, $end - $start + 1), true);
            }
            return is_array($obj) ? $obj : null;
        }

        /**
         * Normalizza e valida la definizione. Torna [definition, title] o
         * lancia InvalidArgumentException con il motivo (che torna al modello).
         */
        private function validateDefinition($d, $kindWanted) {
            $kind = isset($d['kind']) && in_array($d['kind'], self::KINDS, true) ? $d['kind'] : $kindWanted;
            $sql = isset($d['sql']) && is_string($d['sql']) ? trim($d['sql']) : '';
            if ($sql === '') throw new InvalidArgumentException('sql mancante');
            $sql = preg_replace('/;+\s*$/', '', $sql);
            $usesDates = !empty($d['uses_dates']);
            $hasFrom = preg_match('/:from\b/', $sql) === 1;
            $hasTo = preg_match('/:to\b/', $sql) === 1;
            if ($usesDates && (!$hasFrom || !$hasTo)) throw new InvalidArgumentException('uses_dates=true ma :from o :to mancano nel sql');
            if (!$usesDates && ($hasFrom || $hasTo)) throw new InvalidArgumentException('uses_dates=false ma il sql contiene :from/:to');
            if (substr_count($sql, ':from') > 1 || substr_count($sql, ':to') > 1) {
                throw new InvalidArgumentException(':from e :to vanno usati una volta sola (PDO non ammette segnaposto ripetuti)');
            }
            $col = function ($v, $what) {
                if (!is_string($v) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $v)) throw new InvalidArgumentException("{$what}: nome di colonna non valido");
                return $v;
            };
            $def = [
                'kind' => $kind,
                'sql' => $sql,
                'uses_dates' => $usesDates,
                'date_note' => isset($d['date_note']) && is_string($d['date_note']) ? mb_substr($d['date_note'], 0, 120) : '',
            ];
            if ($kind === 'chart') {
                $c = isset($d['chart']) && is_array($d['chart']) ? $d['chart'] : [];
                $series = [];
                foreach (isset($c['series']) && is_array($c['series']) ? $c['series'] : [] as $s) {
                    if (!is_array($s)) continue;
                    $series[] = ['name' => isset($s['name']) ? mb_substr((string)$s['name'], 0, 60) : 'Serie', 'column' => $col(isset($s['column']) ? $s['column'] : null, 'chart.series.column')];
                    if (count($series) >= self::MAX_SERIES) break;
                }
                if (!count($series)) throw new InvalidArgumentException('chart.series vuoto');
                $def['chart'] = [
                    'type' => isset($c['type']) && in_array($c['type'], self::CHART_TYPES, true) ? $c['type'] : 'bar',
                    'format' => isset($c['format']) && in_array($c['format'], self::FORMATS, true) ? $c['format'] : 'number',
                    'label' => $col(isset($c['label']) ? $c['label'] : null, 'chart.label'),
                    'series' => $series,
                ];
            } else {
                $k = isset($d['kpi']) && is_array($d['kpi']) ? $d['kpi'] : [];
                $def['kpi'] = [
                    'format' => isset($k['format']) && in_array($k['format'], self::FORMATS, true) ? $k['format'] : 'number',
                    'column' => $col(isset($k['column']) ? $k['column'] : null, 'kpi.column'),
                    'label' => isset($k['label']) ? mb_substr((string)$k['label'], 0, 60) : '',
                ];
            }
            $title = isset($d['title']) && is_string($d['title']) ? trim(preg_replace('/\s+/', ' ', $d['title'])) : '';
            return [$def, $title];
        }

        /** Parametri PDO per il periodo: :to e' il giorno dopo `to` (escluso). */
        private function dateParams($from, $to) {
            $toNext = (new DateTime($to))->modify('+1 day')->format('Y-m-d');
            return [':from' => $from, ':to' => $toNext];
        }

        /** Esegue la definizione sul periodo e la traduce in spec/valore. Lancia se qualcosa non va. */
        private function execute($def, $from, $to) {
            $sql = $def['sql'];
            $params = $def['uses_dates'] ? $this->dateParams($from, $to) : [];
            $res = $this->ai->sqlGuard()->runSelectParams($sql, $params, self::MAX_POINTS + 1);
            $rows = $res['rows'];

            if ($def['kind'] === 'kpi') {
                $c = $def['kpi']['column'];
                if (count($rows) && !array_key_exists($c, $rows[0])) throw new RuntimeException("la colonna \"{$c}\" non e' nel risultato");
                $value = count($rows) ? $rows[0][$c] : 0;
                return ['kind' => 'kpi', 'value' => is_numeric($value) ? (float)$value : 0, 'format' => $def['kpi']['format'],
                        'label' => $def['kpi']['label'], 'row_count' => count($rows)];
            }

            $c = $def['chart'];
            if (count($rows) > self::MAX_POINTS) $rows = array_slice($rows, 0, self::MAX_POINTS);
            if (count($rows)) {
                if (!array_key_exists($c['label'], $rows[0])) throw new RuntimeException("la colonna delle etichette \"{$c['label']}\" non e' nel risultato");
                foreach ($c['series'] as $s) {
                    if (!array_key_exists($s['column'], $rows[0])) throw new RuntimeException("la colonna \"{$s['column']}\" non e' nel risultato");
                }
            }
            $labels = [];
            $series = [];
            foreach ($c['series'] as $s) $series[] = ['name' => $s['name'], 'values' => []];
            foreach ($rows as $r) {
                $labels[] = (string)$r[$c['label']];
                foreach ($c['series'] as $i => $s) {
                    $v = $r[$s['column']];
                    $series[$i]['values'][] = is_numeric($v) ? (float)$v : null;
                }
            }
            return ['kind' => 'chart', 'spec' => ['type' => $c['type'], 'title' => '', 'format' => $c['format'], 'labels' => $labels, 'series' => $series],
                    'row_count' => count($rows)];
        }

        private function thisMonth() {
            return [date('Y-m-01'), date('Y-m-d')];
        }

        /**
         * { question, queries: [sql...], tools?: [{name, input}], chart?: spec, kind: 'chart'|'kpi', title? }
         * `tools`: i tool di progetto usati dalla risposta, quando non c'era una query.
         */
        public function create($p) {
            if ($this->userId <= 0) return $this->err("Utente non riconosciuto.", 'ai.widget.user', 401);

            $kind = isset($p->kind) && in_array($p->kind, self::KINDS, true) ? $p->kind : 'chart';
            $question = isset($p->question) && is_string($p->question) ? mb_substr(trim($p->question), 0, 2000) : '';
            $queries = [];
            foreach (isset($p->queries) && is_array($p->queries) ? $p->queries : [] as $q) {
                if (is_string($q) && trim($q) !== '') $queries[] = $this->stripChatLimit($q);
                if (count($queries) >= 6) break;
            }
            $tools = [];
            foreach (isset($p->tools) && is_array($p->tools) ? $p->tools : [] as $t) {
                if (!is_object($t) || !isset($t->name) || !is_string($t->name) || !$this->ai->projectTools()->has($t->name)) continue;
                $tools[] = ['name' => $t->name, 'input' => isset($t->input) ? json_decode(json_encode($t->input), true) : []];
                if (count($tools) >= 6) break;
            }
            if (!count($queries) && !count($tools)) return $this->err("Nessuna query o tool da cui costruire il widget.");
            $chart = isset($p->chart) && is_object($p->chart) ? json_decode(json_encode($p->chart), true) : null;
            $titleWanted = isset($p->title) && is_string($p->title) ? trim(preg_replace('/\s+/', ' ', $p->title)) : '';

            AiStore::ensure($this->db);
            $count = $this->db->prepare("SELECT COUNT(*) FROM `" . self::TABLE . "` WHERE user_id = :u");
            $count->execute([':u' => $this->userId]);
            if ((int)$count->fetchColumn() >= self::MAX_WIDGETS) {
                return $this->err("Hai gia' " . self::MAX_WIDGETS . " widget: eliminane uno prima di crearne un altro.");
            }

            list($from, $to) = $this->thisMonth();
            $error = null;
            $def = null;
            $title = '';
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $res = $this->ai->askJson($this->systemPrompt(), $this->userPrompt($question, $queries, $tools, $chart, $kind, $error), 3000);
                if (isset($res['error'])) return $res['error'];
                $obj = $this->parseJson($res['text']);
                if ($obj === null) { $error = 'la risposta non era JSON valido'; continue; }
                try {
                    list($def, $title) = $this->validateDefinition($obj, $kind);
                    $this->execute($def, $from, $to);   /* prova: guardia + esecuzione + colonne */
                    $error = null;
                    break;
                } catch (InvalidArgumentException $e) {
                    $error = $e->getMessage(); $def = null;
                } catch (AiSqlGuardError $e) {
                    $error = $e->getMessage(); $def = null;
                } catch (PDOException $e) {
                    $error = 'Errore SQL: ' . $e->getMessage(); $def = null;
                } catch (RuntimeException $e) {
                    $error = $e->getMessage(); $def = null;
                }
            }
            if ($def === null) {
                return $this->err("Non sono riuscito a costruire un widget da questa risposta: " . $error, 'ai.widget.build');
            }

            $finalTitle = $titleWanted !== '' ? $titleWanted : ($title !== '' ? $title : ($kind === 'kpi' ? 'Indicatore' : 'Grafico'));
            if (mb_strlen($finalTitle) > self::MAX_TITLE) $finalTitle = mb_substr($finalTitle, 0, self::MAX_TITLE);

            $pos = $this->db->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM `" . self::TABLE . "` WHERE user_id = :u");
            $pos->execute([':u' => $this->userId]);
            $position = (int)$pos->fetchColumn();

            $stmt = $this->db->prepare(
                "INSERT INTO `" . self::TABLE . "` (user_id, title, definition, position, shared, created_at, updated_at)
                 VALUES (:u, :t, :d, :p, 0, NOW(), NOW())");
            $stmt->execute([':u' => $this->userId, ':t' => $finalTitle,
                ':d' => json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':p' => $position]);
            $id = (int)$this->db->lastInsertId();

            return $this->ok(['widget' => $this->row(['id' => $id, 'title' => $finalTitle, 'definition' => json_encode($def), 'position' => $position, 'size' => 'third'])]);
        }

        // ------------------------------------------------------------------
        // Lettura, esecuzione, modifica
        // ------------------------------------------------------------------

        private function row($r) {
            $def = is_array($r['definition']) ? $r['definition'] : json_decode($r['definition'], true);
            if (!is_array($def)) $def = [];
            return [
                'id' => (int)$r['id'],
                'title' => $r['title'],
                'position' => (int)$r['position'],
                'size' => isset($r['size']) && in_array($r['size'], self::SIZES, true) ? $r['size'] : 'third',
                'kind' => isset($def['kind']) ? $def['kind'] : 'chart',
                'usesDates' => !empty($def['uses_dates']),
                'dateNote' => isset($def['date_note']) ? $def['date_note'] : '',
                'sql' => isset($def['sql']) ? $def['sql'] : '',
                'format' => isset($def['kpi']['format']) ? $def['kpi']['format'] : (isset($def['chart']['format']) ? $def['chart']['format'] : 'number'),
            ];
        }

        private function load($id) {
            if (!AiStore::exists($this->db, self::TABLE)) return null;
            $stmt = $this->db->prepare("SELECT * FROM `" . self::TABLE . "` WHERE id = :id AND user_id = :u");
            $stmt->execute([':id' => $id, ':u' => $this->userId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r ? $r : null;
        }

        public function list() {
            if ($this->userId <= 0) return $this->err("Utente non riconosciuto.", 'ai.widget.user', 401);
            if (!AiStore::exists($this->db, self::TABLE)) return $this->ok(['widgets' => []]);
            $stmt = $this->db->prepare("SELECT id, title, definition, position, size FROM `" . self::TABLE . "` WHERE user_id = :u ORDER BY position, id");
            $stmt->execute([':u' => $this->userId]);
            $out = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $out[] = $this->row($r);
            return $this->ok(['widgets' => $out]);
        }

        /** { id, from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' } -> { kind, spec | value, row_count } */
        public function run($p) {
            $id = isset($p->id) ? (int)$p->id : 0;
            $r = $this->load($id);
            if (!$r) return $this->err("Widget non trovato.", 'ai.widget.404', 404);
            $def = json_decode($r['definition'], true);
            if (!is_array($def) || !isset($def['sql'])) return $this->err("Definizione del widget non leggibile.");

            $date = function ($v, $fallback) {
                return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $fallback;
            };
            list($mFrom, $mTo) = $this->thisMonth();
            $from = $date(isset($p->from) ? $p->from : null, $mFrom);
            $to = $date(isset($p->to) ? $p->to : null, $mTo);
            if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

            try {
                $out = $this->execute($def, $from, $to);
                $out['from'] = $from;
                $out['to'] = $to;
                return $this->ok($out);
            } catch (AiSqlGuardError $e) {
                return $this->err("Query del widget non piu' ammessa: " . $e->getMessage(), 'ai.widget.run');
            } catch (PDOException $e) {
                return $this->err("Errore SQL: " . $e->getMessage(), 'ai.widget.run');
            } catch (RuntimeException $e) {
                return $this->err($e->getMessage(), 'ai.widget.run');
            }
        }

        /** { id, title? , size? } oppure { order: [id, id, ...] } */
        public function update($p) {
            if ($this->userId <= 0) return $this->err("Utente non riconosciuto.", 'ai.widget.user', 401);
            if (isset($p->order) && is_array($p->order)) {
                $stmt = $this->db->prepare("UPDATE `" . self::TABLE . "` SET position = :p, updated_at = NOW() WHERE id = :id AND user_id = :u");
                $i = 1;
                foreach ($p->order as $id) $stmt->execute([':p' => $i++, ':id' => (int)$id, ':u' => $this->userId]);
                return $this->ok(['reordered' => $i - 1]);
            }
            $id = isset($p->id) ? (int)$p->id : 0;
            if (!$this->load($id)) return $this->err("Widget non trovato.", 'ai.widget.404', 404);
            if (isset($p->size)) {
                if (!in_array($p->size, self::SIZES, true)) return $this->err("Larghezza non valida.");
                $stmt = $this->db->prepare("UPDATE `" . self::TABLE . "` SET size = :s, updated_at = NOW() WHERE id = :id AND user_id = :u");
                $stmt->execute([':s' => $p->size, ':id' => $id, ':u' => $this->userId]);
            }
            if (isset($p->title)) {
                $title = is_string($p->title) ? trim(preg_replace('/\s+/', ' ', $p->title)) : '';
                if ($title === '') return $this->err("Titolo vuoto.");
                $stmt = $this->db->prepare("UPDATE `" . self::TABLE . "` SET title = :t, updated_at = NOW() WHERE id = :id AND user_id = :u");
                $stmt->execute([':t' => mb_substr($title, 0, self::MAX_TITLE), ':id' => $id, ':u' => $this->userId]);
            }
            return $this->ok(['id' => $id]);
        }

        public function delete($p) {
            if ($this->userId <= 0) return $this->err("Utente non riconosciuto.", 'ai.widget.user', 401);
            $id = isset($p->id) ? (int)$p->id : 0;
            if (!AiStore::exists($this->db, self::TABLE)) return $this->ok(['deleted' => 0]);
            $stmt = $this->db->prepare("DELETE FROM `" . self::TABLE . "` WHERE id = :id AND user_id = :u");
            $stmt->execute([':id' => $id, ':u' => $this->userId]);
            return $this->ok(['deleted' => $stmt->rowCount()]);
        }
    }
