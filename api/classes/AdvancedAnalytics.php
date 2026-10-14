<?php
/**
 * Advanced Analytics API Class
 * Classe generica per analytics che funziona su tutti i progetti CKD framework
 * Usa le entità definite in tables.json per essere universale
 */

class AdvancedAnalytics {
    
    private $db;
    private $user_info;
    private $parameters;
    private $entities;
    
    function __construct($db, $user_info, $parameters) {
        $this->db = $db;
        $this->user_info = json_decode($user_info, true);
        $this->parameters = $parameters;
        
        // Carica le entità dal tables.json
        $this->loadEntities();
    }
    
    /**
     * Carica le entità dal file tables.json
     */
    private function loadEntities() {
        $tablesFile = __DIR__ . '/../../config/tables.json';
        if (file_exists($tablesFile)) {
            $tablesContent = file_get_contents($tablesFile);
            $tablesData = json_decode($tablesContent, true);
            $this->entities = array_keys($tablesData);
        } else {
            // Fallback con entità base sempre presenti
            $this->entities = ['user', 'user_group', 'log', 'resource'];
        }
    }
    
    /**
     * Helper per restituire risposte di successo
     */
    private function success($data) {
        return [
            'success' => 1,
            'data' => $data
        ];
    }
    
    /**
     * Helper per restituire errori
     */
    private function error($message) {
        return [
            'success' => 0,
            'message' => $message
        ];
    }
    
    /**
     * Overview Analytics - KPI principali basati sulle entità disponibili
     */
    public function get_overview_analytics() {
        try {
            $params = $this->extractParameters();
            $timeRange = $params['timeRange'] ?? '7d';
            $dateFilter = $this->getDateFilter($timeRange, $params);
            
            $analytics = [];
            
            // Per ogni entità, calcola statistiche base
            foreach ($this->entities as $entity) {
                // Conta totali
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `$entity` WHERE deleted = 0");
                $stmt->execute();
                $total = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                
                // Conta nuovi nel periodo
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `$entity` WHERE deleted = 0 AND date >= :start");
                $stmt->execute(['start' => $dateFilter['start']]);
                $newInPeriod = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                
                // Calcola variazione percentuale
                $previousPeriod = $this->getPreviousPeriodFilter($timeRange);
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `$entity` WHERE deleted = 0 AND date >= :start AND date <= :end");
                $stmt->execute(['start' => $previousPeriod['start'], 'end' => $previousPeriod['end']]);
                $prevPeriod = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                
                $change = $prevPeriod > 0 ? round((($newInPeriod - $prevPeriod) / $prevPeriod) * 100, 1) : 0;
                
                $analytics[$entity] = [
                    'total' => $total,
                    'newInPeriod' => $newInPeriod,
                    'change' => $change
                ];
            }
            
            // Utenti attivi nel periodo selezionato (usando auth_token con lastUpdate nel range)
            $stmt = $this->db->prepare("SELECT COUNT(DISTINCT idUser) as count FROM auth_token WHERE lastUpdate >= :start AND lastUpdate <= :end");
            $stmt->execute([
                'start' => $dateFilter['start'],
                'end' => $dateFilter['end']
            ]);
            $activeUsers = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            // Operazioni nel periodo selezionato (usando tabella log)
            $operations = 0;
            if (in_array('log', $this->entities)) {
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end");
                $stmt->execute([
                    'start' => $dateFilter['start'],
                    'end' => $dateFilter['end']
                ]);
                $operations = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            }
            
            return $this->success([
                'entities' => $analytics,
                'activeUsers' => $activeUsers,
                'todayOperations' => $operations,
                'period' => $timeRange,
                'availableEntities' => $this->entities
            ]);
            
        } catch (Exception $e) {
            return $this->error("Errore nel recupero delle analytics: " . $e->getMessage());
        }
    }
    
    /**
     * Trends Data - Dati per grafici temporali
     */
    public function get_trends_data() {
        try {
            $params = $this->extractParameters();
            $timeRange = $params['timeRange'] ?? '7d';
            $entity = $params['entity'] ?? null;
            $dateFilter = $this->getDateFilter($timeRange, $params);
            
            // Verifica che l'entità esista (se specificata)
            if ($entity !== null && !in_array($entity, $this->entities)) {
                return $this->error("Entità '$entity' non trovata");
            }
            
            $granularity = $this->getGranularity($timeRange);
            
            $labels = [];
            $created = [];
            $updated = [];
            $deleted = [];
            $logins = [];
            $logouts = [];
            
            // Genera le date labels
            $current = new DateTime($dateFilter['start']);
            $end = new DateTime($dateFilter['end']);
            
            while ($current <= $end) {
                $dateKey = $this->formatDateByGranularity($current, $granularity);
                $labels[] = $dateKey;
                
                $startOfPeriod = $this->getStartOfPeriod($current, $granularity);
                $endOfPeriod = $this->getEndOfPeriod($current, $granularity);
                
                if (in_array('log', $this->entities)) {
                    // Costruisci filtro per entità (solo per operazioni CRUD)
                    // Se entity = 'all', non aggiungere filtro per vedere TUTTI i log
                    $entityFilter = "";
                    $crudParams = ['start' => $startOfPeriod, 'end' => $endOfPeriod];
                    
                    if ($entity !== null && !empty($entity)) {
                        $entityFilter = " AND entity = :entity";
                        $crudParams['entity'] = $entity;
                    }
                    
                    // Creazioni (operation = 2_ADD) - con filtro entità
                    $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end AND operation = '2_ADD'" . $entityFilter);
                    $stmt->execute($crudParams);
                    $created[] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    // Modifiche (operation = 1_EDIT) - con filtro entità
                    $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end AND operation = '1_EDIT'" . $entityFilter);
                    $stmt->execute($crudParams);
                    $updated[] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    // Eliminazioni (operation = 0_DELETE) - con filtro entità
                    $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end AND operation = '0_DELETE'" . $entityFilter);
                    $stmt->execute($crudParams);
                    $deleted[] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    // Login (operation = 3_LOGIN) - SEMPRE globale, senza filtro entità
                    $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end AND operation = '3_LOGIN'");
                    $stmt->execute(['start' => $startOfPeriod, 'end' => $endOfPeriod]);
                    $logins[] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    // Logout (operation = 4_LOGOUT) - SEMPRE globale, senza filtro entità
                    $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end AND operation = '4_LOGOUT'");
                    $stmt->execute(['start' => $startOfPeriod, 'end' => $endOfPeriod]);
                    $logouts[] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                } else {
                    // Fallback se non c'è la tabella log
                    $created[] = 0;
                    $updated[] = 0;
                    $deleted[] = 0;
                    $logins[] = 0;
                    $logouts[] = 0;
                }
                
                $this->incrementPeriod($current, $granularity);
            }
            
            // Attività oraria e settimanale (sempre globali, non filtrati per entità)
            $hourlyActivity = $this->getHourlyActivity();
            $weeklyActivity = $this->getWeeklyActivity($dateFilter);
            
            return $this->success([
                'labels' => $labels,
                'created' => $created,
                'updated' => $updated,
                'deleted' => $deleted,
                'logins' => $logins,
                'logouts' => $logouts,
                'hourlyActivity' => $hourlyActivity,
                'weeklyActivity' => $weeklyActivity,
                'granularity' => $granularity
            ]);
            
        } catch (Exception $e) {
            return $this->error("Errore nel recupero dei trend: " . $e->getMessage());
        }
    }
    
    /**
     * Entity Analytics - Analisi dettagliata entità
     */
    public function get_entity_analytics() {
        try {
            $params = $this->extractParameters();
            $entity = $params['entity'] ?? 'user';
            $timeRange = $params['timeRange'] ?? '7d';
            
            // Verifica che l'entità esista
            if (!in_array($entity, $this->entities)) {
                return $this->error("Entità '$entity' non trovata");
            }
            
            // Top entità per attività recente (con controllo campi disponibili)
            try {
                // Verifica i campi disponibili per questa entità
                $stmt = $this->db->prepare("SHOW COLUMNS FROM `$entity`");
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Verifica campi base necessari
                if (!in_array('date', $columns) || !in_array('deleted', $columns) || !in_array('lastUpdate', $columns)) {
                    return $this->error("Entità '$entity' non ha i campi base richiesti (date, deleted, lastUpdate)");
                }
                
                // Trova un campo nome appropriato
                $nameField = 'CONCAT(\'ID: \', e.id)'; // default
                if (in_array('nome', $columns)) {
                    $nameField = 'COALESCE(e.nome, CONCAT(\'ID: \', e.id))';
                } elseif (in_array('name', $columns)) {
                    $nameField = 'COALESCE(e.name, CONCAT(\'ID: \', e.id))';
                } elseif (in_array('username', $columns)) {
                    $nameField = 'COALESCE(e.username, CONCAT(\'ID: \', e.id))';
                } elseif (in_array('title', $columns)) {
                    $nameField = 'COALESCE(e.title, CONCAT(\'ID: \', e.id))';
                }
                
                $sql = "SELECT 
                            e.id,
                            $nameField as name,
                            e.date as created,
                            e.lastUpdate as lastModified
                        FROM `$entity` e
                        WHERE e.deleted = 0
                        ORDER BY e.lastUpdate DESC
                        LIMIT 10";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute();
                $topEntities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                return $this->error("Errore nel recupero dati entità: " . $e->getMessage());
            }
            
            // Tasso di crescita mensile
            $monthlyGrowthRate = $this->calculateMonthlyGrowthRate($entity);
            
            // Statistiche generali
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM `$entity` WHERE deleted = 0");
            $stmt->execute();
            $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `$entity` WHERE deleted = 1");
            $stmt->execute();
            $deletedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            return $this->success([
                'topEntities' => $topEntities,
                'monthlyGrowthRate' => $monthlyGrowthRate,
                'totalActive' => $totalCount,
                'totalDeleted' => $deletedCount,
                'entity' => $entity
            ]);
            
        } catch (Exception $e) {
            return $this->error("Errore nell'analisi entità: " . $e->getMessage());
        }
    }
    
    
    /**
     * Activity Log - Log delle attività recenti con filtro date, ricerca e pagination
     */
    public function get_activity_log() {
        try {
            $params = $this->extractParameters();
            $limit = intval($params['limit'] ?? 50);
            $page = intval($params['page'] ?? 1);
            $search = $params['search'] ?? '';
            $timeRange = $params['timeRange'] ?? '7d';
            $dateFilter = $this->getDateFilter($timeRange, $params);
            
            $offset = ($page - 1) * $limit;
            $activities = [];
            $totalCount = 0;
            
            // Query principale per la tabella log con join su user
            try {
                // Prima verifica che la tabella log esista
                $stmt = $this->db->prepare("SHOW TABLES LIKE 'log'");
                $stmt->execute();
                if ($stmt->rowCount() == 0) {
                    return $this->success([]);
                }
                
                // Verifica i campi disponibili nella tabella log
                $stmt = $this->db->prepare("SHOW COLUMNS FROM log");
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Campi opzionali
                $hasEntity = in_array('entity', $columns);
                $hasIdItem = in_array('idItem', $columns);
                $hasMessage = in_array('message', $columns);
                
                // Costruisci condizioni WHERE per la ricerca
                $whereConditions = ["l.date >= :start AND l.date <= :end"];
                $searchParams = [];
                
                if (!empty($search)) {
                    $searchConditions = [];
                    $searchConditions[] = "u.username LIKE :searchUser";
                    if ($hasEntity) {
                        $searchConditions[] = "l.entity LIKE :searchEntity";
                    }
                    if ($hasIdItem) {
                        $searchConditions[] = "CAST(l.idItem AS CHAR) LIKE :searchId";
                    }
                    $whereConditions[] = "(" . implode(" OR ", $searchConditions) . ")";
                    $searchParams['searchUser'] = '%' . $search . '%';
                    $searchParams['searchEntity'] = '%' . $search . '%';
                    $searchParams['searchId'] = '%' . $search . '%';
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                // Prima conta il totale per la pagination
                $countSql = "SELECT COUNT(*) as total
                            FROM log l
                            LEFT JOIN user u ON l.idUser = u.id
                            WHERE " . $whereClause;
                
                $stmt = $this->db->prepare($countSql);
                $stmt->bindValue(':start', $dateFilter['start'], PDO::PARAM_STR);
                $stmt->bindValue(':end', $dateFilter['end'], PDO::PARAM_STR);
                foreach ($searchParams as $key => $value) {
                    $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
                }
                $stmt->execute();
                $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                
                // Poi recupera i record paginati
                $sql = "SELECT 
                            l.id,
                            l.date,
                            l.idUser,
                            l.operation,
                            " . ($hasEntity ? "l.entity" : "NULL as entity") . ",
                            " . ($hasIdItem ? "l.idItem" : "NULL as idItem") . ",
                            " . ($hasMessage ? "l.message" : "NULL as message") . ",
                            u.username,
                            u.email,
                            CONCAT(IFNULL(u.name, ''), ' ', IFNULL(u.surname, '')) as fullname
                        FROM log l
                        LEFT JOIN user u ON l.idUser = u.id
                        WHERE " . $whereClause . "
                        ORDER BY l.date DESC
                        LIMIT :limit OFFSET :offset";
                
                $stmt = $this->db->prepare($sql);
                $stmt->bindValue(':start', $dateFilter['start'], PDO::PARAM_STR);
                $stmt->bindValue(':end', $dateFilter['end'], PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                foreach ($searchParams as $key => $value) {
                    $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
                }
                $stmt->execute();
                    
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Formatta i log per il frontend
                foreach ($logs as $log) {
                    $activities[] = [
                        'id' => $log['id'],
                        'date' => $log['date'],
                        'idUser' => $log['idUser'],
                        'username' => $log['username'] ?? 'Sistema',
                        'email' => $log['email'],
                        'fullname' => trim($log['fullname']) ?: null,
                        'operation' => $log['operation'],
                        'entity' => $log['entity'],
                        'idItem' => $log['idItem'],
                        'message' => $log['message'],
                        'details' => $log['message']
                    ];
                }
                
            } catch (Exception $e) {
                error_log("Error in get_activity_log: " . $e->getMessage());
                // Se fallisce, restituisci array vuoto
                $activities = [];
                $totalCount = 0;
            }
            
            // Restituisci i dati con informazioni di pagination
            return $this->success([
                'records' => $activities,
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => ceil($totalCount / $limit)
            ]);
            
        } catch (Exception $e) {
            return $this->error("Errore nel recupero log attività: " . $e->getMessage());
        }
    }
    
    /**
     * Session Analytics - Login/Logout data
     */
    public function get_sessions_data() {
        try {
            $params = $this->extractParameters();
            $timeRange = $params['timeRange'] ?? '7d';
            $dateFilter = $this->getDateFilter($timeRange, $params);
            
            $granularity = $this->getGranularity($timeRange);
            
            $labels = [];
            $logins = [];
            $logouts = [];
            
            if (in_array('log', $this->entities)) {
                // Genera le date labels
                $current = new DateTime($dateFilter['start']);
                $end = new DateTime($dateFilter['end']);
                
                while ($current <= $end) {
                    $dateKey = $this->formatDateByGranularity($current, $granularity);
                    $labels[] = $dateKey;
                    
                    $startOfPeriod = $this->getStartOfPeriod($current, $granularity);
                    $endOfPeriod = $this->getEndOfPeriod($current, $granularity);
                    
                    // Login (operation = 3_LOGIN)
                    $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end AND operation = '3_LOGIN'");
                    $stmt->execute(['start' => $startOfPeriod, 'end' => $endOfPeriod]);
                    $logins[] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    // Logout (operation = 4_LOGOUT)
                    $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end AND operation = '4_LOGOUT'");
                    $stmt->execute(['start' => $startOfPeriod, 'end' => $endOfPeriod]);
                    $logouts[] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    $this->incrementPeriod($current, $granularity);
                }
            }
            
            return $this->success([
                'labels' => $labels,
                'logins' => $logins,
                'logouts' => $logouts,
                'timeRange' => $timeRange
            ]);
            
        } catch (Exception $e) {
            return $this->error("Errore nel recupero dati sessioni: " . $e->getMessage());
        }
    }
    
    /**
     * Server Info - Informazioni server real-time per monitoring
     */
    public function get_server_info() {
        try {
            // Info PHP
            $phpVersion = PHP_VERSION;
            
            // Info MySQL
            $mysqlVersion = 'N/A';
            try {
                $stmt = $this->db->query("SELECT VERSION() as version");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $mysqlVersion = $result['version'] ?? 'N/A';
            } catch (Exception $e) {
                $mysqlVersion = 'Error: ' . $e->getMessage();
            }
            
            // Info Sistema Operativo
            $osInfo = php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m');
            
            // Info Server Web
            $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
            
            // Utilizzo Memoria
            $memoryUsage = $this->formatBytes(memory_get_usage(true));
            $memoryPeak = $this->formatBytes(memory_get_peak_usage(true));
            
            // Test connessione database
            $dbStatus = 'OK';
            $dbResponseTime = 0;
            try {
                $startTime = microtime(true);
                $stmt = $this->db->query("SELECT 1");
                $endTime = microtime(true);
                $dbResponseTime = round(($endTime - $startTime) * 1000, 2);
            } catch (Exception $e) {
                $dbStatus = 'ERROR: ' . $e->getMessage();
                $dbResponseTime = 0;
            }
            
            return $this->success([
                'phpVersion' => $phpVersion,
                'mysqlVersion' => $mysqlVersion,
                'osInfo' => $osInfo,
                'serverSoftware' => $serverSoftware,
                'frameworkVersion' => 'CKD Framework v1.0',
                'memoryUsage' => $memoryUsage . ' (Peak: ' . $memoryPeak . ')',
                'dbStatus' => $dbStatus,
                'dbResponseTime' => $dbResponseTime,
                'serverTime' => date('Y-m-d H:i:s'),
                'uptime' => $this->getServerUptime(),
                'diskSpace' => $this->getDiskSpace()
            ]);
            
        } catch (Exception $e) {
            return $this->error("Errore nel recupero info server: " . $e->getMessage());
        }
    }
    
    /**
     * Helper per formattare i byte
     */
    private function formatBytes($size, $precision = 2) {
        $base = log($size, 1024);
        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }
    
    /**
     * Helper per ottenere l'uptime del server (se disponibile)
     */
    private function getServerUptime() {
        if (function_exists('sys_getloadavg') && file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $uptime = explode(' ', $uptime);
            $seconds = intval($uptime[0]);
            
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            
            return "{$days}d {$hours}h {$minutes}m";
        }
        return 'N/A';
    }
    
    /**
     * Helper per ottenere lo spazio disco disponibile
     */
    private function getDiskSpace() {
        try {
            $totalSpace = disk_total_space('.');
            $freeSpace = disk_free_space('.');
            
            if ($totalSpace && $freeSpace) {
                $usedSpace = $totalSpace - $freeSpace;
                $usedPercent = round(($usedSpace / $totalSpace) * 100, 2);
                
                return $this->formatBytes($freeSpace) . ' liberi di ' . 
                       $this->formatBytes($totalSpace) . ' (' . $usedPercent . '% utilizzato)';
            }
        } catch (Exception $e) {
            return 'N/A';
        }
        return 'N/A';
    }

    /**
     * Export Analytics Report - Restituisce il file in base64
     */
    public function export_analytics_report() {
        try {
            $params = $this->extractParameters();
            $timeRange = $params['timeRange'] ?? '7d';
            
            // Genera sempre il CSV con i log del periodo
            $content = $this->generateCsvReport($timeRange, $params);
            $mimeType = 'text/csv';
            
            // Converti in base64
            $base64Content = base64_encode($content);
            
            return $this->success([
                'content' => $base64Content,
                'filename' => "activity_logs_" . date('Y-m-d_H-i-s') . ".csv",
                'format' => 'csv',
                'mimeType' => $mimeType,
                'generated' => date('Y-m-d H:i:s'),
                'size' => strlen($content)
            ]);
            
        } catch (Exception $e) {
            return $this->error("Errore nell'export del report: " . $e->getMessage());
        }
    }
    
    /**
     * Genera report CSV con tutti i log del periodo selezionato
     */
    private function generateCsvReport($timeRange, $params) {
        // Recupera tutti i log senza limite di paginazione
        $dateFilter = $this->getDateFilter($timeRange, $params);
        
        $csv = "Date,Username,Email,Operation,Entity,Item ID,Details\n";
        
        try {
            // Query per recuperare tutti i log del periodo senza limit
            $sql = "SELECT 
                        l.id,
                        l.date,
                        l.idUser,
                        l.operation,
                        COALESCE(l.entity, '') as entity,
                        COALESCE(l.idItem, '') as idItem,
                        COALESCE(l.message, '') as message,
                        COALESCE(u.username, 'Sistema') as username,
                        COALESCE(u.email, '') as email
                    FROM log l
                    LEFT JOIN user u ON l.idUser = u.id
                    WHERE l.date >= :start AND l.date <= :end
                    ORDER BY l.date DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':start', $dateFilter['start'], PDO::PARAM_STR);
            $stmt->bindValue(':end', $dateFilter['end'], PDO::PARAM_STR);
            $stmt->execute();
            
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($logs as $record) {
                $csv .= '"' . ($record['date'] ?? '') . '",';
                $csv .= '"' . ($record['username'] ?? '') . '",';
                $csv .= '"' . ($record['email'] ?? '') . '",';
                $csv .= '"' . ($record['operation'] ?? '') . '",';
                $csv .= '"' . ($record['entity'] ?? '') . '",';
                $csv .= '"' . ($record['idItem'] ?? '') . '",';
                $csv .= '"' . str_replace('"', '""', $record['message'] ?? '') . '"';
                $csv .= "\n";
            }
            
        } catch (Exception $e) {
            // In caso di errore, aggiungi solo l'header
            error_log("Error generating CSV: " . $e->getMessage());
        }
        
        return $csv;
    }
    
    // ========== METODI HELPER ==========
    
    private function extractParameters() {
        return is_object($this->parameters) ? (array)$this->parameters : $this->parameters;
    }
    
    private function getDateFilter($timeRange, $params = []) {
        $end = new DateTime();
        $start = clone $end;
        
        switch($timeRange) {
            case '24h':
                $start->sub(new DateInterval('P1D'));
                break;
            case '7d':
                $start->sub(new DateInterval('P7D'));
                break;
            case '30d':
                $start->sub(new DateInterval('P30D'));
                break;
            case 'custom':
                if (!empty($params['startDate'])) {
                    $start = new DateTime($params['startDate']);
                    $start->setTime(0, 0, 0); // Imposta all'inizio del giorno
                }
                if (!empty($params['endDate'])) {
                    $end = new DateTime($params['endDate']);
                    $end->setTime(23, 59, 59); // Imposta alla fine del giorno
                }
                break;
        }
        
        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s')
        ];
    }
    
    private function getPreviousPeriodFilter($timeRange) {
        $filter = $this->getDateFilter($timeRange);
        $start = new DateTime($filter['start']);
        $end = new DateTime($filter['end']);
        $diff = $start->diff($end);
        
        $prevEnd = clone $start;
        $prevStart = clone $start;
        $prevStart->sub($diff);
        
        return [
            'start' => $prevStart->format('Y-m-d H:i:s'),
            'end' => $prevEnd->format('Y-m-d H:i:s')
        ];
    }
    
    private function getGranularity($timeRange) {
        switch($timeRange) {
            case '24h':
                return 'hour';
            case '7d':
                return 'day';
            case '30d':
                return 'day';
            default:
                return 'day';
        }
    }
    
    private function formatDateByGranularity($date, $granularity) {
        switch($granularity) {
            case 'hour':
                return $date->format('H:00');
            case 'day':
                return $date->format('d/m');
            case 'week':
                return 'Sett ' . $date->format('W');
            case 'month':
                return $date->format('M Y');
            default:
                return $date->format('d/m/Y');
        }
    }
    
    private function getStartOfPeriod($date, $granularity) {
        $start = clone $date;
        switch($granularity) {
            case 'hour':
                $start->setTime($start->format('H'), 0, 0);
                break;
            case 'day':
                $start->setTime(0, 0, 0);
                break;
            case 'week':
                $start->modify('monday this week')->setTime(0, 0, 0);
                break;
            case 'month':
                $start->modify('first day of this month')->setTime(0, 0, 0);
                break;
        }
        return $start->format('Y-m-d H:i:s');
    }
    
    private function getEndOfPeriod($date, $granularity) {
        $end = clone $date;
        switch($granularity) {
            case 'hour':
                $end->setTime($end->format('H'), 59, 59);
                break;
            case 'day':
                $end->setTime(23, 59, 59);
                break;
            case 'week':
                $end->modify('sunday this week')->setTime(23, 59, 59);
                break;
            case 'month':
                $end->modify('last day of this month')->setTime(23, 59, 59);
                break;
        }
        return $end->format('Y-m-d H:i:s');
    }
    
    private function incrementPeriod(&$date, $granularity) {
        switch($granularity) {
            case 'hour':
                $date->add(new DateInterval('PT1H'));
                break;
            case 'day':
                $date->add(new DateInterval('P1D'));
                break;
            case 'week':
                $date->add(new DateInterval('P7D'));
                break;
            case 'month':
                $date->add(new DateInterval('P1M'));
                break;
        }
    }
    
    private function countOperations($since) {
        $count = 0;
        
        // Conta operazioni per ogni entità
        foreach ($this->entities as $entity) {
            try {
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `$entity` WHERE date >= :since");
                $stmt->execute(['since' => $since]);
                $count += $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            } catch (Exception $e) {
                continue; // Ignora errori per entità che potrebbero non esistere
            }
        }
        
        return $count;
    }
    
    private function calculateMonthlyGrowthRate($entity) {
        $lastMonth = new DateTime();
        $lastMonth->sub(new DateInterval('P1M'));
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `$entity` WHERE date >= :date AND deleted = 0");
        $stmt->execute(['date' => $lastMonth->format('Y-m-d H:i:s')]);
        $lastMonthCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        $twoMonthsAgo = clone $lastMonth;
        $twoMonthsAgo->sub(new DateInterval('P1M'));
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `$entity` WHERE date >= :start AND date < :end AND deleted = 0");
        $stmt->execute([
            'start' => $twoMonthsAgo->format('Y-m-d H:i:s'),
            'end' => $lastMonth->format('Y-m-d H:i:s')
        ]);
        $previousMonthCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        if ($previousMonthCount > 0) {
            return round((($lastMonthCount - $previousMonthCount) / $previousMonthCount) * 100, 1);
        }
        
        return 0;
    }
    
    private function getHourlyActivity() {
        $activity = array_fill(0, 24, 0);
        
        if (in_array('log', $this->entities)) {
            // Calcola attività reale per ogni ora usando i log di oggi
            $today = date('Y-m-d');
            for ($i = 0; $i < 24; $i++) {
                $startHour = sprintf('%s %02d:00:00', $today, $i);
                $endHour = sprintf('%s %02d:59:59', $today, $i);
                
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end");
                $stmt->execute(['start' => $startHour, 'end' => $endHour]);
                $activity[$i] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            }
        } else {
            // Simula dati di attività oraria se non c'è il log
            for ($i = 0; $i < 24; $i++) {
                if ($i >= 8 && $i <= 18) {
                    $activity[$i] = rand(5, 30);
                } else {
                    $activity[$i] = rand(0, 10);
                }
            }
        }
        
        return $activity;
    }
    
    private function getWeeklyActivity($dateFilter) {
        $activity = array_fill(0, 7, 0);
        
        if (in_array('log', $this->entities)) {
            // Calcola attività reale per ogni giorno della settimana
            for ($i = 0; $i < 7; $i++) {
                // Calcola la data per ogni giorno della settimana (0 = lunedì, 6 = domenica)
                $date = new DateTime();
                $date->modify('monday this week');
                $date->add(new DateInterval('P' . $i . 'D'));
                
                $startOfDay = $date->format('Y-m-d 00:00:00');
                $endOfDay = $date->format('Y-m-d 23:59:59');
                
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM log WHERE date >= :start AND date <= :end");
                $stmt->execute(['start' => $startOfDay, 'end' => $endOfDay]);
                $activity[$i] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            }
        } else {
            // Simula dati se non c'è il log
            for ($i = 0; $i < 7; $i++) {
                $activity[$i] = rand(10, 50);
            }
        }
        
        return $activity;
    }
    
    
    
}
?>