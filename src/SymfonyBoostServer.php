<?php

namespace SymfonyBoost;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;

class SymfonyBoostServer
{
  private ?Connection $connection = null;
  private array $connectionConfig;
  private string $projectPath;
  private McpServer $server;

  public function __construct(string $databaseUrl, string $projectPath = '.')
  {
    // Parse DATABASE_URL manually for better compatibility
    $parsed = parse_url($databaseUrl);

    // Determine driver from scheme
    $driver = match($parsed['scheme'] ?? 'mysql') {
      'mysql', 'mysqli' => 'pdo_mysql',
      'pgsql', 'postgres', 'postgresql' => 'pdo_pgsql',
      'sqlite', 'sqlite3' => 'pdo_sqlite',
      default => 'pdo_mysql',
    };

    // Store connection config for lazy initialization (don't connect yet!)
    $this->connectionConfig = [
      'driver' => $driver,
      'host' => $parsed['host'] ?? 'localhost',
      'port' => $parsed['port'] ?? 3306,
      'user' => $parsed['user'] ?? 'root',
      'password' => $parsed['pass'] ?? '',
      'dbname' => trim($parsed['path'] ?? '', '/'),
    ];

    $this->projectPath = realpath($projectPath);
    $this->server = new McpServer('symfony-boost', '1.0.0-beta.5');

    $this->registerTools();
  }

  /**
   * Lazy connection initialization - only connect to database when actually needed
   * This speeds up the MCP server startup significantly
   */
  private function getConnection(): Connection
  {
    if ($this->connection === null) {
      $this->connection = DriverManager::getConnection($this->connectionConfig);
    }
    return $this->connection;
  }

  private function registerTools(): void
  {
    // Tool: application_info
    $this->server->addTool(
      'application_info',
      'Returns information about the Symfony application',
      ['type' => 'object', 'properties' => new \stdClass()],
      function() {
        $platform = $this->getConnection()->getDatabasePlatform();
        $platformName = get_class($platform);
        // Extract only the class name without namespace
        $platformName = substr($platformName, strrpos($platformName, '\\') + 1);

        return json_encode([
          'php_version' => PHP_VERSION,
          'symfony_version' => $this->getSymfonyVersion(),
          'project_path' => $this->projectPath,
          'database_platform' => $platformName,
        ], JSON_PRETTY_PRINT);
      }
    );

    // Tool: database_query
    $this->server->addTool(
      'database_query',
      'Executes READ-ONLY SQL queries (SELECT, SHOW, EXPLAIN, DESCRIBE)',
      [
        'type' => 'object',
        'properties' => [
          'query' => ['type' => 'string', 'description' => 'SQL Query'],
          'limit' => ['type' => 'integer', 'description' => 'Max rows', 'default' => 100]
        ],
        'required' => ['query']
      ],
      function(array $args) {
        $query = trim($args['query']);
        $limit = $args['limit'] ?? 100;

        // Security check
        $allowedKeywords = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];
        $firstWord = strtoupper(preg_split('/\s+/', $query)[0]);

        if (!in_array($firstWord, $allowedKeywords)) {
          throw new \Exception('Only READ-ONLY queries allowed');
        }

        if (stripos($query, 'LIMIT') === false && $firstWord === 'SELECT') {
          $query .= " LIMIT {$limit}";
        }

        $result = $this->getConnection()->fetchAllAssociative($query);

        return json_encode([
          'rows' => $result,
          'count' => count($result)
        ], JSON_PRETTY_PRINT);
      }
    );

    // Tool: list_tables
    $this->server->addTool(
      'list_tables',
      'Lists all database tables',
      ['type' => 'object', 'properties' => new \stdClass()],
      function() {
        $schemaManager = $this->getConnection()->createSchemaManager();
        $tables = $schemaManager->listTableNames();

        return json_encode([
          'tables' => $tables,
          'count' => count($tables)
        ], JSON_PRETTY_PRINT);
      }
    );

    // Tool: describe_table
    $this->server->addTool(
      'describe_table',
      'Shows the structure of a table',
      [
        'type' => 'object',
        'properties' => [
          'table_name' => ['type' => 'string', 'description' => 'Table name']
        ],
        'required' => ['table_name']
      ],
      function(array $args) {
        $tableName = $args['table_name'];
        $schemaManager = $this->getConnection()->createSchemaManager();

        $columns = $schemaManager->listTableColumns($tableName);
        $indexes = $schemaManager->listTableIndexes($tableName);

        $result = ['table' => $tableName, 'columns' => [], 'indexes' => []];

        foreach ($columns as $column) {
          $type = $column->getType();
          $typeName = get_class($type);
          $typeName = substr($typeName, strrpos($typeName, '\\') + 1);
          // Remove "Type" suffix if present
          $typeName = preg_replace('/Type$/', '', $typeName);

          $result['columns'][] = [
            'name' => $column->getName(),
            'type' => $typeName,
            'nullable' => !$column->getNotnull(),
            'default' => $column->getDefault(),
          ];
        }

        foreach ($indexes as $index) {
          $result['indexes'][] = [
            'name' => $index->getName(),
            'columns' => $index->getColumns(),
            'unique' => $index->isUnique(),
            'primary' => $index->isPrimary(),
          ];
        }

        return json_encode($result, JSON_PRETTY_PRINT);
      }
    );

    // Tool: list_entities
    $this->server->addTool(
      'list_entities',
      'Lists all Doctrine entities',
      ['type' => 'object', 'properties' => new \stdClass()],
      function() {
        $entityPath = $this->projectPath . '/src/Entity';
        if (!is_dir($entityPath)) {
          return json_encode(['error' => 'Entity directory not found']);
        }

        $entities = [];
        $files = glob($entityPath . '/*.php');

        foreach ($files as $file) {
          $content = file_get_contents($file);
          $className = basename($file, '.php');

          if (preg_match('/#\[ORM\\\\Entity\]|@ORM\\\\Entity/', $content)) {
            $tableName = null;
            if (preg_match('/#\[ORM\\\\Table\(name:\s*["\']([^"\']+)["\']/', $content, $matches)) {
              $tableName = $matches[1];
            }

            $entities[] = [
              'class' => $className,
              'table' => $tableName,
              'file' => basename($file)
            ];
          }
        }

        return json_encode(['entities' => $entities, 'count' => count($entities)], JSON_PRETTY_PRINT);
      }
    );

    // Tool: read_logs
    $this->server->addTool(
      'read_logs',
      'Reads the last N log entries',
      [
        'type' => 'object',
        'properties' => [
          'entries' => ['type' => 'integer', 'description' => 'Number of entries', 'default' => 50],
          'env' => ['type' => 'string', 'description' => 'Environment (dev/prod)', 'default' => 'dev']
        ],
        'required' => ['entries']
      ],
      function(array $args) {
        $entries = (int)($args['entries'] ?? 50);
        $env = $args['env'] ?? 'dev';
        $logPath = $this->projectPath . "/var/log/{$env}.log";

        if (!file_exists($logPath)) {
          return "Log file not found: {$logPath}";
        }

        $escapedPath = escapeshellarg($logPath);
        $output = shell_exec("tail -n {$entries} {$escapedPath}");
        return $output ?: 'No log entries';
      }
    );

    // Tool: list_routes
    $this->server->addTool(
      'list_routes',
      'Lists all Symfony routes',
      ['type' => 'object', 'properties' => new \stdClass()],
      function() {
        $escapedPath = escapeshellarg($this->projectPath);
        $output = shell_exec("cd {$escapedPath} && php bin/console debug:router --format=json 2>&1");
        return $output ?: 'Could not retrieve routes';
      }
    );

    // Tool: get_table_sizes
    $this->server->addTool(
      'get_table_sizes',
      'Shows number of rows per table',
      ['type' => 'object', 'properties' => new \stdClass()],
      function() {
        $schemaManager = $this->getConnection()->createSchemaManager();
        $tables = $schemaManager->listTableNames();

        $sizes = [];
        foreach ($tables as $table) {
          $count = $this->getConnection()->fetchOne("SELECT COUNT(*) FROM `{$table}`");
          $sizes[] = ['table' => $table, 'rows' => (int)$count];
        }

        usort($sizes, fn($a, $b) => $b['rows'] - $a['rows']);
        return json_encode($sizes, JSON_PRETTY_PRINT);
      }
    );

    // Tool: show_foreign_keys
    $this->server->addTool(
      'show_foreign_keys',
      'Shows all foreign keys',
      [
        'type' => 'object',
        'properties' => [
          'table_name' => ['type' => 'string', 'description' => 'Optional: Table name']
        ]
      ],
      function(array $args) {
        $schemaManager = $this->getConnection()->createSchemaManager();
        $tables = isset($args['table_name']) ? [$args['table_name']] : $schemaManager->listTableNames();

        $result = [];
        foreach ($tables as $tableName) {
          $foreignKeys = $schemaManager->listTableForeignKeys($tableName);
          foreach ($foreignKeys as $fk) {
            $result[] = [
              'table' => $tableName,
              'name' => $fk->getName(),
              'local_columns' => $fk->getLocalColumns(),
              'foreign_table' => $fk->getForeignTableName(),
              'foreign_columns' => $fk->getForeignColumns(),
            ];
          }
        }

        return json_encode($result, JSON_PRETTY_PRINT);
      }
    );

    // Tool: console_command
    $this->server->addTool(
      'console_command',
      'Executes Symfony console commands',
      [
        'type' => 'object',
        'properties' => [
          'command' => ['type' => 'string', 'description' => 'Command (without php bin/console)']
        ],
        'required' => ['command']
      ],
      function(array $args) {
        // Use command directly - comes from trusted source (Claude Code)
        $command = $args['command'];
        $escapedPath = escapeshellarg($this->projectPath);

        $output = shell_exec("cd {$escapedPath} && php bin/console {$command} 2>&1");
        return $output ?: 'No output';
      }
    );

    // Tool: get_config
    $this->server->addTool(
      'get_config',
      'Get configuration value using dot notation (e.g., "app.secret")',
      [
        'type' => 'object',
        'properties' => [
          'key' => ['type' => 'string', 'description' => 'Configuration key in dot notation']
        ],
        'required' => ['key']
      ],
      function(array $args) {
        $key = $args['key'];
        $escapedPath = escapeshellarg($this->projectPath);
        $escapedKey = escapeshellarg($key);

        $output = shell_exec("cd {$escapedPath} && php bin/console debug:config {$escapedKey} 2>&1");
        return $output ?: "Configuration key '{$key}' not found";
      }
    );

    // Tool: list_env_vars
    $this->server->addTool(
      'list_env_vars',
      'Lists all available environment variables from .env files',
      ['type' => 'object', 'properties' => new \stdClass()],
      function() {
        $envFiles = [
          $this->projectPath . '/.env',
          $this->projectPath . '/.env.local',
        ];

        $vars = [];
        foreach ($envFiles as $envFile) {
          if (!file_exists($envFile)) {
            continue;
          }

          $content = file_get_contents($envFile);
          $lines = explode("\n", $content);

          foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
              continue;
            }

            // Extract variable name
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=/', $line, $matches)) {
              $varName = $matches[1];
              if (!in_array($varName, $vars)) {
                $vars[] = $varName;
              }
            }
          }
        }

        sort($vars);
        return json_encode([
          'env_vars' => $vars,
          'count' => count($vars)
        ], JSON_PRETTY_PRINT);
      }
    );

    // Tool: last_error
    $this->server->addTool(
      'last_error',
      'Reads the last error from application logs',
      [
        'type' => 'object',
        'properties' => [
          'env' => ['type' => 'string', 'description' => 'Environment (dev/prod)', 'default' => 'dev']
        ]
      ],
      function(array $args) {
        $env = $args['env'] ?? 'dev';
        $logPath = $this->projectPath . "/var/log/{$env}.log";

        if (!file_exists($logPath)) {
          return "Log file not found: {$logPath}";
        }

        $escapedPath = escapeshellarg($logPath);
        // Get last 200 lines and search for ERROR level
        $output = shell_exec("tail -n 200 {$escapedPath} | grep -i 'ERROR\\|CRITICAL\\|EMERGENCY' | tail -n 10 2>&1");

        if (empty($output)) {
          return 'No errors found in recent logs';
        }

        return $output;
      }
    );

    // Tool: list_bundles
    $this->server->addTool(
      'list_bundles',
      'Lists all installed Symfony bundles',
      ['type' => 'object', 'properties' => new \stdClass()],
      function() {
        $bundlesFile = $this->projectPath . '/config/bundles.php';

        if (!file_exists($bundlesFile)) {
          return json_encode(['error' => 'bundles.php not found']);
        }

        $bundles = require $bundlesFile;
        $bundleList = [];

        foreach ($bundles as $bundleClass => $envs) {
          $bundleName = substr($bundleClass, strrpos($bundleClass, '\\') + 1);
          $bundleList[] = [
            'name' => $bundleName,
            'class' => $bundleClass,
            'environments' => array_keys(array_filter($envs))
          ];
        }

        return json_encode([
          'bundles' => $bundleList,
          'count' => count($bundleList)
        ], JSON_PRETTY_PRINT);
      }
    );
  }

  public function run(): void
  {
    $this->server->run();
  }

  private function getSymfonyVersion(): string
  {
    $composerLock = $this->projectPath . '/composer.lock';
    if (file_exists($composerLock)) {
      $lock = json_decode(file_get_contents($composerLock), true);
      foreach ($lock['packages'] ?? [] as $package) {
        if ($package['name'] === 'symfony/framework-bundle') {
          return $package['version'];
        }
      }
    }
    return 'unknown';
  }
}