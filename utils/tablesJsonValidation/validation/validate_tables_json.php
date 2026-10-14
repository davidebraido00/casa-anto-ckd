<?php
//require_once __DIR__ . '/vendor/autoload.php';
//use Swaggest\JsonSchema\Schema;
//use Swaggest\JsonSchema\Validator;

class ValidateTablesJson
{

    private $path_tables_json =  __DIR__ . '/../../../config/tables.json';
    private $path_schema_tables_json =  __DIR__ . '/schema_tables.json';
    private $db;
    private $schema_tables;
    private bool $simplePrinting = true;
    private $totalErrorCount = 0;
    private $totalOrphansEntites = 0;
    private $tablesErrorCount;
    private $unknownEntityLanguegesStrings = [];

    //declare externals for orphans tables
    private $usedEntities = [];
    //private $externalEntities = [];
    //private $deepExternalEntities = [];
    //private $multipleExternalEntities = [];

    private $coreEntities = ['log' , 'user_entity_preferences' , 'user_entity_saved_filters' , 'resource' , 'auth_pin' , 'auth_token' , 'user_group' , 'user'];


    /**
     * @param string $printType HTML | simple
     */
    function __construct($printType = 'simple')
    {
        $this->loadFile('tables');
        $this->loadFile('schemas');
        if ($printType === 'HTML') {
            $this->printTypeHtml();
        } else {
            $this->printTypeSimple();
        }
    }

    /**
     * print in $this->tables the file content
     * @param   string $path = 'tables'|'schema_tables'
     * @return  bool
     */

    private function loadFile($path)
    {
        if ($path === 'tables') {
            $this->db = json_decode($this->getFileContent($path), true);
        } else if ($path === 'schema_tables') {
            $this->schema_tables = json_decode($this->getFileContent($path), true);
        } else {
            return;
        }
    }

    /**
     * return file content
     * @param   string $path = 'tables'|'schema_tables'
     * @return  string
     */
    private function getFileContent($path)
    {
        if ($path === 'tables') {
            $path = $this->path_tables_json;
        } else if ($path === 'schema_tables') {
            $path = $this->path_schema_tables_json;
        } else {
            return;
        }
        if (file_exists($path)) {
            if (is_readable($path)) {
                $json = file_get_contents($path);
                if ($json === false) {
                    $this->throwWarning("Reading tables.json Error", "Failed to read the contents of the file", null);
                }
                return $json;
            } else {
                $this->throwWarning("Reading Error", "tables.json exists but is not readable", null);
            }
        } else {
            $this->throwWarning("tables.json not found", "check the path", null);
        }
    }

    /**
     * return $schema location
     * @return  string
     */
    private function getSchema()
    {
        return $this->db['$schema'];
    }

    /**
     * validate file handler
     */
    public function validate()
    {
        $this->validateIntegrity() && $this->throwValid('JSON Integrity Pass');
        //$this->schemaValidation() && $this->throwValid('JSON pass schema');

        $db = $this->db;
        $skipTable = ['$schema'];
        $checkFieldsInFields = ['primary_key', 'default_key', 'authorization', 'permissions' , 'callback_entity' , 'reference_entity'];
        $notCritical = ['default_key' , 'callback_entity' , 'reference_entity', 'authorization']; //set the not critical error fields
        $authorizationValues = ['groupField', 'userField'];
        $checkExternals = ['externalEntity', 'deepExternalEntity', 'multipleExternalEntity'];

        $userLevels = [];
        $notLoggedUser = '999'; //userlevel with read only 
        foreach ($db['user']['columns']['level']['options']['options'] as $levelOption) {
            array_push($userLevels, $levelOption['value']);
        };

        foreach ($db as $table => $value) {

            if (!$this->simplePrinting) {
                $this->printHtmlGroup('OPEN', $table, '', $table);
            }

            $this->tablesErrorCount[$table] = 0;
            //check if element in checkFieldsInFields is declared in columns
            if (!in_array($table, $skipTable)) {
                foreach ($checkFieldsInFields as $field) {
                    if (isset($db[$table][$field])) {
                        // is primary key a int?
                        if ($field === 'primary_key') {
                            $this->isColumnType($db[$table][$field], $table, 'INT');
                        }
                        // is default key declared?
                        if ($field === 'default_key') {
                            $this->isColumn($db[$table][$field], $table);
                        }
                        // is callback_entity key declared?
                        if ($field === 'callback_entity' || $field === 'reference_entity') {
                            $this->isTable($db[$table][$field]);
                        }

                        // is authorization fields declared?
                        if ($field === 'authorization') {
                            if (count($db[$table]['authorization']) <= 0) {
                                $this->throwWarning("$field Error in $table", "$field not has fields", $table);
                            }

                            // check authorizazion fields
                            foreach ($db[$table]['authorization'] as $key => $value) {
                                //check "groupField" or "userField" is in columns
                                if (in_array($key, $authorizationValues)) {
                                    $this->isColumn($value, $table);
                                } else {
                                    $this->throwError("$field Error in $table", "the $key in not in accepted values: " . implode(' , ', $authorizationValues), $table);
                                }
                            }
                        }
                        
                        // check the permissions
                        if ($field === 'permissions') {
                            if (count($db[$table]['permissions']) > 0) {
                                foreach ($db[$table]['permissions'] as $key => $value) {
                                    if (in_array($key, $userLevels)) {
                                        if ($key == $notLoggedUser) {
                                            if (
                                                $value['create'] != false ||
                                                $value['edit'] != false ||
                                                $value['delete'] != false
                                            ) {
                                                $this->throwWarning("$field Error in $table", "user level $key isn't read only", $table);
                                            }
                                        }
                                        if($value['view'] === 'user' || $value['create'] === 'user' || $value['edit'] === 'user' || $value['delete'] === 'user'){
                                            if(!isset($db[$table]['authorization']['userField']) || empty($db[$table]['authorization']['userField'])){
                                                $this->throwError("Authorization Error in $table", "userField is not declared", $table);
                                            }
                                        }
                                        if($value['view'] === 'group' || $value['create'] === 'group' || $value['edit'] === 'group' || $value['delete'] === 'group'){
                                            if(!isset($db[$table]['authorization']['groupField']) || empty($db[$table]['authorization']['groupField'])){
                                                $this->throwError("Authorization Error in $table", "groupField is not declared", $table);
                                            }
                                        }
                                    } else {
                                        $this->throwWarning("$field Error in $table", "user level $key is not declared", $table);
                                    }
                                }
                            } else {
                                $this->throwWarning("$field Allert for $table", "no users permissions set", $table);
                            }
                        }
                    } else {
                        if (!in_array($field, $notCritical)) {
                            $this->throwError("$field Error in $table", "$field is not set", $table);
                        }
                    }
                }
            }
            if (!in_array($table, $skipTable)) {
                //check if externals is declared in the declared table
                if (isset($db[$table]['columns'])) {
                    foreach ($db[$table]['columns'] as $key => $column) {
                        switch ($column['input_type']) {
                            case $checkExternals[0]:
                                //array_push($this->externalEntities, [$table , $key]);
                                $this->isExternalEntity($key, $table);
                                break;
                            case $checkExternals[1]:
                                //array_push($this->deepExternalEntities, [$table , $key]);
                                $this->isDeepExternalEntity($key, $table);
                                break;
                            case $checkExternals[2]:
                                //array_push($this->multipleExternalEntities, [$table , $key]);
                                $this->isMultipleExternalEntity($key, $table);
                                break;
                        }
                    }
                } else {
                    $this->throwError("columns Error in $table", "column not set", $table);
                }
            }  
            if ($this->tablesErrorCount[$table] === 0) {
                $this->throwValid($table, 'pass check', $table);
            }

            if (!$this->simplePrinting) {
                $this->printHtmlGroup('CLOSE', $table, '', $table);
            }
        }
    }

    /**
     * populate the relations Enities Arrays
     * @return array
     */
    public function getRelations(){

        $keysAcceptEntities = ['callback_entity' , 'reference_entity' , 'entity' , 'link_entity'];
        $skipTables = ['$schema'];
        //additional entites/tables to check  
        $additionalsEntities = ['user'];

        foreach($this->db as $table => $value){
            if(!in_array($table , $skipTables)){
                if(!in_array( $table , $this->coreEntities) && !in_array( $table , $additionalsEntities)){
                    foreach($value as $key => $value){
                        if(in_array($key , $keysAcceptEntities)){
                            !in_array($value , $this->usedEntities) && array_push($this->usedEntities , $value);
                        }
                    }
                    foreach ($this->db[$table]['columns'] as $key => $column) {
                        if(isset($column['options'])){
                            foreach($column['options'] as $k => $v){
                                if(in_array($k , $keysAcceptEntities)){
                                    //check if is already declared
                                    !in_array($v , $this->usedEntities) && array_push($this->usedEntities , $v);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $this->usedEntities;
    }

    /**
     * 
     */
    function doesNotStartWithAny($string, $prefixes) {
        // Use array_filter to check if any prefix matches the start of the string
        $filtered = array_filter($prefixes, function($prefix) use ($string) {
            return strpos($string, $prefix) === 0; // Check if the string starts with the prefix
        });
    
        // If filtered array is empty, then string does not start with any of the prefixes
        return empty($filtered);
    }

    /**
     * checking if there is some orphans/not related tables
     * required validate()
     * 
     */
    public function orphansTables(){
        
        if( empty($this->externalEntities) || empty($this->deepExternalEntities) || empty($this->multipleExternalEntities)){
            $this->getRelations();
        }

        //additional entites/tables to check  
        $additionalsEntities = ['user'];
        //ingnored all enitities begin with
        $ignorePrefix = ['gyv_' , '$schema'];
        
        foreach (array_keys($this->db) as $table) {
            if($this->doesNotStartWithAny($table, $ignorePrefix)){
                if( !in_array( $table , $this->coreEntities) && !in_array( $table , $additionalsEntities) && !in_array($table , $this->usedEntities)){

                    if (!$this->simplePrinting) {
                        $this->printHtmlGroup('OPEN', $table, '', $table);
                    }

                    $this->throwWarning("Orphan: $table" , 'is not related to any other entity' , $table );
                    $this->totalOrphansEntites++;

                    if (!$this->simplePrinting) {
                        $this->printHtmlGroup('CLOSE', $table, '', $table);
                    }
                }
            }
        }
    }

    /**
     * get all users levels permissions
     * 
     * @return array
     */

    public function getLevelsPermissions(){
        $levelsPermissions = [];
        $userslevels = $this->getUsersLevels();
        $entitesNames = $this->getEntitiesNames();

        foreach( $userslevels as $userlevel){
            $levelName = $this->generateLevelString($userlevel['value'] , $userlevel['name']);
            $level = $userlevel['value'];

            foreach( $entitesNames as $entityName){

                $permissions = [];
                //if not has permissions all false
                if( isset($this->db[$entityName]['permissions']) &&
                    isset($this->db[$entityName]['permissions'][$level]) ){ 
                    
                        $allowedPermissionsType = ['view' , 'create' , 'edit' , 'delete'];
                        foreach($allowedPermissionsType as $type){
                            if( isset($this->db[$entityName]['permissions'][$level][$type]) && 
                                $this->db[$entityName]['permissions'][$level][$type] == true ){
                                if( is_bool($this->db[$entityName]['permissions'][$level][$type]) ){
                                    $val = boolval($this->db[$entityName]['permissions'][$level][$type]);
                                    array_push($permissions, [$type => true]);
                                }else{
                                    $val = $this->db[$entityName]['permissions'][$level][$type];
                                    array_push($permissions, [$type => $val]);
                                }
                            }else{
                                array_push($permissions, [$type => false]);
                            }
                        }
                    
                }else{
                    $permissions = [
                            ['view' => false], ['create' => false], ['edit' => false], ['delete' => false]
                        ];
                }
                if( !isset($levelsPermissions[$levelName]) ){
                    $levelsPermissions[$levelName] = [];
                }
                if( !isset($levelsPermissions[$levelName][$entityName]) ){
                    $levelsPermissions[$levelName][$entityName] = [];
                }
                $levelsPermissions[$levelName][$entityName] = $permissions;

            }

        }

        return $levelsPermissions;
    }

    /**
     * get all users levels permissions
     * 
     * @return array
     */

    public function getEntitiesPermissions(){
        $entitiesPermissions = [];
        $userslevels = $this->getUsersLevels();
        $entitesNames = $this->getEntitiesNames();

        foreach( $entitesNames as $entityName){

            foreach( $userslevels as $userlevel){
                $levelName = $this->generateLevelString($userlevel['value'] , $userlevel['name']);
                $level = $userlevel['value'];

                $permissions = [];
                //if not has permissions all false
                if( isset($this->db[$entityName]['permissions']) &&
                    isset($this->db[$entityName]['permissions'][$level]) ){ 
                    
                        $allowedPermissionsType = ['view' , 'create' , 'edit' , 'delete'];
                        foreach($allowedPermissionsType as $type){
                            if( isset($this->db[$entityName]['permissions'][$level][$type]) && 
                                $this->db[$entityName]['permissions'][$level][$type] == true ){
                                if( is_bool($this->db[$entityName]['permissions'][$level][$type]) ){
                                    $val = boolval($this->db[$entityName]['permissions'][$level][$type]);
                                    array_push($permissions, [$type => true]);
                                }else{
                                    $val = $this->db[$entityName]['permissions'][$level][$type];
                                    array_push($permissions, [$type => $val]);
                                }
                            }else{
                                array_push($permissions, [$type => false]);
                            }
                        }
                    
                }else{
                    $permissions = [
                            ['view' => false], ['create' => false], ['edit' => false], ['delete' => false]
                        ];
                }
                if( !isset($entitiesPermissions[$entityName]) ){
                    $entitiesPermissions[$entityName] = [];
                }
                if( !isset($entitiesPermissions[$entityName][$levelName]) ){
                    $entitiesPermissions[$entityName][$levelName] = [];
                }
                $entitiesPermissions[$entityName][$levelName] = $permissions;

            }

        }

        return $entitiesPermissions;
    }

    /**
     * get all users levels names
     * 
     * @return string[]    
     * */

    public function generateLevelString( int $id , string $level = 'unknown' ){
        return $id . ' - ' . $level;
    }
    
    /**
     * get all users levels
     * 
     * @return array
     */

    public function getUsersLevels(){
        $levels = [];

        if(!isset($this->db)) { return []; }
        if(!isset($this->db['user'])) { return []; }
        if(!isset($this->db['user']['columns'])) { return []; }
        if(!isset($this->db['user']['columns']['level'])) { return []; }
        if(!isset($this->db['user']['columns']['level']['options'])) { return []; }
        if(!isset($this->db['user']['columns']['level']['options']['options'])) { return []; }

        foreach($this->db['user']['columns']['level']['options']['options'] as $key => $value){
            // id / value is required
            if( !isset($value['value']) ) { return; }

            $newValue = ['name' => 'unknown' , 'value' => 0];
            if( isset($value['value']) ) { $newValue['value'] = intval($value['value']); }
            if( isset($value['name']) ) { $newValue['name'] = $value['name']; }
            array_push($levels , $newValue);
        }

        return $levels;
    }

    /**
     * get all entities
     * 
     * @return array
     */

    public function getEntitiesNames(){
        $entites = [];
        foreach($this->db as $key => $value){
            array_push($entites , $key);
        }
        return $entites;
    }

    /**
     * prepare the entity_language for react backoffice
     * 
     * @return string
     */

    public function getEntityLanguage()
    {
        $db = $this->db;
        $skipTables = ['$schema' , 'auth_pin' , 'auth_token' , 'resource'];
        $skipColumns = ['avatar' , 'level'];

        $defaultsValues = [
            "user"=> "Utenti", 
            "user_group"=> "Gruppi", 
            "user_entity_saved_filters"=> "Filtri salvati", 
            "user_entity_preferences"=> "Preferenze entità", 
            "country"=> "Nazioni", 
            "city"=> "Città", 
            "log"=> "Log di sistema",
            "customer"=> "Clienti",
            "languages"=> "Lingue",
            "id"=> 'id',
            "date"=> 'Data',
            "lastUpdate"=> 'Ultimo aggiornamento',
            "deleted"=> 'Eliminato',
            "entity"=> 'Entità',
            "author"=> 'Utente',
            "idUser"=> 'Utente',
            "notes"=> 'Note',
            "name"=> 'Nome',
            "firstName" => 'Nome',
            "lastName" => 'Cognome',
            "birthday" => "Data di nascita",
            "province"=> 'Provincia',
            "region"=> 'Regione',
            "country"=> 'Nazione',
            "postalCode"=> 'Codice postale',
            "vatNumber"=> 'P.iva',
            "taxCode"=> 'Codice fiscale',
            "abi"=> "ABI",
            "cab"=> "CAB",
            "pec"=> "PEC",
            "iban"=> "IBAN",
            "sdi"=> "Codice SDI",
            "phone"=> "Telefono",
            "mobile"=> "Cellulare",
            "email"=> "Email",
            "idRole"=> "Ruolo",
            "role"=> "Ruolo",
            "idCountry"=> "Nazione",
            "idCity"=> "Città",
            "percentage"=> "Percentuale",
            "code"=> "Codice",
            "prefix"=> "Prefisso",
            "company"=> "Azienda",
            "quantity"=> "Quantità",
            "volume"=> "Volume",
            "length"=> "Lunghezza",
            "height"=> "Altezza",
            "depth"=> "Profondità",
            "type"=> "Tipologia",
            "price"=> "Prezzo",
            "file"=> "Allegato",
            "address"=> "Indirizzo",
            "discount"=> "Sconto",
            "status"=> "Stato",
            "active"=> "Attivo",
            "position"=> "Posizione",
            "message" => "Messaggi",
            "operation"=> "Operazione",
            "operation.options.0_DELETE"=> "Elimina",
            "operation.options.1_EDIT"=> "Modifica",
            "operation.options.2_ADD"=> "Aggiungi",
            "operation.options.3_LOGIN"=> "Login",
            "operation.options.4_LOGOUT"=> "Logout",
        ];

        $languageJson = "\n export const entity_language = { \n";
        //entity
        $languageJson .= "\t entity: { \n";
        foreach ($db as $key => $table) {
            if (!in_array($key, $skipTables)) {
                //search translations 
                if(isset($defaultsValues[$key])) {
                    $convertedValue = $defaultsValues[$key];
                }else{
                    $convertedValue = ucfirst(strtolower($key . '__'));

                    //add to unknown strings
                    array_push($this->unknownEntityLanguegesStrings , ucfirst(strtolower($key . '__')));
                }

                //print text 
                $languageJson .= "\t \t" . '"' . $key . '": ' . "'" . $convertedValue .  "', \n ";
            }
        }
        $languageJson .= "\t }, \n";

        // labels 
        $languageJson .= "\t labels: { \n";
        foreach ($db as $key => $table) {
            if (!in_array($key, $skipTables)) {

                $languageJson .= "\t \t" . '"' . $key . '": {' . " \n " ;
                foreach ($table['columns'] as $k => $v) {
                    if (!in_array($k, $skipColumns)) {

                        //search translations 
                        if(isset($defaultsValues[$k])) {
                            $convertedValue = $defaultsValues[$k];
                        }else{
                            $convertedValue = ucfirst(strtolower($k . '__'));

                            //add to unknown strings
                            array_push($this->unknownEntityLanguegesStrings , ucfirst(strtolower($k . '__')));
                        }

                        //print text 
                        $languageJson .= "\t \t \t" . '"' . $k . '": ' . "'" . $convertedValue .  "', \n ";
                        if (isset($v['options']['options'])) {
                            foreach ($v['options']['options'] as $option) {
                                    //search translations 
                                    isset($option['name']) ? $optionName = $option['name'] :  $optionName = $option['value'];
                                    if(isset($defaultsValues[$optionName])) {
                                        $convertedValue = $defaultsValues[$optionName];
                                    }else{
                                        $convertedValue = ucfirst(strtolower($optionName));

                                        //add to unknown strings
                                        array_push($this->unknownEntityLanguegesStrings , ucfirst(strtolower($optionName . '__')));
                                    }

                                    //print text 
                                    $languageJson .= "\t \t \t" . '"' . $k . '.options.' . $option['value'] . '": ' . "'" . $convertedValue . "', \n ";
                                }
                            }
                    }
                }
                $languageJson .= "\t\t}, \n";
            }
        }
        $languageJson .= "\t} \n };";
        return $languageJson;
    }

    /**
     * check if $table is declared
     * @param string $table
     * table name to check
     * @param bool $printError
     * define if fucntion return error or true
     * $printError == true return error in string
     * $printError == false return a bool 
     * 
     * @return bool|string
     * if $printError == true return error in string
     * if $printError == false return a bool
     */
    private function isTable($table, $printError = true)
    {
        $db = $this->db;
        if (isset($db[$table])) {
            return true;
        } else {
            $text = 'Table Error:' . $table . ' is not a table';
            if ($printError) {
                $this->throwError($text);
                return false;
            } else {
                return $text;
            }
        }
    }

    /**
     * check if $column is declared in table
     * @param string $column
     * column name to check
     * @param string $table
     * table where column could exists
     * @param bool $printError
     * define if function return needs to print and count error
     * $printError == true return error in string
     * $printError == false return a bool 
     * 
     * @return bool
     * if $printError == true return error in string
     * if $printError == false return a bool
     */
    private function isColumn($column, $table, $printError = true)
    {
        $db = $this->db;
        if (isset($db[$table]['columns'][$column])) {
            return true;
        } else {
            if ($printError) {
                $this->throwError("Column Error: $column", "column '$column' not exist in a $table table", $table);
            }
            return false;
        }
    }

    /**
     * check if $column is declared in table
     * @param string $column
     * column name to check
     * @param string $table
     * table where column could exists
     * @param bool $printError
     * define if function return needs to print and count error
     * $printError == true return error in string
     * $printError == false return a bool 
     * 
     * @return bool
     * if $printError == true return error in string
     * if $printError == false return a bool
     */
    private function isPrimary($columnName, $table, $printError = true)
    {
        $db = $this->db;
        if ($db[$table]['primary_key'] === $columnName) {
            return true;
        } else {
            if ($printError) {
                $this->throwError("Primary Key Error $table", "$columnName is not set as primary_key", $table);
                return false;
            } else {
                return false;
            }
        }
    }

    /**
     * check if required options fields is set 
     * @param string $columnName
     * column name to check 
     * @param string $table
     * table name of column
     * @param string 'externalEntity' | 'deepExternalEntity' | 'multipleExternalEntity'
     * input type selected by user
     * @param bool $printError
     * define if fucntion return error or true
     * $printError == true return error in string
     * $printError == false return a bool 
     * 
     * @return bool
     */
    private function isExternalsOptionValid($columnName, $table, $inputType, $printError = true)
    {
        $db = $this->db;
        $column = $db[$table]['columns'][$columnName];
        $options = $db[$table]['columns'][$columnName]['options'];
        switch ($inputType) {
            case 'externalEntity':
                $externalsErrorCount = 0;

                if (!isset($options['entity']) || empty($options['entity'])) {
                    $title = "$columnName (External Entity) Error in $table";
                    $body = 'no entity field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (!isset($options['labelField']) || empty($options['labelField'])) {
                    $title = "$columnName (External Entity) Error in $table";
                    $body = 'no labelField field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (isset($column['type'])) {
                    if ($column['type'] != 'INT') {
                        $title = "$columnName (External) Error in $table";
                        $body = "type field is not 'INT'";
                        if ($printError) {
                            $this->throwError($title, $body, $table);
                            $externalsErrorCount++;
                        } else {
                            $externalsErrorCount++;
                        }
                    }
                } else {
                    $title = "$columnName (External) type_field is not set to 'INT'";
                    $body = "check the $columnName in $table";
                    $this->throwError($title, $body, $table);
                }
                if ($externalsErrorCount > 0) {
                    return false;
                }
                break;
            case 'deepExternalEntity':
                $externalsErrorCount = 0;

                if (!isset($options['entity']) || empty($options['entity'])) {
                    $title = "$columnName (Deep External) Error in $table";
                    $$body = 'no entity field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (!isset($options['labelField']) || empty($options['labelField'])) {
                    $title = "$columnName (Deep External) Error in $table";
                    $body = 'no labelField field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (!isset($options['source_field']) || empty($options['source_field'])) {
                    $title = "$columnName (Deep External) Error in $table";
                    $body = 'no source_field field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (isset($column['type'])) {
                    if ($column['type'] != 'NONE') {
                        $title = "$columnName (Deep External) Error in $table";
                        $body = "type field is not 'NONE'";
                        if ($printError) {
                            $this->throwWarning($title, $body, $table);
                            $externalsErrorCount++;
                        } 
                    }
                } else {
                    $title = "$columnName (Deep External) type_field is not set to 'NONE'";
                    $body = "check the $columnName in $table";
                    $this->throwWarning($title, $body, $table);
                }
                if (isset($column['length'])) {
                    if ($column['length'] != '0') {
                        $title = "$columnName (Deep External) Error in $table";
                        $body = "length field is not '0' ";
                        if ($printError) {
                            $this->throwWarning($title, $body, $table);
                        }
                    }
                } else {
                    $title = "$columnName (Deep External) length is not set to '0'";
                    $body = "check the $columnName in $table";
                    $this->throwWarning($title, $body, $table);
                }
                if ($externalsErrorCount > 0) {
                    return false;
                }
                break;
            case 'multipleExternalEntity':
                $externalsErrorCount = 0;

                if (!isset($options['entity']) || empty($options['entity'])) {
                    $title = "$columnName (Multiple External) Error in $table";
                    $body = 'no entity field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (!isset($options['labelField']) || empty($options['labelField'])) {
                    $title = "$columnName (Multiple External) Error in $table";
                    $body = 'no labelField field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (!isset($options['link_entity']) || empty($options['link_entity'])) {
                    $title = "$columnName (Multiple External) Error in $table";
                    $body = 'no link_entity field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (!isset($options['source_field']) || empty($options['source_field'])) {
                    $title = "$columnName (Multiple External) Error in $table";
                    $body = 'no source_field field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (!isset($options['destination_field']) || empty($options['destination_field'])) {
                    $title = "$columnName (Multiple External) Error in $table";
                    $body = 'no destination_field field declared';
                    if ($printError) {
                        $this->throwError($title, $body, $table);
                        $externalsErrorCount++;
                    } else {
                        $externalsErrorCount++;
                    }
                }
                if (isset($column['type'])) {
                    if ($column['type'] != 'NONE') {
                        $title = "$columnName (Multiple External) Error in $table";
                        $body = "type field is not 'NONE'";
                        if ($printError) {
                            $this->throwWarning($title, $body, $table);
                        }
                    }
                } else {
                    $title = "$columnName (Multiple External) type_field is not set to 'NONE'";
                    $body = "check the $columnName in $table";
                    $this->throwError($title, $body, $table);
                }
                if (isset($column['length'])) {
                    if ($column['length'] != '0') {
                        $title = "$columnName (Multiple External) Error in $table";
                        $body = "length field is not '0' ";
                        if ($printError) {
                            $this->throwWarning($title, $body, $table);
                        }
                    }
                } else {
                    $title = "$columnName (Multiple External) length is not set to '0'";
                    $body = "check the $columnName in $table";
                    $this->throwWarning($title, $body, $table);
                }
                if ($externalsErrorCount > 0) {
                    return false;
                }
                if ($externalsErrorCount > 0) {
                    return false;
                }
                break;
            default:
                return true;
                break;
        }
    }


    /**
     * check if external enitity is valid
     * @param string $column
     * column name to check 
     * @param string $table
     * table name of column
     * 
     * @return bool
     */
    private function isExternalEntity($columnName, $table)
    {
        $db = $this->db;
        $column = $db[$table]['columns'][$columnName];
        //check if entity and labelField exist
        if ($this->isExternalsOptionValid($columnName, $table, 'externalEntity')) {
            if ($this->isTable($column['options']['entity'], false) != true) {
                $title = "$columnName (External Entity) Error in $table";
                $body = $columnName . ' expect ' . $column['options']['entity'] . ' but it not exits in tables';
                $this->throwError($title, $body, $table);
                return;
            }
            if ($this->isColumn($column['options']['labelField'], $column['options']['entity'], false) != true) {
                $title = "$columnName (External Entity) Error in $table";
                $body = $columnName . ' expect label ' . $column['options']['labelField'] . ' but it not exits in table ' . $column['options']['entity'];
                $this->throwError($title, $body, $table);
            }
        }
    }

    /**
     * check if deep external enitity is valid
     * @param string $column
     * column name to check 
     * @param string $table
     * table name of column
     * 
     * @return bool
     */
    private function isDeepExternalEntity($columnName, $table)
    {
        $db = $this->db;
        $column = $db[$table]['columns'][$columnName];
        //check if entity and labelField exist
        if ($this->isExternalsOptionValid($columnName, $table, 'deepExternalEntity')) {
            if ($this->isTable($column['options']['entity'], false) != true) {
                $title = "$columnName (Deep External) Error in $table";
                $body = $columnName . ' expect ' . $column['options']['entity'] . ' but it not exits in tables';
                $this->throwError($title, $body, $table);
                return;
            }
            if ($this->isColumn($column['options']['labelField'], $column['options']['entity'], false) != true) {
                $title = "$columnName (Deep External) Error in $table";
                $body = $columnName . ' expect label ' . $column['options']['labelField'] . ' but it not exits in table ' . $column['options']['entity'];
                $this->throwError($title, $body, $table);
            }

            if ($this->isColumn($column['options']['source_field'], $column['options']['entity'], false) != true) {
                $title = "$columnName (Deep External) Error in $table";
                $body = $columnName . ' expect ' . $column['options']['source_field'] . ' but it not exits in table' . $column['options']['entity'];
                $this->throwError($title, $body, $table);
            }
        }
    }

    /**
     * check if multiple external enitity is valid
     * @param string $column
     * column name to check 
     * @param string $table
     * table name of column
     * 
     * @return bool
     */
    private function isMultipleExternalEntity($columnName, $table)
    {
        $db = $this->db;
        $column = $db[$table]['columns'][$columnName];
        //check if entity and labelField exist
        if ($this->isExternalsOptionValid($columnName, $table, 'multipleExternalEntity')) {
            if ($this->isTable($column['options']['entity'], false) != true) {
                $title = "$columnName (Multiple External) Error in $table";
                $body = $columnName . ' expect ' . $column['options']['entity'] . ' but it not exits in tables';
                $this->throwError($title, $body, $table);
                return;
            }

            if ($this->isTable($column['options']['link_entity'], false) != true) {
                $title = "$columnName (Multiple External) Error in $table";
                $body = $columnName . ' expect ' . $column['options']['link_entity'] . ' but it not exits in tables';
                $this->throwError($title, $body, $table);
                return;
            }

            if ($this->isColumn($column['options']['labelField'], $column['options']['entity'], false) != true) {
                $title = "$columnName (Multiple External) Error in $table";
                $body = $columnName . ' expect label ' . $column['options']['labelField'] . ' but it not exits in table ' . $column['options']['entity'];
                $this->throwError($title, $body, $table);
            }


            if ($this->isColumn($column['options']['source_field'], $column['options']['entity'], false) != true) {
                $title = "$columnName (Multiple External) Error in $table";
                $body = $columnName . ' expect ' . $column['options']['source_field'] . ' but it not exits in table' . $column['options']['entity'];
                $this->throwError($title, $body, $table);
            }

            if ($this->isColumn($column['options']['destination_field'], $column['options']['link_entity'], false) != true) {
                $title = "$columnName (Multiple External) Error in $table";
                $body = $columnName . ' expect ' . $column['options']['destination_field'] . ' but it not exits in table' . $column['options']['link_entity'];
                $this->throwError($title, $body, $table);
            }
        }
    }

    /**
     * check if a column is a specific data type
     * @param string $column
     * column name to check 
     * @param string $table
     * table name of column
     * @param string $dataType
     * type expected
     * 
     * @return bool
     */
    private function isColumnType($column, $table, $dataType)
    {
        $db = $this->db;
        if ($db[$table]['columns'][$column]['type'] === $dataType) {
            return true;
        } else {
            $this->throwError($db[$table][$column]['type'] . ' expected ' . $dataType . ':' . $db[$table][$column] . ' is ' . $db[$table][$column]['type'] . ' and not ' . $dataType);
            return false;
        }
    }

    /**
     * validate json with schema
     * @return bool
     */

    /* public function schemaValidation()
    {
        $schema = Schema::import(json_decode($this->getFileContent('schema_tables')));
        try {
            $schema->in(json_decode($this->getFileContent('tables')));
            return true;
        } catch (\Exception $e) {
            $this->throwError("JSON data is invalid ", $e->getMessage());
            return false;
        }
    } */

    /**
     * check if file loaded and read to verification stuff
     * 
     * @return  bool
     */
    public function validateIntegrity()
    {
        $db = $this->db;
        switch ($db) {
            case (empty($db)):
                $this->throwWarning('File Integrity', 'File is empty', null);
                break;
            case (!is_array($db)):
                $this->throwWarning('File Integrity', 'File not converted in an array', null);
                break;
            case (!isset($db['$schema'])):
                $this->throwWarning('File Integrity', 'Not defined $schema in file', null);
                break;
            default:
                return true;
                break;
        }
    }

    /**
     * change printing method to Html
     */
    public function printTypeHtml()
    {
        $this->simplePrinting = false;
    }

    /**
     * change printing method to Html
     */
    public function printTypeSimple()
    {
        $this->simplePrinting = true;
    }

    /**
     * Print Error despacer
     * @param string $title
     * @param string $body
     * @param string $table table name
     * @param bool $ignoreError skip counting this error
     */
    public function throwError($title = '', $body = '', $table = null)
    {
        $this->totalErrorCount++;
        if (isset($this->tablesErrorCount[$table]) && isset($this->tablesErrorCount[$table]) >= 0 && isset($table)) {
            $this->tablesErrorCount[$table]++;
        } else {
            $this->tablesErrorCount[$table] = 1;
        }

        if ($this->simplePrinting) {
            $this->simplePrintError($title . ' ; ' . $body);
        } else {
            $this->printHtmlError($title, $body);
        }
    }

    /**
     * Print Valid despacer
     * @param string $title
     * @param string $body
     * @param string $table
     */
    public function throwValid(string $title = '', string $body = '', ?string $table = null)
    {
        if (!isset($this->tablesErrorCount[$table]) && isset($table)) {
            array_push($this->tablesErrorCount, [$table => 0]);
        }

        if (!$this->simplePrinting) {
            $this->printHtmlValid($title, $body);
        }
    }

    /**
     * Print Warining despacer
     * @param string $title
     * @param string $body
     * @param string $table
     */
    public function throwWarning(string $title = '', string $body = '', ?string $table = null)
    {

        if (!$this->simplePrinting) {
            $this->printHtmlWarning($title, $body);
        }
    }

    /**
     * print error in a pritty html
     * @param string $title 
     * @param string $body
     */

    protected function printHtmlError($title = 'Error', $body = '')
    {
        $html = <<<HTML
                <div class="list-group-item list-group-item-action d-flex gap-3 py-3 danger" aria-current="true">
                    <svg class="flex-shrink-0" xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#dc3545" class="bi bi-exclamation-circle-fill" viewBox="0 0 16 16">
                        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M4.5 7.5a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1z"/>
                    </svg>
                    <div class="d-flex flex-column gap-2 w-100">
                    <span style="text-transform:uppercase;">$title</span>
        HTML;
        if (!empty($body)) {
            $html .= "<small class='d-block text-body-secondary'>$body</small>";
        }
        $html .= <<<HTML
                </div>
                </div>
        HTML;
        echo $html;
    }

    /**
     * print error for api
     * @param string $errstr
     * error text to print
     */

    protected function simplePrintError($errstr)
    {
        $message = $errstr . "/n";
        echo $message;
    }

    /**
     * print valid 
     * @param string $title
     * @param string $body
     * text to print
     */

    protected function printHtmlWarning($title = 'Warning', $body = '')
    {

        $html = <<<HTML
                <div class="list-group-item list-group-item-action d-flex gap-3 py-3" aria-current="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#ffc107" fill="currentColor" class="bi bi-dash-circle-fill" viewBox="0 0 16 16">
                        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M8 4a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 4m.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2"/>
                    </svg>
                    <div class="d-flex flex-column gap-2 w-100">
                    <span style="text-transform:uppercase;">$title</span>
        HTML;
        if (!empty($body)) {
            $html .= "<small class='d-block text-body-secondary'>$body</small>";
        }
        $html .= <<<HTML
                    </div>
                    </div>
            HTML;
        echo $html;
    }

    /**
     * print valid 
     * @param string $title
     * @param string $body
     * text to print
     */

    protected function printHtmlValid($title = 'Valid', $body = '')
    {

        $html = <<<HTML
                <div class="list-group-item list-group-item-action d-flex gap-3 py-3" aria-current="true">
                    <svg class="flex-shrink-0" xmlns="http://www.w3.org/2000/svg" height="40px" viewBox="0 -960 960 960" width="40px" fill="#2E7D32"><path d="M422-297.33 704.67-580l-49.34-48.67L422-395.33l-118-118-48.67 48.66L422-297.33ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-156t85.83-127q54.34-54 127.34-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 82.33-31.5 155.33-31.5 73-85.5 127.34Q709-143 636-111.5T480-80Z"/></svg>
                    <div class="d-flex align-items-center gap-2 w-100 justify-content-between">
                    <span style="text-transform:uppercase;">$title</span>
        HTML;
        if (!empty($body)) {
            $html .= "<small class='d-block text-body-secondary'>$body</small>";
        }
        $html .= <<<HTML
                    </div>
                    </div>
            HTML;
        echo $html;
    }

    /**
     * print collapse 
     * @param string $option OPEN | CLOSE
     * @param string $title
     * @param string $body
     * text to print
     */

    protected function printHtmlGroup($option = 'OPEN', $title = '', $body = '', $table = null)
    {
        if ($option != 'CLOSE') {
            $html = <<<HTML
                <div class="accordion my-2 rounded-2 w-full" id="accordion_$table">
                    <div class="accordion-item ">
                        <h4 class="accordion-header">
                        <button class="accordion-button py-4 w-full collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_$table" aria-expanded="false" aria-controls="collapse_$table">
                            $title
            HTML;
            !empty($body) && $html .= "<p class='pt-1'>$body</p>";
            $html .= <<<HTML
                        </button>
                        </h4>
                        <div id="collapse_$table" class="accordion-collapse collapse w-full" data-bs-parent="">
                        <div class="accordion-body">
            HTML;
        } else {
            $html = <<<HTML
                     </div>
                    </div>
                </div>
             </div>
            HTML;
        }

        echo $html;
    }

    /**
     * get general error count
     */

    public function getErrorCount()
    {
        return $this->totalErrorCount;
    }

    /**
     * get orphans error count
     */

    public function getOrphansCount()
    {
        return $this->totalOrphansEntites;
    }
    
    /**
     * get orphans error count
     */

    public function getUnknownEntityLanguegesStrings()
    {
        return $this->unknownEntityLanguegesStrings;
    }

    /**
     * get tables error count
     * @return array [ 'table' => count , 'table' => count ]
     */

    public function getTablesErrorCount()
    {
        return $this->tablesErrorCount;
    }
}
