<?php
elgg_register_event_handler('init', 'system', 'transfer_init');

function transfer_init() {
    elgg_register_admin_menu_item('administer', 'transfer', 'administer_utilities');

    elgg_register_action('transfer/export', dirname(__FILE__) . '/actions/export.php', 'admin');
    elgg_register_action('transfort/import', dirname(__FILE__) . '/actions/import.php', 'admin');

}

function transfer_get_groups() {
    $groups = [];
    foreach (elgg_get_entities(["type" => "group", "limit" => 0]) as $group) {
        $groups[$group->name] = $group->guid;
    }

    return $groups;
}