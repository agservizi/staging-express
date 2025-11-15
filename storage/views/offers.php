<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $providers
 * @var array<int, array<string, mixed>> $offers
 * @var array<string, mixed>|null $editOffer
 * @var array{success:bool, errors?:array<int, string>, offer_id?:int}|null $feedback
 * @var array{page:int, per_page:int, total:int, pages:int}|null $pagination
 */

$pageTitle = 'Listini & Canvass';
$editing = $editOffer !== null;
$offerId = $editing ? (int) $editOffer['id'] : null;
$pagination = $pagination ?? ['page' => 1, 'per_page' => 7, 'total' => count($offers), 'pages' => 1];
$currentPage = (int) $pagination['page'];
$listUrl = 'index.php?page=offers' . ($currentPage > 1 ? '&page_no=' . $currentPage : '');
$buildPageUrl = static function (int $pageNo): string {
    return 'index.php?' . http_build_query([
        'page' => 'offers',
        'page_no' => $pageNo,
    ]);
};
?>
<section class="page">
    <header class="page__header">
        <h2>Listini &amp; Canvass</h2>
        <p>Gestisci le offerte degli operatori e rendile disponibili rapidamente in cassa.</p>
    </header>

    <?php if ($feedback): ?>
        <div class="alert <?= ($feedback['success'] ?? false) ? 'alert--success' : 'alert--error' ?>">
            <?php if (($feedback['success'] ?? false) === true): ?>
                <p><?= htmlspecialchars($feedback['message'] ?? 'Operazione completata.') ?></p>
            <?php endif; ?>
            <?php foreach ($feedback['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="page__section">
        <header class="section__header">
            <h3><?= $editing ? 'Modifica offerta' : 'Nuova offerta' ?></h3>
            <?php if ($editing): ?>
                <a class="btn btn--secondary" href="<?= htmlspecialchars($listUrl, ENT_QUOTES) ?>">Annulla</a>
            <?php endif; ?>
        </header>
        <form method="post" class="form">
            <input type="hidden" name="id" value="<?= $offerId ?? '' ?>">
            <input type="hidden" name="page_no" value="<?= $currentPage ?>">
            <div class="form__grid">
                <div class="form__group">
                    <label for="provider_id">Operatore</label>
                    <select name="provider_id" id="provider_id">
                        <option value="" <?= !$editing || ($editOffer['provider_id'] === null) ? 'selected' : '' ?>>Generico</option>
                        <?php foreach ($providers as $provider): ?>
                            <option value="<?= (int) $provider['id'] ?>" <?= $editing && (int) $provider['id'] === (int) ($editOffer['provider_id'] ?? 0) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $provider['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form__group">
                    <label for="title">Nome offerta *</label>
                    <input type="text" name="title" id="title" required value="<?= htmlspecialchars($editOffer['title'] ?? '') ?>">
                </div>
                <div class="form__group">
                    <label for="price">Prezzo (€)</label>
                    <input type="number" step="0.01" min="0" name="price" id="price" value="<?= htmlspecialchars((string) ($editOffer['price'] ?? '0.00')) ?>">
                </div>
                <div class="form__group">
                    <label for="status">Stato</label>
                    <select name="status" id="status">
                        <?php $currentStatus = $editing ? (string) $editOffer['status'] : 'Active'; ?>
                        <option value="Active" <?= $currentStatus === 'Active' ? 'selected' : '' ?>>Attiva</option>
                        <option value="Inactive" <?= $currentStatus === 'Inactive' ? 'selected' : '' ?>>Archiviata</option>
                    </select>
                </div>
                <div class="form__group">
                    <label for="valid_from">Inizio validità</label>
                    <input type="date" name="valid_from" id="valid_from" value="<?= htmlspecialchars((string) ($editOffer['valid_from'] ?? '')) ?>">
                </div>
                <div class="form__group">
                    <label for="valid_to">Fine validità</label>
                    <input type="date" name="valid_to" id="valid_to" value="<?= htmlspecialchars((string) ($editOffer['valid_to'] ?? '')) ?>">
                </div>
            </div>
            <div class="form__group">
                <label for="description">Descrizione</label>
                <textarea name="description" id="description" rows="3" placeholder="Dettagli e note opzionali"><?= htmlspecialchars($editOffer['description'] ?? '') ?></textarea>
            </div>
            <footer class="form__footer">
                <button type="submit" name="action" value="save" class="btn btn--primary">Salva offerta</button>
            </footer>
        </form>
    </section>

    <section class="page__section">
        <header class="section__header">
            <h3>Elenco offerte</h3>
        </header>

        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Operatore</th>
                        <th>Offerta</th>
                        <th>Prezzo</th>
                        <th>Validità</th>
                        <th>Stato</th>
                        <th>Aggiornata</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($offers === []): ?>
                        <tr>
                            <td colspan="7">Nessuna offerta configurata.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($offers as $offer): ?>
                        <tr>
                            <td><?= htmlspecialchars($offer['provider_name'] ?? 'Generico') ?></td>
                            <td>
                                <strong><?= htmlspecialchars((string) $offer['title']) ?></strong><br>
                                <small><?= nl2br(htmlspecialchars((string) ($offer['description'] ?? ''))) ?></small>
                            </td>
                            <td><?= number_format((float) $offer['price'], 2) ?> €</td>
                            <td>
                                <?php if ($offer['valid_from'] || $offer['valid_to']): ?>
                                    <span><?= htmlspecialchars($offer['valid_from'] ?? '—') ?> → <?= htmlspecialchars($offer['valid_to'] ?? '—') ?></span>
                                <?php else: ?>
                                    <span>—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge--<?= $offer['status'] === 'Active' ? 'success' : 'muted' ?>">
                                    <?= $offer['status'] === 'Active' ? 'Attiva' : 'Archiviata' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string) $offer['updated_at']) ?></td>
                            <td>
                                <form method="post" class="table-actions">
                                    <a class="btn btn--secondary" href="<?= htmlspecialchars($buildPageUrl($currentPage) . '&edit=' . (int) $offer['id'], ENT_QUOTES) ?>">Modifica</a>
                                    <input type="hidden" name="id" value="<?= (int) $offer['id'] ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="status" value="<?= $offer['status'] === 'Active' ? 'Inactive' : 'Active' ?>">
                                    <input type="hidden" name="page_no" value="<?= $currentPage ?>">
                                    <button type="submit" class="btn btn--secondary">
                                        <?= $offer['status'] === 'Active' ? 'Archivia' : 'Riattiva' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pagination['pages'] > 1): ?>
            <nav class="pagination">
                <?php $current = $currentPage; ?>
                <a class="pagination__link <?= $current === 1 ? 'is-disabled' : '' ?>" href="<?= $current === 1 ? '#' : $buildPageUrl(1) ?>">«</a>
                <a class="pagination__link <?= $current === 1 ? 'is-disabled' : '' ?>" href="<?= $current === 1 ? '#' : $buildPageUrl($current - 1) ?>">‹</a>
                <span class="pagination__info">Pagina <?= $current ?> di <?= (int) $pagination['pages'] ?> (<?= (int) $pagination['total'] ?> risultati)</span>
                <a class="pagination__link <?= $current === (int) $pagination['pages'] ? 'is-disabled' : '' ?>" href="<?= $current === (int) $pagination['pages'] ? '#' : $buildPageUrl($current + 1) ?>">›</a>
                <a class="pagination__link <?= $current === (int) $pagination['pages'] ? 'is-disabled' : '' ?>" href="<?= $current === (int) $pagination['pages'] ? '#' : $buildPageUrl((int) $pagination['pages']) ?>">»</a>
            </nav>
        <?php endif; ?>
    </section>
</section>
