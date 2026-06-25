<?php

require_once __DIR__ . '/knowledge_helper.php';

try {
    $env = loadEnv();
    $response = createQdrantCollection($env);

    echo "HTTP: {$response['status']}\n";
    echo $response['body'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
