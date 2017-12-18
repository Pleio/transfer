<?php
$site = elgg_get_site_entity();
?>
<div>
    <b>Site</b>
    <ul>
        <li>
            <label>
                <?php echo elgg_view("input/checkbox", [
                    "name" => "guids[]",
                    "value" => $site->guid,
                    "default" => false
                ]); ?>
                <?php echo $site->name; ?>
            </label>
        </li>
    </ul>
</div>

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