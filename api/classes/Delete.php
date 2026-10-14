<?php

    class Delete {
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

        protected function isQueryAuthorized($entity) {
            $tables = json_decode(file_get_contents("../config/tables.json"), true);
            if (array_key_exists($entity, $tables)) {
                $table = $tables[$entity];
                $l = $this->user_info['level'];
                if(isset($table['permissions'][$l]['delete'])) {
                    return $table['permissions'][$l]['delete'];
                }
                else {
                    return 0;
                }
            }
            else {
                return 0;
            }
        }

        protected function delete($entity, $id) {
            try {
                /*
                $delete = "DELETE FROM {$entity} WHERE id=:id ";
                $query_stmt = $this->db->prepare($delete);
                $query_stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $query_stmt->execute();
                */
                $delete = "UPDATE {$entity} SET deleted=1 WHERE id=:id";
                $query_stmt = $this->db->prepare($delete);
                $query_stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $query_stmt->execute();

                /** log */
                $insert = "INSERT INTO log (idUser, entity, idItem, operation, notes) VALUES (:idUser, :entity, :idItem, '0_DELETE', '') ";
                $query_stmt = $this->db->prepare($insert);
                $query_stmt->bindValue(":idUser", $this->user_info['id'], PDO::PARAM_INT);
                $query_stmt->bindValue(":entity", $entity, PDO::PARAM_STR);
                $query_stmt->bindValue(":idItem", $id, PDO::PARAM_INT);
                $query_stmt->execute();
                
                return [
                    'success' => 1,
                    'status' => 200,
                    'id' => $id
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

        public function fetchDelete() {
            $entity = $this->path;
            $id = $this->parameters->id;

            if($this->isQueryAuthorized($entity) === true) {
                return $this->delete($entity, $id);
            }
            else if($this->isQueryAuthorized($entity) === 'user') {
                $tables = json_decode(file_get_contents("../config/tables.json"), true);
                $table = $tables[$entity];
                if(isset($table['authorization']['userField'])) {
                    $userField = $table['authorization']['userField'];
                    $fetch_auth = "SELECT * FROM {$entity} WHERE id = :id AND {$userField} = :user_id ";
                    $query_stmt = $this->db->prepare($fetch_auth);
                    $query_stmt->bindValue(":id", $id, PDO::PARAM_INT);
                    $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                    $query_stmt->execute();
                    $continue = $query_stmt->rowCount();
                    if($continue === 1) {
                        return $this->delete($entity, $id);
                    }
                    else {
                        return [
                            'success' => 0,
                            'message' => "User not allowed.",
                            'status' => 403,
                            'error_code' => 'api.post.forbidden.idUser'
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
            else if($this->isQueryAuthorized($entity) === 'group') {
                $tables = json_decode(file_get_contents("../config/tables.json"), true);
                $table = $tables[$entity];
                if(isset($table['authorization']['groupField'])) {
                    $groupField = $table['authorization']['groupField'];
                    $fetch_auth = "SELECT * FROM {$entity} WHERE id = :id AND {$groupField} = :group_id ";
                    $query_stmt = $this->db->prepare($fetch_auth);
                    $query_stmt->bindValue(":id", $id, PDO::PARAM_INT);
                    $query_stmt->bindValue(":group_id", $this->user_info['idUserGroup'], PDO::PARAM_INT);
                    $query_stmt->execute();
                    $continue = $query_stmt->rowCount();
                    if($continue === 1) {
                        return $this->delete($entity, $id);
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
            else {
                return [
                    'success' => 0,
                    'message' => "Forbidden.",
                    'status' => 403
                ];
            }
        }
        
    }
?>