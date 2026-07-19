<?php

namespace WPMCP\Connect;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds the downloadable Claude Desktop bundle (.mcpb) for issue #76: a
 * zip containing a manifest plus an embedded Node stdio-to-HTTP proxy that
 * bridges Claude Desktop's stdio transport to this site's MCP endpoint.
 *
 * Self-contained: the proxy uses Node builtins only (fetch, stdin/stdout)
 * and runs on the runtime Claude Desktop bundles with mcpb extensions, so
 * connecting needs no PATH lookup, npx, or package install.
 *
 * Secret-free by construction: build() takes only the endpoint URL — there
 * is no code path that could place a credential in the archive. The
 * username and Application Password are declared as required user_config
 * fields (the password marked sensitive, so the client stores it in the OS
 * keychain) and reach the proxy as environment variables at launch time.
 */
class Bundle_Builder
{
    /**
     * @param string      $endpoint The site's MCP endpoint URL to bake in.
     * @param string|null $target   Optional output path; defaults to a random
     *                              name in the temp dir.
     * @return string Path to the written .mcpb file.
     */
    public function build(string $endpoint, ?string $target = null): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to build the desktop bundle.');
        }

        $target ??= get_temp_dir() . 'wpmcp-bundle-' . wp_generate_password(12, false) . '.mcpb';

        $zip = new \ZipArchive();
        if (true !== $zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Could not open the bundle archive for writing.');
        }

        $zip->addFromString(
            'manifest.json',
            (string) wp_json_encode($this->manifest($endpoint), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $zip->addFromString('server/index.js', $this->proxy_source());
        $zip->close();

        return $target;
    }

    /** The mcpb (desktop extension) manifest. */
    public function manifest(string $endpoint): array
    {
        return [
            'manifest_version' => '0.2',
            'name'             => 'wpmcp',
            'display_name'     => 'WP MCP',
            'version'          => defined('WPMCP_VERSION') ? WPMCP_VERSION : '0.0.0',
            'description'      => 'Connect Claude Desktop to the wpmcp server at ' . $endpoint,
            'author'           => ['name' => 'wpmcp'],
            'server'           => [
                'type'        => 'node',
                'entry_point' => 'server/index.js',
                'mcp_config'  => [
                    'command' => 'node',
                    'args'    => ['${__dirname}/server/index.js'],
                    'env'     => [
                        'WPMCP_ENDPOINT'     => $endpoint,
                        'WPMCP_USERNAME'     => '${user_config.username}',
                        'WPMCP_APP_PASSWORD' => '${user_config.app_password}',
                    ],
                ],
            ],
            'user_config'      => [
                'username'     => [
                    'type'        => 'string',
                    'title'       => 'WordPress username',
                    'description' => 'The user the agent connects as. Its role and the wpmcp governance settings bound what the agent can do.',
                    'required'    => true,
                ],
                'app_password' => [
                    'type'        => 'string',
                    'title'       => 'Application password',
                    'description' => 'Generated on the wpmcp Connection screen in wp-admin. Stored in your OS keychain by Claude Desktop; never contained in this bundle.',
                    'sensitive'   => true,
                    'required'    => true,
                ],
            ],
        ];
    }

    /**
     * The embedded stdio-to-HTTP proxy. Node builtins only: reads
     * line-delimited JSON-RPC from stdin, POSTs each message to the wpmcp
     * endpoint with Basic auth from the environment, and writes responses
     * back to stdout. Notifications (no id) get forwarded but produce no
     * stdout line. SSE-formatted responses are unwrapped to their JSON data.
     */
    public function proxy_source(): string
    {
        return <<<'JS'
#!/usr/bin/env node
// wpmcp stdio-to-HTTP proxy. Self-contained: Node builtins only, no deps.
'use strict';

const endpoint = process.env.WPMCP_ENDPOINT;
const auth = 'Basic ' + Buffer.from(
  process.env.WPMCP_USERNAME + ':' + process.env.WPMCP_APP_PASSWORD
).toString('base64');

let buffer = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
  buffer += chunk;
  let newline;
  while ((newline = buffer.indexOf('\n')) !== -1) {
    const line = buffer.slice(0, newline).trim();
    buffer = buffer.slice(newline + 1);
    if (line) {
      forward(line);
    }
  }
});
process.stdin.on('end', () => process.exit(0));

async function forward(line) {
  let message;
  try {
    message = JSON.parse(line);
  } catch (err) {
    return; // Not JSON-RPC; nothing to forward.
  }
  const isNotification = message.id === undefined;
  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json, text/event-stream',
        'Authorization': auth,
      },
      body: JSON.stringify(message),
    });
    if (isNotification) {
      return;
    }
    const text = await response.text();
    const reply = extractJson(text, response.headers.get('content-type') || '');
    if (reply !== null) {
      process.stdout.write(JSON.stringify(reply) + '\n');
    } else {
      writeError(message.id, -32000, 'HTTP ' + response.status + ' from the wpmcp endpoint');
    }
  } catch (err) {
    if (!isNotification) {
      writeError(message.id, -32001, String(err && err.message ? err.message : err));
    }
  }
}

function extractJson(text, contentType) {
  if (contentType.indexOf('text/event-stream') !== -1) {
    for (const line of text.split('\n')) {
      if (line.startsWith('data:')) {
        try {
          return JSON.parse(line.slice(5));
        } catch (err) {
          // Keep scanning subsequent data: lines.
        }
      }
    }
    return null;
  }
  try {
    return JSON.parse(text);
  } catch (err) {
    return null;
  }
}

function writeError(id, code, messageText) {
  process.stdout.write(JSON.stringify({
    jsonrpc: '2.0',
    id: id,
    error: { code: code, message: messageText },
  }) + '\n');
}
JS;
    }
}
