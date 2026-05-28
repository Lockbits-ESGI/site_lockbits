<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ─── Limite quotidienne ───────────────────────────────────────────
$counterFile = __DIR__ . '/usage_count.txt';
$maxRequests = 100; // max requêtes par jour

$data = file_exists($counterFile)
    ? json_decode(file_get_contents($counterFile), true)
    : [];

$today = date('Y-m-d');
if (($data['date'] ?? '') !== $today) {
    $data = ['date' => $today, 'count' => 0];
}

if ($data['count'] >= $maxRequests) {
    echo json_encode(['reply' => "Le service est temporairement indisponible. Réessayez demain."]);
    exit;
}

$data['count']++;
file_put_contents($counterFile, json_encode($data));
// ─────────────────────────────────────────────────────────────────

$body        = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($body['message']) ? trim($body['message']) : '';
$history     = isset($body['history']) ? $body['history'] : [];

if (empty($userMessage)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message requis']);
    exit;
}

// ─── Contexte entreprise ─────────────────────────────────────────
$systemPrompt = "Tu es l'assistant virtuel de LockBits, une entreprise spécialisée dans l'hébergement sécurisé et la cybersécurité. Réponds toujours en français sauf si le client s'adresse à toi en anglais.

=== INFORMATIONS SUR L'ENTREPRISE ===
- Nom : LockBits
- Services : Hébergement haute disponibilité, protection cybersécurité, infrastructure cloud sécurisée
- SLA uptime : 99.99%
- Monitoring SOC : 24/7
- Temps de réponse aux incidents : < 5 minutes
- Certifications : ISO 27001 Ready, EU Data Compliance, Zero Trust Architecture
- Support humain 24/7 disponible

=== DONNÉES DU DASHBOARD ===
- Tentatives DDoS bloquées : 1 248
- Vulnérabilités critiques ouvertes : 0
- Incidents résolus : 100%

=== INSTRUCTIONS ===
- Sois professionnel, concis et rassurant
- Si on te demande un récapitulatif du dashboard, résume les 3 métriques ci-dessus clairement
- Pour les questions techniques complexes ou les devis, invite le client à contacter l'équipe
- Ne réponds qu'aux questions concernant LockBits et la cybersécurité
- Ne donne jamais d'informations confidentielles sur l'infrastructure interne";
// ─────────────────────────────────────────────────────────────────

$messages = [];
foreach ($history as $msg) {
    if (isset($msg['role'], $msg['content'])) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
}
$messages[] = ['role' => 'user', 'content' => $userMessage];

$payload = [
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => 500,
    'system'     => $systemPrompt,
    'messages'   => $messages,
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur API', 'details' => $response]);
    exit;
}

$decoded = json_decode($response, true);
$reply   = $decoded['content'][0]['text'] ?? 'Désolé, une erreur est survenue.';

echo json_encode(['reply' => $reply]);
