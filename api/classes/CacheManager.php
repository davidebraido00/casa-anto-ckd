<?php

class CacheManager {
    protected $db;
    protected $user_info;
    protected $parameters;
    protected $tablesConfig;
    protected $dependencyMap;
    protected static $tableStatsCache = null;
    protected static $databaseNameCache = null;
    
    function __construct($db, $user_info, $parameters) {
        $this->db = $db;
        $this->user_info = json_decode($user_info, true);
        $this->parameters = $parameters;
    }
    
    /**
     * Get list of tables that have been modified since the last pull time
     * @return array Response with modified tables and current server time
     */
    public function pullTablesStatus() {
        try {
            $lastPullTime = $this->parameters->lastPullTime ?? 0;
            
            // Convert timestamp from milliseconds to seconds if needed
            if ($lastPullTime > 9999999999) {
                $lastPullTime = intval($lastPullTime / 1000);
            }
            
            // Convert to MySQL datetime format
            $lastPullDateTime = date('Y-m-d H:i:s', $lastPullTime);
            
            $modifiedTables = array();
            
            // Get all tables and views from information_schema that belong to current database
            $dbName = $this->getDatabaseName();
            
            // Query to get both tables and views (including gyv_ views)
            // We check the lastUpdate field which is standard in CKD framework
            $tablesQuery = "SELECT DISTINCT TABLE_NAME 
                           FROM information_schema.TABLES 
                           WHERE TABLE_SCHEMA = :dbname 
                           AND TABLE_TYPE IN ('BASE TABLE', 'VIEW')";
            
            $stmt = $this->db->prepare($tablesQuery);
            $stmt->bindParam(':dbname', $dbName, PDO::PARAM_STR);
            $stmt->execute();
            
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // No need to pre-filter tables - UPDATE_TIME approach handles all tables efficiently
            
            // ULTIMATE SOLUTION: Use INFORMATION_SCHEMA UPDATE_TIME (lightning fast!)
            $cacheResult = $this->checkTablesWithUpdateTime($tables, $lastPullDateTime);
            $modifiedTables = $cacheResult['modifiedTables'];
            
            // Add dependent tables to the list of modified tables
            $modifiedTables = $this->addDependentTables($modifiedTables);
            
            // Get current server time in milliseconds
            $serverTime = time() * 1000;
            
            // Debug info
            $dependencyMap = $this->buildDependencyMap();
            
            return [
                'success' => 1,
                'modifiedTables' => $modifiedTables,
                'serverTime' => $serverTime,
                'message' => 'Tables status retrieved successfully',
                'debug' => [
                    'totalTables' => count($tables),
                    'modifiedBaseTables' => $cacheResult['modifiedBaseTables'],
                    'allViews' => $cacheResult['allViews'],
                    'allModifiedTablesBeforeFilter' => $cacheResult['allModifiedTables'],
                    'modifiedTablesFound' => count($modifiedTables),
                    'finalModifiedTables' => $modifiedTables,
                    'executionTime' => $cacheResult['executionTime'] . 'ms',
                    'dependencyMapExists' => !empty($dependencyMap),
                    'dependencyMapKeys' => array_keys($dependencyMap),
                    'configLoaded' => !empty($this->tablesConfig),
                    'configEntities' => $this->tablesConfig ? array_keys($this->tablesConfig) : [],
                    'strategy' => [
                        'usesInformationSchemaUpdateTime' => true,
                        'includesAllViewsOnAnyChange' => true,
                        'includesDependentEntities' => true,
                        'noComplexViewParsing' => true,
                        'performanceGain' => 'From 20+ seconds to milliseconds using UPDATE_TIME'
                    ]
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => 0,
                'message' => 'Error retrieving tables status: ' . $e->getMessage()
            ];
        }
    }
    
    
    /**
     * ULTIMATE SOLUTION: Use INFORMATION_SCHEMA.TABLES.UPDATE_TIME
     * This is LIGHTNING FAST because it doesn't scan table data at all!
     * Works by checking MySQL's internal table modification timestamps
     * @param array $tables Array of table names to check
     * @param string $sinceDate Date in MySQL format
     * @return array Array of modified table names
     */
    private function checkTablesWithUpdateTime($tables, $sinceDate) {
        try {
            $startTime = microtime(true);
            $dbName = $this->getDatabaseName();
            
            // Step 1: Get modified BASE TABLES using UPDATE_TIME (super fast!)
            $modifiedBaseTables = $this->getModifiedBaseTables($dbName, $sinceDate);
            
            // Step 2: If ANY table was modified, include ALL views
            $allViews = array();
            if (!empty($modifiedBaseTables)) {
                $allViews = $this->getAllViews($dbName);
            }
            
            // Step 3: Build final list of modified tables + dependent entities + all views
            $allModifiedTables = $modifiedBaseTables;
            
            // Add all views if there were any base table modifications
            if (!empty($allViews)) {
                $allModifiedTables = array_merge($allModifiedTables, $allViews);
            }
            
            // Step 4: Add dependent entities from tables.json configuration
            $allModifiedTables = $this->addDependentTables($allModifiedTables);
            
            // Step 5: Filter to only requested tables
            $modifiedTables = array_intersect($allModifiedTables, $tables);
            
            $totalTime = (microtime(true) - $startTime) * 1000;
            
            // Return array with debug info for main method
            return array(
                'modifiedTables' => array_unique($modifiedTables),
                'modifiedBaseTables' => $modifiedBaseTables,
                'allViews' => $allViews,
                'allModifiedTables' => $allModifiedTables,
                'executionTime' => $totalTime
            );
            
        } catch (Exception $e) {
            return array(
                'modifiedTables' => array(),
                'modifiedBaseTables' => array(),
                'allViews' => array(),
                'allModifiedTables' => array(),
                'executionTime' => 0
            );
        }
    }
    
    /**
     * Get modified base tables using INFORMATION_SCHEMA.UPDATE_TIME
     * @param string $dbName Database name
     * @param string $sinceDate Date in MySQL format
     * @return array Array of modified base table names
     */
    private function getModifiedBaseTables($dbName, $sinceDate) {
        $query = "SELECT TABLE_NAME 
                  FROM information_schema.TABLES 
                  WHERE TABLE_SCHEMA = :dbname 
                  AND TABLE_TYPE = 'BASE TABLE'
                  AND UPDATE_TIME > :sinceDate";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':dbname', $dbName, PDO::PARAM_STR);
        $stmt->bindParam(':sinceDate', $sinceDate, PDO::PARAM_STR);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Get all views from the database
     * @param string $dbName Database name
     * @return array Array of all view names
     */
    private function getAllViews($dbName) {
        $query = "SELECT TABLE_NAME
                  FROM information_schema.VIEWS 
                  WHERE TABLE_SCHEMA = :dbname";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':dbname', $dbName, PDO::PARAM_STR);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    
    
    /**
     * Get the current database name from settings.json
     * @return string
     */
    private function getDatabaseName() {
        if (self::$databaseNameCache === null) {
            try {
                $settingsPath = __DIR__ . '/../../config/settings.json';
                if (file_exists($settingsPath)) {
                    $jsonContent = file_get_contents($settingsPath);
                    $settings = json_decode($jsonContent, true);
                    
                    if (isset($settings['db']['name'])) {
                        self::$databaseNameCache = $settings['db']['name'];
                        return self::$databaseNameCache;
                    }
                }
                
                // Fallback to SELECT DATABASE() if settings.json not found
                $stmt = $this->db->prepare("SELECT DATABASE()");
                $stmt->execute();
                self::$databaseNameCache = $stmt->fetch(PDO::FETCH_COLUMN);
                
            } catch (Exception $e) {
                // Last resort fallback
                self::$databaseNameCache = 'claude_base_ckd';
            }
        }
        
        return self::$databaseNameCache;
    }
    
    /**
     * Clear cache for specific tables (optional method for manual cache clearing)
     * @return array
     */
    public function clearTableCache() {
        try {
            $tables = $this->parameters->tables ?? [];
            
            if (empty($tables)) {
                return [
                    'success' => 0,
                    'message' => 'No tables specified for cache clearing'
                ];
            }
            
            // This is just a notification endpoint
            // The actual cache clearing happens on the frontend
            return [
                'success' => 1,
                'message' => 'Cache clear request acknowledged',
                'tables' => $tables
            ];
            
        } catch (Exception $e) {
            return [
                'success' => 0,
                'message' => 'Error processing cache clear request: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Load tables configuration from tables.json
     * @return array
     */
    private function loadTablesConfig() {
        if ($this->tablesConfig === null) {
            $configPath = __DIR__ . '/../../config/tables.json';
            if (file_exists($configPath)) {
                $jsonContent = file_get_contents($configPath);
                $this->tablesConfig = json_decode($jsonContent, true);
            } else {
                $this->tablesConfig = array();
            }
        }
        return $this->tablesConfig;
    }
    
    /**
     * Build dependency map from tables configuration
     * @return array
     */
    private function buildDependencyMap() {
        if ($this->dependencyMap !== null) {
            return $this->dependencyMap;
        }
        
        $this->dependencyMap = array();
        $config = $this->loadTablesConfig();
        
        foreach ($config as $entityName => $entityConfig) {
            if (!isset($entityConfig['columns'])) {
                continue;
            }
            
            foreach ($entityConfig['columns'] as $fieldName => $fieldConfig) {
                $inputType = $fieldConfig['input_type'] ?? '';
                
                // Handle externalEntity relationships
                if ($inputType === 'externalEntity' && isset($fieldConfig['options']['entity'])) {
                    $referencedEntity = $fieldConfig['options']['entity'];
                    
                    // Add dependency: if referencedEntity changes, entityName cache should be invalidated
                    if (!isset($this->dependencyMap[$referencedEntity])) {
                        $this->dependencyMap[$referencedEntity] = array();
                    }
                    if (!in_array($entityName, $this->dependencyMap[$referencedEntity])) {
                        $this->dependencyMap[$referencedEntity][] = $entityName;
                    }
                }
                
                // Handle multipleExternalEntity relationships
                if ($inputType === 'multipleExternalEntity' && isset($fieldConfig['options']['entity'])) {
                    $referencedEntity = $fieldConfig['options']['entity'];
                    
                    if (!isset($this->dependencyMap[$referencedEntity])) {
                        $this->dependencyMap[$referencedEntity] = array();
                    }
                    if (!in_array($entityName, $this->dependencyMap[$referencedEntity])) {
                        $this->dependencyMap[$referencedEntity][] = $entityName;
                    }
                }
                
                // Handle deepExternalEntity relationships
                if ($inputType === 'deepExternalEntity' && isset($fieldConfig['options']['entity'])) {
                    $referencedEntity = $fieldConfig['options']['entity'];
                    
                    // For deep relationships, both directions should invalidate cache
                    if (!isset($this->dependencyMap[$referencedEntity])) {
                        $this->dependencyMap[$referencedEntity] = array();
                    }
                    if (!in_array($entityName, $this->dependencyMap[$referencedEntity])) {
                        $this->dependencyMap[$referencedEntity][] = $entityName;
                    }
                    
                    // Also add reverse dependency (child changes should invalidate parent)
                    if (!isset($this->dependencyMap[$entityName])) {
                        $this->dependencyMap[$entityName] = array();
                    }
                    if (!in_array($referencedEntity, $this->dependencyMap[$entityName])) {
                        $this->dependencyMap[$entityName][] = $referencedEntity;
                    }
                }
            }
        }
        
        return $this->dependencyMap;
    }
    
    /**
     * Add dependent tables to the list of modified tables
     * @param array $modifiedTables
     * @return array
     */
    private function addDependentTables($modifiedTables) {
        $dependencyMap = $this->buildDependencyMap();
        $allModifiedTables = $modifiedTables;
        
        // For each modified table, add its dependents
        foreach ($modifiedTables as $table) {
            if (isset($dependencyMap[$table])) {
                foreach ($dependencyMap[$table] as $dependentTable) {
                    if (!in_array($dependentTable, $allModifiedTables)) {
                        $allModifiedTables[] = $dependentTable;
                    }
                }
            }
        }
        
        return array_unique($allModifiedTables);
    }
    
    /**
     * Get dependency information (for debugging)
     * @return array
     */
    public function getDependencyInfo() {
        try {
            $dependencyMap = $this->buildDependencyMap();
            
            return [
                'success' => 1,
                'dependencyMap' => $dependencyMap,
                'message' => 'Dependency information retrieved successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => 0,
                'message' => 'Error retrieving dependency info: ' . $e->getMessage()
            ];
        }
    }
}

?>