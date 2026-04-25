<?php
/**
 * email_sender.php
 *
 * Envía el correo de confirmación al cliente cuando una cotización pasa
 * a estado "completed". Usa PHPMailer vía SMTP con las credenciales
 * configuradas en .env (SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, etc).
 *
 * Requiere PHPMailer en `api/lib/PHPMailer/` (instalación manual,
 * descargar desde https://github.com/PHPMailer/PHPMailer/releases).
 */

require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envía el correo de confirmación de cotización completada.
 *
 * @param PDO $pdo            Conexión activa a la base de datos.
 * @param int $cotizacionId   ID de la cotización a notificar.
 * @return bool               true si el envío fue exitoso, false en caso contrario.
 */
function sendQuoteCompletedEmail($pdo, $cotizacionId)
{
    // 1. Cargar cotización
    $stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
    $stmt->execute([$cotizacionId]);
    $cot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cot || empty($cot['cliente_email'])) {
        error_log("sendQuoteCompletedEmail: cotización $cotizacionId no encontrada o sin email");
        return false;
    }

    // 2. Cargar viajes
    $vStmt = $pdo->prepare("SELECT * FROM viajes WHERE reserva_id = ? ORDER BY item_index ASC");
    $vStmt->execute([$cotizacionId]);
    $viajes = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Renderizar HTML usando el template
    ob_start();
    $cotizacion = $cot;  // Renombrar para el template
    include __DIR__ . '/../templates/email_quote_complete.php';
    $html = ob_get_clean();

    // 4. Enviar vía SMTP
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = env('SMTP_HOST');
        $mail->SMTPAuth   = true;
        $mail->Username   = env('SMTP_USER');
        $mail->Password   = env('SMTP_PASS');

        $encryption = strtolower(env('SMTP_ENCRYPTION', 'ssl'));
        $mail->SMTPSecure = $encryption === 'tls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;

        $mail->Port    = intval(env('SMTP_PORT', 465));
        $mail->CharSet = 'UTF-8';

        $fromEmail = env('SMTP_FROM_EMAIL', env('SMTP_USER'));
        $fromName  = env('SMTP_FROM_NAME', 'Liberia Airport Shuttle');

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($cot['cliente_email'], $cot['cliente_nombre'] ?? '');
        $mail->addReplyTo($fromEmail, $fromName);

        $mail->isHTML(true);
        $mail->Subject = 'Your Liberia Airport Shuttle reservation is now complete';
        $mail->Body    = $html;
        $mail->AltBody = strip_tags(preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log("sendQuoteCompletedEmail SMTP error (cot $cotizacionId): " . $mail->ErrorInfo);
        return false;
    } catch (Exception $e) {
        error_log("sendQuoteCompletedEmail general error (cot $cotizacionId): " . $e->getMessage());
        return false;
    }
}
