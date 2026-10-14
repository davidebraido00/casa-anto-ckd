<?php

    /**
     * Pollice su / pollice giu' su una risposta dell'assistente.
     *
     * Una riga per (utente, conversazione, indice del messaggio): rivotare
     * aggiorna la riga, togliere il voto la cancella. Insieme al voto si
     * salva una copia della domanda e della risposta, cosi' il pannello
     * admin mostra le segnalazioni senza dipendere dallo storico (che
     * l'utente puo' cancellare).
     *
     * Tabella `ai_feedback`, creata da AiStore e nascosta al modello. Chi
     * scrive e' sempre chi vota: l'id utente viene dalla sessione, mai dal
     * client.
     */
    class AiFeedback {

        const TABLE = AiStore::FEEDBACK;
        const MAX_COMMENT = 2000;
        const MAX_QUESTION = 4000;
        const MAX_ANSWER = 20000;

        protected $db;
        protected $userId;

        function __construct($db, $userInfo) {
            $this->db = $db;
            $info = is_array($userInfo) ? $userInfo : json_decode((string)$userInfo, true);
            $this->userId = isset($info['id']) ? (int)$info['id'] : 0;
        }

        private function err($message, $code = 'ai.feedback', $status = 400) {
            return ['success' => 0, 'status' => $status, 'error_code' => $code, 'message' => $message];
        }

        private function ok($data) {
            return ['success' => 1, 'status' => 200, 'data' => $data, 'message' => 'ok'];
        }

        /**
         * { conversation, index, vote: 1 | -1 | 0, comment?, question?, answer? }
         * vote 0 = rimuove il voto.
         */
        public function save($p) {
            if ($this->userId <= 0) return $this->err("Utente non riconosciuto.", 'ai.feedback.user', 401);

            $conversation = isset($p->conversation) ? preg_replace('/[^a-z0-9]/i', '', (string)$p->conversation) : '';
            if ($conversation === '' || strlen($conversation) > 32) return $this->err("Id conversazione non valido.");
            $index = isset($p->index) && is_numeric($p->index) ? (int)$p->index : -1;
            if ($index < 0) return $this->err("Indice del messaggio non valido.");
            $vote = isset($p->vote) && is_numeric($p->vote) ? (int)$p->vote : 0;
            if ($vote !== 1 && $vote !== -1 && $vote !== 0) return $this->err("Voto non valido.");

            AiStore::ensure($this->db);

            if ($vote === 0) {
                $stmt = $this->db->prepare(
                    "DELETE FROM `" . self::TABLE . "` WHERE user_id = :u AND conversation_id = :c AND message_index = :i");
                $stmt->execute([':u' => $this->userId, ':c' => $conversation, ':i' => $index]);
                return $this->ok(['vote' => 0]);
            }

            $text = function ($key, $max) use ($p) {
                if (!isset($p->$key) || !is_string($p->$key)) return null;
                $v = trim($p->$key);
                if ($v === '') return null;
                return mb_substr($v, 0, $max);
            };

            $stmt = $this->db->prepare(
                "INSERT INTO `" . self::TABLE . "`
                    (user_id, conversation_id, message_index, vote, comment, question, answer, created_at, updated_at)
                 VALUES (:u, :c, :i, :v, :m, :q, :a, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE vote = VALUES(vote), comment = VALUES(comment),
                    question = VALUES(question), answer = VALUES(answer), updated_at = NOW()"
            );
            $stmt->execute([
                ':u' => $this->userId,
                ':c' => $conversation,
                ':i' => $index,
                ':v' => $vote,
                ':m' => $text('comment', self::MAX_COMMENT),
                ':q' => $text('question', self::MAX_QUESTION),
                ':a' => $text('answer', self::MAX_ANSWER),
            ]);
            return $this->ok(['vote' => $vote]);
        }
    }
