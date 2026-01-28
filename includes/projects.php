<?php
// XML-based storage for projects

function enlil_projects_xml_path(): string {
    $config = require __DIR__ . '/config.php';
    return $config['projects_xml'];
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
