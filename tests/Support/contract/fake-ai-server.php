<?php
// php -S 127.0.0.1:8012 tests/Support/contract/fake-ai-server.php — the AI provider for a contract run against a live
// server: CLASSIFIER_ANTHROPIC_BASE_URL=http://127.0.0.1:8012/v1 (or CLASSIFIER_PROVIDER=gemini with
// CLASSIFIER_GEMINI_BASE_URL=http://127.0.0.1:8012/v1beta). Answers any path, in the request's format, like tests/Support/FakeAi.php.
require __DIR__.'/../../../vendor/autoload.php';

[$status, $body] = Tests\Support\FakeAi::respond(json_decode(file_get_contents('php://input'), true) ?? []);
http_response_code($status);
header('Content-Type: application/json');
echo json_encode($body, JSON_UNESCAPED_UNICODE);
