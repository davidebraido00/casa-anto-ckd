<?php

    /**
     * StorageManager
     * -----------------------------------------------------------------------
     * Unico punto di accesso ai byte fisici dei file gestiti dal framework.
     * Il provider viene dichiarato in config/settings.json -> "storage".
     *
     *   "storage": {
     *       "provider": "local",            // "local" (default) | "supabase"
     *       "supabase": {
     *           "url": "https://<ref>.supabase.co",
     *           "bucket": "tie-uploads",
     *           "service_key": "<service_role_key>",
     *           "public": true
     *       }
     *   }
     *
     * Se il blocco "storage" manca o provider = "local", il comportamento e'
     * identico a quello storico (scrittura/lettura su ../upload/{entity}/).
     *
     * API:
     *   StorageManager::put($entity, $name, $content)  -> void  (throw su errore)
     *   StorageManager::get($entity, $name)            -> string|false
     *   StorageManager::exists($entity, $name)         -> bool
     *   StorageManager::size($entity, $name)           -> int|null
     *   StorageManager::url($entity, $name)            -> string
     *
     * $entity = sottocartella logica (es. "resource", "cargo_documents")
     * $name   = nome file completo "hash.ext"
     */
    class StorageManager {

        private static $cfg = null;
        private static $override = null;

        /**
         * Forza il provider a runtime, ignorando settings.json.
         * Usato SOLO dallo script di migrazione: consente di caricare su
         * "supabase" mentre in settings il provider attivo e' ancora "local".
         * Le credenziali supabase vengono comunque lette da settings.json.
         */
        public static function useProvider($provider) {
            self::$override = $provider;
        }

        /** Carica (una sola volta) la configurazione storage da settings.json */
        private static function config() {
            if (self::$cfg !== null) {
                return self::$cfg;
            }
            $settings = json_decode(file_get_contents(__DIR__ . '/../../config/settings.json'), true);
            $storage = (isset($settings['storage']) && is_array($settings['storage'])) ? $settings['storage'] : array();
            if (!isset($storage['provider']) || $storage['provider'] === '') {
                $storage['provider'] = 'local';
            }
            $storage['__domain'] = isset($settings['host']['domain']) ? rtrim($settings['host']['domain'], '/') : '';
            self::$cfg = $storage;
            return self::$cfg;
        }

        private static function provider() {
            if (self::$override !== null) {
                return self::$override;
            }
            $cfg = self::config();
            return $cfg['provider'];
        }

        /** Path base dello storage locale: ckd_root/upload/ */
        private static function localBase() {
            return __DIR__ . '/../../upload/';
        }

        /** MIME type a partire dall'estensione (per header Content-Type upload) */
        private static function mimeFor($name) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            switch ($ext) {
                case 'pdf':  return 'application/pdf';
                case 'png':  return 'image/png';
                case 'jpg':
                case 'jpeg': return 'image/jpeg';
                case 'doc':  return 'application/msword';
                case 'docx': return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                default:     return 'application/octet-stream';
            }
        }

        // =====================================================================
        //  API PUBBLICA
        // =====================================================================

        public static function put($entity, $name, $content) {
            if (self::provider() === 'supabase') {
                self::supabasePut($entity, $name, $content);
            } else {
                self::localPut($entity, $name, $content);
            }
        }

        public static function get($entity, $name) {
            if (self::provider() === 'supabase') {
                return self::supabaseGet($entity, $name);
            }
            return self::localGet($entity, $name);
        }

        public static function exists($entity, $name) {
            if (self::provider() === 'supabase') {
                return self::supabaseGet($entity, $name) !== false;
            }
            return file_exists(self::localBase() . $entity . '/' . $name);
        }

        public static function size($entity, $name) {
            if (self::provider() === 'supabase') {
                // Per scelta: nessuna HEAD per file negli elenchi -> size non calcolato.
                return null;
            }
            $path = self::localBase() . $entity . '/' . $name;
            return file_exists($path) ? filesize($path) : null;
        }

        public static function url($entity, $name) {
            if (self::provider() === 'supabase') {
                $sb   = self::supabaseCfg();
                $base = rtrim($sb['url'], '/');
                return $base . '/storage/v1/object/public/' . $sb['bucket'] . '/' . self::supabaseObjectKey($entity, $name);
            }
            $cfg = self::config();
            return $cfg['__domain'] . '/upload/' . $entity . '/' . $name;
        }

        // =====================================================================
        //  DRIVER: LOCAL  (replica fedele del comportamento storico)
        // =====================================================================

        private static function localPut($entity, $name, $content) {
            $dir = self::localBase() . $entity . '/';
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }
            $written = file_put_contents($dir . $name, $content);
            if ($written === false) {
                throw new Exception("Impossibile scrivere il file sullo storage locale.");
            }
        }

        private static function localGet($entity, $name) {
            return @file_get_contents(self::localBase() . $entity . '/' . $name);
        }

        // =====================================================================
        //  DRIVER: SUPABASE STORAGE  (REST API, solo cURL, nessuna dipendenza)
        // =====================================================================

        private static function supabaseCfg() {
            $cfg = self::config();
            if (!isset($cfg['supabase']) || !isset($cfg['supabase']['url']) || !isset($cfg['supabase']['bucket']) || !isset($cfg['supabase']['service_key'])) {
                throw new Exception("Configurazione storage 'supabase' incompleta in settings.json.");
            }
            return $cfg['supabase'];
        }

        /**
         * Chiave oggetto dentro il bucket. Mantiene il prefisso "upload/" cosi'
         * la struttura combacia con quella locale e con il frontend, che
         * costruisce l'URL come ckdPath + "upload/" + entity + "/" + name.
         * -> impostando ckdPath al base pubblico Supabase, le URL coincidono.
         */
        private static function supabaseObjectKey($entity, $name) {
            return 'upload/' . rawurlencode($entity) . '/' . rawurlencode($name);
        }

        private static function supabasePut($entity, $name, $content) {
            $sb   = self::supabaseCfg();
            $base = rtrim($sb['url'], '/');
            $key  = $sb['service_key'];
            $endpoint = $base . '/storage/v1/object/' . $sb['bucket'] . '/' . self::supabaseObjectKey($entity, $name);

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
                'Content-Type: ' . self::mimeFor($name),
                'x-upsert: true'
            ));
            $response = curl_exec($ch);
            $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($status < 200 || $status >= 300) {
                throw new Exception("Upload su Supabase fallito (HTTP {$status}): " . ($response ? $response : $curlErr));
            }
        }

        private static function supabaseGet($entity, $name) {
            $sb   = self::supabaseCfg();
            $base = rtrim($sb['url'], '/');
            $key  = $sb['service_key'];
            // Bucket pubblico: endpoint /object/public/. Includo comunque l'apikey (innocua).
            $endpoint = $base . '/storage/v1/object/public/' . $sb['bucket'] . '/' . self::supabaseObjectKey($entity, $name);

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key
            ));
            $response = curl_exec($ch);
            $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status < 200 || $status >= 300) {
                return false;
            }
            return $response;
        }

    }
?>
