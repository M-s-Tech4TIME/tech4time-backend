<?php
/**
 * Tech4TIME — where the backend's content arrives.
 *
 * FRONTEND ONLY. The public site's one inbound endpoint, and the only thing on
 * this host that writes content/.
 *
 * The admin at admin.tech4time.bd owns the content. On save it writes its own
 * record and posts a signed copy here; this verifies it, re-sanitises it and
 * writes the replica the two dynamic pages render from. Nothing here ever
 * calls the backend — docs/90-decisions/0010-backend-pushes-content.md.
 *
 * THE URL IS /api/publish.php, WITH THE EXTENSION
 * ADR 0010 wrote it as /api/publish. It is the plain path instead, so that the
 * one route content travels over does not depend on a rewrite rule: .htaccess
 * is not read by the local dev server, would have to be reproduced on any
 * server this moves to, and is the single file most likely to arrive damaged
 * or not at all. A path that is just a file works everywhere, unchanged.
 *
 * WHAT IT REFUSES, AND WHY EACH ONE IS SEPARATE — see lib/publish.php.
 * In short: the signature says who, the timestamp says when, the revision
 * stops a replay from rolling the site backwards, and the contract version
 * stops a document being written in a shape this side would mis-render.
 *
 * IT RE-SANITISES WHAT IT IS SENT.
 * A signature proves origin, not safety. If the admin host were ever
 * compromised, the public site should still not render script — so every rich
 * field goes back through this side's own lib/html.php before it is written.
 *
 * WHAT IT ANSWERS
 *   200  {"ok":true,  "revision":12, "footer_synced":"<sha256>"}
 *   4xx  {"ok":false, "code":"not-newer", "revision":12}
 *
 * The current revision is in every answer the caller is entitled to, so the
 * backend and tools/reconcile.py learn this side's state from any attempt and
 * need no second endpoint to ask. It is withheld until the signature has
 * verified: a stranger gets the refusal and nothing about what is here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/publish.php';
require_once __DIR__ . '/../lib/careers.php';
require_once __DIR__ . '/../lib/contact.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/** Answer and stop. $revision is omitted until the caller has proved itself. */
function publish_answer(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

function publish_refuse(int $status, string $code, ?int $revision = null): never
{
    $body = ['ok' => false, 'code' => $code, 'error' => publish_reason($code)];

    if ($revision !== null) {
        $body['revision'] = $revision;
    }

    publish_answer($status, $body);
}

/* ------------------------------------------------------------ the request */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    publish_refuse(405, 'method-not-allowed');
}

/* Before reading the body, not after. Content-Length is the sender's claim and
   is checked again below against what actually arrived, because a claim is not
   a measurement. */
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > PUBLISH_MAX_BYTES) {
    publish_refuse(413, 'too-large');
}

$body = (string)file_get_contents('php://input', false, null, 0, PUBLISH_MAX_BYTES + 1);

if (strlen($body) > PUBLISH_MAX_BYTES) {
    publish_refuse(413, 'too-large');
}

/* The store or the key is missing. Said plainly rather than as a signature
   failure, because the two send whoever is reading the admin's error to
   completely different places. */
if (publish_problem() !== '') {
    publish_refuse(503, 'not-configured');
}

$fault = publish_verify(
    $body,
    (string)($_SERVER['HTTP_X_T4T_SIGNATURE'] ?? ''),
    (string)($_SERVER['HTTP_X_T4T_TIMESTAMP'] ?? '')
);

if ($fault !== '') {
    publish_refuse(401, $fault);
}

/* ---------------------------------------------------------- the envelope */

/* Decoded only now. Everything above ran against the raw bytes on purpose: the
   signature covers what arrived, and verifying a re-encoding of the decoded
   value would be verifying a different document. */
$envelope = json_decode($body, true);

$fault = publish_check_envelope($envelope);
if ($fault !== '') {
    publish_refuse($fault === 'contract-mismatch' ? 422 : 400, $fault);
}

$document = (string)$envelope['document'];
$file     = $document === 'careers' ? CAREERS_FILE : CONTACT_FILE;
$current  = $document === 'careers' ? careers_load() : contact_load();
$held     = (int)($current['revision'] ?? 0);

/* Strictly newer. A replayed request inside the five-minute window is signed
   perfectly well and carries a revision this side already holds — so it stops
   here, as a no-op, instead of restoring whatever the site said then. */
if ((int)$envelope['revision'] <= $held) {
    publish_refuse(409, 'not-newer', $held);
}

/* ------------------------------------------------------------- the write */

$incoming = $document === 'careers'
    ? careers_normalise($envelope['data'])
    : contact_normalise($envelope['data']);

$incoming = contract_sanitise($document, $incoming);

/* The revision is taken from the envelope rather than from the document, so
   that what was checked above is what is written. They were proved equal by
   publish_check_envelope(). */
$incoming['revision'] = (int)$envelope['revision'];

if (!store_write($file, $incoming)) {
    publish_refuse(500, 'write-failed', $held);
}

/* What this side's footers currently say. The backend records it and its
   editor compares — see lib/footer-fingerprint.php. Absent only if
   tools/sync_site_contact.py has never been run here. */
$stamp = __DIR__ . '/../lib/footer-fingerprint.php';
if (is_file($stamp)) {
    require_once $stamp;
}

publish_answer(200, [
    'ok'            => true,
    'document'      => $document,
    'revision'      => (int)$envelope['revision'],
    'footer_synced' => defined('FOOTER_FINGERPRINT') ? FOOTER_FINGERPRINT : '',
]);
