<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ─── Limite quotidienne (partagée avec le chat public) ────────────
$counterFile = __DIR__ . '/../usage_count.txt';
$maxRequests = 100;

$countData = file_exists($counterFile)
    ? json_decode(file_get_contents($counterFile), true)
    : [];

$today = date('Y-m-d');
if (($countData['date'] ?? '') !== $today) {
    $countData = ['date' => $today, 'count' => 0];
}

if ($countData['count'] >= $maxRequests) {
    echo json_encode(['reply' => "Le service est temporairement indisponible. Réessayez demain."]);
    exit;
}

$countData['count']++;
file_put_contents($counterFile, json_encode($countData));
// ─────────────────────────────────────────────────────────────────

$body        = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($body['message'])  ? trim($body['message'])  : '';
$history     = isset($body['history'])  ? $body['history']        : [];
$pageContext = isset($body['context'])  ? trim($body['context'])  : '';
$pageName    = isset($body['page'])     ? trim($body['page'])     : 'dashboard';

if (empty($userMessage)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message requis']);
    exit;
}

// ─── Contexte dynamique selon la page ────────────────────────────
$pageDescription = match($pageName) {
    'dashboard'     => "Le client consulte son tableau de bord (dashboard).",
    'tickets'       => "Le client consulte la liste de ses tickets de support.",
    'create_ticket' => "Le client est en train de créer un nouveau ticket de support.",
    default         => "Le client est dans l'espace client LockBits.",
};

$systemPrompt = "Tu es l'assistant virtuel de LockBits intégré dans l'espace client sécurisé.
Réponds toujours en français sauf si le client s'adresse à toi en anglais.
Sois concis, professionnel et rassurant.

=== CONTEXTE DE LA PAGE ACTUELLE ===
{$pageDescription}

=== DONNÉES AFFICHÉES SUR LA PAGE ===
{$pageContext}

=== INFORMATIONS GÉNÉRALES LOCKBITS ===
- Services : Hébergement haute disponibilité, cybersécurité, infrastructure cloud
- SLA uptime : 99.99% | SOC 24/7 | Temps de réponse : < 5 min
- Certifications : ISO 27001 Ready, EU Data Compliance, Zero Trust Architecture

=== INSTRUCTIONS ===
- Fais un résumé clair et utile des données de la page si le client le demande
- Réponds aux questions sur les données affichées (tickets, statuts, alertes…)
- Pour les problèmes techniques urgents, invite à créer un ticket de support
- Ne donne jamais d'informations confidentielles sur l'infrastructure interne
- Ne réponds qu'aux sujets liés à LockBits et aux données de l'espace client";
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
