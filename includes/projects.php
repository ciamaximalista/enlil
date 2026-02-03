<?php
// XML-based storage for projects

function enlil_projects_xml_path(): string {
    $config = require __DIR__ . '/config.php';
    return $config['projects_xml'];
}

function enlil_projects_save_xml(SimpleXMLElement $xml): void {
    $path = enlil_projects_xml_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }
    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de proyectos.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de proyectos.');
    }
    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    rename($tempPath, $path);
    chmod($path, 0660);
}

function enlil_projects_all(): array {
    $path = enlil_projects_xml_path();
    if (!file_exists($path)) {
        return [];
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return [];
    }

    $projects = [];
    foreach ($xml->project as $project) {
        $teamIds = [];
        if (isset($project->teams)) {
            foreach ($project->teams->team_id as $teamId) {
                $teamIds[] = (int)$teamId;
            }
        }
        $projects[] = [
            'id' => (int)$project['id'],
            'name' => (string)$project->name,
            'description' => (string)$project->description,
            'created_at' => (string)$project->created_at,
            'team_ids' => $teamIds,
        ];
    }

    return $projects;
}

function enlil_projects_get(int $projectId): ?array {
    $path = enlil_projects_xml_path();
    if (!file_exists($path)) {
        return null;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return null;
    }

    foreach ($xml->project as $project) {
        if ((int)$project['id'] !== $projectId) {
            continue;
        }
        $teamIds = [];
        if (isset($project->teams)) {
            foreach ($project->teams->team_id as $teamId) {
                $teamIds[] = (int)$teamId;
            }
        }
        $objectives = [];
        if (isset($project->objectives)) {
            foreach ($project->objectives->objective as $objective) {
                $depends = [];
                if (isset($objective->depends_on)) {
                    foreach ($objective->depends_on->objective_id as $depId) {
                        $depends[] = (int)$depId;
                    }
                }
                $tasks = [];
                if (isset($objective->tasks)) {
                    foreach ($objective->tasks->task as $task) {
                        $taskDepends = [];
                        if (isset($task->depends_on)) {
                            foreach ($task->depends_on->task_id as $depId) {
                                $taskDepends[] = (int)$depId;
                            }
                        }
                        $responsibleIds = [];
                        if (isset($task->responsibles)) {
                            foreach ($task->responsibles->person_id as $personId) {
                                $responsibleIds[] = (int)$personId;
                            }
                        }
                        $tasks[] = [
                            'id' => (int)$task['id'],
                            'name' => (string)$task->name,
                            'due_date' => (string)$task->due_date,
                            'status' => (string)$task->status,
                            'completed_at' => isset($task->completed_at) ? (string)$task->completed_at : '',
                            'recurrence' => isset($task->recurrence) ? (string)$task->recurrence : '',
                            'parent_id' => isset($task->parent_id) ? (int)$task->parent_id : 0,
                            'depends_on' => $taskDepends,
                            'responsible_ids' => $responsibleIds,
                        ];
                    }
                }
                $objectives[] = [
                    'id' => (int)$objective['id'],
                    'name' => (string)$objective->name,
                    'due_date' => (string)$objective->due_date,
                    'depends_on' => $depends,
                    'tasks' => $tasks,
                ];
            }
        }
        return [
            'id' => (int)$project['id'],
            'name' => (string)$project->name,
            'description' => (string)$project->description,
            'created_at' => (string)$project->created_at,
            'team_ids' => $teamIds,
            'objectives' => $objectives,
        ];
    }

    return null;
}

function enlil_projects_add(string $name, string $description, array $teamIds): void {
    $path = enlil_projects_xml_path();
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    if (file_exists($path)) {
        $xml = @simplexml_load_file($path);
        if (!$xml) {
            throw new RuntimeException('No se pudo leer el XML de proyectos.');
        }
    } else {
        $xml = new SimpleXMLElement('<projects></projects>');
    }

    $maxId = 0;
    foreach ($xml->project as $project) {
        $id = (int)$project['id'];
        if ($id > $maxId) {
            $maxId = $id;
        }
    }

    $newId = $maxId + 1;
    $node = $xml->addChild('project');
    $node->addAttribute('id', (string)$newId);
    $node->addChild('name', $name);
    $node->addChild('description', $description);
    $node->addChild('created_at', date('c'));
    if ($teamIds) {
        $teamsNode = $node->addChild('teams');
        foreach ($teamIds as $teamId) {
            $teamsNode->addChild('team_id', (string)$teamId);
        }
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de proyectos.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de proyectos.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
}

function enlil_projects_delete(int $id): bool {
    $path = enlil_projects_xml_path();
    if (!file_exists($path)) {
        return false;
    }

    $xml = @simplexml_load_file($path);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer el XML de proyectos.');
    }

    $index = 0;
    $deleted = false;
    foreach ($xml->project as $project) {
        if ((int)$project['id'] === $id) {
            unset($xml->project[$index]);
            $deleted = true;
            break;
        }
        $index++;
    }

    if (!$deleted) {
        return false;
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de proyectos.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de proyectos.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}

function enlil_projects_update(int $projectId, string $name, string $description, array $teamIds, array $objectives): void {
    $path = enlil_projects_xml_path();
    if (!file_exists($path)) {
        throw new RuntimeException('No se pudo leer el XML de proyectos.');
    }
    $xml = @simplexml_load_file($path);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer el XML de proyectos.');
    }

    $target = null;
    foreach ($xml->project as $project) {
        if ((int)$project['id'] === $projectId) {
            $target = $project;
            break;
        }
    }
    if (!$target) {
        throw new RuntimeException('Proyecto no encontrado.');
    }

    $target->name = $name;
    $target->description = $description;

    if (isset($target->teams)) {
        unset($target->teams);
    }
    if ($teamIds) {
        $teamsNode = $target->addChild('teams');
        foreach ($teamIds as $teamId) {
            $teamsNode->addChild('team_id', (string)$teamId);
        }
    }

    if (isset($target->objectives)) {
        unset($target->objectives);
    }
    if ($objectives) {
        $objectivesNode = $target->addChild('objectives');
        foreach ($objectives as $objective) {
            $objNode = $objectivesNode->addChild('objective');
            $objNode->addAttribute('id', (string)$objective['id']);
            $objNode->addChild('name', $objective['name']);
            $objNode->addChild('due_date', $objective['due_date']);
            if (!empty($objective['depends_on'])) {
                $depsNode = $objNode->addChild('depends_on');
                foreach ($objective['depends_on'] as $depId) {
                    $depsNode->addChild('objective_id', (string)$depId);
                }
            }
            if (!empty($objective['tasks'])) {
                $tasksNode = $objNode->addChild('tasks');
                foreach ($objective['tasks'] as $task) {
                    $taskNode = $tasksNode->addChild('task');
                    $taskNode->addAttribute('id', (string)$task['id']);
                    $taskNode->addChild('name', $task['name']);
                    $taskNode->addChild('due_date', $task['due_date']);
                    $taskNode->addChild('status', $task['status']);
                    if (!empty($task['recurrence'])) {
                        $taskNode->addChild('recurrence', $task['recurrence']);
                    }
                    if (!empty($task['parent_id'])) {
                        $taskNode->addChild('parent_id', (string)$task['parent_id']);
                    }
                    if (!empty($task['completed_at'])) {
                        $taskNode->addChild('completed_at', $task['completed_at']);
                    }
                    if (!empty($task['depends_on'])) {
                        $depsNode = $taskNode->addChild('depends_on');
                        foreach ($task['depends_on'] as $depId) {
                            $depsNode->addChild('task_id', (string)$depId);
                        }
                    }
                    if (!empty($task['responsible_ids'])) {
                        $respNode = $taskNode->addChild('responsibles');
                        foreach ($task['responsible_ids'] as $personId) {
                            $respNode->addChild('person_id', (string)$personId);
                        }
                    }
                }
            }
        }
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new RuntimeException('No se pudo crear el XML temporal de proyectos.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('No se pudo bloquear el XML temporal de proyectos.');
    }

    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tempPath, $path);
    chmod($path, 0660);
}

function enlil_projects_mark_task_done(int $projectId, int $objectiveId, int $taskId, string $completedAt): bool {
    $path = enlil_projects_xml_path();
    if (!file_exists($path)) {
        return false;
    }
    $xml = @simplexml_load_file($path);
    if (!$xml) {
        return false;
    }

    $updated = false;
    foreach ($xml->project as $project) {
        if ($projectId !== 0 && (int)$project['id'] !== $projectId) {
            continue;
        }
        if (!isset($project->objectives)) {
            continue;
        }
        foreach ($project->objectives->objective as $objective) {
            if ($objectiveId !== 0 && (int)$objective['id'] !== $objectiveId) {
                continue;
            }
            if (!isset($objective->tasks)) {
                continue;
            }
            foreach ($objective->tasks->task as $task) {
                if ((int)$task['id'] !== $taskId) {
                    continue;
                }
                if ((string)$task->status !== 'done') {
                    $task->status = 'done';
                    if (!isset($task->completed_at) || (string)$task->completed_at === '') {
                        $task->addChild('completed_at', $completedAt);
                    }
                }
                $updated = true;
                break 3;
            }
        }
    }

    if (!$updated) {
        return false;
    }

    $tempPath = $path . '.tmp';
    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    fwrite($fp, $xml->asXML());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    rename($tempPath, $path);
    chmod($path, 0660);
    return true;
}

function enlil_projects_mark_task_pending(int $projectId, int $objectiveId, int $taskId): bool {
    $path = enlil_projects_xml_path();
    if (!file_exists($path)) {
        return false;
    }
    $xml = simplexml_load_file($path);
    if (!$xml) {
        return false;
    }
    foreach ($xml->project as $project) {
        if ($projectId !== 0 && (int)$project['id'] !== $projectId) {
            continue;
        }
        if (!isset($project->objectives)) {
            continue;
        }
        foreach ($project->objectives->objective as $objective) {
            if ($objectiveId !== 0 && (int)$objective['id'] !== $objectiveId) {
                continue;
            }
            if (!isset($objective->tasks)) {
                continue;
            }
            foreach ($objective->tasks->task as $task) {
                if ((int)$task['id'] !== $taskId) {
                    continue;
                }
                $task->status = 'pending';
                if (isset($task->completed_at)) {
                    $task->completed_at = '';
                }
                return enlil_projects_save_xml($xml);
            }
        }
    }
    return false;
}

function enlil_projects_mark_task_by_id_for_person(int $taskId, int $personId, string $status, string $completedAt = ''): int {
    if ($taskId <= 0) {
        return 0;
    }
    $path = enlil_projects_xml_path();
    if (!file_exists($path)) {
        return 0;
    }
    $xml = simplexml_load_file($path);
    if (!$xml) {
        return 0;
    }
    $updated = 0;
    foreach ($xml->project as $project) {
        if (!isset($project->objectives)) {
            continue;
        }
        foreach ($project->objectives->objective as $objective) {
            if (!isset($objective->tasks)) {
                continue;
            }
            foreach ($objective->tasks->task as $task) {
                if ((int)$task['id'] !== $taskId) {
                    continue;
                }
                if ($personId > 0) {
                    $responsibles = [];
                    if (isset($task->responsibles)) {
                        foreach ($task->responsibles->person_id as $pid) {
                            $responsibles[] = (int)$pid;
                        }
                    }
                    if ($responsibles && !in_array($personId, $responsibles, true)) {
                        continue;
                    }
                }
                $task->status = $status;
                if ($status === 'done') {
                    if (!isset($task->completed_at) || (string)$task->completed_at === '') {
                        $task->addChild('completed_at', $completedAt !== '' ? $completedAt : date('c'));
                    }
                } else {
                    if (isset($task->completed_at)) {
                        $task->completed_at = '';
                    }
                }
                $updated++;
            }
        }
    }
    if ($updated > 0) {
        enlil_projects_save_xml($xml);
    }
    return $updated;
}

function enlil_projects_mark_task_by_id_in_project(int $projectId, int $taskId, string $status, string $completedAt = ''): int {
    if ($projectId <= 0 || $taskId <= 0) {
        return 0;
    }
    $path = enlil_projects_xml_path();
    if (!file_exists($path)) {
        return 0;
    }
    $xml = simplexml_load_file($path);
    if (!$xml) {
        return 0;
    }
    $updated = 0;
    foreach ($xml->project as $project) {
        if ((int)$project['id'] !== $projectId) {
            continue;
        }
        foreach ($project->objectives->objective as $objective) {
            if (!isset($objective->tasks)) {
                continue;
            }
            foreach ($objective->tasks->task as $task) {
                if ((int)$task['id'] !== $taskId) {
                    continue;
                }
                $task->status = $status;
                if ($status === 'done') {
                    if (!isset($task->completed_at) || (string)$task->completed_at === '') {
                        $task->addChild('completed_at', $completedAt !== '' ? $completedAt : date('c'));
                    }
                } else {
                    if (isset($task->completed_at)) {
                        $task->completed_at = '';
                    }
                }
                $updated++;
            }
        }
    }
    if ($updated > 0) {
        enlil_projects_save_xml($xml);
    }
    return $updated;
}
