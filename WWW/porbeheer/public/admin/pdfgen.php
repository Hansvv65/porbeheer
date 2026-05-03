<?php
// Laad de Composer autoloader – pad relatief t.o.v. dit script
require '../../../libs/porbeheer/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
// Gebruik een standaard PDF-lettertype - geen extern bestand nodig
$options->set('defaultFont', 'Helvetica');
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Lorem Ipsum A4 PDF</title>
    <style>
        body {
            font-family: Helvetica, sans-serif;
            margin: 2cm;
            line-height: 1.4;
        }
        h1 { color: #0f3b5c; border-bottom: 1px solid #ccc; }
        p { text-align: justify; margin-bottom: 10px; }
        .footer {
            position: fixed;
            bottom: 1cm;
            left: 0;
            right: 0;
            font-size: 10px;
            text-align: center;
            color: #888;
        }
    </style>
</head>
<body>
    <h1>Lorem Ipsum - A4 PDF</h1>
    <p>Gegenereerd: ' . date('d-m-Y H:i:s') . '</p>
    <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed non risus. Suspendisse lectus tortor, dignissim sit amet, adipiscing nec, ultricies sed, dolor. Cras elementum ultrices diam. Maecenas ligula massa, varius a, semper congue, euismod non, mi.</p>
    <p>Ut in risus volutpat libero pharetra tempor. Cras vestibulum bibendum augue. Praesent egestas leo in pede. Praesent blandit odio eu enim. Pellentesque sed dui ut augue blandit sodales.</p>
    <p>Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Aliquam nibh. Mauris ac mauris sed pede pellentesque fermentum.</p>
    <div class="footer">Pagina {PAGE_NUM} van {PAGE_COUNT}</div>
</body>
</html>
HTML;

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("lorem_ipsum.pdf", ["Attachment" => false]);
?>