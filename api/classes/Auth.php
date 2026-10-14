<?php

    class Auth {
        protected $db;
        protected $token = null;

        function __construct($db) {
            $this->db = $db;
            $headers = apache_request_headers();
            if (isset($headers["Authorization"])) {
                $matches = array();
                if (preg_match("/Bearer\s(\S+)/", $headers["Authorization"], $matches)) {
                    $token = $matches[1];
                    $this->token = $token;
                }
            } else {
                $this->token = "null";
            }
        }

        public function getUserId() {
            if ($this->token === "null") {
                return "NONE";
            } else {
                try {
                    $fetch_user = "SELECT * FROM auth_token WHERE sid=:token";
                    $query_stmt = $this->db->prepare($fetch_user);
                    $query_stmt->bindValue(":token", $this->token, PDO::PARAM_STR);
                    $query_stmt->execute();

                    if ($query_stmt->rowCount() === 1) {
                        $data = $query_stmt->fetch(PDO::FETCH_ASSOC);
                        if (time() - strtotime($data["lastUpdate"]) > 3600) { // 60 minuti
                            return "AUTH-REFRESH-SID";
                        } else {
                            // Verifica se l'utente è disabilitato
                            $fetch_user = "SELECT * FROM user WHERE id=:user AND deleted = 0 LIMIT 1";
                            $query_user = $this->db->prepare($fetch_user);
                            $query_user->bindValue(":user", $data["idUser"], PDO::PARAM_INT);
                            $query_user->execute();

                            if ($query_user->rowCount()) {
                                $user_data = $query_user->fetch(PDO::FETCH_ASSOC);

                                // Verifica se l'utente è disabilitato (solo se il campo esiste)
                                if (isset($user_data["disabled"]) && $user_data["disabled"] == 1) {
                                    return "USER-DISABLED";
                                }

                                return $data["idUser"];
                            } else {
                                return "NONE";
                            }
                        }
                    } else {
                        return "NONE";
                    }
                } catch (PDOException $e) {
                    return "NONE";
                }
            }
        }

        public function getUser() {
            if ($this->token !== "null") {
                try {
                    $fetch_user = "SELECT u.* FROM user u LEFT JOIN auth_token t ON u.id = t.idUser WHERE t.sid=:token LIMIT 1";
                    $query_stmt = $this->db->prepare($fetch_user);
                    $query_stmt->bindValue(":token", $this->token, PDO::PARAM_STR);
                    $query_stmt->execute();

                    if ($query_stmt->rowCount()) {
                        $user = $query_stmt->fetch(PDO::FETCH_ASSOC);
                        // Verifica se l'utente è disabilitato
                        if (isset($user["disabled"]) && $user["disabled"] == 1) {
                            return array("name" => "disabled", "level" => false, "disabled" => true);
                        }
                        return $user;
                    } else {
                        return array("name" => "visitor", "level" => false);
                    }
                } catch (PDOException $e) {
                    return array("name" => "visitor", "level" => false);
                }
            } else {
                return array("name" => "visitor", "level" => false);
            }
        }
    }
?>
