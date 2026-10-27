<?php
require_once 'connection.php';
require_once 'includes/functions.php';
require_once 'includes/event.php';
require_once 'includes/analytics.php';

$event = huidig_event($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_bezoek($conn, $event['id'], $event['naam'], 'deelnemers');
}

$form_errors = [];
$form_values = ['naam' => '', 'iban' => '', 'edit_id' => ''];

if (isset($_GET['nieuw'])) {
    flash_set('success', 'Evenement "' . $event['naam'] . '" is aangemaakt! Deel de link hierboven met je groep.');
}

// --- Eerder bekend IBAN opzoeken (vult alleen het formulier, slaat niets op) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['zoek_iban'])) {
    verify_csrf();
    $form_values['naam'] = trim($_POST['naam'] ?? '');
    $form_values['edit_id'] = $_POST['edit_id'] ?? '';
    if ($form_values['naam'] === '') {
        $form_errors['naam'] = 'Vul eerst een naam in om op te zoeken.';
    } else {
        $gevonden = laatst_bekend_iban($conn, $form_values['naam']);
        if ($gevonden !== null) {
            $form_values['iban'] = $gevonden;
            flash_set('success', 'Eerder IBAN gevonden voor "' . $form_values['naam'] . '". Controleer het en pas het zo nodig aan voor dit evenement.');
        } else {
            flash_set('error', 'Geen eerder IBAN gevonden voor "' . $form_values['naam'] . '". Vul het handmatig in (of laat leeg).');
        }
    }
}

// --- Deelnemer toevoegen of bewerken ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['opslaan_deelnemer'])) {
    verify_csrf();

    $form_values['naam'] = trim($_POST['naam'] ?? '');
    $form_values['iban'] = trim($_POST['iban'] ?? '');
    $form_values['edit_id'] = $_POST['edit_id'] ?? '';

    if ($form_values['naam'] === '') {
        $form_errors['naam'] = 'Vul een naam in.';
    } elseif (mb_strlen($form_values['naam']) > 100) {
        $form_errors['naam'] = 'Naam mag maximaal 100 tekens zijn.';
    }
    if (!is_geldig_iban($form_values['iban'])) {
        $form_errors['iban'] = 'Dit lijkt geen geldig IBAN (bijv. NL00BANK0123456789).';
    }

    if (empty($form_errors)) {
        if (!empty($form_values['edit_id'])) {
            $stmt = $conn->prepare('UPDATE Deelnemers SET naam=?, iban=? WHERE id=? AND event_id=?');
            $stmt->execute([$form_values['naam'], $form_values['iban'], $form_values['edit_id'], $event['id']]);
            flash_set('success', 'Wijziging opgeslagen.');
        } else {
            $stmt = $conn->prepare('INSERT INTO Deelnemers (event_id, naam, iban) VALUES (?, ?, ?)');
            $stmt->execute([$event['id'], $form_values['naam'], $form_values['iban']]);
            flash_set('success', 'Deelnemer toegevoegd.');
        }
        header('Location: ' . event_url('deelnemers.php', $event));
        exit;
    }
}

// --- Deelnemer verwijderen (POST + CSRF i.p.v. een kale GET-link) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verwijder'])) {
    verify_csrf();
    try {
        $stmt = $conn->prepare('DELETE FROM Deelnemers WHERE id=? AND event_id=?');
        $stmt->execute([$_POST['verwijder'], $event['id']]);
        flash_set('success', 'Deelnemer verwijderd.');
    } catch (PDOException $e) {
        flash_set('error', 'Je kunt deze persoon niet verwijderen omdat er al uitgaven aan hem/haar gekoppeld zijn.');
    }
    header('Location: ' . event_url('deelnemers.php', $event));
    exit;
}

// --- Checken of we een deelnemer aan het bewerken zijn ---
$edit_data = null;
if (isset($_GET['bewerk'])) {
    $stmt = $conn->prepare('SELECT * FROM Deelnemers WHERE id=? AND event_id=?');
    $stmt->execute([$_GET['bewerk'], $event['id']]);
    $edit_data = $stmt->fetch();
    if ($edit_data) {
        $form_values = [
            'naam'    => $edit_data['naam'],
            'iban'    => $edit_data['iban'],
            'edit_id' => $edit_data['id'],
        ];
    }
}

// --- Deelnemers van dit evenement ophalen ---
$stmt = $conn->prepare('SELECT * FROM Deelnemers WHERE event_id=? ORDER BY naam ASC');
$stmt->execute([$event['id']]);
$deelnemers = $stmt->fetchAll();

$eerdere_namen = eerder_gebruikte_namen($conn);

$page_title = 'IOU - Deelnemers - ' . $event['naam'];
require 'includes/header.php';
?>

    <h1>Deelnemers &mdash; <?= e($event['naam']) ?></h1>
    <p class="subtitle">Beheer wie er in dit evenement meedoet aan het delen van kosten.</p>

    <div class="box accent">
        <h2><?= $edit_data ? 'Deelnemer aanpassen' : 'Deelnemer toevoegen' ?></h2>
        <?php if (!empty($form_errors)): ?>
            <p class="flash error" role="alert">Controleer de gemarkeerde velden hieronder.</p>
        <?php endif; ?>
        <form method="POST" action="<?= e(event_url('deelnemers.php', $event)) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="edit_id" value="<?= e((string) $form_values['edit_id']) ?>">
            <div class="form-group">
                <label for="naam">Naam</label>
                <input type="text" id="naam" name="naam" maxlength="100" required aria-required="true"
                       value="<?= e($form_values['naam']) ?>" placeholder="Bijv. Jan" list="eerdere-namen"
                       <?= isset($form_errors['naam']) ? 'aria-invalid="true" aria-describedby="naam-error"' : '' ?>>
                <datalist id="eerdere-namen">
                    <?php foreach ($eerdere_namen as $n): ?>
                        <option value="<?= e($n) ?>">
                    <?php endforeach; ?>
                </datalist>
                <?php if (isset($form_errors['naam'])): ?>
                    <p class="field-error" id="naam-error"><?= e($form_errors['naam']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="iban">IBAN <span class="hint" style="display:inline;">(optioneel, voor overboekingen)</span></label>
                <div class="actions">
                    <input type="text" id="iban" name="iban" maxlength="50" autocapitalize="characters"
                           value="<?= e($form_values['iban']) ?>" placeholder="NL00 BANK 0123 4567 89" style="flex: 1 1 220px;"
                           <?= isset($form_errors['iban']) ? 'aria-invalid="true" aria-describedby="iban-error"' : '' ?>>
                    <button type="submit" name="zoek_iban" value="1" class="btn-secondary" style="padding: 10px 14px; border-radius: 8px;">
                        Haal eerder IBAN op
                    </button>
                </div>
                <span class="hint">Al eerder meegedaan? Vul je naam in en klik op "Haal eerder IBAN op" &mdash; je kunt het daarna nog aanpassen.</span>
                <?php if (isset($form_errors['iban'])): ?>
                    <p class="field-error" id="iban-error"><?= e($form_errors['iban']) ?></p>
                <?php endif; ?>
            </div>

            <div class="actions">
                <button type="submit" name="opslaan_deelnemer" value="1" class="btn">
                    <?= $edit_data ? 'Wijziging opslaan' : 'Toevoegen aan dit evenement' ?>
                </button>
                <?php if ($edit_data): ?>
                    <a href="<?= e(event_url('deelnemers.php', $event)) ?>" class="btn-secondary" style="padding: 10px 18px; border-radius: 8px; text-decoration: none;">Annuleren</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="box">
        <h2>Huidige groep (<?= count($deelnemers) ?> <?= count($deelnemers) === 1 ? 'persoon' : 'personen' ?>)</h2>
        <?php if (empty($deelnemers)): ?>
            <p class="empty-state">Nog niemand toegevoegd aan dit evenement.</p>
        <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Naam</th>
                <th scope="col">IBAN</th>
                <th scope="col">Acties</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($deelnemers as $d): ?>
                <tr>
                    <td data-label="Naam"><?= e($d['naam']) ?></td>
                    <td data-label="IBAN"><?= e($d['iban'] ?: '—') ?></td>
                    <td data-label="Acties">
                        <div class="actions">
                            <a href="<?= e(event_url('deelnemers.php', $event, ['bewerk' => $d['id']])) ?>" class="btn-edit"
                               aria-label="Bewerk deelnemer: <?= e($d['naam']) ?>">Bewerk</a>
                            <form method="POST" action="<?= e(event_url('deelnemers.php', $event)) ?>" style="display:inline;"
                                  onsubmit="return confirm('Deelnemer &quot;<?= e($d['naam']) ?>&quot; verwijderen?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="verwijder" value="<?= (int) $d['id'] ?>">
                                <button type="submit" class="btn-delete" aria-label="Verwijder deelnemer: <?= e($d['naam']) ?>">Verwijder</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php require 'includes/footer.php'; ?>
