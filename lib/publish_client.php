<?php
/**
 * Tech4TIME — pushing content to the public site.
 *
 * BACKEND ONLY. The public site holds lib/publish.php, which is the format;
 * this is the half that sends. It is not on the frontend deliberately — the
 * public site has no business making outbound signed requests, and the split
 * exists so that each side ships only what it needs.
 *
 * WHEN IT RUNS
 * On every save, straight after the backend has written its own record. The
 * backend's copy is the system of record and is written first: if the push
 * then fails, the edit is safe here and can be sent again. Publishing first
 * would mean a live site ahead of the record it is supposed to replicate.
 *
 * WHEN IT FAILS
 * The editor says so, plainly, with a Publish again control. Never a silent
 * gap — a save that appeared to work and did not reach the site is the one
 * failure nobody investigates, because nothing asked them to.
 *
 * tools/reconcile.py sends everything that is behind, out of band. It exists
 * for the case where nobody was watching: the push failed, the tab was closed,
 * and the two have disagreed ever since.
 *
 * WHERE IT SENDS
 * PUBLISH_ENDPOINT below, or $T4T_PUBLISH_URL when it is set — which is how
 * the tests point it at a local server, and how a staging frontend could be
 * fed without editing code.
 *
 * Not reachable over HTTP: lib/ is outside this host's document root.
 */

declare(strict_types=1);

require_once __DIR__ . '/publish.php';

/** The live site's endpoint. Overridden by $T4T_PUBLISH_URL. */
const PUBLISH_ENDPOINT = 'https://tech4time.bd/api/publish.php';

/** Seconds to wait for the connection, and for the whole exchange. */
const PUBLISH_CONNECT_TIMEOUT = 5;
const PUBLISH_TIMEOUT = 15;

function publish_endpoint(): string
{
    $url = trim((string)(getenv('T4T_PUBLISH_URL') ?: ($_SERVER['T4T_PUBLISH_URL'] ?? '')));

    return $url !== '' ? $url : PUBLISH_ENDPOINT;
}

/**
 * Send one document. Returns what the editor should show.
 *
 *   ['ok' => true,  'revision' => 12, 'footer_synced' => '…']
 *   ['ok' => false, 'code' => 'not-newer', 'error' => '…', 'revision' => 12]
 *
 * Never throws for a network problem: an unreachable site is a thing to report
 * in the editor, not a stack trace over a form somebody has just filled in.
 * It does throw when this host is misconfigured — no key, no private store —
 * because that is not a transient failure and should not be reported as one.
 */
function publish_push(string $document, array $data): array
{
    if (!in_array($document, CONTRACT_DOCUMENTS, true)) {
        throw new RuntimeException('Unknown document: ' . $document);
    }

    $problem = publish_problem();
    if ($problem !== '') {
        return ['ok' => false, 'code' => 'not-configured', 'error' => $problem];
    }

    $body      = publish_body(publish_envelope($document, $data));
    $timestamp = time();

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        PUBLISH_TIMESTAMP_HEADER . ': ' . $timestamp,
        PUBLISH_SIGNATURE_HEADER . ': ' . publish_sign($body, $timestamp),
    ];

    [$status, $answer, $transport] = function_exists('curl_init')
        ? publish_post_curl(publish_endpoint(), $headers, $body)
        : publish_post_stream(publish_endpoint(), $headers, $body);

    if ($transport !== '') {
        return [
            'ok'    => false,
            'code'  => 'unreachable',
            'error' => 'The live site could not be reached: ' . $transport,
        ];
    }

    $decoded = json_decode($answer, true);

    if (!is_array($decoded)) {
        /* A 200 that is not JSON is almost always a host error page or an
           interstitial, and saying which status it carried is what tells the
           difference between "the endpoint is missing" and "the endpoint
           broke". */
        return [
            'ok'    => false,
            'code'  => 'bad-answer',
            'error' => 'The live site answered ' . $status . ' with something that '
                     . 'was not JSON. Check that ' . publish_endpoint()
                     . ' is deployed.',
        ];
    }

    if (($decoded['ok'] ?? false) === true) {
        return [
            'ok'            => true,
            'revision'      => (int)($decoded['revision'] ?? 0),
            'footer_synced' => (string)($decoded['footer_synced'] ?? ''),
        ];
    }

    $code = (string)($decoded['code'] ?? 'refused');

    return [
        'ok'       => false,
        'code'     => $code,
        'error'    => (string)($decoded['error'] ?? publish_reason($code)),
        'revision' => isset($decoded['revision']) ? (int)$decoded['revision'] : null,
        'status'   => $status,
    ];
}

/**
 * POST with curl. Returns [status, body, transport error].
 *
 * The certificate is verified, and there is no option here to turn that off.
 * The whole point of the signature is that the payload cannot be forged; the
 * whole point of TLS is that the content of an edit is nobody else's business
 * on the way. Turning off verification is how "it would not connect" gets
 * fixed at the cost of both.
 */
function publish_post_curl(string $url, array $headers, string $body): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => PUBLISH_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => PUBLISH_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        /* Not followed. A redirect on this route means something is in front
           of the endpoint that should not be, and following it would post a
           signed document wherever it pointed. */
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $answer = curl_exec($ch);
    $error  = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return $answer === false || $error !== ''
        ? [0, '', $error !== '' ? $error : 'the request failed']
        : [$status, (string)$answer, ''];
}

/** The same, without curl. Some PHP builds do not have it. */
function publish_post_stream(string $url, array $headers, string $body): array
{
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => PUBLISH_TIMEOUT,
            'ignore_errors' => true,   /* read the body of a 4xx, do not throw */
            'follow_location' => 0,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $answer = @file_get_contents($url, false, $context);

    if ($answer === false) {
        $last = error_get_last();
        return [0, '', trim((string)($last['message'] ?? 'the request failed'))];
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
        }
    }

    return [$status, (string)$answer, ''];
}
