<?php

    require_once __DIR__ . '/../../libs/SimpleXLSXGen.php';

    /**
     * Export in Excel di una query dell'assistente.
     *
     * In chat il modello vede al massimo `max_rows` righe (default 200): per
     * avere l'elenco completo l'utente scarica un file, e il file NON passa
     * dal modello. Il client rimanda il SQL della query cosi' com'e' nel
     * registro della risposta; qui viene RI-validato da AiSql come qualsiasi
     * altro SQL che arriva dal browser, e rieseguito con l'utente read-only e
     * un tetto dedicato (`ai_assistant.export_max_rows`, default 5000).
     *
     * Il LIMIT che AiSql aggiunge in coda alle query senza limite (quello di
     * chat) viene tolto, altrimenti l'export sarebbe lungo quanto la chat. Un
     * LIMIT diverso e' del modello o dell'utente ("i primi 10") e resta.
     *
     * Il file ha due fogli: i dati (intestazioni = colonne della SELECT) e
     * la query, con data, utente e l'eventuale nota di troncamento. Torna in
     * base64 dentro il JSON, come /utils/export_for_import, e lo scarica il
     * client (Utils.downloadBase64).
     */
    class AiExport {

        const DEFAULT_MAX_ROWS = 5000;
        const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        private $sql;
        private $user_info;
        private $maxRows;

        function __construct($aiSql, $user_info, $aiConfig = []) {
            $this->sql = $aiSql;
            $this->user_info = $user_info;
            $this->maxRows = isset($aiConfig['export_max_rows'])
                ? max(1, (int)$aiConfig['export_max_rows']) : self::DEFAULT_MAX_ROWS;
        }

        public function maxRows() {
            return $this->maxRows;
        }

        /**
         * Toglie il LIMIT di chat aggiunto da AiSql::guardSelect (e' sempre in
         * coda ed e' sempre uguale a max_rows). Qualsiasi altro LIMIT resta.
         */
        private function stripChatLimit($sql) {
            $chatLimit = (int)$this->sql->maxRows();
            return preg_replace('/\s+limit\s+' . $chatLimit . '\s*$/i', '', trim((string)$sql));
        }

        /**
         * I valori arrivano da PDO come stringhe: un importo o un conteggio
         * deve diventare un numero in Excel, un telefono o un CAP con lo zero
         * davanti no. Regola: numerico, senza zero iniziale (tranne "0" e
         * "0.5"), non troppo lungo per un double.
         */
        private function cellValue($v) {
            if ($v === null) return '';
            if (is_int($v) || is_float($v)) return $v;
            $s = (string)$v;
            if ($s === '' || !is_numeric($s) || strlen($s) > 15) return $s;
            if (preg_match('/^-?0\d/', $s)) return $s;          /* 0123 */
            if (preg_match('/[eE]/', $s)) return $s;             /* 1e5: lascia il testo */
            return strpos($s, '.') !== false ? (float)$s : (int)$s;
        }

        private function slug($text) {
            $s = strtolower(trim((string)$text));
            $s = preg_replace('/[^a-z0-9]+/', '-', $s);
            $s = trim($s, '-');
            return $s === '' ? 'query' : substr($s, 0, 50);
        }

        /**
         * Torna [success, data{content, filename, mimetype, row_count,
         * truncated}] oppure [success => 0, message].
         */
        public function xlsx($rawSql, $purpose = null) {
            $sql = $this->stripChatLimit($rawSql);
            if ($sql === '') {
                return ['success' => 0, 'status' => 400, 'error_code' => 'ai.export', 'message' => 'Query vuota.'];
            }

            /* Una riga in piu' del tetto: cosi' si sa se il file e' completo. */
            try {
                $res = $this->sql->runSelect($sql, $this->maxRows + 1);
            } catch (AiSqlGuardError $e) {
                return ['success' => 0, 'status' => 400, 'error_code' => 'ai.export', 'message' => $e->getMessage()];
            } catch (PDOException $e) {
                return ['success' => 0, 'status' => 400, 'error_code' => 'ai.export', 'message' => 'Errore SQL: ' . $e->getMessage()];
            }

            $rows = $res['rows'];
            $truncated = count($rows) > $this->maxRows;
            if ($truncated) $rows = array_slice($rows, 0, $this->maxRows);

            if (!count($rows)) {
                return ['success' => 0, 'status' => 400, 'error_code' => 'ai.export_empty', 'message' => 'La query non restituisce righe: niente da esportare.'];
            }

            $data = [array_keys($rows[0])];
            foreach ($rows as $row) {
                $line = [];
                foreach ($row as $v) $line[] = $this->cellValue($v);
                $data[] = $line;
            }

            $userName = trim((isset($this->user_info['name']) ? $this->user_info['name'] : '') . ' '
                . (isset($this->user_info['surname']) ? $this->user_info['surname'] : ''));
            $info = [
                ['Query', $res['sql']],
                ['Scopo', (string)$purpose],
                ['Esportato il', date('d/m/Y H:i')],
                ['Da', $userName !== '' ? $userName : (isset($this->user_info['email']) ? $this->user_info['email'] : '')],
                ['Righe', count($rows)],
            ];
            if ($truncated) {
                $info[] = ['Nota', "File troncato a {$this->maxRows} righe: restringi il filtro per averle tutte."];
            }

            try {
                $xlsx = SimpleXLSXGen::fromArray($data, 'Dati');
                $xlsx->addSheet($info, 'Query');
                $content = $xlsx->toString();
            } catch (Exception $e) {
                $content = false;
            }
            if ($content === false) {
                return ['success' => 0, 'status' => 500, 'error_code' => 'ai.export', 'message' => 'Generazione del file Excel non riuscita.'];
            }

            error_log('[ai-export] ' . count($rows) . ' righe' . ($truncated ? ' (troncato)' : '') . ' :: ' . $res['sql']);

            return ['success' => 1, 'status' => 200, 'message' => 'ok', 'data' => [
                'content' => base64_encode($content),
                'filename' => 'assistente_' . $this->slug($purpose) . '_' . date('Y-m-d_H-i') . '.xlsx',
                'mimetype' => self::MIME,
                'row_count' => count($rows),
                'truncated' => $truncated,
                'max_rows' => $this->maxRows,
            ]];
        }
    }
