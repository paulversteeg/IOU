<?php
/**
 * Gedeelde paginakop. Verwacht dat de aanroepende pagina al $page_title
 * heeft gezet, en (als de pagina aan een evenement hangt) $event vóór
 * het includen.
 */
$page_title = $page_title ?? 'IOU';
$flash = flash_get();
$heeft_event = isset($event);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?></title>
    <meta name="description" content="IOU - deel eenvoudig gezamenlijke uitgaven met vrienden.">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💰</text></svg>">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<a href="#main" class="skip-link">Ga naar hoofdinhoud</a>

<div class="topbar">
    <a href="<?= $heeft_event ? e(event_url('index.php', $event)) : 'start.php' ?>" class="brand">
        <span aria-hidden="true">💰</span> IOU<?= $heeft_event ? ' · ' . e($event['naam']) : '' ?>
    </a>
    <?php if ($heeft_event): ?>
        <nav aria-label="Hoofdnavigatie">
            <a href="<?= e(event_url('index.php', $event)) ?>">Uitgaven</a>
            <a href="<?= e(event_url('deelnemers.php', $event)) ?>">Deelnemers</a>
        </nav>
    <?php endif; ?>
</div>

<?php if ($heeft_event): ?>
    <div class="container" style="padding-top: 14px; margin-bottom: -14px;">
        <details class="share-box">
            <summary>🔗 Deel-link van dit evenement</summary>
            <p class="hint" style="margin: 8px 0;">Stuur deze link naar de groep. Iedereen die hem opent kan uitgaven en deelnemers toevoegen &mdash; er is geen account nodig.</p>
            <div class="actions">
                <input type="text" readonly value="<?= e(huidige_basis_url() . '/' . event_url('index.php', $event)) ?>"
                       onclick="this.select()" aria-label="Deel-link van dit evenement" style="flex: 1 1 260px;">
                <button type="button" class="btn-secondary"
                        onclick="var i=this.previousElementSibling;i.select();try{navigator.clipboard.writeText(i.value);this.textContent='Gekopieerd!';}catch(e){document.execCommand('copy');}">
                    Kopieer link
                </button>
            </div>
        </details>
    </div>
<?php endif; ?>

<main class="container" id="main">
    <?php if ($flash): ?>
        <p class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p>
    <?php endif; ?>
