# Symfony Boost MCP Server

[![Latest Version](https://img.shields.io/packagist/v/itsjustvita/symfony-boost.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/symfony-boost)
[![Total Downloads](https://img.shields.io/packagist/dt/itsjustvita/symfony-boost.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/symfony-boost)
[![License](https://img.shields.io/packagist/l/itsjustvita/symfony-boost.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/symfony-boost)
[![PHP Version](https://img.shields.io/packagist/php-v/itsjustvita/symfony-boost.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/symfony-boost)

> **⚠️ BETA VERSION** - This package is currently in beta. Please report any issues you encounter.

An MCP (Model Context Protocol) server for Symfony projects that provides Claude Code and PhpStorm with advanced functionality.

## Features

- 🗄️ **Database Tools**: List tables, describe structure, execute queries
- 📊 **Doctrine Integration**: List entities, show foreign keys
- 🛣️ **Routing**: Display all routes
- 📝 **Logs**: Read log files
- 🎯 **Console**: Execute Symfony console commands
- ℹ️ **App Info**: PHP version, Symfony version, database platform

## Installation

### 1. Install Package

**Local in project (recommended):**
```bash
composer require itsjustvita/symfony-boost --dev
```

**Or globally:**
```bash
composer global require itsjustvita/symfony-boost
```

### 2. Generate Configuration

```bash
php vendor/bin/symfony-boost install
```

This command automatically creates:
- `.mcp.json` - MCP server configuration
- `.claude/settings.local.json` - Claude Code permissions

### 3. Start Claude Code/PhpStorm

The tools are now available! Test with: "List all database tables"

## Available Tools

| Tool | Description |
|------|-------------|
| `application_info` | Shows PHP version, Symfony version, database info |
| `list_tables` | Lists all database tables |
| `describe_table` | Shows table structure (columns, indexes) |
| `list_entities` | Lists all Doctrine entities |
| `get_table_sizes` | Shows number of rows per table |
| `show_foreign_keys` | Shows all foreign key constraints |
| `database_query` | Executes READ-ONLY SQL queries |
| `list_routes` | Lists all Symfony routes |
| `read_logs` | Reads the last N log entries |
| `console_command` | Executes Symfony console commands |

## Usage

### With Claude Code

Simply use natural language commands:

```
"Show me all tables"
"Describe the 'users' table"
"Execute a SELECT on 'products'"
"List all routes"
"Show the last 50 log entries"
```

### Direct (for debugging)

```bash
# Start server (stdio mode)
php vendor/bin/symfony-boost

# Show help
php vendor/bin/symfony-boost --help

# Show installation help
php vendor/bin/symfony-boost install --help
```

## Configuration

### .mcp.json Structure

```json
{
  "mcpServers": {
    "symfony-boost": {
      "command": "php",
      "args": ["vendor/bin/symfony-boost"],
      "cwd": "/path/to/project",
      "env": {
        "DATABASE_URL": "mysql://user:pass@localhost/dbname",
        "APP_ENV": "dev"
      }
    }
  }
}
```

### Manual Configuration

If `install` doesn't work, you can create the files manually:

1. Create `.mcp.json` with the structure above
2. Create `.claude/settings.local.json`:

```json
{
  "permissions": {
    "allow": [
      "mcp__symfony-boost__*"
    ]
  },
  "enableAllProjectMcpServers": true,
  "enabledMcpjsonServers": ["symfony-boost"]
}
```

## Security

- **READ-ONLY Queries Only**: The `database_query` tool only allows SELECT, SHOW, EXPLAIN, DESCRIBE
- **Automatic LIMIT**: Queries without LIMIT are automatically limited to 100 rows
- **Shell Escaping**: All paths are properly escaped
- **Trusted Source**: Commands come from Claude Code

## Troubleshooting

### "Tool not available" Error

1. Check if `.mcp.json` exists and is correct
2. Restart Claude Code
3. Run `php vendor/bin/symfony-boost install --force`

### "DATABASE_URL not found"

1. Make sure `.env` exists
2. Check if `DATABASE_URL` is defined in `.env`
3. Run `symfony-boost install` again

### Server doesn't start

```bash
# Test manually
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | php vendor/bin/symfony-boost

# Should return JSON-RPC response
```

### Tools not showing

```bash
# List all tools
echo '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | php vendor/bin/symfony-boost
```

## Development

### Project Structure

```
symfony-boost/
├── bin/
│   └── symfony-boost          # Executable
├── src/
│   ├── McpServer.php         # JSON-RPC Server
│   ├── SymfonyBoostServer.php # Tool implementations
│   └── InstallCommand.php     # Install command
├── composer.json
└── README.md
```

### Testing

```bash
# Test server manually
php vendor/bin/symfony-boost install --help
php vendor/bin/symfony-boost --help

# Test MCP protocol
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | php vendor/bin/symfony-boost
echo '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | php vendor/bin/symfony-boost
```

## Requirements

- PHP 8.1+
- Symfony 6.0+ or 7.0+
- Doctrine DBAL 3.0+ or 4.0+
- Claude Code or PhpStorm with MCP support

## License

MIT

## Support

For issues or questions:
1. Check the troubleshooting section
2. Create an issue on GitHub
3. Contact the maintainer

## Changelog

### 1.0.0-beta (Current)
- Initial beta release
- 10 core MCP tools for Symfony development
- Automatic installation command
- Support for both local and global installation
- Doctrine DBAL 3.x and 4.x compatibility

---

**Made with ❤️ for Symfony Developers**
