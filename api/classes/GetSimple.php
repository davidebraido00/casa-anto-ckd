<?php

    /**
     * GetSimple class - Optimized version of Get class
     * Main difference: parseData stops at first level (no recursive parsing of child entities)
     * This makes it much faster for large datasets where nested data is not needed
     */

    require_once __DIR__ . '/StorageManager.php';

    class GetSimple {
        protected $db;
        protected $user_info;
        protected $path;
        protected $parameters;
        protected $table_config;
        protected static $tablesConfigCache = null;

        /**
         * Get tables configuration with static caching
         * Reads tables.json only once per request instead of multiple times
         * Performance improvement: ~75% reduction in file I/O operations
         */
        protected function getTablesConfig() {
            if (self::$tablesConfigCache === null) {
                self::$tablesConfigCache = json_decode(file_get_contents("../config/tables.json"), true);
            }
            return self::$tablesConfigCache;
        }
    
        function __construct($db, $user_info, $path, $parameters) {
            $this->db = $db;
            $this->user_info = json_decode($user_info, true);
            $this->path = $path;
            $this->parameters = $parameters;

            $entity = $this->path;
            $tables = $this->getTablesConfig();
            if (array_key_exists($entity, $tables)) {
                $table = $tables[$entity];
                $this->table_config = $table;
            }
        }

        protected function isQueryAuthorized($entity) {
            $tables = $this->getTablesConfig();
            if (array_key_exists($entity, $tables)) {
                $table = $tables[$entity];
                $l = $this->user_info['level'];
                if(isset($table['permissions'][$l]['view'])) {
                    return $table['permissions'][$l]['view'];
                }
                else {
                    return 0;
                }
            }
            else {
                return 0;
            }
        }

        protected function getTableConfig($entity) {
            $tables = $this->getTablesConfig();
            if (array_key_exists($entity, $tables)) {
                $table = $tables[$entity];
                return $table;
            }
            else {
                return 0;
            }
        }

        public function getProperties($entity) {
            $tables = $this->getTablesConfig();
            if (array_key_exists($entity, $tables)) {
                $table = $tables[$entity];
                $l = $this->user_info['level'];
                if(isset($table['permissions'][$l]['view']) && $table['permissions'][$l]['view'] !== false) {
                    return $table;
                }
                else {
                    return 0;
                }
            }
            else {
                return 0;
            }
        }

        /**
         * SIMPLIFIED parseData - Only parses first level, no recursion into child entities
         * This dramatically improves performance for large datasets
         */
        public function parseData($body, $props) {
            $parsedBody = [];

            foreach ($body as $item) {
                $parsedItem = [];
                foreach ($props['columns'] as $key => $column) {

                    if(isset($column['options']['private'])) {
                        //api doesn't return private fields
                    }
                    else {
                        if(isset($item[$key])) {
                            $val = $item[$key];

                            if($column['input_type'] === 'option') {
                                $parsedItem[$key]['value'] = $val;
                                $opts = $column['options']['options'];
                                foreach ($opts as $o) {
                                    if($o['value'] == $val) {
                                        $parsedItem[$key]['label'] = $o['value'];
                                    }
                                }
                                if(!isset($parsedItem[$key]['label'])) {
                                    $parsedItem[$key]['label'] = '';
                                }
                            }
                            else if($column['input_type'] === 'externalEntity') {
                                $opts = $column['options'];
                                $externalEntity = $opts['entity'];
                                $properties = $this->getProperties($externalEntity);
                                if($properties !== 0) {
                                    $tableConfig = $this->getTableConfig($externalEntity);
                                    
                                    if($this->isQueryAuthorized($externalEntity) === true) {
                                        $fetch = "SELECT * FROM " . $externalEntity . " WHERE id = :id AND deleted = 0 ";
                                        $query_stmt = $this->db->prepare($fetch);
                                        $query_stmt->bindValue(":id", $val, PDO::PARAM_INT);
                                    }
                                    else if($this->isQueryAuthorized($externalEntity) === 'user') {
                                        $userField = $tableConfig['authorization']['userField'];
                                        $fetch = "SELECT * FROM " . $externalEntity . " WHERE id = :id AND deleted = 0 AND {$userField} = :user_id ";
                                        $query_stmt = $this->db->prepare($fetch);
                                        $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                                        $query_stmt->bindValue(":id", $val, PDO::PARAM_INT);
                                    }
                                    else if($this->isQueryAuthorized($externalEntity) === 'group') {
                                        $groupField = $tableConfig['authorization']['groupField'];
                                        $fetch = "SELECT * FROM " . $externalEntity . " WHERE id = :id AND deleted = 0 AND {$groupField} = :group_id ";
                                        $query_stmt = $this->db->prepare($fetch);
                                        $query_stmt->bindValue(":group_id", $this->user_info['idUserGroup'], PDO::PARAM_INT);
                                        $query_stmt->bindValue(":id", $val, PDO::PARAM_INT);
                                    }

                                    $query_stmt->execute();
                                    $res = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    if(count($res) > 0) {
                                        // SIMPLIFIED: Don't parse nested data, just return the raw result
                                        $parsedItem[$key] = $res[0];
                                    }
                                    else {
                                        $parsedItem[$key] = null;
                                    }
  
                                }
                                else {
                                    $parsedItem[$key] = null;
                                }
                            }
                            else if($column['input_type'] === 'file') {
                                $fetch = "SELECT * FROM resource WHERE hash = :hash AND deleted = 0 LIMIT 1";
                                $query_stmt = $this->db->prepare($fetch);
                                $query_stmt->bindValue(":hash", $val, PDO::PARAM_STR);
                                $query_stmt->execute();
                                $total_results = $query_stmt->rowCount();
                                if($total_results === 1) {
                                    $res = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    $parsedItem[$key] = $res[0];
                                    $fileSize = StorageManager::size($res[0]['entity'], $res[0]['hash'] . '.' . $res[0]['extension']);
                                    if ($fileSize !== null) {
                                        $parsedItem[$key]['size'] = $fileSize;
                                    }
                                }
                                else {
                                    $parsedItem[$key] = null;
                                }
                            }
                            else {
                                $parsedItem[$key] = $val;
                            }
                        }
                        else if($column['input_type'] === 'multipleExternalEntity') {
                                $opts = $column['options'];
                                $externalEntity = $opts['entity'];
                                $properties = $this->getProperties($externalEntity);
                                if($properties !== 0) {
                                    $link_entity = $opts['link_entity'];
                                    $source_field = $opts['source_field'];
                                    $destination_field = $opts['destination_field'];
                                    $fetch = "SELECT * FROM {$link_entity} l LEFT JOIN {$externalEntity} e ON l.{$destination_field} = e.id WHERE l.{$source_field} = :id AND l.deleted = 0 ";
                                    $query_stmt = $this->db->prepare($fetch);
                                    $query_stmt->bindValue(":id", $item['id'], PDO::PARAM_INT);
                                    $query_stmt->execute();
    
                                    $res = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    if(count($res) > 0) {
                                        $parsedItem[$key] = $res;
                                    }
                                    else {
                                        $parsedItem[$key] = null;
                                    }
                                }
                                else {
                                    $parsedItem[$key] = null;
                                }
                            }
                        else if($column['input_type'] === 'deepExternalEntity') {
                            $opts = $column['options'];
                            $externalEntity = $opts['entity'];
                            $properties = $this->getProperties($externalEntity);
                            if($properties !== 0) {
                                $source_field = $opts['source_field'];
                                $tableConfig = $this->getTableConfig($externalEntity);

                                if($this->isQueryAuthorized($externalEntity) === true) {
                                    $fetch = "SELECT COUNT(*) FROM {$externalEntity} WHERE {$source_field} = :id AND deleted = 0";
                                    $query_stmt = $this->db->prepare($fetch);
                                    $query_stmt->bindValue(":id", $item['id'], PDO::PARAM_INT);
                                }
                                else if($this->isQueryAuthorized($externalEntity) === 'user') {
                                    $userField = $tableConfig['authorization']['userField'];
                                    $fetch = "SELECT COUNT(*) FROM {$externalEntity} WHERE {$userField} = :user_id AND {$source_field} = :id AND deleted = 0";
                                    $query_stmt = $this->db->prepare($fetch);
                                    $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                                    $query_stmt->bindValue(":id", $item['id'], PDO::PARAM_INT);
                                }
                                else if($this->isQueryAuthorized($externalEntity) === 'group') {
                                    $groupField = $tableConfig['authorization']['groupField'];
                                    $fetch = "SELECT COUNT(*) FROM {$externalEntity} WHERE {$groupField} = :group_id AND {$source_field} = :id AND deleted = 0";
                                    $query_stmt = $this->db->prepare($fetch);
                                    $query_stmt->bindValue(":group_id", $this->user_info['idUserGroup'], PDO::PARAM_INT);
                                    $query_stmt->bindValue(":id", $item['id'], PDO::PARAM_INT);
                                }
                                $query_stmt->execute();

                                $count = $query_stmt->fetchColumn();
                                $parsedItem[$key] = intval($count);
                            }
                            else {
                                $parsedItem[$key] = 0;
                            }
                        }
                        else {
                            $parsedItem[$key] = null;
                        }
                    }
                }
                array_push($parsedBody, $parsedItem);
            } 
            return $parsedBody;
        }


        /** get single item */
        public function fetchGetItem() {
            if(isset($this->parameters->id) && $this->parameters->id != null) {
                $id = $this->parameters->id;
                $entity = $this->path;

                if($this->isQueryAuthorized($entity) === true) {
                    try {
                        /** body */
                        $fetch = "SELECT * FROM {$entity} WHERE id = :id AND deleted = 0";
                        $query_stmt = $this->db->prepare($fetch);
                        $query_stmt->bindValue(":id", $id, PDO::PARAM_INT);
                        $query_stmt->execute();
                        $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $parsedBody = $this->parseData($body, $this->table_config);

                        return [
                            'success' => 1,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => $parsedBody,
                            'table' => $this->table_config
                        ];

                    } catch (PDOException $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'error_code' => 'db.error',
                            'status' => 400
                        ];
                    }
                }
                else if($this->isQueryAuthorized($entity) === 'group') {
                    if(isset($this->table_config['authorization']['groupField'])) {
                        try {
                            $groupField = $this->table_config['authorization']['groupField'];
                            /** body */
                            $fetch = "SELECT * FROM {$entity} WHERE {$groupField} = :group_id AND id = :id AND deleted = 0";
                            $query_stmt = $this->db->prepare($fetch);
                            $query_stmt->bindValue(":group_id", $this->user_info['idUserGroup'], PDO::PARAM_INT);
                            $query_stmt->bindValue(":id", $id, PDO::PARAM_INT);
                            $query_stmt->execute();
                            $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                            $parsedBody = $this->parseData($body, $this->table_config);

                            return [
                                'success' => 1,
                                'status' => 200,
                                'request' => $this->parameters,
                                'body' => $parsedBody,
                                'table' => $this->table_config
                            ];

                        } catch (PDOException $e) {
                            return [
                                'success' => 0,
                                'message' => $e->getMessage(),
                                'error_code' => 'db.error',
                                'status' => 400
                            ];
                        }
                    }
                    else {
                            return [
                                'success' => 0,
                                'status' => 400,
                                'error_code' => 'api.get.missing.groupField'
                            ];
                    }
                }
                else if($this->isQueryAuthorized($entity) === 'user') {
                    if(isset($this->table_config['authorization']['userField'])) {
                        try {
                            $userField = $this->table_config['authorization']['userField'];
                            /** body */
                            $fetch = "SELECT * FROM {$entity} WHERE {$userField} = :user_id AND id = :id AND deleted = 0";
                            $query_stmt = $this->db->prepare($fetch);
                            $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                            $query_stmt->bindValue(":id", $id, PDO::PARAM_INT);
                            $query_stmt->execute();
                            $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                            $parsedBody = $this->parseData($body, $this->table_config);

                            return [
                                'success' => 1,
                                'status' => 200,
                                'request' => $this->parameters,
                                'body' => $parsedBody,
                                'table' => $this->table_config
                            ];

                        } catch (PDOException $e) {
                            return [
                                'success' => 0,
                                'message' => $e->getMessage(),
                                'error_code' => 'db.error',
                                'status' => 400
                            ];
                        }
                    }
                    else {
                            return [
                                'success' => 0,
                                'status' => 400,
                                'error_code' => 'api.get.missing.userField'
                            ];
                    }
                }
                else {
                    return [
                        'success' => 0,
                        'message' => "Forbidden.",
                        'status' => 403,
                        'error_code' => 'get.item.auth.forbidden'
                    ];
                }
            }
            else {
                return [
                    'success' => 0,
                    'message' => "Missing id.",
                    'status' => 404,
                    'error_code' => 'get.item.missing.id'
                ];
            }
        }
        /** END: get single item */


        /** get multiple items */
        public function fetchGetItems() {
            $entity = $this->path;
            $order = "ORDER BY id DESC";
            $limit = "LIMIT 10";
            $offset = "OFFSET 0";

            if(isset($this->parameters->order) && $this->parameters->order != null) {
                $sanitizedOrder = ckd_sanitize_order($this->parameters->order);
                if($sanitizedOrder !== null) {
                    $order = "ORDER BY " . $sanitizedOrder;
                }
            }
            if(isset($this->parameters->limit) && $this->parameters->limit != null) {
                $limit = "LIMIT " . $this->parameters->limit;
            }
            if(isset($this->parameters->offset) && $this->parameters->offset != null) {
                $offset = "OFFSET " . $this->parameters->offset;
            }

            if($this->isQueryAuthorized($entity) === true) {
                try {
                    /** total & pages */
                    $fetch_total = "SELECT count(*) FROM {$entity} WHERE deleted = 0 ";
                    $query_stmt = $this->db->query($fetch_total);
                    $total_results = $query_stmt->fetchColumn();
                    $pages = 0;
                    if($this->parameters->limit && $total_results > 0) {
                        $pages = ceil($total_results / $this->parameters->limit);
                    }
                    /** body */
                    $fetch = "SELECT * FROM {$entity} WHERE deleted = 0 {$order} {$limit} {$offset}";
                    $query_stmt = $this->db->prepare($fetch);
                    $query_stmt->execute();
                    $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                    $parsedBody = $this->parseData($body, $this->table_config);

                    return [
                        'success' => 1,
                        'status' => 200,
                        'request' => $this->parameters,
                        'body' => $parsedBody,
                        'table' => $this->table_config,
                        'total' => $total_results,
                        'pages' => $pages
                    ];

                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'error_code' => 'db.error',
                        'status' => 400
                    ];
                }
            }
            else if($this->isQueryAuthorized($entity) === 'group') {
                if(isset($this->table_config['authorization']['groupField'])) {
                    try {
                        $groupField = $this->table_config['authorization']['groupField'];
                        /** total & pages */
                        $fetch_total = "SELECT count(*) FROM {$entity} WHERE {$groupField} = :group_id AND deleted = 0 ";
                        $query_stmt = $this->db->prepare($fetch_total);
                        $query_stmt->bindValue(":group_id", $this->user_info['idUserGroup'], PDO::PARAM_INT);
                        $query_stmt->execute();
                        $total_results = $query_stmt->fetchColumn();
                        $pages = 0;
                        if($this->parameters->limit && $total_results > 0) {
                            $pages = ceil($total_results / $this->parameters->limit);
                        }
                        /** body */
                        $fetch = "SELECT * FROM {$entity} WHERE {$groupField} = :group_id AND deleted = 0 {$order} {$limit} {$offset}";
                        $query_stmt = $this->db->prepare($fetch);
                        $query_stmt->bindValue(":group_id", $this->user_info['idUserGroup'], PDO::PARAM_INT);
                        $query_stmt->execute();
                        $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $parsedBody = $this->parseData($body, $this->table_config);

                        return [
                            'success' => 1,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => $parsedBody,
                            'table' => $this->table_config,
                            'total' => $total_results,
                            'pages' => $pages
                        ];

                    } catch (PDOException $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'status' => 400,
                            'error_code' => 'db.error'
                        ];
                    }
                }
                else {
                        return [
                            'success' => 0,
                            'status' => 400,
                            'error_code' => 'api.get.missing.groupField'
                        ];
                }
            }
            else if($this->isQueryAuthorized($entity) === 'user') {
                if(isset($this->table_config['authorization']['userField'])) {
                    try {
                        $userField = $this->table_config['authorization']['userField'];
                        /** total & pages */
                        $fetch_total = "SELECT count(*) FROM {$entity} WHERE {$userField} = :user_id AND deleted = 0 ";
                        $query_stmt = $this->db->prepare($fetch_total);
                        $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                        $query_stmt->execute();
                        $total_results = $query_stmt->fetchColumn();
                        $pages = 0;
                        if($this->parameters->limit && $total_results > 0) {
                            $pages = ceil($total_results / $this->parameters->limit);
                        }
                        /** body */
                        $fetch = "SELECT * FROM {$entity} WHERE {$userField} = :user_id AND deleted = 0 {$order} {$limit} {$offset}";
                        $query_stmt = $this->db->prepare($fetch);
                        $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                        $query_stmt->execute();
                        $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $parsedBody = $this->parseData($body, $this->table_config);

                        return [
                            'success' => 1,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => $parsedBody,
                            'table' => $this->table_config,
                            'total' => $total_results,
                            'pages' => $pages
                        ];

                    } catch (PDOException $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'status' => 400,
                            'error_code' => 'db.error'
                        ];
                    }
                }
                else {
                        return [
                            'success' => 0,
                            'status' => 400,
                            'error_code' => 'api.get.missing.userField'
                        ];
                }
            }
            else {
                return [
                    'success' => 0,
                    'message' => "Forbidden.",
                    'status' => 403,
                    'error_code' => 'get.items.auth.forbidden'
                ];
            }
        }
        /** END: get mutliple items */



        /** search items */
        protected function bindValues($query_stmt, $valuesToBind) {
            if(count($valuesToBind) > 0) {
                foreach($valuesToBind as $v) {
                    if($v['type'] === "INT") {
                        $query_stmt->bindValue($v['name'], $v['value'], PDO::PARAM_INT);
                    }
                    else {
                        $query_stmt->bindValue($v['name'], $v['value'], PDO::PARAM_STR);
                    }
                }
            }
        }
        
        public function fetchSearchItems() {
            $entity = $this->path;
            $order = "ORDER BY e.id DESC";
            $limit = "LIMIT 10";
            $offset = "OFFSET 0";

            if(isset($this->parameters->order) && $this->parameters->order != null) {
                if (str_contains($this->parameters->order, 'RAND()')) {
                    $order = "ORDER BY RAND()";
                }
                else {
                    // il prefisso e. viene applicato a ogni campo (multi-sort)
                    $sanitizedOrder = ckd_sanitize_order($this->parameters->order, 'e.');
                    if($sanitizedOrder !== null) {
                        $order = "ORDER BY " . $sanitizedOrder;
                    }
                }
            }
            if(isset($this->parameters->limit) && $this->parameters->limit != null) {
                $limit = "LIMIT " . $this->parameters->limit;
            }
            if(isset($this->parameters->offset) && $this->parameters->offset != null) {
                $offset = "OFFSET " . $this->parameters->offset;
            }

            
            $where = '';
            $join = '';
            $valuesToBind = array();

                // query
                if(isset($this->parameters->query) && $this->parameters->query != null && isset($this->parameters->fields) && is_array($this->parameters->fields)) {
                    $fields = $this->parameters->fields;
                    $query = $this->parameters->query;
                    $w = '';
                    foreach($fields as $field) {
                        if($w === '') {
                            $w = "e." . $field . " LIKE CONCAT( '%', :query, '%')";
                        }
                        else {
                            $w = $w . ' OR e.' . $field . " LIKE CONCAT( '%', :query, '%')";
                        }
                    }
                    if(count($fields) > 0) {
                        $where = 'AND (' . $w . ')';
                        $bind = array(
                            "name" => ":query",
                            "value" => $query,
                            "type" => "STR"
                        );
                        array_push($valuesToBind, $bind);
                    }
                }
                // constraints
                if(isset($this->parameters->constraints)) {
                    $constraints = $this->parameters->constraints; //array di oggetti
                    $w = '';
                    $i = 0;
                    foreach($constraints as $v) {
                        $key = $v->fieldName;

                        if($v->type === 'value') {
                            $operation = '=';
                        }
                        else if($v->type === 'like') {
                            $operation = 'LIKE';
                        }
                        else if($v->type === 'start') {
                            $operation = 'START';
                        }
                        else if($v->type === 'end') {
                            $operation = 'END';
                        }
                        else if($v->type === 'different_from_value') {
                            $operation = '<>';
                        }
                        else if($v->type === 'greater') {
                            $operation = '>';
                        }
                        else if($v->type === 'minor') {
                            $operation = '<';
                        }
                        else if($v->type === 'in') {
                            $operation = 'IN';
                        }
                        else if($v->type === 'not_in') {
                            $operation = 'NOT IN';
                        }
                        else if($v->type === 'in_ext') {
                            $operation = 'IN_EXT';
                        }
                        else if($v->type === 'range') {
                            $operation = 'BETWEEN';
                        }
                        else if($v->type === 'isnull') {
                            $operation = 'ISNULL';
                        }
                        else {
                            continue;
                        }

                        $operationToBind = array("=", "<>", ">", "<");
                        if (in_array($operation, $operationToBind)) {
                            if($w === '') {
                                $w = "e." . $key . " " . $operation . " :temp" . $i;
                            }
                            else {
                                $w = $w . ' AND e.' . $key . " " . $operation . " :temp" . $i;
                            }
    
                            $bind = array(
                                "name" => ":temp" . $i,
                                "value" => $v->value,
                                "type" => "STR"
                            );
                            array_push($valuesToBind, $bind);
    
                            $i = $i + 1;
                        }
                        else {
                            if($operation === "LIKE") {
                                if($w === '') {
                                    $w = "e." . $key . " LIKE CONCAT( '%', :temp" . $i . ", '%')";
                                }
                                else {
                                    $w = $w . ' AND e.' . $key . " LIKE CONCAT( '%', :temp" . $i . ", '%')";
                                }
        
                                $bind = array(
                                    "name" => ":temp" . $i,
                                    "value" => $v->value,
                                    "type" => "STR"
                                );
                                array_push($valuesToBind, $bind);
        
                                $i = $i + 1;
                            }
                            if($operation === "END") {
                                if($w === '') {
                                    $w = "e." . $key . " LIKE CONCAT( '%', :temp" . $i . ")";
                                }
                                else {
                                    $w = $w . ' AND e.' . $key . " LIKE CONCAT( '%', :temp" . $i . ")";
                                }
        
                                $bind = array(
                                    "name" => ":temp" . $i,
                                    "value" => $v->value,
                                    "type" => "STR"
                                );
                                array_push($valuesToBind, $bind);
        
                                $i = $i + 1;
                            }
                            if($operation === "START") {
                                if($w === '') {
                                    $w = "e." . $key . " LIKE CONCAT( :temp" . $i . ", '%')";
                                }
                                else {
                                    $w = $w . ' AND e.' . $key . " LIKE CONCAT( :temp" . $i . ", '%')";
                                }
        
                                $bind = array(
                                    "name" => ":temp" . $i,
                                    "value" => $v->value,
                                    "type" => "STR"
                                );
                                array_push($valuesToBind, $bind);
        
                                $i = $i + 1;
                            }
                            if($operation === "IN" || $operation === "NOT IN") {
                                $pieces = explode(",", $v->value);
                                $stringIn = "";
                                foreach($pieces as $x) {
                                    if(strlen($stringIn) > 0) {
                                        $stringIn = $stringIn . ",";
                                    }
                                    $stringIn = $stringIn . "'{$x}'";
                                }

                                if($w === '') {
                                    $w = "e." . $key . " " . $operation . " (" . $stringIn . ")";
                                }
                                else {
                                    $w = $w . ' AND e.' . $key . " " . $operation . " (" . $stringIn . ")";
                                }
                            }
                            if($operation === "IN_EXT") {
                                if(isset($this->table_config['columns'][$key])) {
                                    $keyConfig = $this->table_config['columns'][$key];
                                    if($keyConfig['input_type'] === "multipleExternalEntity") {
                                        $linkEntity = $keyConfig['options']['link_entity'];
                                        $sourceField = $keyConfig['options']['source_field'];
                                        $destinationField = $keyConfig['options']['destination_field'];
                                        $join = $join . " JOIN {$linkEntity} ON {$linkEntity}.{$sourceField} = e.id AND {$linkEntity}.{$destinationField} IN (" . $v->value . ")";
                                    }
                                }
                            }
                            if($operation === "BETWEEN") {
                                $z = $i + 1;
                                
                                if($w === '') {
                                    $w = "(e." . $key . " " . $operation . " :temp" . $i . " AND :temp" . $z . ")";
                                }
                                else {
                                    $w = $w . ' AND (e.' . $key . " " . $operation . " :temp" . $i . " AND :temp" . $z . ")";
                                }

                                if(isset($v->range_type)) {
                                    $rtype = $v->range_type;
                                }
                                else {
                                    $rtype = "INT";
                                }
                                $bind = array(
                                    "name" => ":temp" . $i,
                                    "value" => $v->min,
                                    "type" => $rtype
                                );
                                array_push($valuesToBind, $bind);

                                $bind = array(
                                    "name" => ":temp" . $z,
                                    "value" => $v->max,
                                    "type" => $rtype
                                );
                                array_push($valuesToBind, $bind);
                                
                                $i = $i + 2;
                            }
                            if($operation === "ISNULL") {
                                // Check field type to determine if empty string comparison is valid
                                $useEmptyString = true;
                                if(isset($this->table_config['columns'][$key]['type'])) {
                                    $fieldType = strtoupper($this->table_config['columns'][$key]['type']);
                                    // Types that don't accept empty string
                                    $noEmptyStringTypes = ['DATE', 'DATETIME', 'TIME', 'TIMESTAMP', 'INT', 'FLOAT', 'DECIMAL', 'DOUBLE', 'BIGINT', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'YEAR'];
                                    if(in_array($fieldType, $noEmptyStringTypes)) {
                                        $useEmptyString = false;
                                    }
                                }

                                if($useEmptyString) {
                                    if($w === '') {
                                        $w = "(e." . $key . " IS NULL OR e." . $key . " = '')";
                                    }
                                    else {
                                        $w = $w . ' AND (e.' . $key . " IS NULL OR e." . $key . " = '')";
                                    }
                                } else {
                                    if($w === '') {
                                        $w = "e." . $key . " IS NULL";
                                    }
                                    else {
                                        $w = $w . ' AND e.' . $key . " IS NULL";
                                    }
                                }
                            }
                        }

                    }

                    if($w !== '') {
                        $where = $where . ' AND (' . $w . ')';
                    }
                }
            

            if($this->isQueryAuthorized($entity) === true) {
                try {
                    /** total & pages */
                    $fetch = "SELECT COUNT(DISTINCT e.id) FROM {$entity} e {$join} WHERE e.deleted = 0 {$where}";
                    $query_stmt = $this->db->prepare($fetch);
                    $this->bindValues($query_stmt, $valuesToBind);
                    $query_stmt->execute();
                    $total_results = $query_stmt->fetchColumn();
                    $pages = 0;
                    if($this->parameters->limit && $total_results > 0) {
                        $pages = ceil($total_results / $this->parameters->limit);
                    }
                    /** body */
                    $fetch = "SELECT DISTINCT e.* FROM {$entity} e {$join} WHERE e.deleted = 0 {$where} {$order} {$limit} {$offset}";
                    $query_stmt = $this->db->prepare($fetch);
                    $this->bindValues($query_stmt, $valuesToBind);
                    $query_stmt->execute();
                    $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                    $parsedBody = $this->parseData($body, $this->table_config);

                    return [
                        'success' => 1,
                        'status' => 200,
                        'request' => $this->parameters,
                        'body' => $parsedBody,
                        'table' => $this->table_config,
                        'total' => $total_results,
                        'pages' => $pages,
                        'db_query' => $fetch
                    ];

                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'db.error',
                        'db_query' => $fetch
                    ];
                }
            }
            else if($this->isQueryAuthorized($entity) === 'group') {
                if(isset($this->table_config['authorization']['groupField'])) {
                    try {
                        $groupField = $this->table_config['authorization']['groupField'];
                        /** total & pages */
                        $fetch_total = "SELECT COUNT(DISTINCT e.id) FROM {$entity} e {$join} WHERE e.{$groupField} = :group_id AND e.deleted = 0 {$where}";
                        $query_stmt = $this->db->prepare($fetch_total);
                        $query_stmt->bindValue(":group_id", $this->user_info['idUserGroup'], PDO::PARAM_INT);
                        $this->bindValues($query_stmt, $valuesToBind);
                        $query_stmt->execute();
                        $total_results = $query_stmt->fetchColumn();
                        $pages = 0;
                        if($this->parameters->limit && $total_results > 0) {
                            $pages = ceil($total_results / $this->parameters->limit);
                        }
                        /** body */
                        $fetch = "SELECT DISTINCT e.* FROM {$entity} e {$join} WHERE e.{$groupField} = :group_id AND e.deleted = 0 {$where} {$order} {$limit} {$offset}";
                        $query_stmt = $this->db->prepare($fetch);
                        $query_stmt->bindValue(":group_id", $this->user_info['idUserGroup'], PDO::PARAM_INT);
                        $this->bindValues($query_stmt, $valuesToBind);
                        $query_stmt->execute();
                        $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $parsedBody = $this->parseData($body, $this->table_config);

                        return [
                            'success' => 1,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => $parsedBody,
                            'table' => $this->table_config,
                            'total' => $total_results,
                            'pages' => $pages,
                            'db_query' => $fetch
                        ];

                    } catch (PDOException $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'status' => 400,
                            'error_code' => 'db.error',
                            'db_query' => $fetch
                        ];
                    }
                }
                else {
                        return [
                            'success' => 0,
                            'status' => 400,
                            'error_code' => 'api.get.missing.groupField',
                            'db_query' => $fetch
                        ];
                }
            }
            else if($this->isQueryAuthorized($entity) === 'user') {
                if(isset($this->table_config['authorization']['userField'])) {
                    try {
                        $userField = $this->table_config['authorization']['userField'];
                        /** total & pages */
                        $fetch_total = "SELECT COUNT(DISTINCT e.id) FROM {$entity} e {$join} WHERE e.{$userField} = :user_id AND e.deleted = 0 {$where} ";
                        $query_stmt = $this->db->prepare($fetch_total);
                        $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                        $this->bindValues($query_stmt, $valuesToBind);
                        $query_stmt->execute();
                        $total_results = $query_stmt->fetchColumn();
                        $pages = 0;
                        if($this->parameters->limit && $total_results > 0) {
                            $pages = ceil($total_results / $this->parameters->limit);
                        }
                        /** body */
                        $fetch = "SELECT DISTINCT e.* FROM {$entity} e {$join} WHERE e.{$userField} = :user_id AND e.deleted = 0 {$where} {$order} {$limit} {$offset}";
                        $query_stmt = $this->db->prepare($fetch);
                        $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                        $this->bindValues($query_stmt, $valuesToBind);
                        $query_stmt->execute();
                        $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $parsedBody = $this->parseData($body, $this->table_config);

                        return [
                            'success' => 1,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => $parsedBody,
                            'table' => $this->table_config,
                            'total' => $total_results,
                            'pages' => $pages,
                            'db_query' => $fetch
                        ];

                    } catch (PDOException $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'status' => 400,
                            'error_code' => 'db.error',
                            'db_query' => $fetch
                        ];
                    }
                }
                else {
                        return [
                            'success' => 0,
                            'status' => 400,
                            'error_code' => 'api.get.missing.userField'
                        ];
                }
            }
            else {
                return [
                    'success' => 0,
                    'message' => "Forbidden.",
                    'status' => 403,
                    'error_code' => 'get.items.auth.forbidden'
                ];
            }
        }
        /** END: search items */
    }
?>