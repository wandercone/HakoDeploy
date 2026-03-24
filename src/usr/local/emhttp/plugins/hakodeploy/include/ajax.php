<?php

declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

const PLUGIN_NAME   = 'hakodeploy';
const CFG_DIR       = '/boot/config/plugins/hakodeploy';
const CFG_FILE      = CFG_DIR . '/hakodeploy.cfg';
const TEMPLATE_DIR  = '/boot/config/plugins/dockerMan/templates-user';
const TEMPLATE_FILE = TEMPLATE_DIR . '/my-HakoFoundry.xml';
const CONTAINER     = 'HakoFoundry';

function postStr(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? null;
    return is_string($v) ? $v : $default;
}

function jsonResponse(bool $success, string $message): never
{
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

function sanitizeAppdataPath(string $raw): string
{
    $path = rtrim(trim($raw), '/');
    if ($path === '' || ! str_starts_with($path, '/mnt/') || str_contains($path, '..')) {
        return '/mnt/user/appdata/hako-foundry';
    }
    // Resolve symlinks on existing paths to prevent traversal outside /mnt
    if (file_exists($path)) {
        $real = realpath($path);
        if ($real === false || ! str_starts_with($real, '/mnt/')) {
            return '/mnt/user/appdata/hako-foundry';
        }
        return $real;
    }
    return $path;
}

/** @return list<string> */
function sanitizeDeviceList(string $raw): array
{
    $valid = [];
    foreach (array_filter(explode(',', $raw)) as $dev) {
        $dev = trim($dev);
        if (preg_match('#^/dev/(sd[a-z]+|nvme\d+n\d+|ttyACM\d+)$#', $dev)) {
            $valid[] = $dev;
        }
    }
    return $valid;
}

/** @param array<string, string> $data */
function saveConfig(array $data): void
{
    if ( ! is_dir(CFG_DIR)) {
        mkdir(CFG_DIR, 0755, true);
    }
    $lines = [];
    foreach ($data as $key => $val) {
        $escaped = str_replace('"', '\\"', (string)$val);
        $lines[] = "{$key}=\"{$escaped}\"";
    }
    file_put_contents(CFG_FILE, implode("\n", $lines) . "\n");
}

function generateSecret(): string
{
    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $secret = '';
    for ($i = 0; $i < 48; $i++) {
        $secret .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $secret;
}

/**
 * @param array<string, string> $cfg
 * @param list<string> $blockDevices
 * @param list<string> $serialDevices
 * @return list<string>
 */
function buildDockerRunArgs(array $cfg, array $blockDevices, array $serialDevices): array
{
    $port  = (int)$cfg['WEB_PORT'];
    $net   = $cfg['NETWORK'] ?? 'bridge';
    $shell = ($cfg['SHELL'] ?? 'bash') === 'sh' ? 'sh' : 'bash';
    $tag   = $cfg['IMAGE_TAG'] ?? 'latest';

    $varIni   = parse_ini_file('/var/local/emhttp/var.ini') ?: [];
    $timezone = $varIni['timeZone'] ?? 'UTC';
    $hostname = $varIni['NAME']     ?? gethostname();

    $webui = 'http://[IP]:[PORT:' . $port . ']/';
    $icon  = 'https://raw.githubusercontent.com/HakoForge/HakoFoundry/main/assets/images/icon.png';

    $args = [
        'docker', 'run', '-d',
        '--name', CONTAINER,
        '--net', $net,
        '--pids-limit', '2048',
        '-e', 'TZ=' . $timezone,
        '-e', 'HOST_OS=Unraid',
        '-e', 'HOST_HOSTNAME=' . $hostname,
        '-e', 'HOST_CONTAINERNAME=' . CONTAINER,
        '-e', 'OPEN_ACCESS=' . ($cfg['OPEN_ACCESS'] === 'true' ? 'true' : 'false'),
        '-e', 'SECRET=' . $cfg['SECRET'],
        '-e', 'PUID=' . (int)$cfg['PUID'],
        '-e', 'PGID=' . (int)$cfg['PGID'],
        '-l', 'net.unraid.docker.managed=dockerman',
        '-l', 'net.unraid.docker.webui=' . $webui,
        '-l', 'net.unraid.docker.icon=' . $icon,
        '-l', 'net.unraid.docker.shell=' . $shell,
    ];
    if ($net === 'bridge') {
        $args[] = '-p';
        $args[] = "{$port}:8080/tcp";
    }
    $args = array_merge($args, [
        '-v', $cfg['APPDATA_PATH'] . ':/app/config:rw',
        '-v', '/sys/class/thermal:/sys/class/thermal:ro',
        '-v', '/sys/class/hwmon:/sys/class/hwmon:ro',
    ]);
    foreach ($blockDevices as $dev) {
        $args[] = '--device=' . $dev;
    }
    foreach ($serialDevices as $dev) {
        $args[] = '--device=' . $dev;
    }
    if (($cfg['TAILSCALE'] ?? 'false') === 'true') {
        $args[] = '-l';
        $args[] = 'net.unraid.docker.tailscale=true';
    }
    $args[] = '--cap-add=SYS_RAWIO';
    $args[] = 'hakoforge/hako-foundry:' . $tag;
    return $args;
}

/**
 * @param array<string, string> $cfg
 * @param list<string> $blockDevices
 * @param list<string> $serialDevices
 */
function buildXml(array $cfg, array $blockDevices, array $serialDevices): string
{
    $port       = (int)$cfg['WEB_PORT'];
    $appdata    = htmlspecialchars($cfg['APPDATA_PATH'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $puid       = (int)$cfg['PUID'];
    $pgid       = (int)$cfg['PGID'];
    $openAccess = $cfg['OPEN_ACCESS'] === 'true' ? 'true' : 'false';
    $tag        = htmlspecialchars($cfg['IMAGE_TAG'] ?? 'latest', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $network    = in_array($cfg['NETWORK'] ?? '', ['bridge', 'host', 'none'], true)
                  ? $cfg['NETWORK'] : 'bridge';
    $shell     = ($cfg['SHELL'] ?? 'bash')      === 'sh' ? 'sh' : 'bash';
    $tailscale = ($cfg['TAILSCALE'] ?? 'false') === 'true';
    $secret    = htmlspecialchars($cfg['SECRET'], ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $deviceConfigs = '';
    foreach ($blockDevices as $dev) {
        $e = htmlspecialchars($dev, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $deviceConfigs .= <<<XML
                <Config Name="Storage Device {$e}" Target="{$e}" Default="{$e}" Mode="" Description="Block storage device passthrough" Type="Device" Display="always" Required="false" Mask="false">{$e}</Config>

            XML;
    }
    foreach ($serialDevices as $dev) {
        $e = htmlspecialchars($dev, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $deviceConfigs .= <<<XML
                <Config Name="Serial Device {$e}" Target="{$e}" Default="{$e}" Mode="" Description="USB serial device passthrough" Type="Device" Display="always" Required="false" Mask="false">{$e}</Config>

            XML;
    }

    $networkingXml = $network === 'bridge' ? <<<XML

            <Networking>
                <Mode>bridge</Mode>
                <Publish>
                    <Port>
                        <HostPort>{$port}</HostPort>
                        <ContainerPort>8080</ContainerPort>
                        <Protocol>tcp</Protocol>
                    </Port>
                </Publish>
            </Networking>
        XML : <<<XML

            <Networking>
                <Mode>{$network}</Mode>
            </Networking>
        XML;
    $tailscaleXml = $tailscale ? "\n    <TailscaleEnabled>true</TailscaleEnabled>" : '';

    return <<<XML
        <?xml version="1.0"?>
        <Container version="2">
            <Name>HakoFoundry</Name>
            <Repository>hakoforge/hako-foundry:{$tag}</Repository>
            <Registry>https://hub.docker.com/r/hakoforge/hako-foundry</Registry>
            <Network>{$network}</Network>
            <MyIP/>
            <Shell>{$shell}</Shell>
            <Privileged>false</Privileged>
            <Support>https://forums.unraid.net/topic/197930-plugin-hakodeploy/</Support>
            <Project>https://github.com/HakoForge/HakoFoundry</Project>
            <Overview>An Unraid plugin that configures and deploys HakoFoundry. A server chassis companion app that displays hard drive SMART data, monitors power, and manages fan curves for the Hako-Core (Mini). To utilize power board features, a HakoForge Power Board is required on the HF-L1 chassis.</Overview>
            <Category>Tools:System</Category>
            <WebUI>http://[IP]:[PORT:{$port}]/</WebUI>
            <TemplateURL>https://raw.githubusercontent.com/HakoForge/HakoFoundry/main/unraid-templates/HakoFoundry.xml</TemplateURL>
            <Icon>https://raw.githubusercontent.com/HakoForge/HakoFoundry/main/assets/images/icon.png</Icon>
            <ExtraParams>--cap-add=SYS_RAWIO</ExtraParams>{$tailscaleXml}{$networkingXml}
            <Data>
                <Volume>
                    <HostDir>{$appdata}</HostDir>
                    <ContainerDir>/app/config</ContainerDir>
                    <Mode>rw</Mode>
                </Volume>
                <Volume>
                    <HostDir>/sys/class/thermal</HostDir>
                    <ContainerDir>/sys/class/thermal</ContainerDir>
                    <Mode>ro</Mode>
                </Volume>
                <Volume>
                    <HostDir>/sys/class/hwmon</HostDir>
                    <ContainerDir>/sys/class/hwmon</ContainerDir>
                    <Mode>ro</Mode>
                </Volume>
            </Data>
            <Config Name="Web UI Port" Target="8080" Default="8080" Mode="tcp" Description="Web interface port for HakoFoundry" Type="Port" Display="always" Required="true" Mask="false">{$port}</Config>
            <Config Name="Configuration Directory" Target="/app/config" Default="/mnt/user/appdata/hako-foundry" Mode="rw" Description="Directory for HakoFoundry configuration and data" Type="Path" Display="always" Required="true" Mask="false">{$appdata}</Config>
            <Config Name="Thermal Monitoring" Target="/sys/class/thermal" Default="/sys/class/thermal" Mode="ro" Description="System thermal information (read-only)" Type="Path" Display="advanced" Required="false" Mask="false">/sys/class/thermal</Config>
            <Config Name="Hardware Monitoring" Target="/sys/class/hwmon" Default="/sys/class/hwmon" Mode="ro" Description="Hardware monitoring information (read-only)" Type="Path" Display="advanced" Required="false" Mask="false">/sys/class/hwmon</Config>
            <Config Name="Open Access" Target="OPEN_ACCESS" Default="false" Mode="" Description="Enable open access without authentication" Type="Variable" Display="always" Required="true" Mask="false">{$openAccess}</Config>
            <Config Name="Secret Key" Target="SECRET" Default="" Mode="" Description="Secret key for session authentication" Type="Variable" Display="always" Required="false" Mask="true">{$secret}</Config>
            <Config Name="User ID" Target="PUID" Default="99" Mode="" Description="User ID for file permissions (default: nobody=99)" Type="Variable" Display="always" Required="true" Mask="false">{$puid}</Config>
            <Config Name="Group ID" Target="PGID" Default="100" Mode="" Description="Group ID for file permissions (default: users=100)" Type="Variable" Display="always" Required="true" Mask="false">{$pgid}</Config>
        {$deviceConfigs}</Container>
        XML;
}

$action = postStr('action');

$webPort = filter_var(
    $_POST['WEB_PORT'] ?? '8080',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]
);
$puid = filter_var(
    $_POST['PUID'] ?? '99',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 65534]]
);
$pgid = filter_var(
    $_POST['PGID'] ?? '100',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 65534]]
);

$cfg = [
    'WEB_PORT'     => (string)($webPort !== false ? $webPort : 8080),
    'APPDATA_PATH' => sanitizeAppdataPath(postStr('APPDATA_PATH')),
    'PUID'         => (string)($puid !== false ? $puid : 99),
    'PGID'         => (string)($pgid !== false ? $pgid : 100),
    'OPEN_ACCESS'  => in_array(postStr('OPEN_ACCESS'), ['true', 'false'], true)
                      ? postStr('OPEN_ACCESS') : 'false',
    'IMAGE_TAG' => (function () {
        $tag = preg_replace('/[^A-Za-z0-9._-]/', '', postStr('IMAGE_TAG')) ?? '';
        return $tag !== '' ? $tag : 'latest';
    })(),
    'NETWORK' => (function () {
        $net = trim(postStr('NETWORK', 'bridge'));
        if (preg_match('/^(bridge|host|none)$/', $net) || preg_match('/^container:[a-zA-Z0-9][a-zA-Z0-9_.\-]*$/', $net) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.\-]*$/', $net)) {
            return $net;
        }
        return 'bridge';
    })(),
    'SHELL' => in_array(postStr('SHELL', 'bash'), ['bash', 'sh'], true)
                      ? postStr('SHELL', 'bash') : 'bash',
    'TAILSCALE' => postStr('TAILSCALE') === 'true' ? 'true' : 'false',
    'SECRET'    => preg_replace('/[^A-Za-z0-9+\/=_-]/', '', postStr('SECRET')) ?? '',
];

if (empty($cfg['SECRET'])) {
    $cfg['SECRET'] = generateSecret();
}

$blockDevices  = sanitizeDeviceList(postStr('SELECTED_DEVICES'));
$serialDevices = sanitizeDeviceList(postStr('SELECTED_SERIAL'));

$cfgForFile = array_merge($cfg, [
    'SELECTED_DEVICES' => implode(',', $blockDevices),
    'SELECTED_SERIAL'  => implode(',', $serialDevices),
]);

switch ($action) {
    case 'deploy':
        try {
            if ( ! file_exists(CFG_FILE)) {
                jsonResponse(false, "Config not found. Run Apply first.");
            }

            $savedCfg     = parse_ini_file(CFG_FILE) ?: [];
            $deployBlock  = sanitizeDeviceList((string)($savedCfg['SELECTED_DEVICES'] ?? ''));
            $deploySerial = sanitizeDeviceList((string)($savedCfg['SELECTED_SERIAL'] ?? ''));

            exec('docker inspect ' . escapeshellarg(CONTAINER) . ' 2>/dev/null', $inspOut, $inspRc);
            if ($inspRc === 0) {
                exec('docker stop ' . escapeshellarg(CONTAINER) . ' 2>&1');
                exec('docker rm ' . escapeshellarg(CONTAINER) . ' 2>&1');
            }

            $args = buildDockerRunArgs($savedCfg, $deployBlock, $deploySerial);
            $cmd  = implode(' ', array_map('escapeshellarg', $args));
            exec($cmd . ' 2>&1', $runOut, $runRc);
            $output = implode("\n", $runOut);

            if ($runRc !== 0) {
                jsonResponse(false, "Container start failed:\n" . $output);
            }

            jsonResponse(true, "Container deployed successfully.\n" . $output);
        } catch (Throwable $e) {
            jsonResponse(false, "Deploy error: " . $e->getMessage());
        }

    case 'apply':
        try {
            if ( ! is_dir(TEMPLATE_DIR)) {
                jsonResponse(
                    false,
                    "Template directory not found: " . TEMPLATE_DIR . "\n" .
                    "Is the Docker / Community Applications plugin installed?"
                );
            }

            saveConfig($cfgForFile);

            if (file_exists(TEMPLATE_FILE)) {
                rename(TEMPLATE_FILE, TEMPLATE_FILE . '.bak.' . date('Ymd_His'));
            }

            file_put_contents(TEMPLATE_FILE, buildXml($cfg, $blockDevices, $serialDevices));

            $count = count($blockDevices) + count($serialDevices);
            jsonResponse(
                true,
                "Template installed successfully.\n" .
                "Devices configured: {$count}\n" .
                "Path: " . TEMPLATE_FILE
            );
        } catch (Throwable $e) {
            jsonResponse(false, "Error: " . $e->getMessage());
        }

    case 'status':
        exec('docker inspect --format "{{.State.Status}}|{{.Id}}" ' . escapeshellarg(CONTAINER) . ' 2>/dev/null', $out, $rc);
        $parts  = $rc === 0 && ! empty($out[0]) ? explode('|', $out[0], 2) : [];
        $status = $parts[0] ?? 'not installed';
        $id     = $parts[1] ?? '';
        echo json_encode([
            'success'            => true,
            'container_status'   => $status,
            'container_id'       => $id,
            'template_installed' => file_exists(TEMPLATE_FILE),
        ]);
        break;
    default:
        jsonResponse(false, "Unknown action: " . htmlspecialchars($action));
}
