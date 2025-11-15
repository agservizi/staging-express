<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $providers
 * @var array{success:bool, inserted?:int, errors?:array<int, string>, message?:string}|null $feedback
 */
$pageTitle = 'Import ICCID';
?>
<section class="page">
    <header class="page__header">
        <h2>Import ICCID</h2>
        <p>Carica un file CSV con ICCID (19-20 cifre) e note opzionali.</p>
    </header>

    <?php if ($feedback !== null): ?>
        <div class="alert <?= ($feedback['success'] ?? false) ? 'alert--success' : 'alert--error' ?>">
            <p><?= htmlspecialchars($feedback['message'] ?? ($feedback['success'] ? 'Operazione completata.' : 'Errore durante l\'import.')) ?></p>
            <?php if (!empty($feedback['errors'])): ?>
                <ul>
                    <?php foreach ($feedback['errors'] as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (($feedback['inserted'] ?? 0) > 0): ?>
                <p class="alert__detail">Inseriti: <?= (int) $feedback['inserted'] ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form">
        <div class="form__group">
            <label for="provider_id">Operatore</label>
            <select name="provider_id" id="provider_id" required>
                <option value="">Seleziona</option>
                <?php foreach ($providers as $provider): ?>
                    <option value="<?= (int) $provider['id'] ?>"><?= htmlspecialchars((string) $provider['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form__group">
            <label for="csv">File CSV</label>
            <input type="file" name="csv" id="csv" accept=".csv" required>
            <small>Formato: ICCID,Note (header opzionale)</small>
        </div>
        <button type="submit" class="btn btn--primary">Importa</button>
    </form>

    <section class="page__section">
        <h3>Template CSV</h3>
        <p>Scarica l'esempio: <a href="../iccid_example.csv" download>iccid_example.csv</a></p>
    </section>
</section>
