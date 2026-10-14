<?php

    class UsersFunctions {
        protected $db;
        protected $parameters;

        function __construct($db, $parameters) {
            $this->db = $db;
            $this->parameters = $parameters;
        }

        /**
         * Restituisce il permesso di edit su user per il livello dato.
         * Ritorna: true (accesso completo), "group" (stesso gruppo), false (negato)
         */
        private function getEditPermission($userLevel) {
            if ($userLevel === null || $userLevel === false) return false;

            $tables = json_decode(file_get_contents("../config/tables.json"), true);
            if (isset($tables['user']['permissions'][$userLevel]['edit'])) {
                return $tables['user']['permissions'][$userLevel]['edit'];
            }
            return false;
        }

        /**
         * Restituisce il permesso di delete su user per il livello dato.
         * Ritorna: true (accesso completo), "group" (stesso gruppo), false (negato)
         */
        private function getDeletePermission($userLevel) {
            if ($userLevel === null || $userLevel === false) return false;

            $tables = json_decode(file_get_contents("../config/tables.json"), true);
            if (isset($tables['user']['permissions'][$userLevel]['delete'])) {
                return $tables['user']['permissions'][$userLevel]['delete'];
            }
            return false;
        }

        /**
         * Verifica se l'utente target appartiene allo stesso gruppo dell'utente autenticato
         */
        private function isSameGroup($targetUserId, $authUser) {
            $fetch = "SELECT idUserGroup FROM user WHERE id = :id";
            $stmt = $this->db->prepare($fetch);
            $stmt->bindValue(":id", $targetUserId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 1) {
                $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
                return $targetUser['idUserGroup'] == ($authUser['idUserGroup'] ?? null);
            }
            return false;
        }

        /**
         * Controlla se il permesso è negato (false, 0, "false")
         */
        private function isPermissionDenied($permission) {
            return $permission === false || $permission === 0 || $permission === "false";
        }

        /**
         * Restituisce i valori di level consentiti da tables.json (opzioni visibili)
         */
        private function getAllowedLevels() {
            $tables = json_decode(file_get_contents("../config/tables.json"), true);
            $levelOptions = $tables['user']['columns']['level']['options']['options'] ?? [];
            $allowed = [];
            foreach ($levelOptions as $option) {
                if (isset($option['value'])) {
                    $allowed[] = (int)$option['value'];
                }
            }
            return $allowed;
        }

        /**
         * Disabilita un utente impostando disabled=1 ed eliminando i token di sessione
         */
        public function disableUser($auth_user) {
            // Verifica permessi
            $userLevel = $auth_user['level'] ?? null;
            $permission = $this->getEditPermission($userLevel);
            if ($this->isPermissionDenied($permission)) {
                return [
                    'success' => 0,
                    'message' => 'Non hai i permessi per eseguire questa azione.',
                    'error_code' => 'users.permission_denied',
                    'status' => 403
                ];
            }

            if (!isset($this->parameters->id) || $this->parameters->id == '') {
                return [
                    'success' => 0,
                    'message' => 'ID utente mancante.',
                    'error_code' => 'users.missing_id',
                    'status' => 400
                ];
            }

            $userId = $this->parameters->id;
            $currentUserId = $auth_user['id'] ?? null;

            // Non permettere di disabilitare se stessi
            if ($userId == $currentUserId) {
                return [
                    'success' => 0,
                    'message' => 'Non puoi disabilitare te stesso.',
                    'error_code' => 'users.cannot_disable_self',
                    'status' => 400
                ];
            }

            // Verifica appartenenza al gruppo se permesso è "group"
            if ($permission === "group" && !$this->isSameGroup($userId, $auth_user)) {
                return [
                    'success' => 0,
                    'message' => 'Non hai i permessi per modificare utenti di un altro gruppo.',
                    'error_code' => 'users.group_denied',
                    'status' => 403
                ];
            }

            try {
                // Verifica se l'utente esiste e non è già disabilitato
                $fetch = "SELECT * FROM user WHERE id = :id AND deleted = 0";
                $query_stmt = $this->db->prepare($fetch);
                $query_stmt->bindValue(":id", $userId, PDO::PARAM_INT);
                $query_stmt->execute();

                if ($query_stmt->rowCount() === 1) {
                    $user = $query_stmt->fetch(PDO::FETCH_ASSOC);

                    // Controlla se già disabilitato
                    if (isset($user['disabled']) && $user['disabled'] == 1) {
                        return [
                            'success' => 0,
                            'message' => "L'utente è già disabilitato.",
                            'error_code' => 'users.already_disabled',
                            'status' => 200
                        ];
                    }

                    // Disabilita l'utente
                    $update = "UPDATE user SET disabled = 1 WHERE id = :id";
                    $query_update = $this->db->prepare($update);
                    $query_update->bindValue(":id", $userId, PDO::PARAM_INT);
                    $query_update->execute();

                    // Elimina tutti i token di sessione dell'utente
                    $delete = "DELETE FROM auth_token WHERE idUser = :id";
                    $query_delete = $this->db->prepare($delete);
                    $query_delete->bindValue(":id", $userId, PDO::PARAM_INT);
                    $query_delete->execute();

                    return [
                        'success' => 1,
                        'status' => 200,
                        'message' => 'Utente disabilitato con successo.'
                    ];
                } else {
                    return [
                        'success' => 0,
                        'message' => 'Utente non trovato.',
                        'error_code' => 'users.not_found',
                        'status' => 404
                    ];
                }

            } catch (PDOException $e) {
                return [
                    'success' => 0,
                    'message' => $e->getMessage(),
                    'status' => 400,
                    'error_code' => 'users.db_error'
                ];
            }
        }

        /**
         * Riabilita un utente impostando disabled=0
         */
        public function enableUser($auth_user) {
            // Verifica permessi
            $userLevel = $auth_user['level'] ?? null;
            $permission = $this->getEditPermission($userLevel);
            if ($this->isPermissionDenied($permission)) {
                return [
                    'success' => 0,
                    'message' => 'Non hai i permessi per eseguire questa azione.',
                    'error_code' => 'users.permission_denied',
                    'status' => 403
                ];
            }

            if (!isset($this->parameters->id) || $this->parameters->id == '') {
                return [
                    'success' => 0,
                    'message' => 'ID utente mancante.',
                    'error_code' => 'users.missing_id',
                    'status' => 400
                ];
            }

            $userId = $this->parameters->id;

            // Verifica appartenenza al gruppo se permesso è "group"
            if ($permission === "group" && !$this->isSameGroup($userId, $auth_user)) {
                return [
                    'success' => 0,
                    'message' => 'Non hai i permessi per modificare utenti di un altro gruppo.',
                    'error_code' => 'users.group_denied',
                    'status' => 403
                ];
            }

            try {
                // Verifica se l'utente esiste
                $fetch = "SELECT * FROM user WHERE id = :id AND deleted = 0";
                $query_stmt = $this->db->prepare($fetch);
                $query_stmt->bindValue(":id", $userId, PDO::PARAM_INT);
                $query_stmt->execute();

                if ($query_stmt->rowCount() === 1) {
                    $user = $query_stmt->fetch(PDO::FETCH_ASSOC);

                    // Controlla se già abilitato
                    if (!isset($user['disabled']) || $user['disabled'] == 0) {
                        return [
                            'success' => 0,
                            'message' => "L'utente è già abilitato.",
                            'error_code' => 'users.already_enabled',
                            'status' => 200
                        ];
                    }

                    // Riabilita l'utente
                    $update = "UPDATE user SET disabled = 0 WHERE id = :id";
                    $query_update = $this->db->prepare($update);
                    $query_update->bindValue(":id", $userId, PDO::PARAM_INT);
                    $query_update->execute();

                    return [
                        'success' => 1,
                        'status' => 200,
                        'message' => 'Utente riabilitato con successo.'
                    ];
                } else {
                    return [
                        'success' => 0,
                        'message' => 'Utente non trovato.',
                        'error_code' => 'users.not_found',
                        'status' => 404
                    ];
                }

            } catch (PDOException $e) {
                return [
                    'success' => 0,
                    'message' => $e->getMessage(),
                    'status' => 400,
                    'error_code' => 'users.db_error'
                ];
            }
        }

        /**
         * Modifica un utente senza richiedere la password
         * I dati vengono passati direttamente nei parameters (non in parameters.data)
         */
        public function editUser($auth_user) {
            // Verifica permessi
            $userLevel = $auth_user['level'] ?? null;
            $permission = $this->getEditPermission($userLevel);
            if ($this->isPermissionDenied($permission)) {
                return [
                    'success' => 0,
                    'message' => 'Non hai i permessi per eseguire questa azione.',
                    'error_code' => 'users.permission_denied',
                    'status' => 403
                ];
            }

            if (!isset($this->parameters->id) || $this->parameters->id == '') {
                return [
                    'success' => 0,
                    'message' => 'ID utente mancante.',
                    'error_code' => 'users.missing_id',
                    'status' => 400
                ];
            }

            $userId = $this->parameters->id;

            // Verifica appartenenza al gruppo se permesso è "group"
            if ($permission === "group" && !$this->isSameGroup($userId, $auth_user)) {
                return [
                    'success' => 0,
                    'message' => 'Non hai i permessi per modificare utenti di un altro gruppo.',
                    'error_code' => 'users.group_denied',
                    'status' => 403
                ];
            }

            try {
                // Verifica se l'utente esiste
                $fetch = "SELECT * FROM user WHERE id = :id AND deleted = 0";
                $query_stmt = $this->db->prepare($fetch);
                $query_stmt->bindValue(":id", $userId, PDO::PARAM_INT);
                $query_stmt->execute();

                if ($query_stmt->rowCount() !== 1) {
                    return [
                        'success' => 0,
                        'message' => 'Utente non trovato.',
                        'error_code' => 'users.not_found',
                        'status' => 404
                    ];
                }

                // Campi consentiti per l'aggiornamento: quelli storici piu' ogni colonna
                // dell'entita' user dichiarata editabile in tables.json. Cosi' un progetto che
                // aggiunge colonne all'utente (es. costo orario, azienda predefinita) le puo'
                // modificare da /users senza toccare il framework.
                $allowedFields = ['name', 'surname', 'username', 'email', 'level', 'idRole', 'avatar', 'phone', 'notes'];
                $linkFields = []; // multipleExternalEntity: tabella di collegamento, gestita dopo l'UPDATE
                $deepFields = []; // deepExternalEntity: righe figlie (es. turni), gestite dopo l'UPDATE
                $tables = json_decode(file_get_contents("../config/tables.json"), true);
                $userColumns = $tables['user']['columns'] ?? [];
                $systemFields = ['id', 'date', 'lastUpdate', 'deleted', 'password', 'idUserGroup', 'author'];
                foreach ($userColumns as $column => $definition) {
                    if (in_array($column, $systemFields, true)) continue;
                    if (!($definition['options']['editable'] ?? false)) continue;
                    $inputType = $definition['input_type'] ?? '';
                    if ($inputType === 'deepExternalEntity') {
                        $deepFields[$column] = $definition; // righe figlie, sincronizzate dopo l'UPDATE
                        continue;
                    }
                    if ($inputType === 'multipleExternalEntity') {
                        $linkFields[$column] = $definition['options'];
                        continue;
                    }
                    if (!in_array($column, $allowedFields, true)) $allowedFields[] = $column;
                }

                // Permesso completo (true): consenti anche idUserGroup per assegnare utenti a gruppi diversi
                $isFullAccess = $permission === true || $permission === 1;
                if ($isFullAccess) {
                    $allowedFields[] = 'idUserGroup';
                }

                $updateParts = [];
                $bindValues = [];

                // Leggi i campi direttamente da parameters
                foreach ($this->parameters as $field => $value) {
                    if ($field === 'id') continue; // Salta l'id

                    // Gestisci campi con formato { value: X } o { id: X }.
                    // Le colonne file arrivano come riga resource idratata ({ id, hash, ... }):
                    // in tabella va l'hash, non l'id numerico della resource.
                    $actualValue = $value;
                    if (is_object($value)) {
                        $inputType = $userColumns[$field]['input_type'] ?? '';
                        if ($inputType === 'file' && isset($value->hash)) {
                            $actualValue = $value->hash;
                        } else if (isset($value->value)) {
                            $actualValue = $value->value;
                        } else if (isset($value->id)) {
                            $actualValue = $value->id;
                        }
                    }

                    if (in_array($field, $allowedFields)) {
                        $updateParts[] = "$field = :$field";
                        $bindValues[$field] = $actualValue;
                    }
                }

                // Valida il livello utente: deve essere tra le opzioni definite in tables.json
                if (isset($bindValues['level'])) {
                    $allowedLevels = $this->getAllowedLevels();
                    if (!in_array((int)$bindValues['level'], $allowedLevels, true)) {
                        return [
                            'success' => 0,
                            'message' => 'Livello utente non valido.',
                            'error_code' => 'users.invalid_level',
                            'status' => 400
                        ];
                    }
                }

                // Gestisci password separatamente (solo se fornita e non vuota)
                if (isset($this->parameters->password) && $this->parameters->password !== '' && $this->parameters->password !== null) {
                    $updateParts[] = "password = :password";
                    $bindValues['password'] = $this->parameters->password;
                }

                // multipleExternalEntity: l'elenco di id (o oggetti con id) sostituisce le righe
                // della tabella di collegamento, con soft delete come fa Post.php
                $linkUpdates = [];
                foreach ($linkFields as $column => $options) {
                    if (!isset($this->parameters->$column) || !is_array($this->parameters->$column)) continue;
                    $ids = [];
                    foreach ($this->parameters->$column as $item) {
                        $itemId = is_object($item) ? ($item->id ?? null) : $item;
                        if ($itemId !== null && $itemId !== '') $ids[] = (int) $itemId;
                    }
                    $linkUpdates[$column] = ['options' => $options, 'ids' => array_values(array_unique($ids))];
                }

                // deepExternalEntity: l'elenco delle righe figlie sostituisce quelle esistenti,
                // con la stessa logica di Post::edit (update per id, delete delle mancanti, insert delle nuove)
                $deepUpdates = [];
                foreach ($deepFields as $column => $definition) {
                    if (!isset($this->parameters->$column) || !is_array($this->parameters->$column)) continue;
                    $deepUpdates[$column] = $this->parameters->$column;
                }

                if (empty($updateParts) && empty($linkUpdates) && empty($deepUpdates)) {
                    return [
                        'success' => 0,
                        'message' => 'Nessun campo valido da aggiornare.',
                        'error_code' => 'users.no_valid_fields',
                        'status' => 400
                    ];
                }

                if (!empty($updateParts)) {
                    $updateQuery = "UPDATE user SET " . implode(", ", $updateParts) . " WHERE id = :id";
                    $query_update = $this->db->prepare($updateQuery);

                    foreach ($bindValues as $field => $value) {
                        $query_update->bindValue(":$field", $value);
                    }
                    $query_update->bindValue(":id", $userId, PDO::PARAM_INT);
                    $query_update->execute();
                }

                foreach ($linkUpdates as $column => $link) {
                    $linkTable = $link['options']['link_entity'] ?? null;
                    $sourceField = $link['options']['source_field'] ?? null;
                    $destinationField = $link['options']['destination_field'] ?? null;
                    if (!$linkTable || !$sourceField || !$destinationField) continue;
                    if (!preg_match('/^[A-Za-z0-9_]+$/', $linkTable . $sourceField . $destinationField)) continue;

                    $existing = [];
                    $stmt = $this->db->prepare("SELECT id, $destinationField AS dest, deleted FROM $linkTable WHERE $sourceField = :src");
                    $stmt->bindValue(":src", $userId, PDO::PARAM_INT);
                    $stmt->execute();
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $existing[(int) $row['dest']] = $row;

                    foreach ($link['ids'] as $destId) {
                        if (isset($existing[$destId])) {
                            if ((int) $existing[$destId]['deleted'] === 1) {
                                $this->db->prepare("UPDATE $linkTable SET deleted = 0 WHERE id = :id")->execute([':id' => (int) $existing[$destId]['id']]);
                            }
                        } else {
                            $ins = $this->db->prepare("INSERT INTO $linkTable ($sourceField, $destinationField) VALUES (:src, :dest)");
                            $ins->bindValue(":src", $userId, PDO::PARAM_INT);
                            $ins->bindValue(":dest", $destId, PDO::PARAM_INT);
                            $ins->execute();
                        }
                    }
                    foreach ($existing as $destId => $row) {
                        if (!in_array($destId, $link['ids'], true) && (int) $row['deleted'] === 0) {
                            $this->db->prepare("UPDATE $linkTable SET deleted = 1 WHERE id = :id")->execute([':id' => (int) $row['id']]);
                        }
                    }
                }

                if (!empty($deepUpdates)) {
                    if (function_exists('ckd_require_class')) ckd_require_class('Post');
                    else if (!class_exists('Post')) require_once __DIR__ . '/Post.php';
                    $post = new Post($this->db, json_encode($auth_user), 'user', null);
                    foreach ($deepUpdates as $column => $items) {
                        $post->syncDeepExternalEntity($tables['user'], $column, $items, (int) $userId);
                    }
                }

                return [
                    'success' => 1,
                    'status' => 200,
                    'message' => 'Utente aggiornato con successo.'
                ];

            } catch (PDOException $e) {
                return [
                    'success' => 0,
                    'message' => $e->getMessage(),
                    'status' => 400,
                    'error_code' => 'users.db_error'
                ];
            }
        }

        /**
         * Ottiene la lista degli utenti eliminati (deleted = 1)
         * Richiede permesso delete su user
         */
        public function getDeletedUsers($auth_user) {
            // Verifica permessi (richiede delete)
            $userLevel = $auth_user['level'] ?? null;
            $permission = $this->getDeletePermission($userLevel);
            if ($this->isPermissionDenied($permission)) {
                return [
                    'success' => 0,
                    'message' => 'Non hai i permessi per visualizzare gli utenti eliminati.',
                    'error_code' => 'users.permission_denied',
                    'status' => 403
                ];
            }

            try {
                $page = $this->parameters->page ?? 1;
                $limit = $this->parameters->limit ?? 10;
                $offset = ($page - 1) * $limit;

                // Filtro gruppo per permesso "group"
                $groupFilter = "";
                $groupId = null;
                if ($permission === "group") {
                    $groupFilter = " AND idUserGroup = :groupId";
                    $groupId = $auth_user['idUserGroup'] ?? null;
                }

                // Count totale
                $countQuery = "SELECT COUNT(*) as total FROM user WHERE deleted = 1" . $groupFilter;
                $countStmt = $this->db->prepare($countQuery);
                if ($groupId !== null) {
                    $countStmt->bindValue(":groupId", $groupId, PDO::PARAM_INT);
                }
                $countStmt->execute();
                $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

                // Query utenti eliminati
                $fetch = "SELECT * FROM user WHERE deleted = 1" . $groupFilter . " ORDER BY id DESC LIMIT :limit OFFSET :offset";
                $query_stmt = $this->db->prepare($fetch);
                if ($groupId !== null) {
                    $query_stmt->bindValue(":groupId", $groupId, PDO::PARAM_INT);
                }
                $query_stmt->bindValue(":limit", (int)$limit, PDO::PARAM_INT);
                $query_stmt->bindValue(":offset", (int)$offset, PDO::PARAM_INT);
                $query_stmt->execute();

                $users = $query_stmt->fetchAll(PDO::FETCH_ASSOC);

                // Rimuovi password dai risultati
                foreach ($users as &$user) {
                    unset($user['password']);
                }
                unset($user);

                // Idrata le righe come get_items (option -> {value,label}, externalEntity -> oggetto,
                // multiple/deep -> elenchi), così la tabella le mostra con FieldPreview come le altre tab
                if (function_exists('ckd_require_class')) ckd_require_class('Get');
                else if (!class_exists('Get')) require_once __DIR__ . '/Get.php';
                $tables = json_decode(file_get_contents("../config/tables.json"), true);
                $get = new Get($this->db, json_encode($auth_user), 'user', null);
                $users = $get->parseData($users, $tables['user']);

                return [
                    'success' => 1,
                    'status' => 200,
                    'body' => $users,
                    'total' => (int)$total
                ];

            } catch (PDOException $e) {
                return [
                    'success' => 0,
                    'message' => $e->getMessage(),
                    'status' => 400,
                    'error_code' => 'users.db_error'
                ];
            }
        }

        /**
         * Ripristina un utente eliminato (deleted = 0)
         * Richiede permesso delete su user
         */
        public function restoreUser($auth_user) {
            // Verifica permessi (richiede delete)
            $userLevel = $auth_user['level'] ?? null;
            $permission = $this->getDeletePermission($userLevel);
            if ($this->isPermissionDenied($permission)) {
                return [
                    'success' => 0,
                    'message' => 'Non hai i permessi per ripristinare utenti.',
                    'error_code' => 'users.permission_denied',
                    'status' => 403
                ];
            }

            if (!isset($this->parameters->id) || $this->parameters->id == '') {
                return [
                    'success' => 0,
                    'message' => 'ID utente mancante.',
                    'error_code' => 'users.missing_id',
                    'status' => 400
                ];
            }

            $userId = $this->parameters->id;

            // Verifica appartenenza al gruppo se permesso è "group"
            if ($permission === "group" && !$this->isSameGroup($userId, $auth_user)) {
                return [
                    'success' => 0,
                    'message' => 'Non hai i permessi per ripristinare utenti di un altro gruppo.',
                    'error_code' => 'users.group_denied',
                    'status' => 403
                ];
            }

            try {
                // Verifica se l'utente esiste ed è eliminato
                $fetch = "SELECT * FROM user WHERE id = :id AND deleted = 1";
                $query_stmt = $this->db->prepare($fetch);
                $query_stmt->bindValue(":id", $userId, PDO::PARAM_INT);
                $query_stmt->execute();

                if ($query_stmt->rowCount() === 1) {
                    // Ripristina l'utente
                    $update = "UPDATE user SET deleted = 0 WHERE id = :id";
                    $query_update = $this->db->prepare($update);
                    $query_update->bindValue(":id", $userId, PDO::PARAM_INT);
                    $query_update->execute();

                    return [
                        'success' => 1,
                        'status' => 200,
                        'message' => 'Utente ripristinato con successo.'
                    ];
                } else {
                    return [
                        'success' => 0,
                        'message' => 'Utente non trovato o non eliminato.',
                        'error_code' => 'users.not_found',
                        'status' => 404
                    ];
                }

            } catch (PDOException $e) {
                return [
                    'success' => 0,
                    'message' => $e->getMessage(),
                    'status' => 400,
                    'error_code' => 'users.db_error'
                ];
            }
        }

    }
?>
