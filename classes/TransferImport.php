<?php

class TransferImport {
    function __construct($id) {
        $id = preg_replace('/[^0-9\-\._]/','', $id);
        $this->path = $this->generateImportPath($id);

        $this->site_guids = [];
        $this->group_guids = [];
        $this->create_folder_relationships = [];
        $this->create_folder_parent_guids = [];

        $this->translate_user_guids = [];
        $this->translate_object_guids = [];

        $this->translate_group_guids = [];
        $this->translate_group_acls = [];
        $this->open_group_guids = [];
    }

    private function generateImportPath($id) {
        $site = elgg_get_site_entity();
        $parameters = get_default_filestore()->getParameters();
        return "{$parameters['dir_root']}import/{$site->guid}/{$id}/";
    }

    function start() {
        $this->importUsers();

        $this->importSites();
        $this->importGroups();

        foreach ($this->site_guids as $guid) {
            $this->importContent($guid);
        }

        foreach ($this->group_guids as $guid) {
            $this->importContent($guid);
        }

        $this->importFolderRelations();
        $this->importFolderParentGuids();
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

            $guid = register_user($this->generateUniqueUsername($row->username), generate_random_cleartext_password(), $row->name, $row->email);
            $this->translate_user_guids[$row->guid] = $guid;

            if (elgg_is_active_plugin("pleio") && $row->pleio_guid) {
                update_data("UPDATE elgg_users_entity SET pleio_guid = {$row->pleio_guid} WHERE guid={$guid}");
            }
        }
    }

    function importSites() {
        $site = elgg_get_site_entity();

        foreach ($this->getData("sites.json") as $row) {
            $this->translate_site_guids[$row->guid] = $site->guid;
            $this->site_guids[] = $row->guid;
        }
    }

    function importGroups() {
        $fields = ["name", "description", "tags"];

        foreach ($this->getData("groups.json") as $row) {
            $group = null;

            if ($row->existing_guid) {
                $group = get_entity($row->existing_guid);
            }

            if (!$group) {
                $group = new ElggGroup();

                foreach ($fields as $field) {
                    $group->$field = $row->$field;
                }

                if (!$this->translate_user_guids[$row->owner_guid]) {
                    throw new Exception("Could not find the translation of owner_guid {$row->owner_guid}.");
                }

                $group->owner_guid = $this->translate_user_guids[$row->owner_guid];
                $group->membership = $row->is_open == 1 ? ACCESS_PUBLIC : ACCESS_PRIVATE;

                $guid = $group->save();

                $group->time_created = $row->time_created;
                $group->time_updated = $row->time_updated;
                $group->access_id = 2;

                $group->save();

                $this->copyIcons("groups", $row->guid, $group);
            }

            $acl = $group->group_acl;

            foreach ($row->members as $member_guid) {
                join_group($group->guid, $this->translate_user_guids[$member_guid]);
            }

            $this->translate_group_guids[$row->guid] = $guid;
            $this->translate_group_acls[$row->guid] = $acl;

            $this->group_guids[] = $row->guid;

            if ($group->membership === ACCESS_PUBLIC) {
                $this->open_group_guids[] = $row->guid;
            }
        }
    }

    function importContent($guid) {
        $fields = ["subtype", "title", "description", "tags"];

        foreach ($this->getData("content_{$guid}.json") as $row) {
            $object = new ElggObject();
            foreach ($fields as $field) {
                $object->$field = $row->$field;
            }

            if (!$this->translate_user_guids[$row->owner_guid]) {
                echo ("Could not find the translation of owner_guid {$row->owner_guid}, skipping {$row->guid}.") . PHP_EOL;
                continue;
            }

            $object->owner_guid = $this->translate_user_guids[$row->owner_guid];

            if ($row->container_guid) {
                if (!$this->translate_group_guids[$row->container_guid]) {
                    echo ("Could not find the translation of container_guid {$row->container_guid}.") . PHP_EOL;
                    continue;
                }

                $object->container_guid = $this->translate_group_guids[$row->container_guid];
            }

            if (in_array($row->container_guid, $this->open_group_guids)) {
                $object->access_id = get_default_access();
            } else {
                $object->access_id = $this->translate_group_acls[$row->container_guid];
            }

            $guid = $object->save();

            $object->time_created = $row->time_created;
            $object->time_updated = $row->time_updated;
            $object->save();

            $this->translate_object_guids[$row->guid] = $guid;

            switch ($row->subtype) {
                case "file":
                    if ($row->parent_guid) {
                        $this->create_folder_relationships[] = [$row->parent_guid, 'folder_of', $guid];
                    }

                    $object->mimetype = $row->mimetype;
                    $object->simpletype = $row->simpletype;
                    $object->filename = $row->filename;
                    $object->originalfilename = $row->originalfilename;
                    $object->save();

                    $file = get_entity($object->guid);
                    $info = pathinfo($file->getFilenameOnFilestore());

                    if (file_exists("{$this->path}files/{$row->guid}.{$info['extension']}")) {
                        $file->open("write");
                        $file->close();
                        copy("{$this->path}files/{$row->guid}.{$info['extension']}", $file->getFilenameOnFilestore());
                    }

                    $this->copyIcons("file", $row->guid, $object);

                    break;
                case "folder":
                    if ($row->parent_guid) {
                        $this->create_folder_parent_guids[] = [$row->parent_guid, 'folder_of', $guid];
                    } else {
                        $object->parent_guid = 0;
                        $object->save();
                    }
                    break;
                case "blog":
                    $this->copyIcons("blogs", $row->guid, $object);
                    break;
                case "event":
                    $object->start_day = $row->start_day;
                    $object->start_time = $row->start_time;
                    $object->end_ts = $row->end_ts;
                    $object->save();

                    foreach ($row->attendees as $attendee) {
                        if ($this->translate_user_guids[$attendee->user_guid]) {
                            add_entity_relationship($object->guid, "event_{$attendee->status}", $this->translate_user_guids[$attendee->user_guid]);
                        }
                    }

                    $this->importComments($object, $row->comments);
                    break;
                case "question":
                    $object->status = $row->status;
                    $this->importComments($object, $row->comments);
                    break;
                case "news":
                case "discussion":
                    $this->importComments($object, $row->comments);
                    break;
            }
        }
    }

    function importFolderRelations() {
        foreach ($this->create_folder_relationships as $relationship) {
            if (!$this->translate_object_guids[$relationship[0]]) {
                throw new Exception("Could not find the translation of parent_guid {$relationship[0]}");
            }

            add_entity_relationship($this->translate_object_guids[$relationship[0]], "folder_of", $relationship[2]);
        }
    }

    function importFolderParentGuids() {
        foreach ($this->create_folder_parent_guids as $relationship) {
            if (!$this->translate_object_guids[$relationship[0]]) {
                throw new Exception("Could not find the translation of parent_guid {$relationship[0]}");
            }

            $folder = get_entity($relationship[2]);
            $folder->parent_guid = $this->translate_object_guids[$relationship[0]];
        }
    }

    function importComments($entity, $comments) {
        $dbprefix = elgg_get_config("dbprefix");

        foreach ($comments as $comment) {
            if (!$this->translate_user_guids[$comment->owner_guid]) {
                echo ("Could not find the translation of owner_guid {$comment->owner_guid}." . PHP_EOL);
                continue;
            }

            if ($entity->getSubtype() == "groupforumtopic") {
                $annotation_id = create_annotation($entity->guid, "group_topic_post", $comment->description, '', $this->translate_user_guids[$comment->owner_guid], $entity->access_id);
                $annotation = elgg_get_annotation_from_id($annotation_id);

                $time_created = (int) $comment->time_created;
                update_data("UPDATE {$dbprefix}annotations SET time_created = {$time_created} WHERE id = {$annotation->id}");
            } else if ($entity->getSubtype() == "file") {
                $annotation_id = create_annotation($entity->guid, "generic_comment", $comment->description, '', $this->translate_user_guids[$comment->owner_guid], $entity->access_id);
                $annotation = elgg_get_annotation_from_id($annotation_id);

                $time_created = (int) $comment->time_created;
                update_data("UPDATE {$dbprefix}annotations SET time_created = {$time_created} WHERE id = {$annotation->id}");
            } else {
                $object = new ElggObject();

                if ($entity->getSubtype() == "question") {
                    $object->subtype = "answer";
                } else {
                    $object->subtype = "comment";
                }

                $object->description = $comment->description;

                $object->owner_guid = $this->translate_user_guids[$comment->owner_guid];
                $object->container_guid = $entity->guid;
                $object->access_id = $entity->access_id;

                $guid = $object->save();

                $object->time_created = $comment->time_created;
                $object->time_updated = $comment->time_updated;
                $object->save();

                if ($comment->correct_answer) {
                    add_entity_relationship($entity->guid, "correctAnswer", $object->guid);
                }
            }
        }
    }

    private function copyIcons($namespace, $old_guid, $new_entity) {
        $filehandler = new ElggFile();
        $filehandler->owner_guid = $new_entity->owner_guid;

        foreach (['large', 'medium', 'small', 'tiny', 'master', 'topbar'] as $size) {
            if (!file_exists("{$this->path}/icons/{$old_guid}{$size}.jpg")) {
                continue;
            }

            $filehandler->setFilename("{$namespace}/{$new_entity->guid}{$size}.jpg");
            $filehandler->open("write");
            $filehandler->close();

            copy("{$this->path}/icons/{$old_guid}{$size}.jpg", $filehandler->getFilenameOnFilestore());
        }

    }

    private function getData($filename) {
        $handle = fopen($this->path . $filename, "r");

        while (($line = fgets($handle)) !== false) {
            yield json_decode($line);
        }

        fclose($handle);
    }

    private function generateUniqueUsername($username) {
        $username = preg_replace("/[^a-zA-Z0-9]+/", "", $username);

        while (strlen($username) < 4) {
            $username .= "0";
        }

        $hidden = access_get_show_hidden_status();
        access_show_hidden_entities(true);

        if (get_user_by_username($username)) {
            $i = 1;

            while (get_user_by_username($username . $i)) {
                $i++;
            }

            $result = $username . $i;
        } else {
            $result = $username;
        }

        access_show_hidden_entities($hidden);

        return $result;
    }
}
