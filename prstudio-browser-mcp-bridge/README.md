# PR STUDIO Browser MCP Bridge

Local loopback bridge used by `prstudio-unified-browser-agent`.

## Install

```bash
cd prstudio-browser-mcp-bridge
npm install
npm start
```

Default endpoint: `http://127.0.0.1:8765`.

## Chrome DevTools MCP

The bridge starts the installed official `chrome-devtools-mcp@1.7.0` over MCP stdio. By default it uses `--autoConnect` and enables the WebMCP tool category.

For Chrome 144+:

1. open `chrome://inspect/#remote-debugging`;
2. enable Remote Debugging;
3. allow the connection prompt shown by Chrome.

To use an explicit debugging endpoint instead of auto-connect:

```bash
CHROME_DEVTOOLS_BROWSER_URL=http://127.0.0.1:9222 npm start
```

## Providers

- `chrome_devtools`: exact tools reported by Chrome DevTools MCP.
- `chrome_webmcp`: only `list_webmcp_tools` / `execute_webmcp_tool`.
- `puppeteer`: same official Chrome DevTools MCP server, documented by Puppeteer as its Puppeteer-based MCP server.
- `selenium`: bounded adapter around official `selenium-webdriver@4.47.0`.

Optional Selenium Grid:

```bash
SELENIUM_REMOTE_URL=http://127.0.0.1:4444 npm start
```

## Security

The HTTP server binds only to `127.0.0.1`. Browser requests with normal `http://` or `https://` origins are rejected; Chrome-extension origins and local non-browser clients are allowed. The bridge does not expose a public listener.
