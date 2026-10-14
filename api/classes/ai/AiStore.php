<?php

    /**
     * Le tabelle interne dell'assistente AI, in un posto solo.
     *
     * Sono dati del framework, non entita' del progetto: NON stanno in
     * tables.json (niente DataGrid, niente export, niente updatedb) e il
     * modello non le vede (AiSchema le nasconde leggendo l'elenco da qui).
     * Vengono create alla prima richiesta all'assistente con CREATE TABLE IF
     * NOT EXISTS, cosi' nessun progetto ha un passo di installazione: chi
     * allinea il framework e ricarica i file ha gia' tutto.
     *
     * Ogni nuova funzionalita' che ha bisogno di una tabella la aggiunge QUI,
     * non in una classe a parte: un elenco solo da tenere, un solo giro di
     * CREATE per richiesta.
     *
     * Scrive con la connessione normale del framework, non con l'utente
     * read-only dell'assistente: a scrivere e' il framework, mai il modello.
     * Modifiche a tabelle esistenti (colonne nuove) vanno fatte come ALTER
     * idempotenti in `upgrade()`: CREATE IF NOT EXISTS non tocca chi c'e' gia'.
     */
    class AiStore {

        /** Conversazioni per utente (AiHistory). */
        const HISTORY = 'ai_history';
        /** Una riga per chiamata al modello: token e cache (AiUsage). */
        const USAGE = 'ai_usage';
        /** Pollice su/giu' su una risposta, con commento (AiFeedback). */
        const FEEDBACK = 'ai_feedback';
        /** Widget in dashboard: query parametrizzata + grafico o KPI, per utente (AiWidget). */
        const WIDGET = 'ai_widget';

        /** Tutte le tabelle: e' l'elenco che AiSchema nasconde al modello. */
        const TABLES = [self::HISTORY, self::USAGE, self::FEEDBACK, self::WIDGET];

        /** Una sola CREATE per richiesta, anche con piu' classi che scrivono. */
        private static $ensured = false;

        /**
         * Crea cio' che manca. Non lancia mai: un fallimento (permessi del
         * DB, connessione) finisce nel log e la richiesta prosegue; sara' la
         * prima INSERT a dire cosa non va.
         */
        public static function ensure($db) {
            if (self::$ensured) return;
            self::$ensured = true;
            try {
                foreach (self::definitions() as $sql) $db->exec($sql);
                self::upgrade($db);
            } catch (Exception $e) {
                error_log('[ai-store] creazione tabelle non riuscita: ' . $e->getMessage());
            }
        }

        private static function definitions() {
            return [
                "CREATE TABLE IF NOT EXISTS `" . self::HISTORY . "` (
                    `id`         VARCHAR(32)  NOT NULL,
                    `user_id`    INT          NOT NULL,
                    `title`      VARCHAR(255) NOT NULL,
                    `messages`   LONGTEXT     NOT NULL,
                    `created_at` DATETIME     NOT NULL,
                    `updated_at` DATETIME     NOT NULL,
                    `archived`   TINYINT(1)   NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_updated` (`user_id`, `updated_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS `" . self::USAGE . "` (
                    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id`              INT          NOT NULL,
                    `conversation_id`      VARCHAR(32)  NULL,
                    `route`                VARCHAR(255) NULL,
                    `model`                VARCHAR(80)  NOT NULL,
                    `input_tokens`         INT          NOT NULL DEFAULT 0,
                    `output_tokens`        INT          NOT NULL DEFAULT 0,
                    `cache_read_tokens`    INT          NOT NULL DEFAULT 0,
                    `cache_write_tokens`   INT          NOT NULL DEFAULT 0,
                    `stop_reason`          VARCHAR(40)  NULL,
                    `created_at`           DATETIME     NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_created` (`user_id`, `created_at`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS `" . self::FEEDBACK . "` (
                    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id`          INT          NOT NULL,
                    `conversation_id`  VARCHAR(32)  NOT NULL,
                    `message_index`    INT          NOT NULL,
                    `vote`             TINYINT      NOT NULL,
                    `comment`          TEXT         NULL,
                    `question`         TEXT         NULL,
                    `answer`           MEDIUMTEXT   NULL,
                    `created_at`       DATETIME     NOT NULL,
                    `updated_at`       DATETIME     NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_message` (`user_id`, `conversation_id`, `message_index`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS `" . self::WIDGET . "` (
                    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id`     INT          NOT NULL,
                    `title`       VARCHAR(255) NOT NULL,
                    `definition`  LONGTEXT     NOT NULL,
                    `position`    INT          NOT NULL DEFAULT 0,
                    `size`        VARCHAR(8)   NOT NULL DEFAULT 'third',
                    `shared`      TINYINT      NOT NULL DEFAULT 0,
                    `created_at`  DATETIME     NOT NULL,
                    `updated_at`  DATETIME     NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_position` (`user_id`, `position`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }

        /**
         * Migrazioni idempotenti sulle tabelle gia' esistenti. Vuota finche'
         * non serve: quando una colonna si aggiunge, qui si controlla
         * information_schema e si fa l'ALTER solo se manca.
         */
        private static function upgrade($db) {
            /* ai_widget.size: larghezza in dashboard (third | half | full), aggiunta dopo la prima versione. */
            self::addColumn($db, self::WIDGET, 'size', "VARCHAR(8) NOT NULL DEFAULT 'third' AFTER `position`");
            /* ai_history.archived: conversazioni messe da parte, fuori dall'elenco ma non cancellate. */
            self::addColumn($db, self::HISTORY, 'archived', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `updated_at`");
        }

        /** ALTER TABLE ADD COLUMN solo se la colonna manca (information_schema). */
        private static function addColumn($db, $table, $column, $definition) {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
            $stmt->execute([':t' => $table, ':c' => $column]);
            if ((int)$stmt->fetchColumn() > 0) return;
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }

        /** La tabella esiste? Per le letture, che non devono creare nulla. */
        public static function exists($db, $table) {
            $stmt = $db->prepare("SHOW TABLES LIKE :t");
            $stmt->execute([':t' => $table]);
            return $stmt->fetchColumn() !== false;
        }
    }
?>
