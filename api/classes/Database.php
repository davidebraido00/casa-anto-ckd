<?php

    class Database {

        private $db_host = "";
        private $db_name = "";
        private $db_username = "";
        private $db_password = "";
        private $db_timezone = "";
        private $db_port = "";
        
        function __construct() {
            $settings = json_decode(file_get_contents("../config/settings.json"), true);
            $this->db_host = $settings['db']['server'];
            $this->db_name = $settings['db']['name'];
            $this->db_username = $settings['db']['username'];
            $this->db_password = $settings['db']['password'];
            if(isset($settings['db']['port'])) {
                $this->db_port = $settings['db']['port'];
            }
            else {
                $this->db_port = "3306";
            }
            if(isset($settings['db']['timezone'])) {
                $dbzone = $settings['db']['timezone'];
                date_default_timezone_set($dbzone);
                $this->db_timezone = date('P');
            }
            else {
                $this->db_timezone = "+02:00";
            }
        }
        
        public function dbConnection() {
            
            try {
                $conn = new PDO('mysql:host='.$this->db_host.';port='.$this->db_port.';dbname='.$this->db_name.';charset=utf8mb4',$this->db_username,$this->db_password,
                array(PDO::MYSQL_ATTR_LOCAL_INFILE => true));
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $conn->exec("SET time_zone = '". $this->db_timezone ."' ");
                
                return $conn;
            }
            catch(PDOException $e) {
                echo "Connection error ".$e->getMessage(); 
                exit;
            }
            
        }
    }
    
?>