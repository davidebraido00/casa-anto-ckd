<?php
    class LoginSocial {
        protected $db;
        protected $parameters;
    
        function __construct($db, $parameters) {
            $this->db = $db;
            $this->parameters = $parameters;
        }


        public function fetchGoogleLogin() {
            if(isset($this->parameters->token)) {
                try {

                    $basepath = dirname(dirname(__FILE__));
                    $settings = json_decode(file_get_contents($basepath . "/../config/settings.json"), true);
                    if(isset($settings['login_social']['google_client_id'])) {
                        $google_client_id = $settings['login_social']['google_client_id'];
                    }
                    else {
                        throw new Exception("Google Login is not active.");
                    }

                    $token = $this->parameters->token;

                    /* Verifica del token presso Google. Non basta che il token sia
                       valido: deve essere stato emesso PER QUESTA applicazione
                       (aud/azp = google_client_id), altrimenti un access token che la
                       vittima ha rilasciato a un'altra app permetterebbe di entrare
                       come lei. tokeninfo accetta sia access token che ID token. */
                    $isJwt = substr_count($token, '.') === 2;
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/tokeninfo?'
                        . ($isJwt ? 'id_token=' : 'access_token=') . urlencode($token));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $info = json_decode($response, true);

                    if ($httpCode !== 200 || !is_array($info)) {
                        throw new Exception("Token Google non valido.");
                    }
                    $audience = isset($info['aud']) ? $info['aud'] : (isset($info['azp']) ? $info['azp'] : '');
                    $authorizedParty = isset($info['azp']) ? $info['azp'] : '';
                    if ($audience !== $google_client_id && $authorizedParty !== $google_client_id) {
                        throw new Exception("Token Google emesso per un'altra applicazione.");
                    }
                    if (!isset($info['email']) || (isset($info['email_verified']) && $info['email_verified'] !== 'true' && $info['email_verified'] !== true)) {
                        throw new Exception("Email Google non verificata.");
                    }

                    $profile = $info;

                    // Make sure the profile data exists
                    if (isset($profile['email'])) {
                        $google_email = $profile['email'];

                        $fetch = "SELECT * FROM user WHERE (email = :email) AND deleted = 0 ";
                        $query_stmt = $this->db->prepare($fetch);
                        $query_stmt->bindValue(":email", $google_email, PDO::PARAM_STR);
                        $query_stmt->execute();
    
                        $results = $query_stmt->rowCount();
                        if($results === 1) {
                            $body = $query_stmt->fetch(PDO::FETCH_ASSOC);
    
                            $userClass = new User($this->db, $this->parameters);
                            $tokens = $userClass->insertSid($body['id']); //user id
    
                            return [
                                'success' => 1,
                                'status' => 200,
                                'request' => $this->parameters,
                                'body' => $body,
                                'sid' => $tokens['sid'],
                                'ltsid' => $tokens['ltsid'],
                                'new_registration' => false
                            ];
                        }
                        else {
                            throw new Exception("Utente non trovato.");
                        }
                    }
                    else {
                        throw new Exception("Could not retrieve profile information! Please try again later!");
                    }
                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'loginGoogle.001'
                    ];
                } catch (Exception $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'loginGoogle.002'
                    ];
                }
            }
            else {
                
                return [
                    'success' => 0,
                    'message' => "Missing fields. (name, surname or token)",
                    'error_code' => 'login.002',
                    'status' => 500
                ];
                
            }
        } 


        public function fetchFacebookLogin() {
            if(isset($this->parameters->token)) {

                try {
                    $accessToken = $this->parameters->token;

                    $basepath = dirname(dirname(__FILE__));
                    $settings = json_decode(file_get_contents($basepath . "/../config/settings.json"), true);
                    if(isset($settings['login_social']['fb_app_id'])) {
                        $fb_app_id = $settings['login_social']['fb_app_id'];
                    }
                    else {
                        throw new Exception("Invalid app id.");
                    }
                    if(isset($settings['login_social']['fb_secret'])) {
                        $fb_secret = $settings['login_social']['fb_secret'];
                    }
                    else {
                        throw new Exception("Invalid fb secret.");
                    }

                    $validationUrl = "https://graph.facebook.com/debug_token?input_token={$accessToken}&access_token={$fb_app_id}|{$fb_secret}";

                    $validationResponse = file_get_contents($validationUrl);
                    $validationResult = json_decode($validationResponse, true);
                    if ($validationResult['data']['is_valid']) {
                        // Access token is valid; get user info
                        $appSecretProof = hash_hmac('sha256', $accessToken, $fb_secret);
                        $graphUrl = "https://graph.facebook.com/me?fields=id,name,email&access_token={$accessToken}&appsecret_proof={$appSecretProof}";
                        $userResponse = file_get_contents($graphUrl);
                        $user = json_decode($userResponse, true);
                    
                        if (isset($user['email'])) {
                            // Authenticate the user
                            $fb_email = $user['email'];
    
                            $fetch = "SELECT * FROM user WHERE (email = :email) AND deleted = 0 ";
                            $query_stmt = $this->db->prepare($fetch);
                            $query_stmt->bindValue(":email", $fb_email, PDO::PARAM_STR);
                            $query_stmt->execute();
    
                            $results = $query_stmt->rowCount();
                            if($results === 1) {
                                $body = $query_stmt->fetch(PDO::FETCH_ASSOC);
    
                                $userClass = new User($this->db, $this->parameters);
                                $tokens = $userClass->insertSid($body['id']); //user id
    
                                return [
                                    'success' => 1,
                                    'status' => 200,
                                    'request' => $this->parameters,
                                    'body' => $body,
                                    'sid' => $tokens['sid'],
                                    'ltsid' => $tokens['ltsid'],
                                    'new_registration' => false
                                ];
                            }
                            else {
                                throw new Exception("Utente non trovato.");
                            }   
                        }
                        else {
                            throw new Exception("Could not retrieve profile information! Please try again later!");
                        }
                        
                    }
                    else {
                        // Invalid access token
                        throw new Exception("Invalid access token.");
                    }
                    
                } catch (PDOException $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'loginFacebook.001'
                    ];
                } catch (Exception $e) {
                    return [
                        'success' => 0,
                        'message' => $e->getMessage(),
                        'status' => 400,
                        'error_code' => 'loginFacebook.002'
                    ];
                }
            }
            else {
                
                return [
                    'success' => 0,
                    'message' => "Missing fields. (username, password)",
                    'error_code' => 'login.002',
                    'status' => 500
                ];
                
            }
        } 

    }
?>