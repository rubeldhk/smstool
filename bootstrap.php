<?php
session_start();

const BASE_PATH = __DIR__;
const STORAGE_PATH = BASE_PATH . '/storage';

require_once BASE_PATH . '/src/helpers.php';

$env = load_env(BASE_PATH . '/.env');

if (!file_exists(STORAGE_PATH)) {
    mkdir(STORAGE_PATH, 0775, true);
}
