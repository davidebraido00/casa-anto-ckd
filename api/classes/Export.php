<?php

require_once __DIR__ . '/../libs/SimpleXLSXGen.php';

class Export {
    protected $db;
    protected $user_info;
    protected $path;
    protected $parameters;
    protected $table_config;
    
    function __construct($db, $user_info, $path, $parameters) {
        $this->db = $db;
        $this->user_info = json_decode($user_info, true);
        $this->path = $path;
        $this->parameters = $parameters;
    }
    
    protected function isQueryAuthorized($entity) {
        $tables = json_decode(file_get_contents("../config/tables.json"), true);
        if (array_key_exists($entity, $tables)) {
            $table = $tables[$entity];
            $this->table_config = $table;
            $l = $this->user_info['level'];
            if (isset($table['permissions'][$l]['view'])) {
                return $table['permissions'][$l]['view'];
            } else {
                return 0;
            }
        } else {
            return 0;
        }
    }
    
    protected function bindValues($query_stmt, $valuesToBind) {
        if (count($valuesToBind) > 0) {
            foreach ($valuesToBind as $v) {
                if ($v['type'] === 'INT') {
                    $query_stmt->bindValue($v['name'], $v['value'], PDO::PARAM_INT);
                } else {
                    $query_stmt->bindValue($v['name'], $v['value'], PDO::PARAM_STR);
                }
            }
        }
    }
    
    public function fetchExport() {
        $entity = $this->path;
        $order = "ORDER BY id DESC";
        $limit = "LIMIT 10";
        $offset = "OFFSET 0";

        $selectColumns = "*";
        if (isset($this->parameters->columns) && is_array($this->parameters->columns) && count($this->parameters->columns) > 0) {
            $validColumns = array_filter($this->parameters->columns, function($col) {
                return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col);
            });
            if (count($validColumns) > 0) {
                $selectColumns = implode(', ', $validColumns);
            }
        }
        
        if (isset($this->parameters->order) && $this->parameters->order != null) {
            $sanitizedOrder = ckd_sanitize_order($this->parameters->order);
            if ($sanitizedOrder !== null) {
                $order = "ORDER BY " . $sanitizedOrder;
            }
        }
        if (isset($this->parameters->limit) && $this->parameters->limit != null) {
            $limit = "LIMIT " . $this->parameters->limit;
        }
        if (isset($this->parameters->offset) && $this->parameters->offset != null) {
            $offset = "OFFSET " . $this->parameters->offset;
        }
        
        $where = '';
        $valuesToBind = array();
        
        if (isset($this->parameters->query) && $this->parameters->query != null && isset($this->parameters->fields) && is_array($this->parameters->fields)) {
            $fields = $this->parameters->fields;
            $query = $this->parameters->query;
            $w = '';
            foreach ($fields as $field) {
                if ($w === '') {
                    $w = $field . " LIKE CONCAT( '%', :query, '%')";
                } else {
                    $w = $w . " OR " . $field . " LIKE CONCAT( '%', :query, '%')";
                }
            }
            if (count($fields) > 0) {
                $where = "AND (" . $w . ")";
                $bind = array("name" => ":query", "value" => $query, "type" => "STR");
                array_push($valuesToBind, $bind);
            }
        }
        
        if (isset($this->parameters->constraints)) {
            $constraints = $this->parameters->constraints;
            $w = '';
            $i = 0;
            foreach ($constraints as $v) {
                $key = $v->fieldName;
                
                // Determine operation like in GetSimple.php
                if ($v->type === "value") {
                    $operation = "=";
                } else if ($v->type === "like") {
                    $operation = "LIKE";
                } else if ($v->type === "start") {
                    $operation = "START";
                } else if ($v->type === "end") {
                    $operation = "END";
                } else if ($v->type === "different_from_value") {
                    $operation = "<>";
                } else if ($v->type === "greater") {
                    $operation = ">";
                } else if ($v->type === "minor") {
                    $operation = "<";
                } else if ($v->type === "in") {
                    $operation = "IN";
                } else if ($v->type === "range") {
                    $operation = "BETWEEN";
                } else if ($v->type === "isnull") {
                    $operation = "ISNULL";
                } else {
                    continue; // Skip unsupported operations
                }

                $operationToBind = array("=", "<>", ">", "<");
                if (in_array($operation, $operationToBind)) {
                    if ($w === '') {
                        $w = $key . " " . $operation . " :temp" . $i;
                    } else {
                        $w = $w . " AND " . $key . " " . $operation . " :temp" . $i;
                    }
                    
                    $bind = array("name" => ":temp" . $i, "value" => $v->value, "type" => "STR");
                    array_push($valuesToBind, $bind);
                    $i = $i + 1;
                } else {
                    if ($operation === "LIKE") {
                        if ($w === '') {
                            $w = $key . " LIKE CONCAT( '%', :temp" . $i . ", '%')";
                        } else {
                            $w = $w . " AND " . $key . " LIKE CONCAT( '%', :temp" . $i . ", '%')";
                        }
                        
                        $bind = array("name" => ":temp" . $i, "value" => $v->value, "type" => "STR");
                        array_push($valuesToBind, $bind);
                        $i = $i + 1;
                    }
                    if ($operation === "END") {
                        if ($w === '') {
                            $w = $key . " LIKE CONCAT( '%', :temp" . $i . ")";
                        } else {
                            $w = $w . " AND " . $key . " LIKE CONCAT( '%', :temp" . $i . ")";
                        }
                        
                        $bind = array("name" => ":temp" . $i, "value" => $v->value, "type" => "STR");
                        array_push($valuesToBind, $bind);
                        $i = $i + 1;
                    }
                    if ($operation === "START") {
                        if ($w === '') {
                            $w = $key . " LIKE CONCAT( :temp" . $i . ", '%')";
                        } else {
                            $w = $w . " AND " . $key . " LIKE CONCAT( :temp" . $i . ", '%')";
                        }
                        
                        $bind = array("name" => ":temp" . $i, "value" => $v->value, "type" => "STR");
                        array_push($valuesToBind, $bind);
                        $i = $i + 1;
                    }
                    if ($operation === "IN") {
                        $pieces = explode(",", $v->value);
                        $stringIn = "";
                        foreach ($pieces as $x) {
                            $x = trim($x); // Trim whitespace
                            if (strlen($stringIn) > 0) {
                                $stringIn = $stringIn . ",";
                            }
                            $stringIn = $stringIn . "'{$x}'";
                        }

                        if ($w === '') {
                            $w = $key . " " . $operation . " (" . $stringIn . ")";
                        } else {
                            $w = $w . " AND " . $key . " " . $operation . " (" . $stringIn . ")";
                        }
                    }
                    if ($operation === "BETWEEN") {
                        $z = $i + 1;
                        
                        if ($w === '') {
                            $w = "(" . $key . " " . $operation . " :temp" . $i . " AND :temp" . $z . ")";
                        } else {
                            $w = $w . " AND (" . $key . " " . $operation . " :temp" . $i . " AND :temp" . $z . ")";
                        }

                        $rtype = isset($v->range_type) ? $v->range_type : "STR";
                        
                        $bind = array("name" => ":temp" . $i, "value" => $v->min, "type" => $rtype);
                        array_push($valuesToBind, $bind);

                        $bind = array("name" => ":temp" . $z, "value" => $v->max, "type" => $rtype);
                        array_push($valuesToBind, $bind);
                        
                        $i = $i + 2;
                    }
                    if ($operation === "ISNULL") {
                        if ($w === '') {
                            $w = $key . " IS NULL";
                        } else {
                            $w = $w . " AND " . $key . " IS NULL";
                        }
                    }
                }
            }
            if ($w !== '') {
                $where = $where . " AND (" . $w . ")";
            }
        }
        
        if ($this->isQueryAuthorized($entity) === true) {
            try {
                $fetch = "SELECT {$selectColumns} FROM {$entity} WHERE deleted = 0 {$where} {$order} {$limit} {$offset}";
                $query_stmt = $this->db->prepare($fetch);
                $this->bindValues($query_stmt, $valuesToBind);
                $query_stmt->execute();
                $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                return array("success" => 1, "status" => 200, "request" => $this->parameters, "body" => $body, "db_query" => $fetch);
            } catch (PDOException $e) {
                return array("success" => 0, "message" => $e->getMessage(), "status" => 400, "error_code" => "db.error", "db_query" => $fetch);
            }
        } else if ($this->isQueryAuthorized($entity) === "group") {
            if (isset($this->table_config["authorization"]["groupField"])) {
                try {
                    $groupField = $this->table_config["authorization"]["groupField"];
                    $fetch = "SELECT {$selectColumns} FROM {$entity} WHERE {$groupField} = :group_id AND deleted = 0 {$where} {$order} {$limit} {$offset}";
                    $query_stmt = $this->db->prepare($fetch);
                    $query_stmt->bindValue(":group_id", $this->user_info["idUserGroup"], PDO::PARAM_INT);
                    $this->bindValues($query_stmt, $valuesToBind);
                    $query_stmt->execute();
                    $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                    return array("success" => 1, "status" => 200, "request" => $this->parameters, "body" => $body, "db_query" => $fetch);
                } catch (PDOException $e) {
                    return array("success" => 0, "message" => $e->getMessage(), "status" => 400, "error_code" => "db.error", "db_query" => $fetch);
                }
            } else {
                return array("success" => 0, "status" => 400, "error_code" => "api.get.missing.groupField", "db_query" => $fetch);
            }
        } else if ($this->isQueryAuthorized($entity) === "user") {
            if (isset($this->table_config["authorization"]["userField"])) {
                try {
                    $userField = $this->table_config["authorization"]["userField"];
                    $fetch = "SELECT {$selectColumns} FROM {$entity} WHERE {$userField} = :user_id AND deleted = 0 {$where} {$order} {$limit} {$offset}";
                    $query_stmt = $this->db->prepare($fetch);
                    $query_stmt->bindValue(":user_id", $this->user_info["id"], PDO::PARAM_INT);
                    $this->bindValues($query_stmt, $valuesToBind);
                    $query_stmt->execute();
                    $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                    return array("success" => 1, "status" => 200, "request" => $this->parameters, "body" => $body, "db_query" => $fetch);
                } catch (PDOException $e) {
                    return array("success" => 0, "message" => $e->getMessage(), "status" => 400, "error_code" => "db.error", "db_query" => $fetch);
                }
            } else {
                return array("success" => 0, "status" => 400, "error_code" => "api.get.missing.userField");
            }
        } else {
            return array("success" => 0, "message" => "Forbidden.", "status" => 403, "error_code" => "get.items.auth.forbidden");
        }
    }
    
    /**
     * Export data with resolved external entities for import compatibility
     * Optimized to reduce memory usage by processing data in-place
     */
    public function fetchExportForImport() {
        // First get the raw data using parent method
        $result = $this->fetchExport();

        if ($result['success'] !== 1) {
            return ['success' => 0, 'message' => 'Failed to fetch export data', 'debug' => $result];
        }

        if (empty($result['body'])) {
            return ['success' => 0, 'message' => 'No data to export'];
        }

        // Get entity from path
        $entity = $this->path;

        // Get table configuration
        $tables = json_decode(file_get_contents("../config/tables.json"), true);
        if (!isset($tables[$entity])) {
            return ['success' => 0, 'message' => 'Entity not found in configuration: ' . $entity];
        }

        $tableConfig = $tables[$entity];

        // Get translations from request parameters (optional)
        $translations = [];
        if (isset($this->parameters->translations) && is_object($this->parameters->translations)) {
            $translations = (array)$this->parameters->translations;
        }

        // Check if specific columns were requested
        $requestedColumns = null;
        if (isset($this->parameters->columns) && is_array($this->parameters->columns) && count($this->parameters->columns) > 0) {
            $requestedColumns = array_filter($this->parameters->columns, function($col) {
                return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col);
            });
        }

        // Build headers first
        $headers = [];
        $translatedHeaders = [];

        // If specific columns requested, use those (in order)
        if ($requestedColumns && count($requestedColumns) > 0) {
            foreach ($requestedColumns as $fieldName) {
                $headers[] = $fieldName;
                $translatedHeaders[] = $translations[$fieldName] ?? $fieldName;
            }
        } else {
            // Default behavior: use all columns from config
            $headers = ['id']; // Always include ID first
            $translatedHeaders = [$translations['id'] ?? 'ID']; // Second row with translations

            foreach ($tableConfig['columns'] as $fieldName => $fieldConfig) {
                // Skip ID if already added
                if ($fieldName === 'id') {
                    continue;
                }

                // Campi di sistema: l'import li rifiuta, quindi non vanno nel file
                if (in_array($fieldName, ['date', 'lastUpdate', 'deleted'])) {
                    continue;
                }

                // Skip deep and multiple external entities
                $inputType = $fieldConfig['input_type'] ?? 'string';
                if (in_array($inputType, ['deepExternalEntity', 'multipleExternalEntity'])) {
                    continue;
                }

                // Skip private fields
                if (isset($fieldConfig['options']['private']) && $fieldConfig['options']['private']) {
                    continue;
                }

                $headers[] = $fieldName;
                // Add translation if available, otherwise use field name
                $translatedHeaders[] = $translations[$fieldName] ?? $fieldName;
            }
        }

        // Prepare data array for Excel - process data directly without intermediate copies
        // Add both headers row and translations row
        $xlsxData = [$headers, $translatedHeaders];
        
        // Process each row directly into the final format
        foreach ($result['body'] as &$row) {
            $xlsxRow = [];
            
            foreach ($headers as $header) {
                if ($header === 'id') {
                    $xlsxRow[] = $row['id'];
                    continue;
                }
                
                $value = $row[$header] ?? '';
                
                // Check if it's an external entity field
                if (isset($tableConfig['columns'][$header])) {
                    $fieldConfig = $tableConfig['columns'][$header];
                    $inputType = $fieldConfig['input_type'] ?? 'string';
                    
                    // Handle external entities - resolve labels in-place
                    if ($inputType === 'externalEntity' && !empty($value)) {
                        $extEntity = $fieldConfig['options']['entity'] ?? '';
                        $labelField = $fieldConfig['options']['labelField'] ?? 'name';
                        
                        if (!empty($extEntity)) {
                            // Query the external entity to get the label (keeping N+1 for now as requested)
                            try {
                                $query = "SELECT {$labelField} FROM {$extEntity} WHERE id = :id AND deleted = 0 LIMIT 1";
                                $stmt = $this->db->prepare($query);
                                $stmt->bindValue(':id', $value, PDO::PARAM_INT);
                                $stmt->execute();
                                
                                $extResult = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($extResult && isset($extResult[$labelField])) {
                                    $value = $extResult[$labelField];
                                }
                            } catch (PDOException $e) {
                                // Keep original value on error
                            }
                        }
                    }
                }
                
                $xlsxRow[] = $value;
            }
            
            $xlsxData[] = $xlsxRow;
            
            // Clear the original row from memory to free space
            unset($row);
        }
        
        // Clear the original result body
        unset($result['body']);
        
        // Generate Excel file using SimpleXLSXGen
        try {
            $xlsx = SimpleXLSXGen::fromArray($xlsxData, $entity . '_export');
            $xlsxContent = $xlsx->toString();
            
            if ($xlsxContent !== false) {
                return [
                    'success' => 1,
                    'data' => [
                        'content' => base64_encode($xlsxContent),
                        'filename' => $entity . '_export_' . date('Y-m-d_H-i-s') . '.xlsx',
                        'mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                    ]
                ];
            } else {
                return [
                    'success' => 0,
                    'message' => 'Failed to generate Excel file'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => 0,
                'message' => 'Error generating Excel file: ' . $e->getMessage()
            ];
        }
    }
}
?>