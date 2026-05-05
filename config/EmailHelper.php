<?php
require_once __DIR__ . '/EmailConfig.php';

class EmailHelper {
    private $smtp_host = SMTP_HOST;
    private $smtp_port = SMTP_PORT;
    private $smtp_user = SMTP_USER;
    private $smtp_password = SMTP_PASSWORD;
    private $from_email = MAIL_FROM_EMAIL;
    private $from_name = MAIL_FROM_NAME;
    
    public function __construct() {
    }
    
    public function sendPasswordResetOTP($to_email, $to_name, $otp) {
        $subject = 'Your Password Reset OTP - UniNeeds';
        
        $message_body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #61B087 0%, #4e8d6c 100%); color: white; padding: 20px; border-radius: 10px 10px 0 0; text-align: center; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; text-align: center; }
                .otp-box { background: white; border: 2px solid #61B087; padding: 20px; border-radius: 10px; margin: 20px 0; }
                .otp-code { font-size: 32px; font-weight: bold; color: #61B087; letter-spacing: 5px; }
                .footer { font-size: 12px; color: #666; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Password Reset OTP</h2>
                </div>
                <div class='content'>
                    <p>Hello <strong>$to_name</strong>,</p>
                    <p>We received a request to reset your UniNeeds password. Use the OTP below to proceed:</p>
                    <div class='otp-box'>
                        <div class='otp-code'>$otp</div>
                    </div>
                    <p><strong>This OTP will expire in 15 minutes.</strong></p>
                    <p style='color: #666;'><small>If you didn't request this, you can safely ignore this email.</small></p>
                    <div class='footer'>
                        <p>UniNeeds System</p>
                        <p><small>Do not share this OTP with anyone.</small></p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $this->sendEmail($to_email, $to_name, $subject, $message_body);
    }
    
    public function sendEmail($to_email, $to_name, $subject, $html_body) {
        try {
            $socket = @fsockopen($this->smtp_host, $this->smtp_port, $errno, $errstr, 30);
            
            if (!$socket) {
                error_log("Failed to connect to SMTP server: $errstr ($errno)");
                return false;
            }
            
            stream_context_set_option($socket, 'ssl', 'allow_self_signed', true);
            stream_set_timeout($socket, 10);
            
            $response = $this->getResponse($socket);
            if (strpos($response, '220') === false) {
                error_log("SMTP connection error: $response");
                fclose($socket);
                return false;
            }
            
            fputs($socket, "EHLO localhost\r\n");
            $response = $this->getResponse($socket);
            
            fputs($socket, "STARTTLS\r\n");
            $response = $this->getResponse($socket);
            
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log("Failed to enable TLS");
                fclose($socket);
                return false;
            }
            
            fputs($socket, "EHLO localhost\r\n");
            $response = $this->getResponse($socket);
            
            fputs($socket, "AUTH LOGIN\r\n");
            $response = $this->getResponse($socket);
            
            fputs($socket, base64_encode($this->smtp_user) . "\r\n");
            $response = $this->getResponse($socket);
            
            $pwd = str_replace(' ', '', $this->smtp_password);
            fputs($socket, base64_encode($pwd) . "\r\n");
            $response = $this->getResponse($socket);
            
            if (strpos($response, '235') === false && strpos($response, '250') === false) {
                error_log("SMTP authentication failed: $response");
                fclose($socket);
                return false;
            }
            
            fputs($socket, "MAIL FROM: <" . $this->from_email . ">\r\n");
            $response = $this->getResponse($socket);
            
            fputs($socket, "RCPT TO: <$to_email>\r\n");
            $response = $this->getResponse($socket);
            
            fputs($socket, "DATA\r\n");
            $response = $this->getResponse($socket);
            
            $message = "From: " . $this->from_name . " <" . $this->from_email . ">\r\n";
            $message .= "To: $to_name <$to_email>\r\n";
            $message .= "Subject: $subject\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-type: text/html; charset=UTF-8\r\n";
            $message .= "\r\n";
            $message .= $html_body;
            $message .= "\r\n.\r\n";
            
            fputs($socket, $message);
            $response = $this->getResponse($socket);
            
            if (strpos($response, '250') === false) {
                error_log("Failed to send email: $response");
                fclose($socket);
                return false;
            }
            
            fputs($socket, "QUIT\r\n");
            fclose($socket);
            
            error_log("Email sent successfully to: $to_email via SMTP");
            return true;
            
        } catch (Exception $e) {
            error_log("Email Exception: " . $e->getMessage());
            return false;
        }
    }
    
    private function getResponse($socket) {
        $response = '';
        while ($line = fgets($socket, 256)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }
}
?>
