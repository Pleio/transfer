<div>
    <b>Groepen</b>
    <?php echo elgg_view("input/checkboxes", [
        "name" => "guids",
        "options" => transfer_get_groups()
    ]); ?>
</div>

<?php echo elgg_view("input/button", [
    "type" => "submit",
    "value" => elgg_echo("transfer:export")
]); ?>