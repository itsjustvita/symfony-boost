<?php

namespace SymfonyBoost;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class InstallCommand extends Command
{
  protected function configure(): void
  {
    $this
      ->setName('install')
      ->setDescription('Installs Symfony Boost in a Symfony project (creates mcp.json and .claude/settings.local.json)')
      ->addOption(
        'path',
        null,
        InputOption::VALUE_OPTIONAL,
        'Path to symfony-boost binary (if not globally installed)'
      )
      ->addOption(
        'force',
        'f',
        InputOption::VALUE_NONE,
        'Overwrite existing configuration'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $fs = new Filesystem();

    // Current directory (should be a Symfony project)
    $projectPath = getcwd();

    $io->title('Symfony Boost Installation for PhpStorm/Claude Code');

    // 1. Check if this is a Symfony project
    if (!file_exists($projectPath . '/bin/console')) {
      $io->error('This does not appear to be a Symfony project. bin/console not found.');
      $io->note('Run this command in the root directory of your Symfony project.');
      return Command::FAILURE;
    }

    $io->success('✓ Symfony project detected: ' . basename($projectPath));

    // 2. Check if .env exists
    $envFile = $projectPath . '/.env';
    if (!file_exists($envFile)) {
      $io->warning('⚠ .env file not found. Make sure DATABASE_URL is configured.');
    } else {
      // Check DATABASE_URL
      $envContent = file_get_contents($envFile);
      if (!str_contains($envContent, 'DATABASE_URL')) {
        $io->warning('⚠ DATABASE_URL not found in .env. Add this variable.');
      } else {
        $io->success('✓ DATABASE_URL found in .env');
      }
    }

    // 3. Determine path to symfony-boost binary
    $boostPath = $input->getOption('path');

    if (!$boostPath) {
      // Try to find different paths (prefer local)
      $possiblePaths = [
        // 1. PREFERRED: Locally installed in project
        $projectPath . '/vendor/bin/symfony-boost',
        // 2. Global composer
        $_SERVER['HOME'] . '/.composer/vendor/bin/symfony-boost',
        $_SERVER['HOME'] . '/.config/composer/vendor/bin/symfony-boost',
        '/usr/local/bin/symfony-boost',
        // 3. Directly in PATH
        trim(shell_exec('which symfony-boost 2>/dev/null') ?: ''),
      ];

      foreach ($possiblePaths as $path) {
        if (!empty($path) && file_exists($path)) {
          $boostPath = $path;
          break;
        }
      }

      if (!$boostPath) {
        $io->error('✗ symfony-boost binary not found!');
        $io->listing([
          'RECOMMENDED: Install it locally in the project: composer require itsjustvita/symfony-boost --dev',
          'Or install it globally: composer global require itsjustvita/symfony-boost',
          'Or specify the path manually: symfony-boost install --path=/path/to/binary',
        ]);
        return Command::FAILURE;
      }
    }

    // Make sure the path is absolute
    if ($boostPath[0] !== '/') {
      $boostPath = realpath($boostPath);
    }

    $io->success('✓ Symfony Boost binary found: ' . $boostPath);

    // Use relative path for local installation (more portable!)
    $pathForConfig = $boostPath;
    if (str_starts_with($boostPath, $projectPath . '/vendor/')) {
      $pathForConfig = 'vendor/bin/symfony-boost';
      $io->note('Using relative path for local installation: ' . $pathForConfig);
    }

    // 4. Create .claude directory
    $claudeDir = $projectPath . '/.claude';
    if (!is_dir($claudeDir)) {
      $fs->mkdir($claudeDir);
      $io->success('✓ .claude directory created');
    }

    // 5. Check if files already exist
    $mcpJsonPath = $projectPath . '/.mcp.json';
    $settingsPath = $claudeDir . '/settings.local.json';

    $forceOverwrite = $input->getOption('force');

    if ((file_exists($mcpJsonPath) || file_exists($settingsPath)) && !$forceOverwrite) {
      $io->warning('⚠ Configuration files already exist!');

      if (!$io->confirm('Do you want to overwrite the existing files?', false)) {
        $io->info('Installation cancelled. Use --force to overwrite.');
        return Command::SUCCESS;
      }
    }

    // 6. Create mcp.json with the CORRECT path
    // Read DATABASE_URL from .env if available
    $databaseUrl = null;
    if (file_exists($envFile)) {
      $envContent = file_get_contents($envFile);
      if (preg_match('/^DATABASE_URL=(.+)$/m', $envContent, $matches)) {
        $databaseUrl = trim($matches[1], '"\'');
      }
    }

    $serverConfig = [
      'command' => 'php',
      'args' => [
        $pathForConfig  // ✅ Relative path for local installation, absolute otherwise
      ],
      'cwd' => $projectPath
    ];

    // Add DATABASE_URL if found
    if ($databaseUrl) {
      $serverConfig['env'] = [
        'DATABASE_URL' => $databaseUrl,
        'APP_ENV' => 'dev'
      ];
    }

    $mcpConfig = [
      'mcpServers' => [
        'symfony-boost' => $serverConfig
      ]
    ];

    $mcpJson = json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    try {
      file_put_contents($mcpJsonPath, $mcpJson);
      $io->success('✓ mcp.json successfully created!');
    } catch (\Exception $e) {
      $io->error('✗ Could not create mcp.json: ' . $e->getMessage());
      return Command::FAILURE;
    }

    // 7. Create .claude/settings.local.json
    $settingsConfig = [
      'permissions' => [
        'allow' => [
          'mcp__symfony-boost__application_info',
          'mcp__symfony-boost__database_query',
          'mcp__symfony-boost__list_tables',
          'mcp__symfony-boost__describe_table',
          'mcp__symfony-boost__list_entities',
          'mcp__symfony-boost__read_logs',
          'mcp__symfony-boost__list_routes',
          'mcp__symfony-boost__get_table_sizes',
          'mcp__symfony-boost__show_foreign_keys',
          'mcp__symfony-boost__console_command',
          'Bash(php bin/console:*)',
          'Bash(php bin/console cache:clear:*)',
          'Bash(php bin/console debug:*)',
        ]
      ],
      'enableAllProjectMcpServers' => true,
      'enabledMcpjsonServers' => [
        'symfony-boost'
      ]
    ];

    $settingsJson = json_encode($settingsConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    try {
      file_put_contents($settingsPath, $settingsJson);
      $io->success('✓ .claude/settings.local.json successfully created!');
    } catch (\Exception $e) {
      $io->error('✗ Could not create settings.local.json: ' . $e->getMessage());
      return Command::FAILURE;
    }

    // 8. Create CLAUDE.md with AI guidelines
    $this->createClaudeMd($projectPath, $io);

    // 9. Optional: Add to .gitignore
    $gitignorePath = $projectPath . '/.gitignore';
    if (file_exists($gitignorePath)) {
      $gitignoreContent = file_get_contents($gitignorePath);
      $needsUpdate = false;
      $newEntries = [];

      if (!str_contains($gitignoreContent, '.mcp.json')) {
        $newEntries[] = '.mcp.json';
        $needsUpdate = true;
      }

      if (!str_contains($gitignoreContent, '.claude/')) {
        $newEntries[] = '.claude/';
        $needsUpdate = true;
      }

      if ($needsUpdate && $io->confirm('Do you want to add .mcp.json and .claude/ to .gitignore?')) {
        $addition = "\n# Symfony Boost MCP Config\n" . implode("\n", $newEntries) . "\n";
        file_put_contents($gitignorePath, $gitignoreContent . $addition);
        $io->success('✓ Entries added to .gitignore');
      }
    }

    // 9. Show created files
    $io->section('Created configuration files:');

    $io->writeln('<info>mcp.json:</info>');
    $io->writeln($mcpJson);

    $io->newLine();
    $io->writeln('<info>.claude/settings.local.json:</info>');
    $io->writeln($settingsJson);

    // 10. Final instructions
    $io->newLine();
    $io->success('✓ Installation complete! 🚀');

    $io->section('Next steps for PhpStorm/Claude Code:');
    $io->listing([
      'Make sure DATABASE_URL is configured in .env',
      'Open PhpStorm with this project',
      'Claude Code should automatically detect the configuration',
      'If not: Settings → Tools → Claude Code → Refresh',
      'Test with Claude Code: "List all tables"',
    ]);

    $io->note([
      'The .claude/settings.local.json defines permissions for tools',
      'The mcp.json defines the server configuration',
      'Binary path: ' . $boostPath
    ]);

    $io->info('💡 For issues: symfony-boost install --help');

    return Command::SUCCESS;
  }

  /**
   * Create CLAUDE.md file with AI development guidelines
   */
  private function createClaudeMd(string $projectPath, SymfonyStyle $io): void
  {
    $claudeMdPath = $projectPath . '/CLAUDE.md';

    // Check if file already exists
    if (file_exists($claudeMdPath)) {
      if (!$io->confirm('CLAUDE.md already exists. Overwrite?', false)) {
        $io->note('Skipped CLAUDE.md creation');
        return;
      }
    }

    $claudeMdContent = <<<'CLAUDE_MD'
# Symfony Boost - AI Development Guidelines

This file provides guidance to Claude Code and other AI assistants when working with this Symfony project.

## Symfony Boost MCP Server

This project uses **Symfony Boost**, an MCP server with powerful tools designed specifically for Symfony applications. Always use these tools when available - they provide accurate, project-specific information.

## When to Use Which Tool

### Application Information
- Use `application_info` to check PHP version, Symfony version, and database platform
- Use `list_entities` to discover available Doctrine entities before querying
- Use `list_routes` to see all available routes and their configurations

### Database Operations
- **Read-only queries**: Use `database_query` for SELECT, SHOW, EXPLAIN, DESCRIBE operations
  - This tool automatically limits results to 100 rows for safety
  - Only read-only operations are allowed - no INSERT, UPDATE, DELETE
- **Schema inspection**: Use `describe_table` to see table structure (columns, indexes, types)
- **Quick overview**: Use `list_tables` to see all available database tables
- **Data volume**: Use `get_table_sizes` to see row counts per table
- **Relationships**: Use `show_foreign_keys` to understand table relationships

### Debugging & Logs
- Use `read_logs` to check recent application logs (default: last 50 entries)
- Always check logs after implementing new features or fixing bugs
- Specify environment: `dev` or `prod` (default is `dev`)

### Console Commands
- Use `console_command` to execute Symfony console commands
- Examples:
  - `console_command cache:clear` - Clear cache
  - `console_command debug:router` - Debug routes
  - `console_command make:entity` - Create new entity
  - `console_command doctrine:schema:update --dump-sql` - Preview schema changes

### Routing
- Use `list_routes` before suggesting new routes to avoid conflicts
- Check existing route patterns and naming conventions

## Best Practices

### Database Queries
1. **Always use `list_tables` first** to see available tables
2. **Use `describe_table`** to understand table structure before querying
3. **Check `show_foreign_keys`** to understand relationships
4. **Then use `database_query`** for actual data retrieval

Example workflow:
```
1. list_tables → See all tables
2. describe_table('users') → See user table structure
3. database_query("SELECT * FROM users WHERE active = 1") → Get data
```

### Doctrine Entities
- Use `list_entities` to see all available Doctrine entities
- Respect existing entity relationships and naming conventions
- Follow Doctrine best practices for entity design

### Security
- **Never suggest** using raw SQL for write operations
- **Always use** Doctrine ORM for data manipulation (INSERT, UPDATE, DELETE)
- **Validate** user input before queries
- **Use** parameterized queries through Doctrine

### Symfony Conventions
- Follow Symfony directory structure (src/, config/, templates/, etc.)
- Use dependency injection and autowiring
- Leverage Symfony's service container
- Follow PSR-4 autoloading standards

### Testing
- Suggest PHPUnit tests for new features
- Use Symfony's test utilities (WebTestCase, KernelTestCase)
- Test database interactions with fixtures

## Tool Usage Examples

### Exploring a New Project
```
1. application_info → Check versions and setup
2. list_entities → See available models
3. list_routes → Understand routing structure
4. list_tables → See database schema
5. get_table_sizes → Identify main tables
```

### Debugging Issues
```
1. read_logs → Check recent errors
2. console_command debug:router → Verify routes
3. console_command debug:container → Check services
4. database_query → Inspect data
```

### Building Features
```
1. list_routes → Check existing routes
2. list_entities → See available entities
3. show_foreign_keys → Understand relationships
4. console_command make:controller → Generate code
5. console_command doctrine:schema:update --dump-sql → Preview changes
```

## Important Reminders

- **Read before write**: Always inspect existing code/schema before suggesting changes
- **Use the tools**: Don't guess about routes, entities, or schema - use the MCP tools
- **Follow Symfony**: Stick to Symfony conventions and best practices
- **Test your suggestions**: Verify routes and commands exist before suggesting them
- **Check logs**: Always review logs when debugging or after implementing features

## Symfony Version Compatibility

Check the Symfony version using `application_info` tool. Always ensure suggestions are compatible with the detected version.

## Getting Help

- Check Symfony documentation for version-specific features
- Use `console_command list` to see all available console commands
- Use `console_command debug:config` to see configuration options
CLAUDE_MD;

    try {
      file_put_contents($claudeMdPath, $claudeMdContent);
      $io->success('✓ CLAUDE.md successfully created!');
    } catch (\Exception $e) {
      $io->warning('⚠ Could not create CLAUDE.md: ' . $e->getMessage());
    }
  }
}