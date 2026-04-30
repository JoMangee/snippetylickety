<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (isset($_GET['action']) && $_GET['action'] === 'health') {
    echo "Operational";
    exit;
}

echo "Bridge Home";