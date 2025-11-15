<?php
$message = $message ?? 'Contenuto non disponibile.';
?>
<section class="portal-section">
    <div class="portal-empty portal-empty--emphasis">
        <h2>Ops!</h2>
        <p><?= htmlspecialchars($message) ?></p>
        <a class="portal-button portal-button--primary" href="index.php">Torna alla dashboard</a>
    </div>
</section>
