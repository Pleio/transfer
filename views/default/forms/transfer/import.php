<?php 
$import = elgg_extract("import", $vars);
?>

<?php echo $import; ?>
<input type="hidden" name="id" value="<?php echo $import; ?>">
<?php echo elgg_view("input/button", [
    "type" => "submit",
    "value" => elgg_echo("transfer:import")
]); ?>
