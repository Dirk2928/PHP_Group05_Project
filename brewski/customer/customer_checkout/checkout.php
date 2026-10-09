<?php

$query = $_SERVER['QUERY_STRING'] ?? '';
$destination = '../checkout/checkout.php' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $destination, true, 302);
exit;
