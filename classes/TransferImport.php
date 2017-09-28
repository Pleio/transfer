<?php

class TransferImport {
    function __construct($id) {
        $id = preg_replace('/[^0-9\-\._]/','', $id);
        $this->path = $this->generateImportPath($id);

        $this->group_guids = [];
        $this->translate_user_guids = [];
        $this->translate_group_guids = [];
    }

    private function generateImportPath($id) {
        $site = elgg_get_site_entity();
        $parameters = get_default_filestore()->getParameters();
        return "{$parameters['dir_root']}import/{$site->guid}/{$id}/";
    }

    function start() {
        $this->importUsers();
        $this->importGroups();

        foreach ($this->group_guids as $guid) {
            $this->importContent($guid);
        }
    }

    function importUsers() {
        foreach ($this->getData("users.json") as $row) {
            $user = get_user_by_email($row->email);
            if ($user) {
                $this->translate_user_guids[$row->guid] = $user[0]->guid;
                continue;
            }

            $user = get_user_by_username($row->username);
            if ($user) {
                $this->translate_user_guids[$row->guid] = $user->guid;
                continue;
            }

            $guid = register_user($row->username, generate_random_cleartext_password(), $row->name, $row->email);
            $this->translate_user_guids[$row->guid] = $guid;
        }
    }

    function importGroups() {
        $fields = ["name", "description"];
        
        foreach ($this->getData("groups.json") as $row) {
            $group = new ElggGroup();

            foreach ($fields as $field) {
                $group->$field = $row->$field;
            }

            if (!$this->translate_user_guids[$row->owner_guid]) {
                throw new Exception("Could not find the translation of owner_guid {$row->owner_guid}.");
            }

            $group->owner_guid = $this->translate_user_guids[$row->owner_guid];
            $guid = $group->save();

            $group->time_created = $row->time_created;
            $group->time_updated = $row->time_updated;
            $group->save();

            foreach ($row->members as $member_guid) {
                join_group($group->guid, $this->translate_user_guids[$member_guid]);
            }

            $this->translate_group_guids[$row->guid] = $guid;
            $this->group_guids[] = $row->guid;
        }
    }

    function importContent($guid) {
        $fields = ["subtype", "title", "description"];

        $guid = (int) $guid;
        foreach ($this->getData("content_{$guid}.json") as $row) {
            $object = new ElggObject();
            foreach ($fields as $field) {
                $object->$field = $row->$field;
            }

            if (!$this->translate_user_guids[$row->owner_guid]) {
                throw new Exception("Could not find the translation of owner_guid {$row->owner_guid}.");
            }

            if (!$this->translate_group_guids[$row->container_guid]) {
                throw new Exception("Could not find the translation of container_guid {$row->container_guid}.");   
            }

            $object->owner_guid = $this->translate_user_guids[$row->owner_guid];
            $object->container_guid = $this->translate_group_guids[$row->container_guid];

            $guid = $object->save();

            $object->time_created = $row->time_created;
            $object->time_updated = $row->time_updated;
            $object->save();

            switch ($subtype) {
                case "file":
                    break;
                case "blog":
                case "news":
                case "question":
                case "discussion":
                    $this->importComments($guid, $row->comments);
            }
        }
    }

    function importComments($object_guid, $comments) {
        foreach ($comments as $comment) {
            $object = new ElggObject();
            $object->subtype = "comment";
            $object->description = $comment->description; 

            if (!$this->translate_user_guids[$comment->owner_guid]) {
                throw new Exception("Could not find the translation of owner_guid {$comment->owner_guid}.");
            }

            $object->owner_guid = $this->translate_user_guids[$comment->owner_guid];
            $object->container_guid = $object_guid;
            $guid = $object->save();

            $object->time_created = $comment->time_created;
            $object->time_updated = $comment->time_updated;
            $object->save();
        }
    }

    private function getData($filename) {
        $handle = fopen($this->path . $filename, "r");

        while (($line = fgets($handle)) !== false) {
            yield json_decode($line);
        }

        fclose($handle);
    }
}