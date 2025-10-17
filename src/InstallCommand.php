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

    // 8. Optional: Add to .gitignore
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
        $addition = "\n# Claude Code MCP Config\n" . implode("\n", $newEntries) . "\n";
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
}