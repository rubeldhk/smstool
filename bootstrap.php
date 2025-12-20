<?php
session_start();

const BASE_PATH = __DIR__;
const STORAGE_PATH = BASE_PATH . '/storage';
const DEFAULT_CSV_MAX_BYTES = 50 * 1024 * 1024; // 50 MB

require_once BASE_PATH . '/src/helpers.php';

$env = load_env(BASE_PATH . '/.env');

define('CSV_MAX_BYTES', parse_size_to_bytes(env('CSV_MAX_BYTES', (string) DEFAULT_CSV_MAX_BYTES), DEFAULT_CSV_MAX_BYTES));

if (!file_exists(STORAGE_PATH)) {
    mkdir(STORAGE_PATH, 0775, true);
}
