<?php

require_once __DIR__ . '/../libs/SimpleXLSX.php';
require_once __DIR__ . '/../libs/SimpleXLSXGen.php';

class Import {
    /**
     * Valore da passare come $escape a str_getcsv().
     * Da PHP 8.4 ometterlo emette un "Deprecated" che, stampato prima del JSON,
     * invalida la risposta delle API; in PHP 9 il default passera' da "\" a ""
     * (nessun escape proprietario, conforme a RFC 4180). La stringa vuota e'
     * accettata solo da PHP 7.4+, quindi su 7.0-7.3 si passa "\" esplicitamente,
     * che e' comunque il default storico di quelle versioni.
     */
    const CSV_ESCAPE = PHP_VERSION_ID >= 70400 ? '' : '\\';

    protected $db;
    protected $user_info;
    protected $parameters;
    protected $path;
    protected $table_config;
    
    function __construct($db, $user_info, $path, $parameters) {
        $this->db = $db;
        $this->user_info = json_decode($user_info, true);
        $this->path = $path;
        $this->parameters = $parameters;
    }
    
    /**
     * Get table configuration for an entity
     */
    protected function getTableConfig($entity) {
        $tables = json_decode(file_get_contents("../config/tables.json"), true);
        if (array_key_exists($entity, $tables)) {
            return $tables[$entity];
        }
        return null;
    }
    
    /**
     * Check if user is authorized for entity
     */
    protected function isAuthorized($entity, $action = 'view') {
        $config = $this->getTableConfig($entity);
        if (!$config) return false;
        
        $level = $this->user_info['level'];
        if (!isset($config['permissions'][$level][$action])) {
            return false;
        }
        
        return $config['permissions'][$level][$action];
    }
    
    /**
     * Get field configuration for import (exclude system fields)
     */
    protected function getImportableFields($entity) {
        $config = $this->getTableConfig($entity);
        if (!$config) return [];
        
        $fields = [];
        $excludedFields = ['id', 'date', 'lastUpdate', 'deleted'];
        $excludedTypes = ['deepExternalEntity', 'multipleExternalEntity'];
        
        foreach ($config['columns'] as $fieldName => $fieldConfig) {
            // Skip excluded fields
            if (in_array($fieldName, $excludedFields)) continue;
            
            // Skip excluded input types
            if (isset($fieldConfig['input_type']) && in_array($fieldConfig['input_type'], $excludedTypes)) continue;
            
            // Skip private fields
            if (isset($fieldConfig['options']['private']) && $fieldConfig['options']['private']) continue;
            
            $fields[$fieldName] = $fieldConfig;
        }
        
        return $fields;
    }
    
    /**
     * Download template Excel for an entity
     */
    public function downloadTemplate() {
        $entity = $this->path;

        // Check authorization
        if (!$this->isAuthorized($entity, 'view')) {
            return ['success' => 0, 'message' => 'Not authorized for this entity'];
        }

        $fields = $this->getImportableFields($entity);

        // Debug: check if fields are found
        if (empty($fields)) {
            return ['success' => 0, 'message' => 'No importable fields found for entity: ' . $entity];
        }

        // Create headers array
        $headers = ['id']; // Always include ID for updates

        foreach ($fields as $fieldName => $config) {
            $headers[] = $fieldName;
        }

        // Create example row with field type hints
        $exampleRow = ['Leave empty for new or enter ID for update'];
        foreach ($fields as $fieldName => $config) {
            $type = $config['input_type'] ?? 'string';
            $example = '';

            switch($type) {
                case 'string':
                case 'text':
                case 'richText':
                case 'markdown':
                    $example = 'Text value';
                    break;
                case 'number':
                case 'float':
                case 'currency':
                    $example = '123';
                    break;
                case 'boolean':
                    $example = '0 or 1';
                    break;
                case 'date':
                    $example = 'YYYY-MM-DD';
                    break;
                case 'datetime':
                    $example = 'YYYY-MM-DD HH:MM:SS';
                    break;
                case 'time':
                    $example = 'HH:MM:SS';
                    break;
                case 'option':
                    if (isset($config['options']['options'])) {
                        $values = array_column($config['options']['options'], 'value');
                        $example = implode(' or ', array_slice($values, 0, 3));
                        if (count($values) > 3) $example .= '...';
                    }
                    break;
                case 'externalEntity':
                    $extEntity = $config['options']['entity'] ?? '';
                    $labelField = $config['options']['labelField'] ?? 'name';
                    $example = "Value from {$extEntity}.{$labelField}";
                    break;
                case 'file':
                    $example = 'File hash';
                    break;
                case 'password':
                    $example = 'Password (will be encrypted)';
                    break;
                case 'color':
                    $example = '#FF0000';
                    break;
                case 'address':
                    $example = 'Full address';
                    break;
                default:
                    $example = 'Value';
                    break;
            }

            $exampleRow[] = $example;
        }

        // Create Excel file with headers and example row
        $data = [$headers, $exampleRow];
        try {
            $xlsx = SimpleXLSXGen::fromArray($data, $entity . '_template');
            $xlsxContent = $xlsx->toString();
            
            if ($xlsxContent !== false) {
                return [
                    'success' => 1,
                    'data' => [
                        'content' => base64_encode($xlsxContent),
                        'filename' => $entity . '_template.xlsx',
                        'mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                    ]
                ];
            }
        } catch (Exception $e) {
            // Log error but continue with CSV fallback
        }
        
        // Fallback to CSV if Excel generation fails
        $csvContent = implode(',', array_map(function($h) { 
            return '"' . str_replace('"', '""', $h) . '"'; 
        }, $headers)) . "\n";
        $csvContent .= implode(',', array_map(function($h) { 
            return '"' . str_replace('"', '""', $h) . '"'; 
        }, $exampleRow)) . "\n";
        
        return [
            'success' => 1,
            'data' => [
                'content' => base64_encode($csvContent),
                'filename' => $entity . '_template.csv',
                'mimetype' => 'text/csv'
            ]
        ];
    }
    
    /**
     * Parse uploaded Excel/CSV file
     */
    public function parseFile() {
        if (!isset($this->parameters->fileContent)) {
            return ['success' => 0, 'message' => 'Missing file content'];
        }
        
        $entity = $this->path;
        
        // Check authorization
        if (!$this->isAuthorized($entity, 'view')) {
            return ['success' => 0, 'message' => 'Not authorized for this entity'];
        }
        
        // Decode file content
        $fileContent = base64_decode($this->parameters->fileContent);
        if (!$fileContent) {
            return ['success' => 0, 'message' => 'Invalid file content'];
        }
        
        // Try to parse as Excel first
        $rows = [];
        $headers = [];

        // Check if it's an Excel file (XLSX signature)
        if (substr($fileContent, 0, 2) === 'PK') {
            // Parse as Excel
            $xlsx = SimpleXLSX::parseData($fileContent);
            if ($xlsx !== false) {
                $xlsxRows = $xlsx->rows();

                if (!empty($xlsxRows)) {
                    $headers = array_shift($xlsxRows);
                    $rows = $xlsxRows;
                }
            }
        }

        // If not Excel or failed, try CSV
        if (empty($rows)) {
            $lines = str_getcsv($fileContent, "\n", '"', self::CSV_ESCAPE);
            if (count($lines) < 2) {
                return ['success' => 0, 'message' => 'File must contain headers and at least one data row'];
            }

            $headers = str_getcsv($lines[0], ',', '"', self::CSV_ESCAPE);

            for ($i = 1; $i < count($lines); $i++) {
                if (trim($lines[$i]) === '') continue;
                $rows[] = str_getcsv($lines[$i], ',', '"', self::CSV_ESCAPE);
            }
        }
        
        // Truncate headers at the first empty column
        $validHeaderCount = count($headers);
        foreach ($headers as $i => $header) {
            if ($header === null || trim($header) === '') {
                $validHeaderCount = $i;
                break;
            }
        }
        $headers = array_slice($headers, 0, $validHeaderCount);
        $rows = array_map(function($row) use ($validHeaderCount) {
            return array_slice($row, 0, $validHeaderCount);
        }, $rows);

        // Stop at the first empty row
        $filteredRows = [];
        foreach ($rows as $row) {
            $isEmptyRow = true;
            foreach ($row as $cell) {
                if ($cell !== null && trim($cell) !== '') {
                    $isEmptyRow = false;
                    break;
                }
            }
            if ($isEmptyRow) break;
            $filteredRows[] = $row;
        }
        $rows = $filteredRows;

        // Get importable fields
        $importableFields = $this->getImportableFields($entity);
        
        // Validate headers
        $validation = $this->validateHeaders($headers, $importableFields);
        if (!$validation['success']) {
            return $validation;
        }
        
        // Parse data rows
        $processedRows = [];
        $errors = [];
        
        foreach ($rows as $rowIndex => $row) {
            // Handle rows with fewer columns than headers (common with trailing empty cells)
            if (count($row) < count($headers)) {
                // Pad row with empty strings to match header count
                while (count($row) < count($headers)) {
                    $row[] = '';
                }
            } else if (count($row) > count($headers)) {
                // Row has more columns than headers - this is an error
                $errors[] = [
                    'row' => $rowIndex + 2,
                    'error' => 'Too many columns'
                ];
                continue;
            }
            
            $rowData = [];
            foreach ($headers as $j => $header) {
                $rowData[$header] = $row[$j] ?? '';
            }
            
            // Validate and process row
            $processedRow = $this->processRow($rowData, $importableFields, $entity);
            $processedRow['_original_row'] = $rowIndex + 2;
            
            $processedRows[] = $processedRow;
        }
        
        return [
            'success' => 1,
            'data' => [
                'headers' => $headers,
                'rows' => $processedRows,
                'entity' => $entity,
                'importableFields' => $importableFields
            ]
        ];
    }
    
    /**
     * Validate headers against entity fields
     */
    protected function validateHeaders($headers, $importableFields) {
        $errors = [];
        $validHeaders = ['id']; // ID is always valid
        
        foreach (array_keys($importableFields) as $fieldName) {
            $validHeaders[] = $fieldName;
        }
        
        /* Colonne di sistema: un export delle colonne visibili puo' contenerle.
           Non sono importabili ma non sono un errore: processRow le ignora. */
        $ignoredHeaders = ['date', 'lastUpdate', 'deleted'];
        
        foreach ($headers as $header) {
            if (in_array($header, $ignoredHeaders)) continue;
            if (!in_array($header, $validHeaders)) {
                $errors[] = "Invalid column: {$header}";
            }
        }
        
        if (!empty($errors)) {
            return [
                'success' => 0,
                'message' => 'Invalid columns in file',
                'errors' => $errors,
                'validColumns' => $validHeaders
            ];
        }
        
        return ['success' => 1];
    }
    
    /**
     * Process a single row - validate and resolve external entities
     */
    protected function processRow($rowData, $importableFields, $entity) {
        $processed = [
            'data' => [],
            'errors' => [],
            'warnings' => [],
            'action' => 'insert',
            '_resolved' => []
        ];
        
        // Check if ID is provided (update vs insert)
        if (isset($rowData['id']) && !empty($rowData['id'])) {
            $processed['action'] = 'update';
            $processed['data']['id'] = intval($rowData['id']);
            
            // Verify record exists
            $stmt = $this->db->prepare("SELECT id FROM {$entity} WHERE id = :id AND deleted = 0");
            $stmt->bindParam(':id', $processed['data']['id'], PDO::PARAM_INT);
            $stmt->execute();
            
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $processed['errors'][] = "Record with ID {$processed['data']['id']} not found";
            }
        }
        
        // Process each field
        foreach ($importableFields as $fieldName => $fieldConfig) {
            if (!isset($rowData[$fieldName])) {
                continue; // campo non presente nel file → salta
            }

            $value = $rowData[$fieldName];

            // Se valore vuoto
            if ($value === '') {
                if (isset($fieldConfig['null']) && $fieldConfig['null'] === true) {
                    $processed['data'][$fieldName] = null;
                } else {
                    $processed['errors'][] = "Field '{$fieldName}' cannot be empty (not nullable)";
                }
                continue;
            }

            $inputType = $fieldConfig['input_type'] ?? 'string';
            
            // Process based on input type
            switch ($inputType) {
                case 'externalEntity':
                    $result = $this->resolveExternalEntity($value, $fieldConfig);
                    if ($result['success']) {
                        $processed['data'][$fieldName] = $result['id'];
                        $processed['_resolved'][$fieldName] = [
                            'label' => $value,
                            'id' => $result['id'],
                            'entity' => $fieldConfig['options']['entity']
                        ];
                    } else {
                        $processed['errors'][] = "Field '{$fieldName}': {$result['error']}";
                    }
                    break;
                    
                case 'boolean':
                    $processed['data'][$fieldName] = in_array(strtolower($value), ['1', 'true', 'yes', 'on']) ? 1 : 0;
                    break;
                    
                case 'number':
                    if (!is_numeric($value)) {
                        $processed['errors'][] = "Field '{$fieldName}' must be a number";
                    } else {
                        $processed['data'][$fieldName] = intval($value);
                    }
                    break;
                    
                case 'float':
                case 'currency':
                    if (!is_numeric($value)) {
                        $processed['errors'][] = "Field '{$fieldName}' must be a number";
                    } else {
                        $processed['data'][$fieldName] = floatval($value);
                    }
                    break;
                    
                case 'date':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $processed['errors'][] = "Field '{$fieldName}' must be in format YYYY-MM-DD";
                    } else {
                        $processed['data'][$fieldName] = $value;
                    }
                    break;
                    
                case 'datetime':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                        $processed['errors'][] = "Field '{$fieldName}' must be in format YYYY-MM-DD HH:MM:SS";
                    } else {
                        $processed['data'][$fieldName] = $value;
                    }
                    break;
                    
                case 'time':
                    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
                        $processed['errors'][] = "Field '{$fieldName}' must be in format HH:MM:SS";
                    } else {
                        $processed['data'][$fieldName] = $value;
                    }
                    break;
                    
                case 'option':
                    $validOptions = array_column($fieldConfig['options']['options'] ?? [], 'value');
                    if (!in_array($value, $validOptions)) {
                        $processed['errors'][] = "Field '{$fieldName}' must be one of: " . implode(', ', $validOptions);
                    } else {
                        $processed['data'][$fieldName] = $value;
                    }
                    break;
                    
                case 'password':
                    // Hash password
                    $processed['data'][$fieldName] = password_hash($value, PASSWORD_DEFAULT);
                    break;
                    
                case 'color':
                    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                        $processed['errors'][] = "Field '{$fieldName}' must be a valid hex color (e.g. #FF0000)";
                    } else {
                        $processed['data'][$fieldName] = $value;
                    }
                    break;
                    
                default:
                    $processed['data'][$fieldName] = $value;
                    break;
            }
        }
        
        return $processed;
    }
    
    /**
     * Resolve external entity label to ID
     */
    protected function resolveExternalEntity($label, $fieldConfig) {
        if (!isset($fieldConfig['options']['entity']) || !isset($fieldConfig['options']['labelField'])) {
            return ['success' => 0, 'error' => 'External entity configuration missing'];
        }
        
        $extEntity = $fieldConfig['options']['entity'];
        $labelField = $fieldConfig['options']['labelField'];
        
        // Check if user has permission to view the external entity
        if (!$this->isAuthorized($extEntity, 'view')) {
            return ['success' => 0, 'error' => 'Not authorized to access ' . $extEntity];
        }
        
        // Query for the ID
        $query = "SELECT id FROM {$extEntity} WHERE {$labelField} = :label AND deleted = 0 LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':label', $label, PDO::PARAM_STR);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return ['success' => 1, 'id' => $result['id']];
        }
        
        return ['success' => 0, 'error' => "Value '{$label}' not found in {$extEntity}.{$labelField}"];
    }
    
    /**
     * Execute import - perform actual database operations
     */
    public function executeImport() {
        if (!isset($this->parameters->rows)) {
            return ['success' => 0, 'message' => 'Missing rows data'];
        }
        
        $entity = $this->path;
        $rows = $this->parameters->rows;
        
        // Check authorization
        $canCreate = $this->isAuthorized($entity, 'create');
        $canEdit = $this->isAuthorized($entity, 'edit');
        
        $results = [
            'inserted' => 0,
            'updated' => 0,
            'errors' => []
        ];
        
        $this->db->beginTransaction();
        
        try {
            foreach ($rows as $index => $row) {
                // Skip rows with errors
                if (!empty($row->errors)) {
                    $results['errors'][] = [
                        'row' => $row->_original_row ?? ($index + 1),
                        'errors' => $row->errors
                    ];
                    continue;
                }
                
                $action = $row->action;
                $data = (array)$row->data;
                
                if ($action === 'insert') {
                    if (!$canCreate) {
                        $results['errors'][] = [
                            'row' => $row->_original_row ?? ($index + 1),
                            'errors' => ['Not authorized to create records']
                        ];
                        continue;
                    }
                    
                    // Add auto-populated fields for insert
                    $tableConfig = $this->getTableConfig($entity);
                    if (isset($tableConfig['columns']['author'])) {
                        $data['author'] = $this->user_info['id'];
                    }
                    if (isset($tableConfig['columns']['idUserGroup'])) {
                        $data['idUserGroup'] = $this->user_info['idUserGroup'];
                    }
                    
                    // Remove null values for fields that have a DB default in tables.json, so DB defaults are applied
                    $columns = $tableConfig['columns'] ?? [];
                    foreach ($data as $field => $value) {
                        if ($value === null && isset($columns[$field])) {
                            if (isset($columns[$field]['default'])) {
                                unset($data[$field]);
                            }
                        }
                    }

                    // Build insert query
                    $fields = array_keys($data);
                    $escapedFields = array_map(function($f) { return "`{$f}`"; }, $fields);
                    $placeholders = array_map(function($f) { return ":{$f}"; }, $fields);
                    
                    $query = "INSERT INTO {$entity} (" . implode(', ', $escapedFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $stmt = $this->db->prepare($query);
                    
                    foreach ($data as $field => $value) {
                        $stmt->bindValue(":{$field}", $value);
                    }
                    
                    if ($stmt->execute()) {
                        $results['inserted']++;
                    } else {
                        $results['errors'][] = [
                            'row' => $row->_original_row ?? ($index + 1),
                            'errors' => ['Failed to insert record']
                        ];
                    }
                    
                } else if ($action === 'update') {
                    if (!$canEdit) {
                        $results['errors'][] = [
                            'row' => $row->_original_row ?? ($index + 1),
                            'errors' => ['Not authorized to edit records']
                        ];
                        continue;
                    }
                    
                    $id = $data['id'];
                    unset($data['id']);
                    
                    // Don't update author and idUserGroup on edit
                    unset($data['author']);
                    unset($data['idUserGroup']);
                    
                    if (empty($data)) {
                        $results['warnings'][] = [
                            'row' => $row->_original_row ?? ($index + 1),
                            'warning' => 'No fields to update'
                        ];
                        continue;
                    }
                    
                    // Build update query
                    $sets = array_map(function($f) { return "`{$f}` = :{$f}"; }, array_keys($data));
                    
                    $query = "UPDATE {$entity} SET " . implode(', ', $sets) . " WHERE id = :id";
                    $stmt = $this->db->prepare($query);
                    
                    foreach ($data as $field => $value) {
                        $stmt->bindValue(":{$field}", $value);
                    }
                    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                    
                    if ($stmt->execute()) {
                        $results['updated']++;
                    } else {
                        $results['errors'][] = [
                            'row' => $row->_original_row ?? ($index + 1),
                            'errors' => ['Failed to update record']
                        ];
                    }
                }
            }
            
            // If no errors, commit transaction
            if (empty($results['errors'])) {
                $this->db->commit();
                return [
                    'success' => 1,
                    'message' => "Import completed: {$results['inserted']} inserted, {$results['updated']} updated",
                    'results' => $results
                ];
            } else {
                // Rollback if there are errors
                $this->db->rollback();
                return [
                    'success' => 0,
                    'message' => 'Import failed due to errors',
                    'results' => $results
                ];
            }
            
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => 0,
                'message' => 'Import failed: ' . $e->getMessage()
            ];
        }
    }
}
?>