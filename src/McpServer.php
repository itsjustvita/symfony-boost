<?php

namespace SymfonyBoost;

/**
 * Simple MCP server without SDK
 * Implements JSON-RPC 2.0 over stdio
 */
class McpServer
{
  private array $tools = [];
  private string $serverName;
  private string $serverVersion;

  public function __construct(string $name = 'symfony-boost', string $version = '1.0.0-beta')
  {
    $this->serverName = $name;
    $this->serverVersion = $version;
  }

  /**
   * Register a tool
   */
  public function addTool(string $name, string $description, array $inputSchema, callable $handler): void
  {
    $this->tools[$name] = [
      'name' => $name,
      'description' => $description,
      'inputSchema' => $inputSchema,
      'handler' => $handler
    ];
  }

  /**
   * Start the server (stdio mode)
   */
  public function run(): void
  {
    while (true) {
      // Read JSON-RPC message from stdin
      $input = fgets(STDIN);

      if ($input === false) {
        break; // EOF
      }

      $input = trim($input);
      if (empty($input)) {
        continue;
      }

      try {
        $message = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        $response = $this->handleMessage($message);

        if ($response) {
          $this->sendResponse($response);
        }
      } catch (\Throwable $e) {
        $this->sendError(-32603, 'Internal error: ' . $e->getMessage(), $message['id'] ?? null);
      }
    }
  }

  /**
   * Process a JSON-RPC message
   */
  private function handleMessage(array $message): ?array
  {
    $method = $message['method'] ?? null;
    $params = $message['params'] ?? [];
    $id = $message['id'] ?? null;

    return match ($method) {
      'initialize' => $this->handleInitialize($id, $params),
      'tools/list' => $this->handleToolsList($id),
      'tools/call' => $this->handleToolCall($id, $params),
      'ping' => $this->handlePing($id),
      default => $this->sendError(-32601, "Method not found: {$method}", $id)
    };
  }

  /**
   * Handle initialize
   */
  private function handleInitialize($id, array $params): array
  {
    return [
      'jsonrpc' => '2.0',
      'id' => $id,
      'result' => [
        'protocolVersion' => '2024-11-05',
        'serverInfo' => [
          'name' => $this->serverName,
          'version' => $this->serverVersion
        ],
        'capabilities' => [
          'tools' => new \stdClass() // empty object
        ]
      ]
    ];
  }

  /**
   * Handle tools/list
   */
  private function handleToolsList($id): array
  {
    $tools = [];
    foreach ($this->tools as $tool) {
      $tools[] = [
        'name' => $tool['name'],
        'description' => $tool['description'],
        'inputSchema' => $tool['inputSchema']
      ];
    }

    return [
      'jsonrpc' => '2.0',
      'id' => $id,
      'result' => [
        'tools' => $tools
      ]
    ];
  }

  /**
   * Handle tools/call
   */
  private function handleToolCall($id, array $params): array
  {
    $toolName = $params['name'] ?? null;
    $arguments = $params['arguments'] ?? [];

    if (!isset($this->tools[$toolName])) {
      return $this->sendError(-32602, "Tool not found: {$toolName}", $id);
    }

    $tool = $this->tools[$toolName];
    $handler = $tool['handler'];

    try {
      $result = $handler($arguments);

      // Convert result to MCP TextContent format
      if (is_string($result)) {
        $content = [['type' => 'text', 'text' => $result]];
      } elseif (is_array($result) && isset($result['content'])) {
        $content = $result['content'];
      } else {
        $content = [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]];
      }

      return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
          'content' => $content
        ]
      ];
    } catch (\Throwable $e) {
      return $this->sendError(-32603, $e->getMessage(), $id);
    }
  }

  /**
   * Handle ping
   */
  private function handlePing($id): array
  {
    return [
      'jsonrpc' => '2.0',
      'id' => $id,
      'result' => new \stdClass() // empty object
    ];
  }

  /**
   * Send error response
   */
  private function sendError(int $code, string $message, $id): array
  {
    return [
      'jsonrpc' => '2.0',
      'id' => $id,
      'error' => [
        'code' => $code,
        'message' => $message
      ]
    ];
  }

  /**
   * Send response to stdout
   */
  private function sendResponse(array $response): void
  {
    $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite(STDOUT, $json . "\n");
    fflush(STDOUT);
  }
}