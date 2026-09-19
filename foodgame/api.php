<?php
ini_set('display_errors','0'); error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff');
const DATA_FILE=__DIR__.'/foodgame-data.json'; const LOCK_FILE=__DIR__.'/foodgame-data.lock'; const NOODLE_SPICE=8;
function out($v,$status=200){http_response_code($status);$j=json_encode($v,JSON_UNESCAPED_SLASHES);echo $j===false?'{