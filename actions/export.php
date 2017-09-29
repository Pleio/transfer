<?php
set_time_limit(0);

$guids = get_input("guids");

if (!$guids) {
    register_error(elgg_echo("transfer:export:no_groups"));
    forward(REFERER);
}

$export = new TransferExport();
foreach ($guids as $guid) {
    $group = get_entity($guid);
    $export->addGroup($group);
}

$export->finish();

system_message(elgg_echo("transfer:export:done"));

exit();