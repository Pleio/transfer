<?php

class TransferImport {
    function __construct($id) {
        $id = preg_replace('/[^0-9\-\._]/','', $id);
        $this->path = $this->generateImportPath($id);

        $this->group_guids = [];
        $this->create_folder_relationships = [];
        $this->create_folder_parent_guids = [];

        $this->translate_user_guids = [];
        $this->translate_group_guids = [];
        $this->translate_object_guids = [];
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

            $guid = register_user($row->username, generate_random_cleartext_password(), $row->name, $row->email);
            $this->translate_user_guids[$row->guid] = $guid;

            if (elgg_is_active_plugin("pleio") && $row->pleio_guid) {
                update_data("UPDATE elgg_users_entity SET pleio_guid = {$row->pleio_guid} WHERE guid={$guid}");
            }
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
            $group->membership = $row->is_open == 1 ? ACCESS_PUBLIC : ACCESS_PRIVATE;

            $guid = $group->save();

            $group->time_created = $row->time_created;
            $group->time_updated = $row->time_updated;
            $group->save();

            $this->copyIcons("groups", $row->guid, $group);

            foreach ($row->members as $member_guid) {
                join_group($group->guid, $this->translate_user_guids[$member_guid]);
            }

            $this->translate_group_guids[$row->guid] = $guid;
            $this->group_guids[] = $row->guid;
        }
    }

    function importContent($guid) {
        $fields = ["subtype", "title", "description", "tags"];

        $guid = (int) $guid;
        foreach ($this->getData("content_{$guid}.json") as $row) {
            $object = new ElggObject();
            foreach ($fields as $field) {
                $object->$field = $row->$field;
            }

            if ($row->subtype == "discussion") {
                $object->subtype = "groupforumtopic";
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
                case "news":
                case "question":
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
                throw new Exception("Could not find the translation of owner_guid {$comment->owner_guid}.");
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
                $guid = $object->save();

                $object->time_created = $comment->time_created;
                $object->time_updated = $comment->time_updated;
                $object->save();
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
}