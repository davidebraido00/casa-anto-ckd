<?php

    require_once __DIR__ . '/StorageManager.php';

    class Resource {
        protected $db;
        protected $user_info;
        protected $path;
        protected $parameters;
    
        function __construct($db, $user_info, $parameters) {
            $this->db = $db;
            $this->user_info = json_decode($user_info, true);
            $this->parameters = $parameters;
        }

        
        protected function isQueryAuthorized() {
            $tables = json_decode(file_get_contents("../config/tables.json"), true);
            if(isset($this->parameters->entity) && $this->parameters->entity != null) {
                $entity = $this->parameters->entity;
            }
            else {
                $entity = 'resource';
            }
            if (array_key_exists($entity, $tables)) {
                $table = $tables[$entity];
                $l = $this->user_info['level'];
                if(isset($table['permissions'][$l]['view']) && $table['permissions'][$l]['view'] !== false) {
                    return 1;
                }
                else {
                    return 0;
                }
            }
            else {
                return 0;
            }
        }

        public function fetchUpload() {
            if($this->isQueryAuthorized() === 1) {
                
                if(isset($this->parameters->entity) && $this->parameters->entity != null) {
                    $entity = $this->parameters->entity;
                }
                else {
                    $entity = 'resource';
                }
                if(isset($this->parameters->filename) && $this->parameters->filename != null) {
                    $filename = $this->parameters->filename;
                }
                if(isset($this->parameters->filedata) && $this->parameters->filedata != null) {
                    $base64 = $this->parameters->filedata;
                    try {
                        //upload file
                        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                        $allowedExtString = "pdf, jpg, jpeg, png, doc, docx"; 
                        $allowedExt = array("pdf", "png", "jpg", "jpeg", "doc", "docx");

                        $settings = json_decode(file_get_contents("../config/settings.json"), true);
                        if(isset($settings['file']['allowed_types'])) {
                            $allowedExtString = $settings['file']['allowed_types'];
                            $allowedExtString = str_replace(' ', '', $allowedExtString);
                            $allowedExt = explode(',', $allowedExtString);
                        }

                        if (!in_array($ext, $allowedExt)) {
                            throw new Exception("Estensione non valida. Estensioni consentite: {$allowedExtString}.");
                        }

                        $maxSizeAllowed = 5242880;
                        if(isset($settings['file']['max_size'])) {
                            $maxSizeAllowed = $settings['file']['max_size'];
                        }
                        $size = (int)(strlen(rtrim($base64, '=')) * 0.75); // in byte
                        if ($size > $maxSizeAllowed) {
                            throw new Exception("Il file è troppo pesante. Limite consentito: 5 Mb.");
                        }

                        $hash = md5(microtime() . '-' . $this->user_info['id'] . '-' . $filename);
                        $name = $hash . "." . $ext;

                        // filedata e' solo base64 (semplice o data URL): mai passato a file_get_contents,
                        // che aprirebbe percorsi locali e URL (lettura di config/settings.json, SSRF).
                        // Il frontend manda data URL, l'app TIE e le classi custom base64 semplice.
                        if (strncmp($base64, 'data:', 5) === 0) {
                            $comma = strpos($base64, ',');
                            if ($comma === false || strpos(substr($base64, 0, $comma), ';base64') === false) {
                                throw new Exception("Base64 errato.");
                            }
                            $base64 = substr($base64, $comma + 1);
                        }
                        $content = base64_decode($base64, true);   // true = modalita' stretta
                        if ($content === false || $content === '') {
                            throw new Exception("Base64 errato.");
                        }


                            /**
                             * if image size to big then resize
                            **/
                            $imageExt = array("JPG", "JPEG", "jpg", "jpeg");
                            if (in_array($ext, $imageExt)) {
                                $im = @imagecreatefromstring($content);
                                if ($im === false) {
                                    throw new Exception("Immagine non valida.");
                                }
                                $width = imagesx($im);
                                $height = imagesy($im);
                                $size = (int)(strlen(rtrim($base64, '=')) * 0.75); // in byte
                                if($width > 2000 && $size > 30000) { //maggiore di 30.000 byte
                                    $newwidth = 2000; //imposto larghezza a 1200px e calcolo altezza in %
                                    $percent = floor(100 * 2000 / $width);
                                    $newheight = floor($height * $percent / 100);
                                    $thumb = imagecreatetruecolor($newwidth, $newheight);
                                    // Resize
                                    imagecopyresized($thumb, $im, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
                                }
                                else {
                                    $thumb = $im;
                                }
                                ob_start();
                                imagejpeg($thumb, null, 60);
                                $stream = ob_get_clean();
                                $content = $stream;
                            }
                            /**
                             * END RESIZE
                            **/


                        StorageManager::put($entity, $name, $content);

                        if(strlen($filename) > 60) {
                            //massima lunghezza nome file 60 chars
                            $filename = md5($filename) . "." . $ext;
                        }

                        $insert = "INSERT INTO resource (idUser, filename, hash, extension, entity) VALUES (:user_id, :filename, :hash, :extension, :entity) ";
                        $query_stmt = $this->db->prepare($insert);
                        $query_stmt->bindValue(":user_id", $this->user_info['id'], PDO::PARAM_INT);
                        $query_stmt->bindValue(":filename", $filename, PDO::PARAM_STR);
                        $query_stmt->bindValue(":hash", $hash, PDO::PARAM_STR);
                        $query_stmt->bindValue(":extension", $ext, PDO::PARAM_STR);
                        $query_stmt->bindValue(":entity", $entity, PDO::PARAM_STR);
                        $query_stmt->execute();

                        $body = [
                            'id' => $this->db->lastInsertId(),
                            'hash' => $hash,
                            'extension' => $ext,
                            'filename' => $filename
                        ];

                        return [
                            'success' => 1,
                            'status' => 200,
                            'body' => $body
                        ];
    
                    } catch (PDOException $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'status' => 400,
                            'error_code' => 'db.error'
                        ];
                    } catch (Exception $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'status' => 400
                        ];
                    }
                }
                else {
                    return [
                        'success' => 0,
                        'message' => "Missing file.",
                        'status' => 400
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

        public function fetchGet() {
            if($this->isQueryAuthorized() === 1) {
                if(isset($this->parameters->hash) && $this->parameters->hash != null) {
                    $hash = $this->parameters->hash;

                    $settings = json_decode(file_get_contents("../config/settings.json"), true);
                    $domain = $settings["host"]["domain"];
    
                    try {
                        $fetch = "SELECT * FROM resource WHERE hash = :hash LIMIT 1";
                        $query_stmt = $this->db->prepare($fetch);
                        $query_stmt->bindValue(":hash", $hash, PDO::PARAM_STR);
                        $query_stmt->execute();
                        $body = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
                        if(count($body) > 0) {
                            $resourceName = $body[0]['hash'] . '.' . $body[0]['extension'];
                            $link = StorageManager::url($body[0]['entity'], $resourceName);

                            $resourceData = StorageManager::get($body[0]['entity'], $resourceName);
                            if ($resourceData === FALSE) {
                                throw new Exception("File non trovato.");
                            }
                            else {
                                $base64Resource = base64_encode($resourceData);
                            }

                            return [
                                'success' => 1,
                                'status' => 200,
                                'request' => $this->parameters,
                                'body' => $body,
                                'domain' => $domain,
                                'link' => $link,
                                'base64' => $base64Resource
                            ];
                        }
                        else {
                            return [
                                'success' => 0,
                                'message' => 'Hash not found.',
                                'status' => 400,
                                'error_code' => 'resource.error'
                            ];
                        }
                        
                        

                    } catch (PDOException $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'status' => 400,
                            'error_code' => 'db.error'
                        ];
                    }  catch (Exception $e) {
                        return [
                            'success' => 0,
                            'message' => $e->getMessage(),
                            'status' => 400,
                            'error_code' => 'error'
                        ];
                    }

                }
                else {
                    return [
                        'success' => 0,
                        'message' => "Not found.",
                        'error_code' => "resource.404",
                        'status' => 404
                    ];
                }
                
            }
            else {
                return [
                    'success' => 0,
                    'message' => "Forbidden.",
                    'error_code' => "resource.403",
                    'status' => 403
                ];
            }
        }
    
    }
?>