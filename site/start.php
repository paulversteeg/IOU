<?php
require_once 'connection.php';
require_once 'includes/functions.php';
require_once 'includes/event.php';
require_once 'includes/analytics.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_bezoek($conn, null, null, 'start');
}

$form_errors = [];
$naam_evenement = '';
$nieuwe_link = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_event'])) {
    verify_csrf();

    $naam_evenement = trim($_POST['naam_evenement'] ?? '');
    if ($naam_evenement === '') {
        $form_errors['naam_evenement'] = 'Geef je evenement een naam, bijv. "Weekend Ardennen".';
    } elseif (mb_strlen($naam_evenement) > 150) {
        $form_errors['naam_evenement'] = 'Maximaal 150 tekens.';
    }

    if (empty($form_errors)) {
        do {
            $token = nieuw_event_token();
            $stmt = $conn->prepare('SELECT id FROM Events WHERE token=?');
            $stmt->execute([$token]);
        } while ($stmt->fetch());

        $stmt = $conn->prepare('INSERT INTO Events (naam, token) VALUES (?, ?)');
        $stmt->execute([$naam_evenement, $token]);

        header('Location: deelnemers.php?e=' . $token . '&nieuw=1');
        exit;
    }
}

$page_title = 'IOU - Nieuw evenement starten';
require 'includes/header.php';
?>

    <h1>Nieuw evenement starten</h1>
    <p class="subtitle">
        Voor elk weekend of event maak je hier een eigen linkje aan. Deel dat linkje met de groep &mdash;
        iedereen kan er zelf uitgaven en (hun eigen) bankrekening aan toevoegen, los van andere evenementen.
    </p>

    <div class="box accent">
        <h2>➕ Nieuw evenement</h2>
        <?php if (!empty($form_errors)): ?>
            <p class="flash error" role="alert">Controleer de gemarkeerde velden hieronder.</p>
        <?php endif; ?>
        <form method="POST" action="start.php" novalidate>
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="naam_evenement">Naam van dit evenement</label>
                <input type="text" id="naam_evenement" name="naam_evenement" maxlength="150"
                       required aria-required="true" autofocus
                       value="<?= e($naam_evenement) ?>" placeholder="Bijv. Weekend Ardennen 2026"
                       <?= isset($form_errors['naam_evenement']) ? 'aria-invalid="true" aria-describedby="naam-evenement-error"' : '' ?>>
                <?php if (isset($form_errors['naam_evenement'])): ?>
                    <p class="field-error" id="naam-evenement-error"><?= e($form_errors['naam_evenement']) ?></p>
                <?php endif; ?>
            </div>
            <button type="submit" name="start_event" value="1" class="btn">Evenement aanmaken &amp; linkje krijgen</button>
        </form>
    </div>

    <div class="box">
        <h2>Al een linkje ontvangen?</h2>
        <p>Vraag de organisator om de link naar het evenement (bijv. via WhatsApp) en open die rechtstreeks &mdash;
           er is geen apart wachtwoord of account nodig.</p>
    </div>

<?php require 'includes/footer.php'; ?>
