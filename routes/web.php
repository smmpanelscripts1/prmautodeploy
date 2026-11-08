<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Servers\ServerCreationService;

$volumesRoot = '/peto-volumes';

$donorForEgg = static function (int $eggId) use ($volumesRoot): ?Server {
    $candidates = Server::query()
        ->where('egg_id', $eggId)
        ->whereNull('status')
        ->orderBy('id')
        ->get();

    foreach ($candidates as $server) {
        $path = $volumesRoot . '/' . $server->uuid;
        if (is_dir($path . '/game') || is_file($path . '/game/cs2.sh') || is_dir($path . '/steamapps')) {
            return $server;
        }
        // generic: non-trivial volume
        if (is_dir($path)) {
            $entries = array_diff(scandir($path) ?: [], ['.', '..', '.sftp']);
            if (count($entries) >= 2) {
                return $server;
            }
        }
    }

    return null;
};

$nextPorts = static function (Node $node, int $count, ?int $startPort): array {
    $used = Allocation::query()
        ->where('node_id', $node->id)
        ->pluck('port')
        ->map(fn ($p) => (int) $p)
        ->all();
    $usedSet = array_fill_keys($used, true);

    $port = $startPort ?: 27015;
    if ($startPort === null) {
        // pick first free from 27015 upward for game-ish defaults, else any free
        $port = 27015;
        while (isset($usedSet[$port]) && $port < 65535) {
            $port++;
        }
    }

    $ports = [];
    while (count($ports) < $count && $port < 65535) {
        if (!isset($usedSet[$port])) {
            $ports[] = $port;
            $usedSet[$port] = true;
        }
        $port++;
    }

    if (count($ports) < $count) {
        throw new RuntimeException('Not enough free ports on this node.');
    }

    return $ports;
};

$ensureAllocation = static function (Node $node, int $port): Allocation {
    $alloc = Allocation::query()->firstOrCreate(
        [
            'node_id' => $node->id,
            'ip' => '0.0.0.0',
            'port' => $port,
        ],
        [
            'ip_alias' => null,
            'server_id' => null,
        ]
    );

    if ($alloc->server_id !== null) {
        throw new RuntimeException("Port {$port} is already assigned to a server.");
    }

    return $alloc;
};

$shareFromDonor = static function (string $donorUuid, string $newUuid) use ($volumesRoot): array {
    $src = $volumesRoot . '/' . $donorUuid;
    $dst = $volumesRoot . '/' . $newUuid;

    if (!is_dir($src)) {
        throw new RuntimeException("Donor volume missing: {$src}");
    }

    // Wait for Wings volume folder (created as uid 988 — nginx cannot write into it)
    for ($w = 0; $w < 40; $w++) {
        if (is_dir($dst)) {
            break;
        }
        usleep(250000);
    }
    if (!is_dir($dst)) {
        throw new RuntimeException("New volume missing: {$dst}");
    }

    $payload = json_encode(['donor' => $donorUuid, 'target' => $newUuid]);
    $ch = curl_init('http://prm-share-helper:8099/share');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 600,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException("Share helper unreachable: {$err}");
    }

    $body = json_decode((string) $raw, true) ?: [];
    $log = is_array($body['log'] ?? null) ? $body['log'] : [trim((string) $raw)];
    if ($http >= 400 || empty($body['ok'])) {
        $msg = $body['error'] ?? implode(' | ', array_slice($log, -6));
        throw new RuntimeException("Share failed (HTTP {$http}): {$msg}");
    }

    if (!is_file("{$dst}/game/cs2.sh") && !is_dir("{$dst}/game")) {
        throw new RuntimeException('Share finished but game/ is still missing on the new volume.');
    }

    return $log;
};

Route::get('/meta', function () use ($donorForEgg) {
    $eggs = Egg::query()->orderBy('name')->get(['id', 'name', 'nest_id', 'author']);
    $nodes = Node::query()->orderBy('name')->get(['id', 'name', 'fqdn']);
    $owners = User::query()->orderBy('id')->limit(50)->get(['id', 'username', 'email']);

    $eggMeta = $eggs->map(function (Egg $egg) use ($donorForEgg) {
        $donor = $donorForEgg((int) $egg->id);
        return [
            'id' => $egg->id,
            'name' => $egg->name,
            'nest_id' => $egg->nest_id,
            'has_donor' => $donor !== null,
            'donor' => $donor ? [
                'id' => $donor->id,
                'name' => $donor->name,
                'uuid' => $donor->uuid,
                'uuidShort' => $donor->uuidShort,
            ] : null,
        ];
    })->values();

    return response()->json([
        'eggs' => $eggMeta,
        'nodes' => $nodes,
        'owners' => $owners,
        'volumes_mounted' => is_dir('/peto-volumes'),
    ]);
})->middleware(['auth', AdminAuthenticate::class]);

Route::post('/deploy', function (Request $request) use ($donorForEgg, $nextPorts, $ensureAllocation, $shareFromDonor, $volumesRoot) {
    if (!is_dir($volumesRoot)) {
        return response()->json([
            'error' => 'Panel cannot see /peto-volumes. Mount D:/PETO-FILES/volumes into the panel container.',
        ], 500);
    }

    $data = $request->validate([
        'egg_id' => 'required|integer|exists:eggs,id',
        'node_id' => 'required|integer|exists:nodes,id',
        'owner_id' => 'required|integer|exists:users,id',
        'count' => 'required|integer|min:1|max:50',
        'name_prefix' => 'required|string|min:1|max:40',
        'start_port' => 'nullable|integer|min:1024|max:65534',
        'memory' => 'nullable|integer|min:128|max:131072',
        'disk' => 'nullable|integer|min:1024|max:1048576',
        'cpu' => 'nullable|integer|min:0|max:10000',
        'map' => 'nullable|string|max:64',
        'maxplayers' => 'nullable|integer|min:1|max:128',
        'start_after' => 'nullable|boolean',
    ]);

    $egg = Egg::query()->with('variables')->findOrFail($data['egg_id']);
    $node = Node::query()->findOrFail($data['node_id']);
    $donor = $donorForEgg((int) $egg->id);

    if (!$donor) {
        return response()->json([
            'error' => 'Bu egg için paylaşılabilecek kurulu bir sunucu yok. Önce normal Create New ile bir kez kur (Steam indirmesi).',
        ], 422);
    }

    $count = (int) $data['count'];
    $ports = $nextPorts($node, $count, isset($data['start_port']) ? (int) $data['start_port'] : null);
    $image = array_values($egg->docker_images ?? ['ghcr.io/k4ryuu/cs2-egg:latest'])[0];

    $created = [];
    $errors = [];

    for ($i = 0; $i < $count; $i++) {
        $port = $ports[$i];
        $name = sprintf('%s-%02d', preg_replace('/\s+/', '-', trim($data['name_prefix'])), $i + 1);

        try {
            $alloc = $ensureAllocation($node, $port);

            $environment = [];
            foreach ($egg->variables as $variable) {
                $environment[$variable->env_variable] = (string) ($variable->default_value ?? '');
            }
            // CS2 / KitsuneLab friendly defaults when vars exist
            if (array_key_exists('ALLOW_TOKENLESS', $environment)) {
                $environment['ALLOW_TOKENLESS'] = '1';
            }
            if (array_key_exists('SRCDS_STOP_UPDATE', $environment)) {
                $environment['SRCDS_STOP_UPDATE'] = '1';
            }
            if (array_key_exists('SRCDS_VALIDATE', $environment)) {
                $environment['SRCDS_VALIDATE'] = '0';
            }
            if (array_key_exists('CUSTOM_PARAMS', $environment)) {
                // Local multi-instance: LAN + no VAC; avoids empty +sv_setsteamaccount eating +game_type
                $environment['CUSTOM_PARAMS'] = '+sv_lan 1 -insecure';
            }
            if (array_key_exists('STEAM_ACC', $environment)) {
                $environment['STEAM_ACC'] = '';
            }
            if (!empty($data['map']) && array_key_exists('SRCDS_MAP', $environment)) {
                $environment['SRCDS_MAP'] = (string) $data['map'];
            }
            if (!empty($data['maxplayers']) && array_key_exists('SRCDS_MAXPLAYERS', $environment)) {
                $environment['SRCDS_MAXPLAYERS'] = (string) ((int) $data['maxplayers']);
            }
            // Don't reinstall plugins onto shared donor tree during first boot
            foreach (['INSTALL_METAMOD', 'INSTALL_CSS', 'INSTALL_SWIFTLY', 'INSTALL_MODSHARP'] as $flag) {
                if (array_key_exists($flag, $environment)) {
                    $environment[$flag] = '0';
                }
            }

            // Empty +sv_setsteamaccount {{STEAM_ACC}} steals next arg (+game_type) → broken servers
            $startup = (string) $egg->startup;
            if ($environment['STEAM_ACC'] === '' || !array_key_exists('STEAM_ACC', $environment)) {
                $startup = preg_replace('/\s*\+sv_setsteamaccount\s*\{\{STEAM_ACC\}\}/', '', $startup) ?? $startup;
            }

            $payload = [
                'name' => $name,
                'description' => 'PRM Auto Deploy from donor ' . $donor->uuidShort,
                'owner_id' => (int) $data['owner_id'],
                'node_id' => $node->id,
                'allocation_id' => $alloc->id,
                'egg_id' => $egg->id,
                'nest_id' => $egg->nest_id,
                'memory' => (int) ($data['memory'] ?? 4096),
                'swap' => 0,
                'disk' => (int) ($data['disk'] ?? 122880),
                'io' => 500,
                'cpu' => (int) ($data['cpu'] ?? 200),
                'oom_disabled' => true,
                'startup' => $startup,
                'image' => $image,
                'database_limit' => 0,
                'allocation_limit' => 1,
                'backup_limit' => 0,
                'start_on_completion' => false,
                'skip_scripts' => true,
                'environment' => $environment,
            ];

            /** @var Server $server */
            $server = app(ServerCreationService::class)->handle($payload);

            // Wait briefly for Wings volume folder
            $dst = $volumesRoot . '/' . $server->uuid;
            for ($w = 0; $w < 20; $w++) {
                if (is_dir($dst)) {
                    break;
                }
                usleep(250000);
            }

            $shareLog = $shareFromDonor($donor->uuid, $server->uuid);

            // Mark installed
            $server->status = null;
            $server->save();

            if (!empty($data['start_after'])) {
                try {
                    app(\Pterodactyl\Repositories\Wings\DaemonPowerRepository::class)
                        ->setServer($server)
                        ->send('start');
                } catch (Throwable $e) {
                    $shareLog[] = 'start warning: ' . $e->getMessage();
                }
            }

            $created[] = [
                'id' => $server->id,
                'name' => $server->name,
                'uuidShort' => $server->uuidShort,
                'port' => $port,
                'share' => $shareLog,
            ];
        } catch (Throwable $e) {
            $errors[] = [
                'name' => $name,
                'port' => $port,
                'error' => $e->getMessage(),
            ];
        }
    }

    return response()->json([
        'donor' => [
            'id' => $donor->id,
            'name' => $donor->name,
            'uuidShort' => $donor->uuidShort,
        ],
        'created' => $created,
        'errors' => $errors,
    ]);
})->middleware(['auth', AdminAuthenticate::class]);

Route::post('/repair', function (Request $request) use ($donorForEgg, $shareFromDonor, $volumesRoot) {
    $data = $request->validate([
        'egg_id' => 'required|integer|exists:eggs,id',
        'start_after' => 'nullable|boolean',
    ]);

    $donor = $donorForEgg((int) $data['egg_id']);
    if (!$donor) {
        return response()->json(['error' => 'Donor yok.'], 422);
    }

    $servers = Server::query()
        ->where('egg_id', (int) $data['egg_id'])
        ->where('id', '!=', $donor->id)
        ->orderBy('id')
        ->get();

    $fixed = [];
    $errors = [];

    foreach ($servers as $server) {
        $game = $volumesRoot . '/' . $server->uuid . '/game/cs2.sh';
        if (is_file($game)) {
            continue;
        }
        try {
            $log = $shareFromDonor($donor->uuid, $server->uuid);
            $server->status = null;
            $server->save();
            if (!empty($data['start_after'])) {
                try {
                    app(\Pterodactyl\Repositories\Wings\DaemonPowerRepository::class)
                        ->setServer($server)
                        ->send('start');
                } catch (Throwable $e) {
                    $log[] = 'start: ' . $e->getMessage();
                }
            }
            $fixed[] = [
                'id' => $server->id,
                'name' => $server->name,
                'uuidShort' => $server->uuidShort,
                'share' => $log,
            ];
        } catch (Throwable $e) {
            $errors[] = [
                'id' => $server->id,
                'name' => $server->name,
                'error' => $e->getMessage(),
            ];
        }
    }

    return response()->json([
        'donor' => ['uuidShort' => $donor->uuidShort, 'name' => $donor->name],
        'fixed' => $fixed,
        'errors' => $errors,
    ]);
})->middleware(['auth', AdminAuthenticate::class]);
