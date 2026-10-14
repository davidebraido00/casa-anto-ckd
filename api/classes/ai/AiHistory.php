<?php

    /**
     * Storico delle conversazioni dell'assistente AI, per utente.
     *
     * Una riga per conversazione: l'id lo genera il client (come faceva con
     * localStorage), i messaggi stanno in un JSON, e ogni turno fa un upsert.
     * Ogni utente vede e tocca soltanto le proprie righe.
     *
     * La tabella `ai_history` NON sta in tables.json di proposito: e' un dato
     * interno del framework, non un'entita' del progetto, quindi niente
     * DataGrid, niente export, niente updatedb. La crea AiStore, insieme alle
     * altre tabelle ai_*, e per lo stesso motivo AiSchema la tiene tra le
     * tabelle nascoste: il modello non la vede e non la puo' leggere.
     *
     * Scrive con la connessione normale del framework, non con l'utente
     * read-only dell'assistente: a scrivere e' il framework, non il modello.
     */
    class AiHistory {

        const TABLE = AiStore::HISTORY;
        const MAX_ROWS = 50;
        const MAX_TITLE = 255;
        const MAX_PAYLOAD_BYTES = 400000;

        protected $db;
        protected $userId;

        function __construct($db, $user_info) {
            $this->db = $db;
            $info = json_decode($user_info, true);
            $this->userId = isset($info['id']) ? (int)$info['id'] : 0;
        }

        private function err($message, $code = 'ai.history', $status = 400) {
            return ['success' => 0, 'status' => $status, 'error_code' => $code, 'message' => $message];
        }

        private function ok($data) {
            return ['success' => 1, 'status' => 200, 'data' => $data, 'message' => 'ok'];
        }

        /** Se la tabella non c'e' ancora, non c'e' nemmeno storico: niente CREATE in lettura. */
        private function tableExists() {
            return AiStore::exists($this->db, self::TABLE);
        }

        private function row($r) {
            $messages = json_decode($r['messages'], true);
            return [
                'id'        => $r['id'],
                'title'     => $r['title'],
                'createdAt' => (int)$r['created_ms'],
                'updatedAt' => (int)$r['updated_ms'],
                'archived'  => isset($r['archived']) ? (int)$r['archived'] === 1 : false,
                'messages'  => is_array($messages) ? $messages : [],
            ];
        }

        /**
         * Le conversazioni dell'utente, dalla piu' recente. Di default quelle
         * attive; con { archived: true } quelle archiviate.
         */
        public function list($p = null) {
            if ($this->userId <= 0) return $this->err("Utente non riconosciuto.", 'ai.history.user', 401);
            if (!$this->tableExists()) return $this->ok(['conversations' => []]);
            /* La tabella c'e': ensure qui fa solo gli ALTER delle colonne
               aggiunte dopo (archived), cosi' una lettura subito dopo un
               aggiornamento non trova una colonna che manca. */
            AiStore::ensure($this->db);

            $archived = is_object($p) && !empty($p->archived) ? 1 : 0;
            $stmt = $this->db->prepare(
                "SELECT id, title, messages, archived,
                        UNIX_TIMESTAMP(created_at) * 1000 AS created_ms,
                        UNIX_TIMESTAMP(updated_at) * 1000 AS updated_ms
                   FROM `" . self::TABLE . "`
                  WHERE user_id = :u AND archived = :a
                  ORDER BY updated_at DESC
                  LIMIT " . self::MAX_ROWS
            );
            $stmt->execute([':u' => $this->userId, ':a' => $archived]);
            $out = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $out[] = $this->row($r);
            return $this->ok(['conversations' => $out]);
        }

        /**
         * Upsert di una conversazione: { id, title, messages[] }.
         * I messaggi vengono ripuliti ai soli campi noti prima di finire nel JSON.
         */
        public function save($p) {
            if ($this->userId <= 0) return $this->err("Utente non riconosciuto.", 'ai.history.user', 401);

            $id = isset($p->id) ? preg_replace('/[^a-z0-9]/i', '', (string)$p->id) : '';
            if ($id === '' || strlen($id) > 32) return $this->err("Id conversazione non valido.", 'ai.history.id');

            $messages = isset($p->messages) ? $p->messages : null;
            if (!is_array($messages) || count($messages) === 0) return $this->err("Nessun messaggio da salvare.", 'ai.history.empty');

            $clean = [];
            foreach ($messages as $m) {
                $role = is_object($m) && isset($m->role) ? $m->role : null;
                if (($role !== 'user' && $role !== 'assistant') || !isset($m->content)) {
                    return $this->err("Messaggio non valido.", 'ai.history.message');
                }
                $item = ['role' => $role, 'content' => (string)$m->content];
                if (isset($m->queries) && is_array($m->queries)) $item['queries'] = $m->queries;
                /* Pagina da cui e' stata scritta la domanda (pannello laterale): rotta interna ed etichetta. */
                if (isset($m->page) && is_object($m->page) && isset($m->page->route) && is_string($m->page->route)
                    && preg_match('#^/[^\s]*$#', $m->page->route)) {
                    $item['page'] = [
                        'route' => mb_substr($m->page->route, 0, 255),
                        'label' => isset($m->page->label) && is_string($m->page->label) ? mb_substr($m->page->label, 0, 120) : '',
                    ];
                }
                /* Allegati trascinati in chat: solo nome, tipo e dimensione, mai il contenuto. */
                if (isset($m->attachments) && is_array($m->attachments)) {
                    $att = [];
                    foreach (array_slice($m->attachments, 0, 10) as $a) {
                        if (!is_object($a) || !isset($a->name)) continue;
                        $att[] = [
                            'name' => mb_substr((string)$a->name, 0, 200),
                            'kind' => isset($a->kind) ? mb_substr((string)$a->kind, 0, 20) : 'file',
                            'size' => isset($a->size) ? (int)$a->size : 0,
                        ];
                    }
                    if (count($att)) $item['attachments'] = $att;
                }
                /* Voto dato alla risposta (vedi AiFeedback): resta anche riaprendo la conversazione. */
                if (isset($m->feedback) && is_object($m->feedback) && isset($m->feedback->vote)) {
                    $item['feedback'] = ['vote' => (int)$m->feedback->vote];
                    if (isset($m->feedback->comment) && is_string($m->feedback->comment)) {
                        $item['feedback']['comment'] = mb_substr($m->feedback->comment, 0, 2000);
                    }
                }
                $clean[] = $item;
            }
            $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > self::MAX_PAYLOAD_BYTES) {
                return $this->err("Conversazione troppo grande per essere salvata.", 'ai.history.too_big');
            }

            $title = isset($p->title) ? trim(preg_replace('/\s+/', ' ', (string)$p->title)) : '';
            if ($title === '') $title = 'Conversazione';
            if (mb_strlen($title) > self::MAX_TITLE) $title = mb_substr($title, 0, self::MAX_TITLE);

            AiStore::ensure($this->db);

            /* L'id arriva dal client: prima di sovrascrivere si controlla che
               la riga, se esiste, sia di chi scrive. */
            $owner = $this->db->prepare("SELECT user_id FROM `" . self::TABLE . "` WHERE id = :id");
            $owner->execute([':id' => $id]);
            $existing = $owner->fetchColumn();
            if ($existing !== false && (int)$existing !== $this->userId) {
                return $this->err("Conversazione non tua.", 'ai.history.forbidden', 403);
            }

            if ($existing === false) {
                $stmt = $this->db->prepare(
                    "INSERT INTO `" . self::TABLE . "` (id, user_id, title, messages, created_at, updated_at)
                     VALUES (:id, :u, :t, :m, NOW(), NOW())"
                );
            }
            else {
                $stmt = $this->db->prepare(
                    "UPDATE `" . self::TABLE . "` SET title = :t, messages = :m, updated_at = NOW(), archived = 0
                      WHERE id = :id AND user_id = :u"
                );
            }
            $stmt->execute([':id' => $id, ':u' => $this->userId, ':t' => $title, ':m' => $json]);

            return $this->ok(['id' => $id]);
        }

        /** Archivia ({ id, archived: true }) o ripristina ({ id, archived: false }). */
        public function archive($p) {
            if ($this->userId <= 0) return $this->err("Utente non riconosciuto.", 'ai.history.user', 401);
            $id = isset($p->id) ? preg_replace('/[^a-z0-9]/i', '', (string)$p->id) : '';
            if ($id === '') return $this->err("Id conversazione non valido.", 'ai.history.id');
            if (!$this->tableExists()) return $this->ok(['updated' => 0]);
            AiStore::ensure($this->db);

            $archived = !empty($p->archived) ? 1 : 0;
            $stmt = $this->db->prepare("UPDATE `" . self::TABLE . "` SET archived = :a WHERE id = :id AND user_id = :u");
            $stmt->execute([':a' => $archived, ':id' => $id, ':u' => $this->userId]);
            return $this->ok(['updated' => $stmt->rowCount(), 'archived' => $archived === 1]);
        }

        public function delete($p) {
            if ($this->userId <= 0) return $this->err("Utente non riconosciuto.", 'ai.history.user', 401);
            $id = isset($p->id) ? preg_replace('/[^a-z0-9]/i', '', (string)$p->id) : '';
            if ($id === '') return $this->err("Id conversazione non valido.", 'ai.history.id');
            if (!$this->tableExists()) return $this->ok(['deleted' => 0]);

            $stmt = $this->db->prepare("DELETE FROM `" . self::TABLE . "` WHERE id = :id AND user_id = :u");
            $stmt->execute([':id' => $id, ':u' => $this->userId]);
            return $this->ok(['deleted' => $stmt->rowCount()]);
        }
    }
