<?php

    class User {
        protected $db;
        protected $parameters;

        function __construct($db, $parameters) {
            $this->db = $db;
            $this->parameters = $parameters;
        }

        /**
         * Token di sessione: 32 caratteri esadecimali da 128 bit del generatore
         * crittografico. Stesso formato del vecchio md5(rand . time), che pero'
         * era prevedibile: rand() e' un Mersenne Twister, non un CSPRNG.
         */
        private function generateToken() {
            return bin2hex(random_bytes(16));
        }

        /** PIN di recupero: alfabeto senza caratteri ambigui (0/O, 1/I/L, J). */
        private function generateRandomStringSimple($length) {
            $characters = '123456789ABCDEFGHKMNPQRSTUVWXYZ';
            $charactersLength = strlen($characters);
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[random_int(0, $charactersLength - 1)];
            }
            return $randomString;
        }

        private function generateAuthTable($level) {
            $properties = array();
            $tables = json_decode(file_get_contents("../config/tables.json"), true);
            foreach ($tables as $key => $value) {
                $entity = $key;
                $columns = $value["columns"];

                $entity_fields = array();
                $entity_visible_fields = array();
                $entity_editable_fields = array();
                foreach ($columns as $k => $field) {
                    array_push($entity_fields, $k);

                    if (isset($field["options"]["visible"]) && $field["options"]["visible"] === true) {
                        array_push($entity_visible_fields, $k);
                    }
                    if (isset($field["options"]["visible"]) && $field["options"]["editable"] === true) {
                        array_push($entity_editable_fields, $k);
                    }
                }
                $properties["entities"][$key]["visible_fields"] = $entity_visible_fields;
                $properties["entities"][$key]["editable_fields"] = $entity_editable_fields;
                $properties["entities"][$key]["fields"] = $entity_fields;

                $permissions = $value["permissions"];

                if (isset($value["permissions"][$level]["view"])) {
                    $properties["entities"][$key]["view"] = $value["permissions"][$level]["view"];
                } else {
                    $properties["entities"][$key]["view"] = 0;
                }
                if (isset($value["permissions"][$level]["create"])) {
                    $properties["entities"][$key]["create"] = $value["permissions"][$level]["create"];
                } else {
                    $properties["entities"][$key]["create"] = 0;
                }
                if (isset($value["permissions"][$level]["edit"])) {
                    $properties["entities"][$key]["edit"] = $value["permissions"][$level]["edit"];
                } else {
                    $properties["entities"][$key]["edit"] = 0;
                }
                if (isset($value["permissions"][$level]["delete"])) {
                    $properties["entities"][$key]["delete"] = $value["permissions"][$level]["delete"];
                } else {
                    $properties["entities"][$key]["delete"] = 0;
                }
            }
            return $properties;
        }

        private function generatePropsTable($level) {
            $properties = array();
            $tables = json_decode(file_get_contents("../config/tables.json"), true);
            return $tables;
        }

        /**
         * Pulizia token di autenticazione per un utente:
         * 1. Rimuove i token non utilizzati da più di 30 giorni
         * 2. Se ci sono ancora 10 o più token, rimuove i meno recenti mantenendo solo i 9 più recenti
         */
        private function cleanUpTokens($user_id) {
            // Rimuove token non utilizzati da più di 30 giorni
            $cleanup = "DELETE FROM auth_token WHERE idUser = :user_id AND lastUpdate < DATE_SUB(NOW(), INTERVAL 30 DAY) ";
            $query_cleanup = $this->db->prepare($cleanup);
            $query_cleanup->bindValue(":user_id", $user_id, PDO::PARAM_INT);
            $query_cleanup->execute();

            // Limite a 10 sessioni: rimuove i meno recenti se necessario
            $count_stmt = $this->db->prepare("SELECT COUNT(*) as total FROM auth_token WHERE idUser = :user_id ");
            $count_stmt->bindValue(":user_id", $user_id, PDO::PARAM_INT);
            $count_stmt->execute();
            $token_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            if ($token_count >= 10) {
                $excess = $token_count - 9;
                $delete_old = $this->db->prepare("DELETE FROM auth_token WHERE idUser = :user_id ORDER BY lastUpdate ASC LIMIT " . intval($excess));
                $delete_old->bindValue(":user_id", $user_id, PDO::PARAM_INT);
                $delete_old->execute();
            }
        }

        public function insertSid($user_id) {
            $sid = $this->generateToken();
            $ltsid = $this->generateToken();

            $this->cleanUpTokens($user_id);

            $insert = "INSERT INTO auth_token (idUser, sid, ltsid) VALUES (:user_id, :sid, :ltsid) ";
            $query_stmt = $this->db->prepare($insert);
            $query_stmt->bindValue(":user_id", $user_id, PDO::PARAM_INT);
            $query_stmt->bindValue(":sid", $sid, PDO::PARAM_STR);
            $query_stmt->bindValue(":ltsid", $ltsid, PDO::PARAM_STR);
            $query_stmt->execute();

            $tokens = array(
                "sid" => $sid,
                "ltsid" => $ltsid
            );
            return $tokens;
        }

        public function updateSid($user_id, $ltsid) {
            $sid = $this->generateToken();

            $update = "UPDATE auth_token SET sid = :sid WHERE idUser = :user_id AND ltsid = :ltsid ";
            $query_stmt = $this->db->prepare($update);
            $query_stmt->bindValue(":user_id", $user_id, PDO::PARAM_INT);
            $query_stmt->bindValue(":sid", $sid, PDO::PARAM_STR);
            $query_stmt->bindValue(":ltsid", $ltsid, PDO::PARAM_STR);
            $query_stmt->execute();

            $tokens = array(
                "sid" => $sid,
                "ltsid" => $ltsid
            );
            return $tokens;
        }

        public function fetchLogin() {
            if (isset($this->parameters->username) && $this->parameters->username != '' && isset($this->parameters->password)) {
                try {
                    $username = $this->parameters->username;
                    $password = $this->parameters->password;

                    $fetch = "SELECT * FROM user WHERE (email = :email OR username = :email) AND password = :password AND deleted = 0 ";
                    $query_stmt = $this->db->prepare($fetch);
                    $query_stmt->bindValue(":email", $username, PDO::PARAM_STR);
                    $query_stmt->bindValue(":password", $password, PDO::PARAM_STR);
                    $query_stmt->execute();

                    $results = $query_stmt->rowCount();
                    if ($results === 1) {
                        $data = $query_stmt->fetch(PDO::FETCH_ASSOC);

                        // Verifica se l'utente è disabilitato
                        if (isset($data['disabled']) && $data['disabled'] == 1) {
                            return [
                                'success' => 0,
                                'status' => 200,
                                'request' => $this->parameters,
                                'body' => [],
                                'message' => "Account disabilitato. Contattare l'amministratore.",
                                'error_code' => 'login.disabled'
                            ];
                        }

                        $tokens = $this->insertSid($data["id"]);

                        /** log */
                        $insert = "INSERT INTO log (idUser, entity, operation, notes) VALUES (:idUser, 'user', '3_LOGIN', '') ";
                        $query_stmt = $this->db->prepare($insert);
                        $query_stmt->bindValue(":idUser", $data["id"], PDO::PARAM_INT);
                        $query_stmt->execute();

                        return [
                            'success' => 1,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => $data,
                            'auth_map' => $this->generateAuthTable($data["level"]),
                            'props' => $this->generatePropsTable($data["level"]),
                            'sid' => $tokens["sid"],
                            'ltsid' => $tokens["ltsid"]
                        ];
                    } else {
                        return [
                            'success' => 0,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => [],
                            'message' => "User does not exist or credentials are wrong.",
                            'error_code' => 'login.000'
                        ];
                    }
                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'login.001'
                    ];
                } catch (Exception $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'login.002'
                    ];
                }
            } else {
                return [
                    'success' => 0,
                    'message' => "Missing fields. (username, password)",
                    'error_code' => 'login.002',
                    'status' => 500
                ];
            }
        }

        public function fetchRefreshSid() {
            if (isset($this->parameters->ltsid) && $this->parameters->ltsid != '') {
                $ltsid = $this->parameters->ltsid;

                try {
                    $fetch = "SELECT * FROM auth_token WHERE ltsid = :ltsid ";
                    $query_stmt = $this->db->prepare($fetch);
                    $query_stmt->bindValue(":ltsid", $ltsid, PDO::PARAM_STR);
                    $query_stmt->execute();

                    $results = $query_stmt->rowCount();
                    if ($results === 1) {
                        $data = $query_stmt->fetch(PDO::FETCH_ASSOC);
                        /* Stesso filtro di Auth::getUserId: un utente cancellato non
                           deve poter rinnovare la sessione, altrimenti il client
                           entra in un ciclo refresh riuscito / chiamata rifiutata. */
                        $fetch_user = "SELECT * FROM user WHERE id = :id AND deleted = 0 ";
                        $query_user = $this->db->prepare($fetch_user);
                        $query_user->bindValue(":id", $data["idUser"], PDO::PARAM_INT);
                        $query_user->execute();
                        $body = $query_user->fetch(PDO::FETCH_ASSOC);

                        if (!$body) {
                            $delete = "DELETE FROM auth_token WHERE idUser = :id";
                            $query_delete = $this->db->prepare($delete);
                            $query_delete->bindValue(":id", $data["idUser"], PDO::PARAM_INT);
                            $query_delete->execute();

                            return [
                                'success' => 0,
                                'status' => 403,
                                'request' => $this->parameters,
                                'body' => [],
                                'message' => "User not found.",
                                'error_code' => 'refreshSid.001'
                            ];
                        }

                        // Verifica se l'utente è disabilitato
                        if (isset($body['disabled']) && $body['disabled'] == 1) {
                            // Elimina i token di sessione dell'utente disabilitato
                            $delete = "DELETE FROM auth_token WHERE idUser = :id";
                            $query_delete = $this->db->prepare($delete);
                            $query_delete->bindValue(":id", $data["idUser"], PDO::PARAM_INT);
                            $query_delete->execute();

                            return [
                                'success' => 0,
                                'status' => 403,
                                'request' => $this->parameters,
                                'body' => [],
                                'message' => "Account disabilitato. Contattare l'amministratore.",
                                'error_code' => 'refreshSid.disabled'
                            ];
                        }

                        $tokens = $this->updateSid($data["idUser"], $ltsid);

                        return [
                            'success' => 1,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => $body,
                            'auth_map' => $this->generateAuthTable($body["level"]),
                            'props' => $this->generatePropsTable($body["level"]),
                            'sid' => $tokens["sid"],
                            'ltsid' => $tokens["ltsid"]
                        ];
                    } else {
                        return [
                            'success' => 0,
                            'status' => 403,
                            'request' => $this->parameters,
                            'body' => [],
                            'message' => "User not found.",
                            'error_code' => 'refreshSid.001'
                        ];
                    }
                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e,
                        'status' => 400
                    ];
                }
            } else {
                return [
                    'success' => 0,
                    'message' => "Missing fields.",
                    'error_code' => 'refreshSid.002',
                    'status' => 500
                ];
            }
        }

        public function fetchForgot() {
            if ($this->parameters->email != '') {
                $email = $this->parameters->email;

                try {
                    $fetch = "SELECT * FROM user WHERE email = :email ";
                    $query_stmt = $this->db->prepare($fetch);
                    $query_stmt->bindValue(":email", $email, PDO::PARAM_STR);
                    $query_stmt->execute();

                    $results = $query_stmt->rowCount();
                    if ($results === 1) {
                        $data = $query_stmt->fetch(PDO::FETCH_ASSOC);

                        // Verifica se l'utente è disabilitato
                        if (isset($data['disabled']) && $data['disabled'] == 1) {
                            return [
                                'success' => 0,
                                'status' => 200,
                                'request' => $this->parameters,
                                'body' => [],
                                'message' => "Account disabilitato. Contattare l'amministratore.",
                                'error_code' => 'forgot.disabled'
                            ];
                        }

                        $user_id = $data["id"];

                        $pin = $this->generateRandomStringSimple(8);

                        $insert = "INSERT INTO auth_pin (idUser, pin) VALUES (:user_id, :pin) ";
                        $query_pin = $this->db->prepare($insert);
                        $query_pin->bindValue(":user_id", $user_id, PDO::PARAM_INT);
                        $query_pin->bindValue(":pin", $pin, PDO::PARAM_STR);
                        $query_pin->execute();

                        $settings = json_decode(file_get_contents("../config/settings.json"), true);
                        $domain = $settings["host"]["domain"];
                        $title = "Recupero password - " . $domain;
                        $text = "<html><body><h1>Password dimenticata</h1><p>Inserisci il seguente pin di conferma:</p><h3>{$pin}</h3></body></html>";

                        $emailClass = new Email($this->db, json_encode(array()));
                        return $emailClass->sendMessage($email, $email, $title, $text);
                    } else {
                        return [
                            'success' => 0,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => [],
                            'message' => "User not found.",
                            'error_code' => 'forgot.000'
                        ];
                    }
                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'forgot.001'
                    ];
                }
            } else {
                return [
                    'success' => 0,
                    'message' => "Missing fields.",
                    'error_code' => 'forgot.002',
                    'status' => 500
                ];
            }
        }

        public function fetchReset() {
            if ($this->parameters->pin != '') {
                if (strcmp($this->parameters->newPassword, $this->parameters->confirmPassword) === 0) {
                    $newPassword = $this->parameters->newPassword;
                    $pin = $this->parameters->pin;

                    try {
                        $fetch = "SELECT * FROM auth_pin WHERE pin = :pin ";
                        $query_stmt = $this->db->prepare($fetch);
                        $query_stmt->bindValue(":pin", $pin, PDO::PARAM_STR);
                        $query_stmt->execute();

                        $results = $query_stmt->rowCount();
                        if ($results === 1) {
                            $data = $query_stmt->fetch(PDO::FETCH_ASSOC);
                            $user_id = $data["idUser"];

                            $update = "UPDATE user SET password = :password WHERE id = :user_id ";
                            $query_stmt = $this->db->prepare($update);
                            $query_stmt->bindValue(":user_id", $user_id, PDO::PARAM_INT);
                            $query_stmt->bindValue(":password", $newPassword, PDO::PARAM_STR);
                            $query_stmt->execute();

                            $delete = "DELETE FROM auth_pin WHERE idUser = :user_id ";
                            $query_del = $this->db->prepare($delete);
                            $query_del->bindValue(":user_id", $user_id, PDO::PARAM_STR);
                            $query_del->execute();

                            /* $data e' la riga di auth_pin: la sessione va creata
                               per l'utente proprietario del PIN, non per l'id del PIN. */
                            $tokens = $this->insertSid($user_id);

                            return [
                                'success' => 1,
                                'status' => 200,
                                'request' => $this->parameters,
                                'sid' => $tokens["sid"],
                                'ltsid' => $tokens["ltsid"]
                            ];
                        } else {
                            return [
                                'success' => 0,
                                'status' => 200,
                                'request' => $this->parameters,
                                'message' => "User not found.",
                                'error_code' => 'reset.000'
                            ];
                        }
                    } catch (PDOException $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'status' => 400,
                            'error_code' => 'reset.001'
                        ];
                    }
                } else {
                    return [
                        'success' => 0,
                        'message' => "Passwords do not match.",
                        'error_code' => 'reset.002',
                        'status' => 202
                    ];
                }
            } else {
                return [
                    'success' => 0,
                    'message' => "Missing fields.",
                    'error_code' => 'reset.003',
                    'status' => 201
                ];
            }
        }

        public function fetchCheckLogin() {
            if (isset($this->parameters->ltsid) && $this->parameters->ltsid != '') {
                $ltsid = $this->parameters->ltsid;

                try {
                    $fetch = "SELECT * FROM auth_token WHERE ltsid = :ltsid ";
                    $query_stmt = $this->db->prepare($fetch);
                    $query_stmt->bindValue(":ltsid", $ltsid, PDO::PARAM_STR);
                    $query_stmt->execute();

                    $results = $query_stmt->rowCount();
                    if ($results === 1) {
                        $data = $query_stmt->fetch(PDO::FETCH_ASSOC);

                        $fetch_user = "SELECT * FROM user WHERE id = :id AND deleted = 0 ";
                        $query_user = $this->db->prepare($fetch_user);
                        $query_user->bindValue(":id", $data["idUser"], PDO::PARAM_INT);
                        $query_user->execute();
                        $body = $query_user->fetch(PDO::FETCH_ASSOC);

                        if (!$body) {
                            $delete = "DELETE FROM auth_token WHERE idUser = :id";
                            $query_delete = $this->db->prepare($delete);
                            $query_delete->bindValue(":id", $data["idUser"], PDO::PARAM_INT);
                            $query_delete->execute();

                            return [
                                'success' => 0,
                                'status' => 403,
                                'request' => $this->parameters,
                                'body' => [],
                                'message' => "User not found.",
                                'error_code' => 'checkLogin.000'
                            ];
                        }

                        // Verifica se l'utente è disabilitato
                        if (isset($body['disabled']) && $body['disabled'] == 1) {
                            // Elimina i token di sessione dell'utente disabilitato
                            $delete = "DELETE FROM auth_token WHERE idUser = :id";
                            $query_delete = $this->db->prepare($delete);
                            $query_delete->bindValue(":id", $data["idUser"], PDO::PARAM_INT);
                            $query_delete->execute();

                            return [
                                'success' => 0,
                                'status' => 403,
                                'request' => $this->parameters,
                                'body' => [],
                                'message' => "Account disabilitato. Contattare l'amministratore.",
                                'error_code' => 'checkLogin.disabled'
                            ];
                        }

                        return [
                            'success' => 1,
                            'status' => 200,
                            'request' => $this->parameters,
                            'body' => $body,
                            'auth_map' => $this->generateAuthTable($body["level"]),
                            'props' => $this->generatePropsTable($body["level"]),
                            'sid' => $data["sid"],
                            'ltsid' => $data["ltsid"]
                        ];
                    } else {
                        return [
                            'success' => 0,
                            'status' => 403,
                            'request' => $this->parameters,
                            'body' => [],
                            'message' => "User not found.",
                            'error_code' => 'checkLogin.000'
                        ];
                    }
                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'checkLogin.000'
                    ];
                }
            } else {
                return [
                    'success' => 0,
                    'message' => "Missing fields.",
                    'error_code' => 'checkLogin.000',
                    'status' => 500
                ];
            }
        }

        public function fetchLogout() {
            if (isset($this->parameters->ltsid) && $this->parameters->ltsid != '') {
                $ltsid = $this->parameters->ltsid;

                try {
                    $fetch = "DELETE FROM auth_token WHERE ltsid = :ltsid ";
                    $query_stmt = $this->db->prepare($fetch);
                    $query_stmt->bindValue(":ltsid", $ltsid, PDO::PARAM_STR);
                    $query_stmt->execute();

                    return [
                        'success' => 1,
                        'status' => 200,
                        'body' => [],
                        'request' => $this->parameters
                    ];
                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'logout.000'
                    ];
                }
            } else {
                return [
                    'success' => 0,
                    'message' => "Missing fields.",
                    'error_code' => 'logout.000',
                    'status' => 500
                ];
            }
        }

    }
?>
