<?php

    class Post {
        protected $db;
        protected $user_info;
        protected $path;
        protected $parameters;
    
        function __construct($db, $user_info, $path, $parameters) {
            $this->db = $db;
            $this->user_info = json_decode($user_info, true);
            $this->path = $path;
            $this->parameters = $parameters;
        }

        protected function isQueryAuthorized($entity, $action) {
            $tables = json_decode(file_get_contents("../config/tables.json"), true);
            if (array_key_exists($entity, $tables)) {
                $table = $tables[$entity];
                $l = $this->user_info['level'];
                if($action === 'create') {
                    if(isset($table['permissions'][$l]['create'])) {
                        return $table['permissions'][$l]['create'];
                    }
                    else {
                        return 0;
                    }
                }
                else if($action === 'edit') {
                    if(isset($table['permissions'][$l]['edit'])) {
                        return $table['permissions'][$l]['edit'];
                    }
                    else {
                        return 0;
                    }
                }
                else {
                    return 0;
                }
            }
            else {
                return 0;
            }
        }

        protected function checkField($props, $value, $key) {
            $input_options = $props['options'];
            $input_type = $props['input_type'];
            $input_length = $props['length'];

            if($input_type === 'option') {
                $options = $input_options['options'];
                $valid = 0;
                foreach ($options as $op) {
                    if($op['value'] == $value) {
                        $valid = 1;
                    }
                }

                if($valid === 0) {
                    throw new Exception("Option doesn't exists for {$key}.");
                }
            }
        }

        protected function create($body, $entity, $table) {
            $excluded_fields = ['id', 'date', 'lastUpdate', 'author', 'idUserGroup', 'deleted'];
            $excluded_types = ['multipleExternalEntity', 'deepExternalEntity'];
            $afterInsertActions = false;
            $afterInsertBody = [];
            

            $insertBody = [];
            $mysql_set = "";
            $mysql_values = "";
            foreach ($body as $key => $value) {
                if (array_key_exists($key, $table['columns']) && !in_array($key, $excluded_fields)) {
                    $input_type = $table['columns'][$key]['input_type'];
                    if (in_array($input_type, $excluded_types)) {
                        $afterInsertActions = true;
                        $afterInsertBody[$key] = $value;
                    }
                    else {
                        /** Controllo dei tipi, della lunghezza, delle options */
                        try {
                            $this->checkField($table['columns'][$key], $value, $key);
                        } catch (Exception $e) {
                            return [
                                'success' => 0,
                                'status' => 200,
                                'error_code' => 'exception.create.field',
                                'message' => $e->getMessage()
                            ];
                        }

                        $mysql_set .= "{$key},";
                        $mysql_values .= ":{$key},";
                        $insertBody[$key] = $value;
                    }
                }
            }

            if (array_key_exists('author', $table['columns'])) {
                $mysql_set .= "author,";
                $userId = $this->user_info['id'];
                $mysql_values .= "{$userId},";
            }

            if (array_key_exists('idUserGroup', $table['columns'])) {
                if($this->user_info['idUserGroup'] !== null) {
                    $mysql_set .= "idUserGroup,";
                    $groupId = $this->user_info['idUserGroup'];
                    $mysql_values .= "{$groupId},";
                }
            }

            if(isset($table['authorization']['groupField'])) {
                $groupField = $table['authorization']['groupField'];
                if($groupField !== 'idUserGroup') {
                    if(isset($body->$groupField)) {
                        //campo già presente nel body

                        //VERIFICARE CHE IN BASE AL PERMESSO CORRISPONDA AL GRUPPO CORRETTO
                    }
                    else {
                        //compilo campo in automatico con idUserGroup
                        if($entity !== 'user_group') {
                            $mysql_set .= "{$groupField},";
                            $groupId = $this->user_info['idUserGroup'];
                            $mysql_values .= "{$groupId},";
                        }
                    }
                }
            }

            if(strlen($mysql_set) > 1) {
                $mysql_set = substr($mysql_set, 0, -1);
                $mysql_values = substr($mysql_values, 0, -1);
                try {
                    $insert = "INSERT INTO " . $entity . " (" . $mysql_set . ") VALUES (" . $mysql_values . ") ";
                    $query_stmt = $this->db->prepare($insert);
                    foreach ($insertBody as $key => $value) {
                        if (array_key_exists($key, $table['columns']) && !in_array($key, $excluded_fields)) {
                            $input_type = $table['columns'][$key]['input_type'];
                            if(!in_array($input_type, $excluded_types)) {
                                if($table['columns'][$key]['type'] === 'INT') {
                                    $query_stmt->bindValue(":{$key}", $value, PDO::PARAM_INT);
                                }
                                else {
                                    $query_stmt->bindValue(":{$key}", $value, PDO::PARAM_STR);
                                }
                            }
                        }
                    }
                    $query_stmt->execute();
                    $lastInsertId = $this->db->lastInsertId();
    
                    if($afterInsertActions === true) {
                        foreach ($afterInsertBody as $key => $value) {
                            $input_type = $table['columns'][$key]['input_type'];
                            if($input_type === 'multipleExternalEntity') {
                                $link_entity = $table['columns'][$key]['options']['link_entity'];
                                $source_field = $table['columns'][$key]['options']['source_field'];
                                $destination_field = $table['columns'][$key]['options']['destination_field'];
                                if($value !== null && count($value) > 0) {
                                    foreach ($value as $item) {
                                        $insert = "INSERT INTO {$link_entity} ({$source_field}, {$destination_field}) VALUES (:source_field, :destination_field) ";
                                        $query_stmt = $this->db->prepare($insert);
                                        $query_stmt->bindValue(":source_field", $lastInsertId, PDO::PARAM_INT);
                                        $query_stmt->bindValue(":destination_field", $item, PDO::PARAM_INT);
                                        $query_stmt->execute();
                                    }
                                }
                            }
                            else if($input_type === 'deepExternalEntity') {
                                $external_entity = $table['columns'][$key]['options']['entity'];
                                $source_field = $table['columns'][$key]['options']['source_field'];
                                if($value !== null && count($value) > 0) {
                                    foreach ($value as $item) {
                                        $tables = json_decode(file_get_contents("../config/tables.json"), true);
                                        $table_external = $tables[$external_entity];
                                        $item->{"$source_field"} = $lastInsertId;
                                        $this->create($item, $external_entity, $table_external);
                                    }
                                }
                            }
                        }
                    }

                    /** log */
                    $bodyJson = json_encode($body);
                    $insert = "INSERT INTO log (idUser, entity, idItem, operation, notes) VALUES (:idUser, :entity, :idItem, '2_ADD', :body) ";
                    $query_stmt = $this->db->prepare($insert);
                    $query_stmt->bindValue(":idUser", $this->user_info['id'], PDO::PARAM_INT);
                    $query_stmt->bindValue(":entity", $entity, PDO::PARAM_STR);
                    $query_stmt->bindValue(":idItem", $lastInsertId, PDO::PARAM_INT);
                    $query_stmt->bindValue(":body", $bodyJson, PDO::PARAM_STR);
                    $query_stmt->execute();
    
                    return [
                        'success' => 1,
                        'status' => 200,
                        'id' => $lastInsertId
                    ];
                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'create.001'
                    ];
                } catch(Exception $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'create.003'
                    ];
                }
            }
            else {
                return [
                    'success' => 0,
                    'status' => 200
                ];
            }
        }

                /**
         * Sincronizza le righe figlie di una colonna deepExternalEntity con l'elenco
         * ricevuto: aggiorna quelle con id, cancella quelle non più presenti, crea le
         * nuove. Usata da edit() e da UsersFunctions::editUser, così /users e il
         * salvataggio standard trattano i campi deep allo stesso modo.
         *
         * $value è l'array di oggetti figli già in formato piatto (come lo produce
         * parseData nel frontend: option -> value, externalEntity -> id).
         */
        public function syncDeepExternalEntity($table, $key, $value, $id) {
            $external_entity = $table['columns'][$key]['options']['entity'];
            $source_field = $table['columns'][$key]['options']['source_field'];

            if($value !== null && count($value) > 0) {
                $ids_not_to_delete = array();
                foreach ($value as $item) {
                    $tables = json_decode(file_get_contents("../config/tables.json"), true);
                    $table_external = $tables[$external_entity];
                    $item->{"$source_field"} = $id;
                    
                    if (isset($item->id)) {
                        array_push($ids_not_to_delete, $item->id);
                        $this->edit($item, $item->id, $external_entity, $table_external);
                    }
                }

                if(count($ids_not_to_delete) > 0) {
                    $not_in_list = implode(",", $ids_not_to_delete);
                    $delete = "DELETE FROM {$external_entity} WHERE {$source_field}=:id AND id NOT IN ({$not_in_list}) ";
                    $query_stmt = $this->db->prepare($delete);
                    $query_stmt->bindValue(':id', $id, PDO::PARAM_INT);
                    $query_stmt->execute();
                }
                else {
                    $delete = "DELETE FROM {$external_entity} WHERE {$source_field}=:id ";
                    $query_stmt = $this->db->prepare($delete);
                    $query_stmt->bindValue(':id', $id, PDO::PARAM_INT);
                    $query_stmt->execute();
                }

                foreach ($value as $item) {
                    $tables = json_decode(file_get_contents("../config/tables.json"), true);
                    $table_external = $tables[$external_entity];
                    $item->{"$source_field"} = $id;
                    
                    if (!isset($item->id)) {
                        $this->create($item, $external_entity, $table_external);
                    }
                }
            }
            else {
                $delete = "DELETE FROM {$external_entity} WHERE {$source_field}=:id ";
                $query_stmt = $this->db->prepare($delete);
                $query_stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $query_stmt->execute();
            }
        }

        protected function edit($body, $id, $entity, $table) {

            $excluded_fields = ['id', 'date', 'lastUpdate', 'author', 'idUserGroup', 'deleted'];
            $excluded_types = ['multipleExternalEntity', 'deepExternalEntity'];
            $afterInsertActions = false;
            $afterInsertBody = [];
            

            $mysql_set = "";
            
            $insertBody = [];
            foreach ($body as $key => $value) {

                if (array_key_exists($key, $table['columns']) && !in_array($key, $excluded_fields)) {
                    $input_type = $table['columns'][$key]['input_type'];

                    if (in_array($input_type, $excluded_types)) {
                        $afterInsertActions = true;
                        $afterInsertBody[$key] = $value;
                    }
                    else {
                        /** Controllo dei tipi, della lunghezza, delle options */
                        try {
                            $this->checkField($table['columns'][$key], $value, $key);
                        } catch (Exception $e) {
                            return [
                                'success' => 0,
                                'status' => 200,
                                'error_code' => 'exception.create.field',
                                'message' => $e->getMessage()
                            ];
                        }

                        $mysql_set .= "{$key}=:{$key},";
                        $insertBody[$key] = $value;
                    }
                }
            }

            if(strlen($mysql_set) > 1) {
                $mysql_set = substr($mysql_set, 0, -1);
                try {
                    $edit = "UPDATE {$entity} SET " . $mysql_set . " WHERE id=:id ";
                    $query_stmt = $this->db->prepare($edit);
                    $query_stmt->bindValue(':id', $id, PDO::PARAM_INT);
                    foreach ($insertBody as $key => $value) {
                        if (array_key_exists($key, $table['columns']) && !in_array($key, $excluded_fields)) {
                            $input_type = $table['columns'][$key]['input_type'];
                            if(!in_array($input_type, $excluded_types)) {
                                if($table['columns'][$key]['type'] === 'INT') {
                                    $query_stmt->bindValue(":{$key}", $value, PDO::PARAM_INT);
                                }
                                else {
                                    $query_stmt->bindValue(":{$key}", $value, PDO::PARAM_STR);
                                }
                            }
                        }
                    }
                    $query_stmt->execute();
    
                    if($afterInsertActions === true) {
                        foreach ($afterInsertBody as $key => $value) {
                            $input_type = $table['columns'][$key]['input_type'];
                            if($input_type === 'multipleExternalEntity') {
                                $link_entity = $table['columns'][$key]['options']['link_entity'];
                                $source_field = $table['columns'][$key]['options']['source_field'];
                                $destination_field = $table['columns'][$key]['options']['destination_field'];
    
                                $delete = "DELETE FROM {$link_entity} WHERE {$source_field}=:id ";
                                $query_stmt = $this->db->prepare($delete);
                                $query_stmt->bindValue(':id', $id, PDO::PARAM_INT);
                                $query_stmt->execute();
    
                                if($value !== null && count($value) > 0) {
                                    foreach ($value as $item) {
                                        $insert = "INSERT INTO {$link_entity} ({$source_field}, {$destination_field}) VALUES (:source_field, :destination_field) ";
                                        $query_stmt = $this->db->prepare($insert);
                                        $query_stmt->bindValue(":source_field", $id, PDO::PARAM_INT);
                                        $query_stmt->bindValue(":destination_field", $item, PDO::PARAM_INT);
                                        $query_stmt->execute();
                                    }
                                }
                            }
                            else if($input_type === 'deepExternalEntity') {
                                $this->syncDeepExternalEntity($table, $key, $value, $id);
                            }
                        }
                    }

                    /** log */
                    $bodyJson = json_encode($body);
                    $insert = "INSERT INTO log (idUser, entity, idItem, operation, notes) VALUES (:idUser, :entity, :idItem, '1_EDIT', :body) ";
                    $query_stmt = $this->db->prepare($insert);
                    $query_stmt->bindValue(":idUser", $this->user_info['id'], PDO::PARAM_INT);
                    $query_stmt->bindValue(":entity", $entity, PDO::PARAM_STR);
                    $query_stmt->bindValue(":idItem", $id, PDO::PARAM_INT);
                    $query_stmt->bindValue(":body", $bodyJson, PDO::PARAM_STR);
                    $query_stmt->execute();
    
                    return [
                        'success' => 1,
                        'status' => 200
                    ];

                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'edit.001'
                    ];
                } catch(Exception $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'edit.003'
                    ];
                }
            }
            else {
                return [
                    'success' => 0,
                    'status' => 200
                ];
            }

        }

        public function fetchPost() {
            $entity = $this->path;
            $tables = json_decode(file_get_contents("../config/tables.json"), true);
            $table = $tables[$entity];

            if($this->isQueryAuthorized($entity, $this->parameters->action) === true) {
                if($this->parameters->action == 'create' ) {
                    $body = $this->parameters->body;

                    return $this->create($body, $entity, $table);
                }
                else if($this->parameters->action == 'edit' ) {
                    $body = $this->parameters->body;
                    $id = $this->parameters->id;

                    return $this->edit($body, $id, $entity, $table);
                }
            }
            else if($this->isQueryAuthorized($entity, $this->parameters->action) === 'group') {
                if($this->parameters->action == 'create' ) {
                    $body = $this->parameters->body;

                    return $this->create($body, $entity, $table);
                }
                else if($this->parameters->action == 'edit' ) {
                    $body = $this->parameters->body;
                    $id = $this->parameters->id;

                    if(isset($table['authorization']['groupField'])) {
                        $groupField = $table['authorization']['groupField'];
                        $fetch_auth = "SELECT * FROM {$entity} WHERE id = :id AND {$groupField} = :group_id ";
                        $query_stmt = $this->db->prepare($fetch_auth);
                        $query_stmt->bindValue(":id", $id, PDO::PARAM_INT);
                        $query_stmt->bindValue(":group_id", $this->user_info['idUserGroup'], PDO::PARAM_INT);
                        $query_stmt->execute();
                        $continue = $query_stmt->rowCount();
                        if($continue === 1) {
                            return $this->edit($body, $id, $entity, $table);
                        }
                        else {
                            return [
                                'success' => 0,
                                'message' => "User not allowed.",
                                'status' => 403,
                                'error_code' => 'api.post.forbidden.idUserGroup'
                            ];
                        }
                    }
                    else {
                            return [
                                'success' => 0,
                                'message' => "Missing groupField",
                                'status' => 400,
                                'error_code' => 'api.post.missing.groupField'
                            ];
                    }
                }
            }
            else if($this->isQueryAuthorized($entity, $this->parameters->action) === 'user') {
                if($this->parameters->action == 'create' ) {
                    $body = $this->parameters->body;

                    return $this->create($body, $entity, $table);
                }
                else if($this->parameters->action == 'edit' ) {
                    $body = $this->parameters->body;
                    $id = $this->parameters->id;

                    if(isset($table['authorization']['userField'])) {
                        $userField = $table['authorization']['userField'];
                        $fetch_auth = "SELECT * FROM {$entity} WHERE id = :id AND {$userField} = :user_id ";
                        $query_stmt = $this->db->prepare($fetch_auth);
                        $query_stmt->bindValue(":id", $id, PDO::PARAM_INT);
                        $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                        $query_stmt->execute();
                        $continue = $query_stmt->rowCount();
                        if($continue === 1) {
                            return $this->edit($body, $id, $entity, $table);
                        }
                        else {
                            return [
                                'success' => 0,
                                'message' => "User not allowed.",
                                'status' => 403,
                                'error_code' => 'api.post.forbidden.user_id'
                            ];
                        }
                    }
                    else {
                            return [
                                'success' => 0,
                                'message' => "Missing userField",
                                'status' => 400,
                                'error_code' => 'api.post.missing.userField'
                            ];
                    }
                }
            }
            else {
                return [
                    'success' => 0,
                    'message' => "Forbidden.",
                    'status' => 403,
                    'error_code' => 'api.post.forbidden'
                ];
            }
        }




    }
?>