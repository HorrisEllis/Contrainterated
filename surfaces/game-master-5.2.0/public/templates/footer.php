<?php
/**
 * footer.php — shared page footer
 *
 * Variables expected before include:
 *   $scripts   string[]   Optional array of extra <script src> paths to append
 */
$scripts = $scripts ?? [];
?>
<?php foreach ($scripts as $src): ?>
    <script src="<?= htmlspecialchars($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
