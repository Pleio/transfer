<?php
set_time_limit(0);

$guids = get_input("guids");

if (!$guids) {
    register_error(elgg_echo("transfer:export:no_groups"));
    forward(REFERER);
}

$export = new TransferExport();
foreach ($guids as $guid) {
    $entity = get_entity($guid);

    if (!$entity->canEdit()) {
        continue;
    }

    if ($entity instanceof ElggGroup) {
        $export->addGroup($entity);
    } elseif ($entity instanceof ElggSite) {
        $export->addSite($entity);
    }
}

$export->finish();

system_message(elgg_echo("transfer:export:done"));