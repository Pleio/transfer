<?php echo elgg_echo("transfer:explanation"); ?>
<?php echo elgg_view_form("transfer/export"); ?>

<div>
    <h2>Exports</h2>
    <?php foreach (transfer_get_exports() as $export): ?>
        <?php echo $export; ?><br />
    <?php endforeach; ?>
</div>

<div>
    <h2>Imports</h2>
    <?php foreach (transfer_get_imports() as $import): ?>
        <?php echo elgg_view_form("transfer/import", [], ["import" => $import]); ?>
    <?php endforeach; ?>
</div>