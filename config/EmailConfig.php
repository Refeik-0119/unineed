<?php
define('MAIL_FROM_EMAIL', 'bpcunineeds@gmail.com');
define('MAIL_FROM_NAME', 'UniNeeds Mail');

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'bpcunineeds@gmail.com');
define('SMTP_PASSWORD', 'usco ozvn rkny qmjb');
define('SHOW_OTP_IN_BROWSER', $_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
function validateEmailCredentials() {
    if (SMTP_USER === 'your-email@gmail.com') {
        error_log("WARNING: Email credentials not configured. Please update config/EmailConfig.php");
        return false;
    }
    return true;
}
?>
