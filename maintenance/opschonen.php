<?php
/**
 * Opschonen: verwijdert ALLE evenementen, deelnemers en uitgaven uit de
 * database (bijv. om testdata weg te halen voordat de app echt in
 * gebruik gaat). Onomkeerbaar, dus vereist zowel een CSRF-token als het
 * expres overtypen van een bevestigingswoord — een simpele klik is niet
 * genoeg voor iets dat niet terug te draaien is.
 *
 * Kan na gebruik gewoon blijven staan (er wordt niets uitgevoerd zonder
 * de bevestiging hieronder), maar verwijder 'm gerust als je 'm niet
 * meer nodig hebt.
 */
require_once 'connection.php';
require_once 'includes/functions.php';

const BEVESTIGINGSWOORD = 'VERWIJDER';

function tabel_bestaat_os(PDO $conn, string $tabel): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$tabel]);
    return (int) $stmt->fetch()['n'] > 0;
}

function aantal(PDO $conn, string $tabel): int
{
    if (!tabel_bestaat_os($conn, $tabel)) {
        return 0;
    }
    return (int) $conn->query("SELECT COUNT(*) AS n FROM `$tabel`")->fetch()['n'];
}

$uitgevoerd = false;
$fout = null;
$verkeerd_woord = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bevestig_opschonen'])) {
    verify_csrf();

    if (trim($_POST['bevestigingswoord'] ?? '') !== BEVESTIGINGSWOORD) {
        $verkeerd_woord = true;
    } else {
        try {
            // Volgorde belangrijk voor foreign keys: eerst de "kindtabellen".
            if (tabel_bestaat_os($conn, 'Uitgaven')) {
                $conn->exec('DELETE FROM Uitgaven');
                $conn->exec('ALTER TABLE Uitgaven AUTO_INCREMENT = 1');
            }
            if (tabel_bestaat_os($conn, 'Deelnemers')) {
                $conn->exec('DELETE FROM Deelnemers');
                $conn->exec('ALTER TABLE Deelnemers AUTO_INCREMENT = 1');
            }
            if (tabel_bestaat_os($conn, 'Events')) {
                $conn->exec('DELETE FROM Events');
                $conn->exec('ALTER TABLE Events AUTO_INCREMENT = 1');
            }
            $uitgevoerd = true;
        } catch (PDOException $e) {
            $fout = $e->getMessage();
            error_log('IOU opschonen mislukt: ' . $fout);
        }
    }
}

$aantal_events = aantal($conn, 'Events');
$aantal_deelnemers = aantal($conn, 'Deelnemers');
$aantal_uitgaven = aantal($conn, 'Uitgaven');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IOU - Database opschonen</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container narrow" style="padding-top: 40px;">
    <div class="box">
        <h1>Database opschonen</h1>

        <?php if ($uitgevoerd): ?>
            <p class="flash success">Alle evenementen, deelnemers en uitgaven zijn verwijderd. De database is weer leeg en klaar voor echt gebruik.</p>
            <p><a href="start.php" class="btn" style="text-decoration:none; display:inline-block;">Nieuw evenement starten &rarr;</a></p>
        <?php elseif ($fout): ?>
            <p class="flash error">Er ging iets mis: <?= e($fout) ?>. Er is niets (verder) verwijderd &mdash; probeer het nogmaals.</p>
        <?php else: ?>
            <?php if ($verkeerd_woord): ?>
                <p class="flash error" role="alert">Dat was niet exact "<?= e(BEVESTIGINGSWOORD) ?>". Er is niets verwijderd.</p>
            <?php endif; ?>

            <p>Dit verwijdert <strong>alle</strong> evenementen, deelnemers én uitgaven uit de database:</p>
            <p class="summary-line">
                <span><strong><?= $aantal_events ?></strong> evenement<?= $aantal_events === 1 ? '' : 'en' ?></span>
                <span><strong><?= $aantal_deelnemers ?></strong> deelnemer<?= $aantal_deelnemers === 1 ? '' : 's' ?></span>
                <span><strong><?= $aantal_uitgaven ?></strong> uitgave<?= $aantal_uitgaven === 1 ? '' : 'n' ?></span>
            </p>

            <?php if ($aantal_events + $aantal_deelnemers + $aantal_uitgaven === 0): ?>
                <p class="empty-state">De database is al leeg &mdash; er is niets om op te schonen.</p>
            <?php else: ?>
                <p><strong>Dit kan niet ongedaan worden gemaakt.</strong> Typ hieronder "<?= e(BEVESTIGINGSWOORD) ?>" om te bevestigen.</p>
                <form method="POST" action="opschonen.php">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="bevestigingswoord">Typ "<?= e(BEVESTIGINGSWOORD) ?>" ter bevestiging</label>
                        <input type="text" id="bevestigingswoord" name="bevestigingswoord" required aria-required="true" autocomplete="off">
                    </div>
                    <button type="submit" name="bevestig_opschonen" value="1" class="btn"
                            style="background: var(--danger);">
                        Ja, verwijder alle evenementen, deelnemers en uitgaven
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
