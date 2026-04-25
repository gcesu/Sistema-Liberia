<?php
/**
 * email_quote_complete.php
 *
 * Template HTML del correo de confirmación de cotización.
 * Variables disponibles (provistas por email_sender.php):
 *   - $cotizacion : array con la fila de la tabla `cotizaciones`
 *   - $viajes     : array de filas de `viajes` ordenados por item_index
 */

// ─── Helpers locales ─────────────────────────────────────────────────────────
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$fmtDate = function ($d, $sep = '/') {
    if (!$d) return '';
    $ts = strtotime($d);
    if ($ts === false) return $d;
    return date("m{$sep}d{$sep}Y", $ts);
};

$fmtTime = function ($t) {
    if (!$t) return '';
    $m = [];
    if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
        return sprintf('%02d:%s', intval($m[1]), $m[2]);
    }
    return $t;
};

$fmtMoney = function ($n) {
    $n = floatval($n);
    if ($n == intval($n)) return '$' . intval($n);
    return '$' . number_format($n, 2);
};

// ─── Datos básicos ───────────────────────────────────────────────────────────
$firstName = trim(explode(' ', $cotizacion['cliente_nombre'] ?? '')[0] ?? '');
if ($firstName === '') $firstName = 'there';

$cotId = intval($cotizacion['id']);
$displayId = $cotId >= 10000000 ? 'C-' . ($cotId - 9999999) : '#' . $cotId;

$dateCreated = $fmtDate($cotizacion['date_created'] ?? null);
$paymentMethod = $cotizacion['metodo_pago'] ?? 'Pending';
$nota = trim($cotizacion['nota_cliente'] ?? '');
$telefono = $cotizacion['cliente_telefono'] ?? '';
$emailCliente = $cotizacion['cliente_email'] ?? '';

// ─── Calcular subtotal y total a partir de viajes ────────────────────────────
$subtotalCalc = 0.0;
foreach ($viajes as $v) {
    $subtotalCalc += floatval($v['subtotal'] ?? 0);
}
$displaySubtotal = floatval($cotizacion['subtotal'] ?? 0);
if ($displaySubtotal <= 0) $displaySubtotal = $subtotalCalc;
$displayTotal = floatval($cotizacion['total'] ?? 0);
if ($displayTotal <= 0) $displayTotal = $displaySubtotal;

// ─── Construir filas de viajes ───────────────────────────────────────────────
$rowsHtml = '';
foreach ($viajes as $viaje) {
    $tipo = $viaje['tipo'] ?? '';
    $pax = intval($viaje['pax'] ?? 1);
    $passengersText = $pax === 1 ? '1 passenger' : "$pax passengers";
    $price = floatval($viaje['subtotal'] ?? 0);

    $title = '';
    $items = [];

    if ($tipo === 'llegada') {
        $title = $viaje['destino'] ?? ($viaje['hotel'] ?? 'Arrival');
        $items[] = ['Passengers', $passengersText];
        $items[] = ['- Type of Trip', 'One way to hotel'];
        if (!empty($viaje['fecha'])) $items[] = ['- Arrival Date', $fmtDate($viaje['fecha'])];
        if (!empty($viaje['hora']))  $items[] = ['- Arrival Time', $fmtTime($viaje['hora'])];
        if (!empty($viaje['vuelo'])) $items[] = ['- Arrival Flight Number', $viaje['vuelo']];
    } elseif ($tipo === 'salida') {
        $title = $viaje['destino'] ?? ($viaje['hotel'] ?? 'Departure');
        $items[] = ['Passengers', $passengersText];
        $items[] = ['- Type of Trip', 'One way to airport'];
        if (!empty($viaje['fecha'])) $items[] = ['- Departure Date', $fmtDate($viaje['fecha'])];
        if (!empty($viaje['hora']))  $items[] = ['- Pick-up Time at Hotel', $fmtTime($viaje['hora'])];
        if (!empty($viaje['vuelo'])) $items[] = ['- Departure Flight Number', $viaje['vuelo']];
    } else { // interno
        $title = 'One Way Shuttle';
        $route = $viaje['destino'] ?? '';
        $pickup = $dropoff = '';
        if ($route) {
            $parts = preg_split('/\s*(?:→|->|—|–)\s*/u', $route, 2);
            $pickup = trim($parts[0] ?? '');
            $dropoff = trim($parts[1] ?? '');
        }
        $items[] = ['- Passengers', (string)$pax];
        if ($pickup)  $items[] = ['- Pick-up Location', $pickup];
        if ($dropoff) $items[] = ['- Drop-off Location', $dropoff];
        if (!empty($viaje['fecha'])) $items[] = ['- Pick-up Date', $fmtDate($viaje['fecha'], '-')];
        if (!empty($viaje['hora']))  $items[] = ['- Pick-up Time', $fmtTime($viaje['hora'])];
    }

    // Renderizar lista de metadatos
    $metaHtml = '<ul class="wc-item-meta" style="font-size: small; margin: 0; padding: 0; list-style: none;">';
    foreach ($items as [$label, $value]) {
        $metaHtml .= '<li style="margin: 0; padding: 0;">'
            . '<strong style="float: left; margin-right: .25em; clear: both;">' . $h($label) . ':</strong> '
            . '<p style="margin: 0; display: inline;">' . $h($value) . '</p>'
            . '</li>';
    }
    $metaHtml .= '</ul>';

    $rowsHtml .= '
        <tr class="order_item" style="font-size: 16px;">
            <td style="color: #747474; border: 1px solid #e5e5e5; font-family: \'Helvetica Neue\',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left; vertical-align: middle; word-wrap: break-word;" align="left">
                ' . $h($title) . $metaHtml . '
            </td>
            <td style="color: #747474; border: 1px solid #e5e5e5; width: 0px; display: block; overflow: hidden; padding: 0;" width="0" align="left">1</td>
            <td style="color: #747474; border: 1px solid #e5e5e5; font-family: \'Helvetica Neue\',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left; vertical-align: middle;" align="left">
                <span class="woocommerce-Price-amount amount">' . $h($fmtMoney($price)) . '</span>
            </td>
        </tr>
    ';
}

// ─── Fila opcional de Note ───────────────────────────────────────────────────
$noteRow = '';
if ($nota !== '') {
    $noteRow = '
        <tr>
            <th style="color: #747474; border: 1px solid #e5e5e5; font-family: \'Helvetica Neue\',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" colspan="2" align="left">Note:</th>
            <td style="color: #747474; border: 1px solid #e5e5e5; font-family: \'Helvetica Neue\',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" align="left">' . $h($nota) . '</td>
        </tr>
    ';
}
?>
<!DOCTYPE html>
<html lang="en-US" style="height: 100%; position: relative;">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Liberia Airport Shuttle | Costa Rica Transfers</title>
</head>
<body style="height: 100%; position: relative; background-color: #f7f7f7; margin: 0; padding: 0;" bgcolor="#f7f7f7">
<div id="wrapper" dir="ltr" style="background-color: #f7f7f7; margin: 0; padding: 70px 0 70px 0; width: 100%; -webkit-text-size-adjust: none;" bgcolor="#f7f7f7" width="100%">
    <table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%">
        <tr>
            <td align="center" valign="top">
                <table border="0" cellpadding="0" cellspacing="0" width="600" id="template_container" style="background-color: #fff; overflow: hidden; border: 1px solid #dedede; border-radius: 3px; box-shadow: 0 1px 4px 1px rgba(0,0,0,.1);" bgcolor="#fff">
                    <!-- Header -->
                    <tr>
                        <td align="center" valign="top">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" style="background-color: #036; color: #fff; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;" bgcolor="#036">
                                <tr>
                                    <td id="header_wrapper" style="padding: 36px 48px; display: block; text-align: center;" align="center">
                                        <h1 style="margin: 0; text-align: center; font-size: 30px; line-height: 40px; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; font-style: normal; font-weight: 400; color: #fff;">
                                            Hi <?= $h($firstName) ?>, thanks for choosing LiberiaAirportShuttle.com!
                                        </h1>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td align="center" valign="top">
                            <table border="0" cellpadding="0" cellspacing="0" width="600" id="template_body">
                                <tr>
                                    <td valign="top" id="body_content" style="background-color: #fff;" bgcolor="#fff">
                                        <table border="0" cellpadding="20" cellspacing="0" width="100%">
                                            <tr>
                                                <td valign="top" style="padding: 0px 48px 0;">
                                                    <div style="color: #747474; text-align: left; font-size: 14px; line-height: 24px; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; font-weight: 400;" align="left">

                                                        <p style="margin: 0 0 16px;">Your reservation has been completed.</p>

                                                        <p style="margin: 0 0 16px;"><b>Where do I find my driver?</b><br>
                                                            Your driver will be <u>outside of customs area</u> holding a sign with your name on it.<br>
                                                            <u>If</u> having trouble finding the driver, please contact us and head to the outside of the Imperial/Britt café (in between Arrivals and Departures) to meet him there.
                                                        </p>

                                                        <p style="margin: 0 0 16px;"><b>Pick-up time at the airport</b><br>
                                                            Your pickup at the airport is based on the arrival time of your flight (s); however, the process of immigration, baggage claim and customs takes time, so your driver will be waiting for you.
                                                        </p>

                                                        <p style="margin: 0 0 16px;"><b>Flight tracking</b><br>
                                                            We monitor your flight in real time through <a href="https://www.flightradar24.com/data/airports/lir" style="font-weight: normal; text-decoration: underline; color: #4a2a4d;">flightradar24.com</a>, so we will be aware of any changes in your flight (delay, diversion, cancellation, etc.).
                                                        </p>

                                                        <p style="margin: 0 0 16px;"><b>Return to LIR Airport</b><br>
                                                            A reminder will be sent to your email the day before your departure date.
                                                        </p>

                                                        <p style="margin: 0 0 16px;"><b>Gratuity</b><br>
                                                            It is customary to tip between 15% and 20% of the transportation rate.
                                                        </p>

                                                        <p style="margin: 0 0 16px;">Payment is made to your driver in USD or Costa Rican Colones upon completion of each service.</p>

                                                        <div style="clear: both; height: 1px;" height="1"></div>

                                                        <h2 style="display: block; margin: 5px 0 25px 0; font-size: 16px; line-height: 26px; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; font-style: normal; font-weight: 500; color: #4a2a4d; text-align: center;">
                                                            Order <?= $h($displayId) ?> (<?= $h($dateCreated) ?>)
                                                        </h2>

                                                        <div style="margin-bottom: 40px;">
                                                            <table cellspacing="0" cellpadding="6" width="100%" border="1" style="color: #747474; border: 1px solid #e5e5e5; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; background-color: #fff; width: 100%;" bgcolor="#fff">
                                                                <thead>
                                                                <tr>
                                                                    <th style="color: #747474; border: 1px solid #e5e5e5; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" align="left">Product</th>
                                                                    <th style="color: #747474; border: 1px solid #e5e5e5; width: 0px; display: block; overflow: hidden; padding: 0;" width="0" align="left">Quantity</th>
                                                                    <th style="color: #747474; border: 1px solid #e5e5e5; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" align="left">Price</th>
                                                                </tr>
                                                                </thead>
                                                                <tbody>
                                                                <?= $rowsHtml ?>
                                                                </tbody>
                                                                <tfoot>
                                                                <tr>
                                                                    <th style="color: #747474; border: 1px solid #e5e5e5; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" colspan="2" align="left">Subtotal:</th>
                                                                    <td style="color: #747474; border: 1px solid #e5e5e5; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" align="left">
                                                                        <span class="woocommerce-Price-amount amount"><?= $h($fmtMoney($displaySubtotal)) ?></span>
                                                                    </td>
                                                                </tr>
                                                                <tr>
                                                                    <th style="color: #747474; border: 1px solid #e5e5e5; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" colspan="2" align="left">Payment method:</th>
                                                                    <td style="color: #747474; border: 1px solid #e5e5e5; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" align="left"><?= $h($paymentMethod) ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th style="color: #747474; border: 1px solid #e5e5e5; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" colspan="2" align="left">Total:</th>
                                                                    <td style="color: #747474; border: 1px solid #e5e5e5; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; padding: 12px; text-align: left;" align="left">
                                                                        <span class="woocommerce-Price-amount amount"><?= $h($fmtMoney($displayTotal)) ?></span>
                                                                    </td>
                                                                </tr>
                                                                <?= $noteRow ?>
                                                                </tfoot>
                                                            </table>
                                                        </div>

                                                        <p style="margin: 0 0 16px;"><strong>Contact info:</strong></p>
                                                        <?php if ($telefono): ?>
                                                            <p style="margin: 0 0 16px;"><strong>Phone:</strong> <?= $h($telefono) ?></p>
                                                        <?php endif; ?>
                                                        <?php if ($emailCliente): ?>
                                                            <p style="margin: 0 0 16px;"><strong>Email:</strong> <?= $h($emailCliente) ?></p>
                                                        <?php endif; ?>

                                                        <p style="margin: 0 0 16px; text-align: center;" align="center">Thank you for booking with us!</p>

                                                        <p style="margin: 0 0 16px; text-align: center;" align="center">
                                                            <a href="https://liberiaairportshuttle.com/" style="font-weight: normal; text-decoration: underline; color: #4a2a4d;">LiberiaAirportShuttle.com</a><br>
                                                            <a href="mailto:booking@liberiaairportshuttle.com" style="font-weight: normal; text-decoration: underline; color: #4a2a4d;">booking@liberiaairportshuttle.com</a><br>
                                                            Phone: <a href="tel:+50689131480" style="font-weight: normal; text-decoration: underline; color: #4a2a4d;">+506 8913-1480</a><br>
                                                            WhatsApp: <a href="http://wa.me/50689131480" style="font-weight: normal; text-decoration: underline; color: #4a2a4d;">+506 8913-1480</a><br>
                                                            Telegram: <a href="http://t.me/liberiaairport" style="font-weight: normal; text-decoration: underline; color: #4a2a4d;">+506 8913-1480</a>
                                                        </p>

                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" valign="top">
                            <table border="0" cellpadding="10" cellspacing="0" width="600" id="template_footer">
                                <tr>
                                    <td valign="top" id="template_footer_inside" style="padding: 0 48px 31px 48px;">
                                        <table border="0" cellpadding="10" cellspacing="0" width="100%">
                                            <tr>
                                                <td colspan="2" valign="middle" style="text-align: center; font-size: 12px; font-family: 'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif; font-weight: 400; color: #9e9e9e; padding-top: 20px;" align="center">
                                                    <p style="color: #9e9e9e;">Liberia Airport Shuttle | Costa Rica Transfers</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
