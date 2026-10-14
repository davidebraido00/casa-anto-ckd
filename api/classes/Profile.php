<?php

    class Profile {
        protected $db;
        protected $parameters;
    
        function __construct($db, $parameters) {
            $this->db = $db;
            $this->parameters = $parameters;
        }
        

        public function updatePassword($user_id) {

            if($this->parameters->password != '' && $this->parameters->newPassword != '' ) {
                $password = $this->parameters->password;
                $newPassword = $this->parameters->newPassword;
                $confirmPassword = $this->parameters->confirmPassword;
                if($newPassword === $confirmPassword) {
                    try {
                        $fetch = "SELECT * FROM user WHERE id = :id AND password = :password ";
                        $query_stmt = $this->db->prepare($fetch);
                        $query_stmt->bindValue(":id", $user_id, PDO::PARAM_INT);
                        $query_stmt->bindValue(":password", $password, PDO::PARAM_STR);
                        $query_stmt->execute();

                        $results = $query_stmt->rowCount();
                        if($results == 1) {
                            $update = "UPDATE user SET password = :password WHERE id = :user_id ";
                            $query_stmt = $this->db->prepare($update);
                            $query_stmt->bindValue(":user_id", $user_id, PDO::PARAM_INT);
                            $query_stmt->bindValue(":password", $newPassword, PDO::PARAM_STR);
                            $query_stmt->execute();  

                            return [
                                'success' => 1,
                                'status' => 200,
                                'request' => $this->parameters,
                                'user_id' => $user_id
                            ];
                        }
                        else {
                            return [
                                'success' => 0,
                                'status' => 200,
                                'request' => $this->parameters,
                                'user_id' => $user_id,
                                'error_code' => 'profile.old_password',
                                'message' => 'Old password is wrong.'
                            ];
                        }
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
                        'status' => 200,
                        'request' => $this->parameters,
                        'error_code' => 'profile.new_password',
                        'message' => 'Verify new password.'
                    ];
                }

            }
            else {
                
                return [
                    'success' => 0,
                    'message' => "Missing fields.",
                    'error_code' => 'profile.003',
                    'status' => 500
                ];
                
            }
        }


        public function updateProfile($user_id) {

            $excluded = ['level', 'idUserGroup', 'id', 'date', 'password'];
            foreach ($this->parameters as $key => $value) {
                if(!in_array($key, $excluded)) {
                    try {
                        $update = "UPDATE user SET {$key} = :val WHERE id = :user_id ";
                        $query_stmt = $this->db->prepare($update);
                        $query_stmt->bindValue(":user_id", $user_id, PDO::PARAM_INT);
                        $query_stmt->bindValue(":val", $value, PDO::PARAM_STR);
                        $query_stmt->execute();

                    } catch (PDOException $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'error_code' => 'db.error',
                            'status' => 400
                        ];
                    }
                }
            }

            return [
                'success' => 1,
                'status' => 200,
                'request' => $this->parameters,
                'user_id' => $user_id
            ];

        }
    
    }
?>