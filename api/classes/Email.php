<?php

    class Email {
        protected $db;
        protected $user_info;
    
        function __construct($db, $user_info) {
            $this->db = $db;
            $this->user_info = json_decode($user_info, true);
        }

        private function buildMailer() {

            $basepath = dirname(__DIR__);
            $settings = json_decode(file_get_contents($basepath . '/../config/settings.json'), true);

            include_once $basepath . '/libs/phpmailer/src/Exception.php';
            include_once $basepath . '/libs/phpmailer/src/PHPMailer.php';
            include_once $basepath . '/libs/phpmailer/src/SMTP.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer();
            $mail->CharSet = 'UTF-8';
            $mail->IsSMTP();
            $mail->Host = $settings['mail']['smtp_server'];
            $mail->SMTPAuth = $settings['mail']['smtp_enable_auth'];
            $mail->Username = $settings['mail']['smtp_username'];
            $mail->Password = $settings['mail']['smtp_password'];
            $mail->SMTPSecure = $settings['mail']['smtp_secure_type'];
            $mail->Port = $settings['mail']['smtp_port'];
            //$mail->SMTPDebug = 1;
            $mail->SMTPKeepAlive = true;
            $mail->isHTML(true);

            $mail->setFrom($settings['mail']['smtp_username'], '');
            $mail->addReplyTo($settings['mail']['smtp_username'], '');
            $mail->ClearAllRecipients();

            return $mail;
        }


        public function sendDemoMessage($emailTo, $userName) {


            $mail = $this->buildMailer();
            
            try {
                $mail->addAddress($emailTo, $userName); //to
                $mail->Subject = "Prova messaggio.";
                $mail->msgHTML("<html>
                                    <body>
                                        Prova messaggio.
                                    </body>
                                </html>");
                $mail->send();
         
                return [
                    'success' => 1,
                    'status' => 200
                ];

            } catch (Exception $e) {
                return [
                    'success' => 0,
                    'status' => 200,
                    'message' => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}"
                ];
            }

        }


        public function sendMessage($emailTo, $userName, $title, $textHTML) {


            $mail = $this->buildMailer();

            try {
                $mail->addAddress($emailTo, $userName); //to
                $mail->Subject = $title;
                $mail->msgHTML($textHTML);
                $mail->send();
         
                return [
                    'success' => 1,
                    'status' => 200
                ];

            } catch (Exception $e) {
                return [
                    'success' => 0,
                    'status' => 200,
                    'message' => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}"
                ];
            }

        }


    }
?>