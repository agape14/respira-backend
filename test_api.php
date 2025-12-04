<?php
$opts = [
    "http" => [
        "method" => "GET",
        "header" => "Accept: application/json",
        "ignore_errors" => true
    ]
];
$context = stream_context_create($opts);
echo file_get_contents("http://localhost:8000/api/configuracion/conformidad", false, $context);
?>
