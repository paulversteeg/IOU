<?php
/**
 * Staging only: included at the top of connection.php on
 * staging-iou.waxine.nl (deploy.sh adds it; live never has it).
 *
 * - Search engines are told not to index staging.
 * - Every HTML page gets a staging bar at the top.
 *
 * Access is closed with a Plesk password-protected directory on the
 * staging web root (IOU has no login of its own).
 */
if (PHP_SAPI === 'cli') {
    return;
}

header('X-Robots-Tag: noindex, nofollow');

ob_start(function (string $html): string {
    if (stripos($html, '<body') === false) {
        return $html;
    }
    $bar = '<div role="note" style="position:sticky;top:0;z-index:10000;margin:0;padding:.55rem 1rem;'
         . 'background:#8a3b12;color:#fff;font:600 15px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;text-align:center">'
         . 'Staging: alles wat je hier invoert wordt elke nacht overschreven door een kopie van de live site '
         . '(<a href="https://iou.waxine.nl/" style="color:#fff;text-decoration:underline">iou.waxine.nl</a>).'
         . '</div>';
    return preg_replace('/<body\b[^>]*>/i', '$0' . $bar, $html, 1) ?? $html;
});
