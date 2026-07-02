#!/home/xui/bin/php/bin/php
<?php

/**
 * Standalone XUI Migration Script
 * 
 * Migrates data from a migration database to a target XUI database
 * Run: /home/xui/bin/php/bin/php migrate.php
 *      /home/xui/bin/php/bin/php migrate.php xstream-ui
 *      /home/xui/bin/php/bin/php migrate.php -skip=users,helps
 *      /home/xui/bin/php/bin/php migrate.php xstream-ui -skip=users,helps
 * 
 * Configuration: Defaults are read from xui/config/new_config.ini, or set environment variables
 */

// ============================================================================
// CONFIGURATION - Edit these values or set as environment variables
// ============================================================================

function load_xui_db_config($config_path = null) {
    $path = $config_path ?: (__DIR__ . '/xui/config/new_config.ini');
    if (!is_readable($path)) {
        return null;
    }
    $ini = parse_ini_file($path, true, INI_SCANNER_RAW);
    if (!$ini) {
        return null;
    }
    return [
        'host' => $ini['XUI']['hostname'] ?? '127.0.0.1',
        'port' => (int) ($ini['XUI']['port'] ?? 3306),
        'user' => $ini['Encrypted']['username'] ?? '',
        'pass' => $ini['Encrypted']['password'] ?? '',
        'db'   => $ini['XUI']['database'] ?? 'xui',
        'server_id' => (int) ($ini['XUI']['server_id'] ?? 1),
    ];
}

function get_xui_server_id($config_path = null) {
    $config = load_xui_db_config($config_path);

    return $config['server_id'] ?? 1;
}

$xui_db = load_xui_db_config();

$CONFIG = [
    // Source database (migration database - contains backup data)
    'source_host'     => getenv('MIGRATION_SOURCE_HOST') ?: ($xui_db['host'] ?? '127.0.0.1'),
    'source_port'     => (int) (getenv('MIGRATION_SOURCE_PORT') ?: ($xui_db['port'] ?? 3306)),
    'source_user'     => getenv('MIGRATION_SOURCE_USER') ?: ($xui_db['user'] ?? 'root'),
    'source_pass'     => getenv('MIGRATION_SOURCE_PASS') !== false ? getenv('MIGRATION_SOURCE_PASS') : ($xui_db['pass'] ?? ''),
    'source_db'       => getenv('MIGRATION_SOURCE_DB') ?: 'xui_migrate',
    
    // Target database (main XUI database)
    'target_host'     => getenv('MIGRATION_TARGET_HOST') ?: ($xui_db['host'] ?? '127.0.0.1'),
    'target_port'     => (int) (getenv('MIGRATION_TARGET_PORT') ?: ($xui_db['port'] ?? 3306)),
    'target_user'     => getenv('MIGRATION_TARGET_USER') ?: ($xui_db['user'] ?? 'root'),
    'target_pass'     => getenv('MIGRATION_TARGET_PASS') !== false ? getenv('MIGRATION_TARGET_PASS') : ($xui_db['pass'] ?? ''),
    'target_db'       => getenv('MIGRATION_TARGET_DB') ?: ($xui_db['db'] ?? 'xui'),
    
    // Tables to migrate (leave empty to auto-detect from source schema)
    'tables'          => [],

    // Source table names to skip (e.g. already imported manually)
    'skip_tables'     => [],
    
    // Whether to truncate target tables before insert
    'truncate_target' => true,
    
    // Batch size for large tables
    'batch_size'      => 1000,
];

// ============================================================================
// XUI SCHEMA - Table definitions for both schemas
// ============================================================================

$XUI_TABLES = [
    'access_codes', 'users', 'blocked_ips', 'blocked_uas', 'blocked_isps',
    'bouquets', 'enigma2_devices', 'mag_devices', 'epg', 'users_groups',
    'users_packages', 'rtmp_ips', 'streams_series', 'streams_episodes',
    'servers', 'streams', 'streams_options', 'streams_servers',
    'streams_categories', 'tickets', 'tickets_replies', 'profiles', 'lines',
    'watch_folders', 'providers'
];

$LEGACY_TABLES = [
    'reg_users', 'users', 'enigma2_devices', 'mag_devices', 'user_output',
    'streaming_servers', 'series', 'series_episodes', 'streams', 'streams_sys',
    'streams_options', 'stream_categories', 'bouquets', 'member_groups',
    'packages', 'rtmp_ips', 'epg', 'blocked_ips', 'blocked_user_agents',
    'isp_addon', 'tickets', 'tickets_replies', 'transcoding_profiles',
    'watch_folders', 'categories', 'epg_sources', 'members', 'blocked_isps',
    'groups', 'servers', 'stream_servers'
];

$MIGRATION_REPORT = [
    'tables' => [],
    'errors' => [],
    'warnings' => [],
];

$SUMMARY_FILE = __DIR__ . '/migration_summary.log';

$MIGRATION_OPTIONS = [
    'xui1.5.13' => [
        'label' => 'XUI 1.5.13 (XUI default)',
        'description' => 'Direct table names, no legacy renaming',
    ],
    'xstream-ui' => [
        'label' => 'XStream UI',
        'description' => 'Legacy XStream schema with table name remapping',
    ],
];

$TABLE_MAPPINGS = [
    'xstream-ui' => [
        // Subscribers vs panel users (legacy naming is inverted)
        'users' => 'lines',
        'reg_users' => 'users',
        // Infrastructure & content
        'streaming_servers' => 'servers',
        'series' => 'streams_series',
        'series_episodes' => 'streams_episodes',
        'stream_categories' => 'streams_categories',
        'streams_sys' => 'streams_servers',
        // Groups, packages, profiles
        'member_groups' => 'users_groups',
        'packages' => 'users_packages',
        'transcoding_profiles' => 'profiles',
        // Blocklists
        'blocked_user_agents' => 'blocked_uas',
        'isp_addon' => 'blocked_isps',
    ],
];

$TABLE_MODE_MAPPING = [];
$USED_TARGET_TABLES = [];

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function output($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$level}] {$message}\n";
}

function mysql_socket_path() {
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock'] as $socket) {
        if (is_readable($socket)) {
            return $socket;
        }
    }
    return null;
}

function create_connection($host, $port, $user, $pass, $db) {
    if (!extension_loaded('pdo_mysql')) {
        output('PDO MySQL driver (pdo_mysql) is not loaded. Run with: /home/xui/bin/php/bin/php migrate.php', 'ERROR');
        return false;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $attempts = [];
    if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
        $socket = mysql_socket_path();
        if ($socket) {
            $attempts[] = [
                'dsn' => "mysql:unix_socket={$socket};dbname={$db};charset=utf8mb4",
                'label' => "socket {$socket}",
            ];
        }
    }
    $attempts[] = [
        'dsn' => "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        'label' => "{$host}:{$port}",
    ];

    $last_error = null;
    foreach ($attempts as $attempt) {
        try {
            $pdo = new PDO($attempt['dsn'], $user, $pass, $options);
            return $pdo;
        } catch (PDOException $e) {
            $last_error = $e;
        }
    }

    output("Failed to connect to {$db} at {$host}:{$port} - " . $last_error->getMessage(), 'ERROR');
    return false;
}

function table_exists($pdo, $table) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function get_table_count($pdo, $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$table}`");
        return (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        return 0;
    }
}

function get_table_columns($pdo, $table) {
    try {
        $stmt = $pdo->query("DESCRIBE `{$table}`");
        $columns = [];
        while ($row = $stmt->fetch()) {
            $columns[] = $row['Field'];
        }
        return $columns;
    } catch (Exception $e) {
        output("Failed to get columns for table {$table}: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

function get_table_columns_details($pdo, $table) {
    try {
        $stmt = $pdo->query("DESCRIBE `{$table}`");
        $columns = [];
        while ($row = $stmt->fetch()) {
            $columns[$row['Field']] = $row;
        }
        return $columns;
    } catch (Exception $e) {
        output("Failed to get column details for table {$table}: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

function detect_schema($source_pdo) {
    global $XUI_TABLES, $LEGACY_TABLES;
    
    // Check if XUI schema exists
    foreach ($XUI_TABLES as $table) {
        if (table_exists($source_pdo, $table)) {
            output("Detected XUI schema (XUI)", 'INFO');
            return 'xui';
        }
    }
    
    // Check if legacy schema exists
    foreach ($LEGACY_TABLES as $table) {
        if (table_exists($source_pdo, $table)) {
            output("Detected legacy schema", 'INFO');
            return 'legacy';
        }
    }
    
    return null;
}

function is_interactive() {
    if (!defined('STDIN') || !is_resource(STDIN)) {
        return false;
    }

    if (function_exists('posix_isatty')) {
        return posix_isatty(STDIN);
    }

    return false;
}

function get_migration_option_ids() {
    global $MIGRATION_OPTIONS;

    return array_keys($MIGRATION_OPTIONS);
}

function resolve_migration_mode($selection) {
    $selection = trim((string)$selection);
    $options = get_migration_option_ids();

    if ($selection === '') {
        return 'xui1.5.13';
    }

    if (in_array($selection, $options, true)) {
        return $selection;
    }

    if (ctype_digit($selection)) {
        $index = (int)$selection - 1;
        if (isset($options[$index])) {
            return $options[$index];
        }
    }

    return null;
}

function terminal_supports_arrow_menu() {
    if (!is_interactive() || !function_exists('shell_exec')) {
        return false;
    }

    $stty = shell_exec('command -v stty 2>/dev/null') ?: shell_exec('which stty 2>/dev/null');

    return $stty !== null && trim($stty) !== '';
}

function terminal_set_raw_mode($enable) {
    static $saved_stty = null;

    if ($enable) {
        $saved_stty = shell_exec('stty -g 2>/dev/null');
        if ($saved_stty === null || trim($saved_stty) === '') {
            return false;
        }

        shell_exec('stty -icanon -echo min 1 time 0 2>/dev/null');

        return true;
    }

    if ($saved_stty !== null) {
        shell_exec('stty ' . trim($saved_stty) . ' 2>/dev/null');
        $saved_stty = null;
    }

    return true;
}

function terminal_hide_cursor() {
    echo "\033[?25l";
}

function terminal_show_cursor() {
    echo "\033[?25h";
}

function terminal_move_up($lines) {
    if ($lines > 0) {
        echo "\033[{$lines}A";
    }
}

function terminal_clear_line() {
    echo "\033[2K\r";
}

function read_terminal_key() {
    $char = fread(STDIN, 1);
    if ($char === false || $char === '') {
        return '';
    }

    if ($char !== "\x1b") {
        return $char;
    }

    $next = fread(STDIN, 1);
    if ($next === false || $next === '') {
        return $char;
    }

    if ($next === 'O') {
        $third = fread(STDIN, 1);
        return $char . $next . ($third !== false ? $third : '');
    }

    if ($next === '[') {
        $third = fread(STDIN, 1);
        return $char . $next . ($third !== false ? $third : '');
    }

    return $char . $next;
}

function render_migration_mode_menu(array $options, $selected_index, $first_render, $previous_line_count) {
    global $MIGRATION_OPTIONS;

    if (!$first_render && $previous_line_count > 0) {
        terminal_move_up($previous_line_count);
    }

    $default = 'xui1.5.13';
    $lines = 0;
    $write = function ($text) use (&$lines) {
        terminal_clear_line();
        echo $text . "\n";
        $lines++;
    };

    $write('');
    $write('[INFO] Select migration source schema:');
    $write('');

    foreach ($options as $index => $id) {
        $meta = $MIGRATION_OPTIONS[$id];
        $is_selected = ($index === $selected_index);
        $marker = ($id === $default) ? ' (default)' : '';
        $prefix = $is_selected ? '  › ' : '    ';
        $style_start = $is_selected ? "\033[1;36m" : '';
        $style_end = $is_selected ? "\033[0m" : '';

        $write($prefix . $style_start . ($index + 1) . ') ' . $meta['label'] . $marker . $style_end);
        $write(($is_selected ? '     ' : '       ') . $meta['description']);
    }

    $write('');
    $write('↑↓ navigate • Enter select • 1-' . count($options) . ' quick pick');

    return $lines;
}

function prompt_migration_mode_arrow() {
    global $MIGRATION_OPTIONS;

    $options = get_migration_option_ids();
    $default = 'xui1.5.13';
    $selected_index = array_search($default, $options, true);
    if ($selected_index === false) {
        $selected_index = 0;
    }

    if (!terminal_set_raw_mode(true)) {
        return prompt_migration_mode_text();
    }

    terminal_hide_cursor();

    $first_render = true;
    $menu_line_count = 0;
    $confirmed = false;

    try {
        while (true) {
            $menu_line_count = render_migration_mode_menu(
                $options,
                $selected_index,
                $first_render,
                $menu_line_count
            );
            $first_render = false;

            $key = read_terminal_key();

            if ($key === "\x1b[A" || $key === "\x1bOA" || $key === 'k') {
                $selected_index = max(0, $selected_index - 1);
                continue;
            }

            if ($key === "\x1b[B" || $key === "\x1bOB" || $key === 'j') {
                $selected_index = min(count($options) - 1, $selected_index + 1);
                continue;
            }

            if ($key === "\r" || $key === "\n") {
                $confirmed = true;
                break;
            }

            if (ctype_digit($key)) {
                $index = (int) $key - 1;
                if (isset($options[$index])) {
                    $selected_index = $index;
                    $confirmed = true;
                    break;
                }
            }

            if ($key === "\x03") {
                echo "\n";
                exit(130);
            }
        }
    } finally {
        terminal_set_raw_mode(false);
        terminal_show_cursor();
        if ($confirmed) {
            echo "\n";
        }
    }

    $chosen = $options[$selected_index];
    output('Selected: ' . ($MIGRATION_OPTIONS[$chosen]['label'] ?? $chosen), 'INFO');

    return $chosen;
}

function prompt_migration_mode_text() {
    global $MIGRATION_OPTIONS;

    $options = get_migration_option_ids();
    $default = 'xui1.5.13';
    $default_index = array_search($default, $options, true);
    $default_choice = $default_index === false ? 1 : $default_index + 1;

    echo "\n";
    output('Select migration source schema:', 'INFO');
    echo "\n";

    foreach ($options as $index => $id) {
        $meta = $MIGRATION_OPTIONS[$id];
        $marker = ($id === $default) ? ' (default)' : '';
        echo '  ' . ($index + 1) . ") {$meta['label']}{$marker}\n";
        echo "     {$meta['description']}\n";
    }

    echo "\n";
    echo "Enter choice [{$default_choice}]: ";

    $input = fgets(STDIN);
    if ($input === false) {
        output("No input received, using default: {$default}", 'INFO');
        return $default;
    }

    $input = trim($input);
    if ($input === '') {
        return $default;
    }

    $resolved = resolve_migration_mode($input);
    if ($resolved === null) {
        output("Invalid choice \"{$input}\", using default: {$default}", 'WARN');
        return $default;
    }

    return $resolved;
}

function prompt_migration_mode() {
    if (terminal_supports_arrow_menu()) {
        return prompt_migration_mode_arrow();
    }

    return prompt_migration_mode_text();
}

function parse_skip_list($value) {
    $tables = array_map('trim', explode(',', (string)$value));

    return array_values(array_filter($tables, function ($table) {
        return $table !== '';
    }));
}

function parse_cli_args(array $argv) {
    $mode = null;
    $skipTables = [];

    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $arg = $argv[$i];

        if (preg_match('/^--?skip=(.+)$/i', $arg, $matches)) {
            $skipTables = array_merge($skipTables, parse_skip_list($matches[1]));
            continue;
        }

        if (preg_match('/^--?skip$/i', $arg)) {
            if (!isset($argv[$i + 1])) {
                output('Missing value for -skip (expected comma-separated table names)', 'ERROR');
                exit(1);
            }
            $skipTables = array_merge($skipTables, parse_skip_list($argv[$i + 1]));
            $i++;
            continue;
        }

        $resolved = resolve_migration_mode($arg);
        if ($resolved === null) {
            $available = implode(', ', get_migration_option_ids());
            output("Unknown argument: {$arg}. Available migration options: {$available}", 'ERROR');
            exit(1);
        }

        if ($mode !== null) {
            output('Only one migration option can be specified on the command line', 'ERROR');
            exit(1);
        }

        $mode = $resolved;
    }

    return [
        'mode' => $mode,
        'skip_tables' => array_values(array_unique($skipTables)),
    ];
}

function apply_skip_tables_from_cli(array $cliSkipTables) {
    global $CONFIG;

    if (empty($cliSkipTables)) {
        return;
    }

    $CONFIG['skip_tables'] = array_values(array_unique(array_merge(
        $CONFIG['skip_tables'] ?? [],
        $cliSkipTables
    )));

    output('Skip tables from CLI: ' . implode(', ', $cliSkipTables), 'INFO');
}

function pick_table_mode_mapping($mode) {
    global $TABLE_MAPPINGS, $TABLE_MODE_MAPPING, $MIGRATION_OPTIONS;

    if (empty($mode) || $mode === 'xui1.5.13') {
        $TABLE_MODE_MAPPING = [];
        $label = $MIGRATION_OPTIONS['xui1.5.13']['label'] ?? 'xui1.5.13';
        output("Using migration mode: {$label}", 'INFO');
        return;
    }

    if (isset($TABLE_MAPPINGS[$mode])) {
        $TABLE_MODE_MAPPING = $TABLE_MAPPINGS[$mode];
        $label = $MIGRATION_OPTIONS[$mode]['label'] ?? $mode;
        output("Using migration mode: {$label}", 'INFO');
    } else {
        $TABLE_MODE_MAPPING = [];
        output("Unknown mapping mode: {$mode}. Using default table names.", 'WARN');
    }
}

function resolve_target_table_name($source_table) {
    global $TABLE_MODE_MAPPING;

    return $TABLE_MODE_MAPPING[$source_table] ?? $source_table;
}

function filter_skip_tables(array $tables) {
    global $CONFIG;

    $skip = $CONFIG['skip_tables'] ?? [];
    if (empty($skip)) {
        return $tables;
    }

    $skip = array_flip(array_map('strval', $skip));
    $filtered = [];

    foreach ($tables as $table) {
        if (isset($skip[$table])) {
            output("Skipping {$table}: listed in skip_tables", 'SKIP');
            continue;
        }
        $filtered[] = $table;
    }

    return $filtered;
}

function get_tables_to_migrate($source_pdo, $schema) {
    global $XUI_TABLES, $LEGACY_TABLES, $TABLE_MODE_MAPPING;

    $catalog = ($schema === 'xui') ? $XUI_TABLES : $LEGACY_TABLES;
    $candidates = [];

    if (!empty($TABLE_MODE_MAPPING)) {
        // Mapped sources are always candidates (keys of TABLE_MODE_MAPPING)
        $candidates = array_keys($TABLE_MODE_MAPPING);
        $mappedTargets = array_flip(array_values($TABLE_MODE_MAPPING));

        // Same-name tables from catalog that are not renamed via mapping
        foreach ($catalog as $table) {
            if (isset($TABLE_MODE_MAPPING[$table])) {
                continue;
            }
            // Skip catalog names that are only XUI target names (e.g. blocked_isps <- isp_addon)
            if (isset($mappedTargets[$table])) {
                continue;
            }
            $candidates[] = $table;
        }
    } else {
        $candidates = $catalog;
    }

    $available = [];
    foreach (array_values(array_unique($candidates)) as $table) {
        if (!table_exists($source_pdo, $table)) {
            continue;
        }
        $count = get_table_count($source_pdo, $table);
        if ($count > 0) {
            $available[] = $table;
        }
    }

    return filter_skip_tables($available);
}

function build_column_definition(array $column_info) {
    $type = $column_info['Type'];
    $definition = $type;
    $is_date_type = preg_match('/^(date|datetime|timestamp)\b/i', $type);
    $default = $column_info['Default'];
    $is_zero_date = $is_date_type && in_array($default, ['0000-00-00 00:00:00', '0000-00-00'], true);

    if ($is_zero_date) {
        // Zero date defaults are invalid in strict mode.
        // Use nullable column with NULL default to keep ALTER TABLE safe.
        $definition .= ' NULL DEFAULT NULL';
    } else {
        $definition .= ($column_info['Null'] === 'NO') ? ' NOT NULL' : ' NULL';

        if ($default !== null) {
            $upperDefault = strtoupper($default);
            if ($upperDefault === 'CURRENT_TIMESTAMP' || $upperDefault === 'NULL') {
                $definition .= ' DEFAULT ' . $default;
            } elseif ($default === '' && $column_info['Null'] === 'YES') {
                $definition .= ' DEFAULT NULL';
            } elseif (is_numeric($default)) {
                $definition .= ' DEFAULT ' . $default;
            } else {
                $definition .= " DEFAULT '" . str_replace("'", "''", $default) . "'";
            }
        } elseif ($column_info['Null'] === 'YES') {
            $definition .= ' DEFAULT NULL';
        }
    }

    if (!empty($column_info['Extra'])) {
        $definition .= ' ' . $column_info['Extra'];
    }

    return trim($definition);
}

function build_safe_column_definition(array $column_info) {
    $type = $column_info['Type'];
    $definition = $type . ' NULL DEFAULT NULL';

    if (!empty($column_info['Extra'])) {
        $definition .= ' ' . $column_info['Extra'];
    }

    return trim($definition);
}

function normalize_column_value($column, $value, $column_info = null) {
    if ($value === null || $column_info === null) {
        return $value;
    }

    $type = strtolower($column_info['Type']);
    if (preg_match('/^(date|datetime|timestamp)\b/i', $type)) {
        if ($value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return null;
        }
    }

    return $value;
}

function add_missing_columns($target_pdo, $table, $missing_columns, $source_columns_details) {
    $added = [];
    foreach ($missing_columns as $column) {
        if (!isset($source_columns_details[$column])) {
            continue;
        }

        $column_info = $source_columns_details[$column];
        $definition = build_column_definition($column_info);
        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}";

        try {
            $target_pdo->exec($sql);
            $added[] = $column;
            output("Added missing column `{$column}` to {$table}", 'INFO');
            continue;
        } catch (Exception $e) {
            output("Failed to add missing column `{$column}` to {$table}: " . $e->getMessage(), 'ERROR');
            output("Failed SQL: {$sql}", 'DEBUG');
        }

        // Fallback: use a safe nullable definition for any column adding failure.
        $safeDefinition = build_safe_column_definition($column_info);
        $safeSql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$safeDefinition}";
        try {
            $target_pdo->exec($safeSql);
            $added[] = $column;
            output("Added missing column `{$column}` to {$table} with safe nullable fallback", 'INFO');
            continue;
        } catch (Exception $e) {
            output("Safe fallback failed for `{$column}` on {$table}: " . $e->getMessage(), 'ERROR');
            output("Safe fallback SQL: {$safeSql}", 'DEBUG');
        }
    }

    return $added;
}

function migrate_table($source_pdo, $target_pdo, $table, $batch_size, $truncate) {
    global $MIGRATION_REPORT, $USED_TARGET_TABLES;

    try {
        $count = get_table_count($source_pdo, $table);

        if ($count === 0) {
            output("Skipping {$table}: no data", 'SKIP');
            return 0;
        }

        $target_table = resolve_target_table_name($table);

        if (isset($USED_TARGET_TABLES[$target_table])) {
            $previousSource = $USED_TARGET_TABLES[$target_table];
            output("Skipping {$table}: target table {$target_table} already used by {$previousSource}", 'WARN');
            $MIGRATION_REPORT['warnings'][] = "Skipping {$table}: target table {$target_table} already used by {$previousSource}";
            return 0;
        }

        if (!table_exists($target_pdo, $target_table)) {
            output("Target table {$target_table} does not exist, skipping", 'ERROR');
            $MIGRATION_REPORT['errors'][] = "Target table {$target_table} missing";
            return 0;
        }

        $USED_TARGET_TABLES[$target_table] = $table;

        // Get column metadata for source and target
        $source_columns_details = get_table_columns_details($source_pdo, $table);
        $target_columns_details = get_table_columns_details($target_pdo, $target_table);

        $source_columns = array_keys($source_columns_details);
        $target_columns = array_keys($target_columns_details);

        if (empty($source_columns) || empty($target_columns)) {
            output("Failed to get columns for {$table}", 'ERROR');
            return 0;
        }

        // Find columns that exist in both source and target
        $common_columns = array_values(array_intersect($source_columns, $target_columns));
        $missing_in_target = array_diff($source_columns, $target_columns);
        $extra_in_target = array_diff($target_columns, $source_columns);

        if ($truncate) {
            try {
                $target_pdo->exec("TRUNCATE `{$target_table}`");
                output("Truncated target table {$target_table} before schema sync", 'INFO');
            } catch (Exception $e) {
                output("Warning: Could not truncate {$target_table} before schema sync: " . $e->getMessage(), 'WARN');
            }
        }

        if (!empty($missing_in_target)) {
            output("Adding missing columns to target table {$target_table}: " . implode(', ', $missing_in_target), 'WARN');
            $added = add_missing_columns($target_pdo, $target_table, $missing_in_target, $source_columns_details);
            $MIGRATION_REPORT['tables'][$target_table]['added_columns'] = $added;
            $missing_in_target = array_diff($missing_in_target, $added);

            if (!empty($missing_in_target)) {
                output("Unable to add some missing columns for {$target_table}: " . implode(', ', $missing_in_target), 'ERROR');
                $MIGRATION_REPORT['errors'][] = "Failed to add columns to {$target_table}: " . implode(', ', $missing_in_target);
            }

            $target_columns = array_keys(get_table_columns_details($target_pdo, $target_table));
            $common_columns = array_values(array_intersect($source_columns, $target_columns));
        }

        if (!empty($extra_in_target)) {
            $MIGRATION_REPORT['tables'][$target_table]['ignored_target_columns'] = array_values($extra_in_target);
            output("Target table {$target_table} has extra columns not in source: " . implode(', ', $extra_in_target), 'WARN');
        }

        if (empty($common_columns)) {
            output("No matching columns found for {$table} after schema sync", 'ERROR');
            return 0;
        }

        $target_table = resolve_target_table_name($table);

        // Truncate target if requested
        if ($truncate) {
            try {
                $target_pdo->exec("TRUNCATE `{$target_table}`");
            } catch (Exception $e) {
                output("Warning: Could not truncate {$target_table}: " . $e->getMessage(), 'WARN');
            }
        }

        // Prepare insert statement with common columns only
        $column_list = implode('`, `', $common_columns);
        $placeholders = implode(', ', array_fill(0, count($common_columns), '?'));
        $insert_sql = "INSERT INTO `{$target_table}` (`{$column_list}`) VALUES ({$placeholders})";

        $inserted = 0;
        $errors = 0;

        // Migrate in batches
        for ($offset = 0; $offset < $count; $offset += $batch_size) {
            $source_stmt = $source_pdo->prepare("SELECT * FROM `{$table}` LIMIT {$batch_size} OFFSET {$offset}");
            $source_stmt->execute();

            $rows = $source_stmt->fetchAll();

            foreach ($rows as $row) {
                try {
                    $values = [];
                    foreach ($common_columns as $col) {
                        $values[] = normalize_column_value($col, $row[$col] ?? null, $source_columns_details[$col] ?? null);
                    }

                    $target_stmt = $target_pdo->prepare($insert_sql);
                    $target_stmt->execute($values);
                    $inserted++;
                } catch (Exception $e) {
                    $errors++;
                    output("Error inserting into {$table}: " . $e->getMessage(), 'WARN');
                }
            }
        }

        $target_table = resolve_target_table_name($table);
        $message = "Migrated {$inserted} records to {$target_table} (source: {$table})";
        if ($errors > 0) {
            $message .= " ({$errors} errors)";
            output($message, 'WARN');
            $MIGRATION_REPORT['tables'][$target_table]['insert_errors'] = $errors;
        } else {
            output($message, 'OK');
        }

        $MIGRATION_REPORT['tables'][$target_table]['source_table'] = $table;
        $MIGRATION_REPORT['tables'][$target_table]['common_columns'] = $common_columns;
        return $inserted;
    } catch (Exception $e) {
        output("Failed to migrate {$table}: " . $e->getMessage(), 'ERROR');
        $MIGRATION_REPORT['errors'][] = "{$table}: " . $e->getMessage();
        return 0;
    }
}

function apply_xstream_ui_post_migration($target_pdo) {
    global $MIGRATION_REPORT;

    output('Running XStream UI post-migration fixes...', 'INFO');

    if (table_exists($target_pdo, 'streams')) {
        try {
            $target_pdo->exec('ALTER TABLE `streams` MODIFY COLUMN `category_id` LONGTEXT');
            $target_pdo->exec('ALTER TABLE `streams` RENAME COLUMN `movie_propeties` to `movie_properties`');
            $target_pdo->exec('ALTER TABLE `signals` DROP COLUMN `activity_id`');
            output('Modified streams.category_id to LONGTEXT', 'OK');
        } catch (Exception $e) {
            output('Failed to alter streams.category_id: ' . $e->getMessage(), 'ERROR');
            $MIGRATION_REPORT['errors'][] = 'streams.category_id ALTER: ' . $e->getMessage();
        }

        try {
            $updated = $target_pdo->exec(
                "UPDATE `streams`
                 SET `category_id` = CONCAT('[', `category_id`, ']')
                 WHERE `category_id` IS NOT NULL
                   AND `category_id` NOT LIKE '[%]'"
            );
            output('Wrapped streams.category_id values in brackets (' . (int) $updated . ' rows updated)', 'OK');
        } catch (Exception $e) {
            output('Failed to update streams.category_id: ' . $e->getMessage(), 'ERROR');
            $MIGRATION_REPORT['errors'][] = 'streams.category_id UPDATE: ' . $e->getMessage();
        }
    } else {
        output('Skipping streams post-migration: table not found in target database', 'SKIP');
    }

    if (table_exists($target_pdo, 'servers')) {
        $current_server_id = get_xui_server_id();

        try {
            $stmt = $target_pdo->prepare('UPDATE `servers` SET `server_type` = ? WHERE `id` = ?');
            $stmt->execute([27, $current_server_id]);
            $main_updated = $stmt->rowCount();

            $stmt = $target_pdo->prepare('UPDATE `servers` SET `server_type` = ? WHERE `id` != ?');
            $stmt->execute([24, $current_server_id]);
            $others_updated = $stmt->rowCount();

            output(
                "Updated servers.server_type: 27 for current server (id {$current_server_id}, {$main_updated} row(s)), 24 for others ({$others_updated} row(s))",
                'OK'
            );
        } catch (Exception $e) {
            output('Failed to update servers.server_type: ' . $e->getMessage(), 'ERROR');
            $MIGRATION_REPORT['errors'][] = 'servers.server_type UPDATE: ' . $e->getMessage();
        }
    } else {
        output('Skipping servers post-migration: table not found in target database', 'SKIP');
    }
}

function report_migration_summary() {
    global $MIGRATION_REPORT, $SUMMARY_FILE;

    $lines = [];
    $lines[] = "Migration schema report";
    $lines[] = "-----------------------";

    foreach ($MIGRATION_REPORT['tables'] as $table => $details) {
        $lines[] = "Table: {$table}";
        if (!empty($details['added_columns'])) {
            $lines[] = "  Added columns: " . implode(', ', $details['added_columns']);
        }
        if (!empty($details['ignored_target_columns'])) {
            $lines[] = "  Ignored target-only columns: " . implode(', ', $details['ignored_target_columns']);
        }
        if (!empty($details['insert_errors'])) {
            $lines[] = "  Insert errors: {$details['insert_errors']}";
        }
    }

    if (!empty($MIGRATION_REPORT['errors'])) {
        $lines[] = "Errors found during migration:";
        foreach ($MIGRATION_REPORT['errors'] as $error) {
            $lines[] = "  - {$error}";
        }
    }

    if (!empty($MIGRATION_REPORT['warnings'])) {
        $lines[] = "Warnings found during migration:";
        foreach ($MIGRATION_REPORT['warnings'] as $warning) {
            $lines[] = "  - {$warning}";
        }
    }

    $output = implode("\n", $lines) . "\n";

    echo "\n" . $output;

    try {
        file_put_contents($SUMMARY_FILE, $output);
        output("Saved migration summary to {$SUMMARY_FILE}", 'INFO');
    } catch (Exception $e) {
        output("Failed to write summary file {$SUMMARY_FILE}: " . $e->getMessage(), 'ERROR');
    }
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

function main() {
    global $CONFIG, $XUI_TABLES, $LEGACY_TABLES;
    
    output("XUI Standalone Migration Tool");
    output("================================", 'INFO');

    global $argv;

    $cliArgs = parse_cli_args($argv ?? []);
    apply_skip_tables_from_cli($cliArgs['skip_tables']);

    $mode = $cliArgs['mode'];
    if ($mode !== null) {
        output("Using migration option from argument: {$mode}", 'INFO');
    }

    if ($mode === null) {
        if (is_interactive()) {
            $mode = prompt_migration_mode();
        } else {
            $mode = 'xui1.5.13';
            output('Non-interactive session with no mode argument, using default: xui1.5.13', 'INFO');
        }
    }

    pick_table_mode_mapping($mode);
    
    // Connect to source database
    output("Connecting to source database ({$CONFIG['source_db']}) ...", 'INFO');
    $source_pdo = create_connection(
        $CONFIG['source_host'],
        $CONFIG['source_port'],
        $CONFIG['source_user'],
        $CONFIG['source_pass'],
        $CONFIG['source_db']
    );
    
    if (!$source_pdo) {
        exit(1);
    }
    
    output("Source database connected", 'OK');
    
    // Connect to target database
    output("Connecting to target database ({$CONFIG['target_db']}) ...", 'INFO');
    $target_pdo = create_connection(
        $CONFIG['target_host'],
        $CONFIG['target_port'],
        $CONFIG['target_user'],
        $CONFIG['target_pass'],
        $CONFIG['target_db']
    );
    
    if (!$target_pdo) {
        exit(1);
    }
    
    output("Target database connected", 'OK');

    // Detect schema
    $schema = detect_schema($source_pdo);
    if (!$schema) {
        output("Could not detect database schema in source database", 'ERROR');
        exit(1);
    }
    
    // Get tables to migrate
    $tables = empty($CONFIG['tables'])
        ? get_tables_to_migrate($source_pdo, $schema)
        : filter_skip_tables($CONFIG['tables']);

    if (!empty($CONFIG['skip_tables'])) {
        output('skip_tables: ' . implode(', ', $CONFIG['skip_tables']), 'INFO');
    }
    
    if (empty($tables)) {
        output("No tables found to migrate", 'ERROR');
        exit(1);
    }
    
    output("Found " . count($tables) . " tables to migrate", 'INFO');
    output("");
    
    // Migrate tables
    set_time_limit(0);
    ini_set('memory_limit', -1);
    
    $total_records = 0;
    foreach ($tables as $table) {
        $records = migrate_table(
            $source_pdo,
            $target_pdo,
            $table,
            $CONFIG['batch_size'],
            $CONFIG['truncate_target']
        );
        $total_records += $records;
    }

    if ($mode === 'xstream-ui') {
        apply_xstream_ui_post_migration($target_pdo);
    }
    
    output("");
    output("Migration completed", 'OK');
    output("Total records migrated: " . number_format($total_records, 0), 'INFO');
    output("================================", 'INFO');

    report_migration_summary();
}

// Run if executed directly
if (php_sapi_name() === 'cli') {
    main();
} else {
    echo "This script must be run from the command line\n";
    exit(1);
}

