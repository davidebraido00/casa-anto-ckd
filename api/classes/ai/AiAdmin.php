<?php

    /**
     * Pannello di amministrazione dell'assistente: spesa, uso, domande,
     * segnalazioni. Sola lettura sulle tabelle ai_* e su `user` per i nomi.
     *
     * Riservato ai livelli in `ai_assistant.admin_levels` (default [0]): e'
     * un controllo a parte rispetto a chi puo' usare la chat.
     *
     * Il costo si calcola in SQL con il listino di AiUsage (CASE sui prefissi
     * del modello), cosi' le somme per giorno, modello e utente sono una
     * query ciascuna. Le domande frequenti si leggono dallo storico
     * (ai_history): i messaggi sono JSON, si scorrono in PHP sulle
     * conversazioni del periodo, con un tetto.
     */
    class AiAdmin {

        const MAX_CONVERSATIONS_SCANNED = 500;
        const TOP_QUESTIONS = 20;
        const FEEDBACK_ROWS = 40;
        const ANSWER_EXCERPT = 800;

        protected $db;
        protected $usage;

        function __construct($db, $aiUsage) {
            $this->db = $db;
            $this->usage = $aiUsage;
        }

        private function ok($data) {
            return ['success' => 1, 'status' => 200, 'data' => $data, 'message' => 'ok'];
        }

        /** Periodo -> [from, to) come DATETIME, piu' l'etichetta. */
        private function period($key) {
            $today = new DateTime('today');
            switch ($key) {
                case 'prev_month': {
                    $from = (clone $today)->modify('first day of last month');
                    $to = (clone $today)->modify('first day of this month');
                    break;
                }
                case '30d': {
                    $from = (clone $today)->modify('-29 days');
                    $to = (clone $today)->modify('+1 day');
                    break;
                }
                case 'year': {
                    $from = new DateTime($today->format('Y') . '-01-01');
                    $to = (clone $today)->modify('+1 day');
                    break;
                }
                case 'all': {
                    $from = new DateTime('2000-01-01');
                    $to = (clone $today)->modify('+1 day');
                    break;
                }
                case 'month':
                default: {
                    $key = 'month';
                    $from = (clone $today)->modify('first day of this month');
                    $to = (clone $today)->modify('+1 day');
                }
            }
            return ['key' => $key, 'from' => $from->format('Y-m-d 00:00:00'), 'to' => $to->format('Y-m-d 00:00:00')];
        }

        /** { period: month | prev_month | 30d | year | all } */
        public function stats($p) {
            $period = $this->period(isset($p->period) && is_string($p->period) ? $p->period : 'month');
            $out = [
                'period' => $period,
                'currency' => $this->usage->currency(),
                'budget' => $this->usage->budget(),
                'spent_month' => $this->usage->spentThisMonth(),
                'totals' => ['calls' => 0, 'conversations' => 0, 'users' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
                             'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'cost' => 0],
                'by_day' => [],
                'by_model' => [],
                'by_user' => [],
                'questions' => [],
                'feedback' => ['up' => 0, 'down' => 0, 'items' => []],
            ];

            $range = [':from' => $period['from'], ':to' => $period['to']];

            if (AiStore::exists($this->db, AiStore::USAGE)) {
                list($costSql, $costParams) = $this->usage->costSql();
                $params = array_merge($range, $costParams);
                $T = '`' . AiStore::USAGE . '`';
                $where = "created_at >= :from AND created_at < :to";

                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) AS calls, COUNT(DISTINCT conversation_id) AS conversations, COUNT(DISTINCT user_id) AS users,
                            COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens,
                            COALESCE(SUM(cache_read_tokens), 0) AS cache_read_tokens, COALESCE(SUM(cache_write_tokens), 0) AS cache_write_tokens,
                            COALESCE(SUM({$costSql}), 0) AS cost
                       FROM {$T} WHERE {$where}");
                $stmt->execute($params);
                $t = $stmt->fetch(PDO::FETCH_ASSOC);
                foreach ($out['totals'] as $k => $v) {
                    $out['totals'][$k] = $k === 'cost' ? round((float)$t[$k], 4) : (int)$t[$k];
                }

                $stmt = $this->db->prepare(
                    "SELECT DATE(created_at) AS day, COUNT(*) AS calls, COALESCE(SUM({$costSql}), 0) AS cost
                       FROM {$T} WHERE {$where} GROUP BY DATE(created_at) ORDER BY day");
                $stmt->execute($params);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $out['by_day'][] = ['day' => $r['day'], 'calls' => (int)$r['calls'], 'cost' => round((float)$r['cost'], 4)];
                }

                $stmt = $this->db->prepare(
                    "SELECT model, COUNT(*) AS calls,
                            COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens,
                            COALESCE(SUM(cache_read_tokens), 0) AS cache_read_tokens, COALESCE(SUM(cache_write_tokens), 0) AS cache_write_tokens,
                            COALESCE(SUM({$costSql}), 0) AS cost
                       FROM {$T} WHERE {$where} GROUP BY model ORDER BY cost DESC");
                $stmt->execute($params);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $out['by_model'][] = [
                        'model' => $r['model'], 'calls' => (int)$r['calls'],
                        'input_tokens' => (int)$r['input_tokens'], 'output_tokens' => (int)$r['output_tokens'],
                        'cache_read_tokens' => (int)$r['cache_read_tokens'], 'cache_write_tokens' => (int)$r['cache_write_tokens'],
                        'cost' => round((float)$r['cost'], 4),
                        'priced' => $this->usage->price($r['model']) !== null,
                    ];
                }

                $stmt = $this->db->prepare(
                    "SELECT a.user_id, u.name, u.surname, u.email, COUNT(*) AS calls,
                            COUNT(DISTINCT a.conversation_id) AS conversations, COALESCE(SUM({$costSql}), 0) AS cost,
                            MAX(a.created_at) AS last_call
                       FROM {$T} a LEFT JOIN `user` u ON u.id = a.user_id
                      WHERE a.created_at >= :from AND a.created_at < :to
                      GROUP BY a.user_id, u.name, u.surname, u.email ORDER BY cost DESC");
                $stmt->execute($params);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $out['by_user'][] = [
                        'user_id' => (int)$r['user_id'], 'name' => $this->userName($r),
                        'calls' => (int)$r['calls'], 'conversations' => (int)$r['conversations'],
                        'cost' => round((float)$r['cost'], 4), 'last_call' => $r['last_call'],
                    ];
                }
            }

            if (AiStore::exists($this->db, AiStore::HISTORY)) {
                $out['questions'] = $this->topQuestions($range);
            }

            if (AiStore::exists($this->db, AiStore::FEEDBACK)) {
                $F = '`' . AiStore::FEEDBACK . '`';
                $stmt = $this->db->prepare(
                    "SELECT SUM(vote = 1) AS up, SUM(vote = -1) AS down FROM {$F}
                      WHERE updated_at >= :from AND updated_at < :to");
                $stmt->execute($range);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                $out['feedback']['up'] = (int)$r['up'];
                $out['feedback']['down'] = (int)$r['down'];

                $stmt = $this->db->prepare(
                    "SELECT f.id, f.vote, f.comment, f.question, f.answer, f.conversation_id, f.updated_at,
                            u.name, u.surname, u.email
                       FROM {$F} f LEFT JOIN `user` u ON u.id = f.user_id
                      WHERE f.updated_at >= :from AND f.updated_at < :to
                      ORDER BY (f.vote = -1) DESC, f.updated_at DESC
                      LIMIT " . self::FEEDBACK_ROWS);
                $stmt->execute($range);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $answer = (string)$r['answer'];
                    $out['feedback']['items'][] = [
                        'id' => (int)$r['id'], 'vote' => (int)$r['vote'], 'comment' => $r['comment'],
                        'question' => $r['question'],
                        'answer' => mb_strlen($answer) > self::ANSWER_EXCERPT ? mb_substr($answer, 0, self::ANSWER_EXCERPT) . '…' : $answer,
                        'conversation' => $r['conversation_id'], 'date' => $r['updated_at'], 'user' => $this->userName($r),
                    ];
                }
            }

            return $this->ok($out);
        }

        private function userName($r) {
            $n = trim((isset($r['name']) ? $r['name'] : '') . ' ' . (isset($r['surname']) ? $r['surname'] : ''));
            if ($n !== '') return $n;
            if (!empty($r['email'])) return $r['email'];
            return isset($r['user_id']) ? 'utente ' . $r['user_id'] : 'utente';
        }

        /**
         * Domande piu' frequenti nel periodo: tutte le domande dell'utente
         * nelle conversazioni toccate nel periodo, normalizzate (minuscole,
         * spazi, punteggiatura finale) e contate.
         */
        private function topQuestions($range) {
            $stmt = $this->db->prepare(
                "SELECT messages FROM `" . AiStore::HISTORY . "`
                  WHERE updated_at >= :from AND updated_at < :to
                  ORDER BY updated_at DESC LIMIT " . self::MAX_CONVERSATIONS_SCANNED);
            $stmt->execute($range);
            $counts = [];
            $samples = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $messages = json_decode($r['messages'], true);
                if (!is_array($messages)) continue;
                foreach ($messages as $m) {
                    if (!is_array($m) || !isset($m['role']) || $m['role'] !== 'user' || !isset($m['content'])) continue;
                    $text = trim(preg_replace('/\s+/u', ' ', (string)$m['content']));
                    if ($text === '') continue;
                    $key = mb_strtolower(rtrim($text, " ?!."));
                    if (!isset($counts[$key])) { $counts[$key] = 0; $samples[$key] = mb_substr($text, 0, 200); }
                    $counts[$key]++;
                }
            }
            arsort($counts);
            $out = [];
            foreach (array_slice($counts, 0, self::TOP_QUESTIONS, true) as $key => $n) {
                $out[] = ['text' => $samples[$key], 'count' => $n];
            }
            return $out;
        }
    }
