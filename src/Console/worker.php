<?php

use Clicalmani\Queue\Worker;

$lockFile = sys_get_temp_dir() . '/tonka_queue_jobs_worker.lock';
$fp = fopen($lockFile, 'w+');

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    logger()->error("A queue worker is already running.");
    exit(1);
}

// ── CLI Options ─────────────────────────────────────────────────────────────
$options = getopt("", ["queues:"]);

$root = dirname(__DIR__, 4);
include_once $root . '/vendor/autoload.php';

$app = require_once $root . '/bootstrap/app.php';
$app->config->set('database', require_once config_path('/database.php'));
$app->boot();

// ── Schedule — retrieve all tasks ───────────────────────────────────────────
$manager = container()->get('queue');

$worker = new Worker(
    $manager, 
    sleep: $options['sleep'],
    maxMemory: $options['memory'],
    timeout: $options['timeout'],
    once: $options['once'] ?? false,
    stopWhenEmpty: $options['stop-when-empty'] ?? false,
    lockFile: sys_get_temp_dir() . '/tonka_queue_jobs_worker.lock'
);
$worker->run($options['queues'] ?? ['default']);

flock($fp, LOCK_UN);
fclose($fp);