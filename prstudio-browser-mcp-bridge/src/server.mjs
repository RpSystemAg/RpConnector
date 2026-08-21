import http from "node:http";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { randomUUID } from "node:crypto";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StdioClientTransport } from "@modelcontextprotocol/sdk/client/stdio.js";
import { Builder, Browser, By } from "selenium-webdriver";

const HOST = "127.0.0.1";
const PORT = boundedPort(process.env.PRSTUDIO_MCP_BRIDGE_PORT || 8765);
const VERSION = "1.0.0";
const MAX_BODY_BYTES = 1024 * 1024;
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const CHROME_MCP_BIN = process.env.CHROME_DEVTOOLS_MCP_BIN
  || path.join(ROOT, "node_modules", "chrome-devtools-mcp", "build", "src", "bin", "chrome-devtools-mcp.js");

let chromeClientPromise = null;
let chromeTransport = null;
let seleniumDriver = null;
const seleniumElements = new Map();

const SELENIUM_TOOLS = Object.freeze([
  tool("selenium_start", "Start Selenium Chrome session / Avvia sessione Chrome Selenium", {
    type: "object", properties: {}, additionalProperties: false
  }),
  tool("selenium_navigate", "Navigate Selenium session / Naviga la sessione Selenium", objectSchema({
    url: { type: "string", minLength: 1 }
  }, ["url"])),
  tool("selenium_get_title", "Read page title / Leggi il titolo pagina"),
  tool("selenium_get_url", "Read current URL / Leggi URL corrente"),
  tool("selenium_find", "Find one element and return a bridge element id / Trova un elemento", objectSchema({
    using: { type: "string", enum: ["css", "xpath", "id", "name", "linkText", "partialLinkText", "tagName"] },
    value: { type: "string", minLength: 1 }
  }, ["using", "value"])),
  tool("selenium_click", "Click an element / Clicca un elemento", objectSchema({
    element_id: { type: "string", minLength: 1 }
  }, ["element_id"])),
  tool("selenium_type", "Type text into an element / Digita testo in un elemento", objectSchema({
    element_id: { type: "string", minLength: 1 },
    text: { type: "string" },
    clear: { type: "boolean", default: false }
  }, ["element_id", "text"])),
  tool("selenium_get_text", "Read element text / Leggi testo elemento", objectSchema({
    element_id: { type: "string", minLength: 1 }
  }, ["element_id"])),
  tool("selenium_get_attribute", "Read element attribute / Leggi attributo elemento", objectSchema({
    element_id: { type: "string", minLength: 1 },
    name: { type: "string", minLength: 1 }
  }, ["element_id", "name"])),
  tool("selenium_screenshot", "Capture PNG screenshot as base64 / Acquisisci screenshot PNG base64"),
  tool("selenium_back", "Browser back / Indietro"),
  tool("selenium_forward", "Browser forward / Avanti"),
  tool("selenium_refresh", "Refresh page / Ricarica pagina"),
  tool("selenium_quit", "Close Selenium session / Chiudi sessione Selenium")
]);

function tool(name, description, inputSchema = { type: "object", properties: {}, additionalProperties: false }) {
  return { name, description, inputSchema };
}

function objectSchema(properties, required = []) {
  return { type: "object", properties, required, additionalProperties: false };
}

function boundedPort(value) {
  const port = Number(value);
  if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error("Invalid PRSTUDIO_MCP_BRIDGE_PORT");
  return port;
}

function chromeArgs() {
  const args = [CHROME_MCP_BIN];
  const browserUrl = String(process.env.CHROME_DEVTOOLS_BROWSER_URL || "").trim();
  if (browserUrl) args.push(`--browser-url=${browserUrl}`);
  else args.push("--autoConnect");
  args.push("--categoryWebMCP");
  return args;
}

async function chromeClient() {
  if (chromeClientPromise) return chromeClientPromise;
  chromeClientPromise = (async () => {
    const client = new Client(
      { name: "prstudio-browser-mcp-bridge", version: VERSION },
      { capabilities: {} }
    );
    chromeTransport = new StdioClientTransport({
      command: process.execPath,
      args: chromeArgs(),
      env: {
        ...process.env,
        CHROME_DEVTOOLS_MCP_NO_UPDATE_CHECKS: "1"
      },
      stderr: "inherit"
    });
    await client.connect(chromeTransport);
    return client;
  })().catch((error) => {
    chromeClientPromise = null;
    chromeTransport = null;
    throw error;
  });
  return chromeClientPromise;
}

async function listChromeTools(provider) {
  const client = await chromeClient();
  const result = await client.listTools();
  let tools = Array.isArray(result?.tools) ? result.tools : [];
  if (provider === "chrome_webmcp") tools = tools.filter((entry) => ["list_webmcp_tools", "execute_webmcp_tool"].includes(entry?.name));
  return {
    provider,
    implementation: provider === "puppeteer"
      ? "chrome-devtools-mcp@1.7.0 (Puppeteer-based MCP server recommended by Puppeteer)"
      : "chrome-devtools-mcp@1.7.0",
    tools
  };
}

async function callChrome(provider, name, args) {
  if (!name) throw coded("MCP_TOOL_REQUIRED", "tool is required");
  if (provider === "chrome_webmcp" && !["list_webmcp_tools", "execute_webmcp_tool"].includes(name)) {
    throw coded("WEBMCP_TOOL_FORBIDDEN", "chrome_webmcp accepts only list_webmcp_tools or execute_webmcp_tool");
  }
  const client = await chromeClient();
  const result = await client.callTool({ name, arguments: plainObject(args) });
  return {
    provider,
    implementation: provider === "puppeteer"
      ? "chrome-devtools-mcp@1.7.0 (Puppeteer-based)"
      : "chrome-devtools-mcp@1.7.0",
    tool: name,
    result
  };
}

async function ensureSelenium() {
  if (seleniumDriver) return seleniumDriver;
  let builder = new Builder().forBrowser(Browser.CHROME);
  const remote = String(process.env.SELENIUM_REMOTE_URL || "").trim();
  if (remote) builder = builder.usingServer(remote);
  seleniumDriver = await builder.build();
  return seleniumDriver;
}

async function seleniumCall(name, args = {}) {
  if (!SELENIUM_TOOLS.some((entry) => entry.name === name)) throw coded("SELENIUM_TOOL_UNKNOWN", `Unknown Selenium tool: ${name}`);
  if (name === "selenium_start") {
    await ensureSelenium();
    return { ok: true, started: true, remote: Boolean(process.env.SELENIUM_REMOTE_URL) };
  }
  if (name === "selenium_quit") {
    if (seleniumDriver) await seleniumDriver.quit();
    seleniumDriver = null;
    seleniumElements.clear();
    return { ok: true, closed: true };
  }
  const driver = await ensureSelenium();
  switch (name) {
    case "selenium_navigate":
      await driver.get(requiredString(args.url, "url"));
      return { ok: true, url: await driver.getCurrentUrl(), title: await driver.getTitle() };
    case "selenium_get_title":
      return { title: await driver.getTitle() };
    case "selenium_get_url":
      return { url: await driver.getCurrentUrl() };
    case "selenium_find": {
      const target = await driver.findElement(by(args.using, requiredString(args.value, "value")));
      const elementId = randomUUID();
      seleniumElements.set(elementId, target);
      return { element_id: elementId, tag_name: await target.getTagName().catch(() => null), text: await target.getText().catch(() => "") };
    }
    case "selenium_click":
      await element(args.element_id).click();
      return { ok: true };
    case "selenium_type": {
      const target = element(args.element_id);
      if (args.clear === true) await target.clear();
      await target.sendKeys(String(args.text ?? ""));
      return { ok: true };
    }
    case "selenium_get_text":
      return { text: await element(args.element_id).getText() };
    case "selenium_get_attribute":
      return { value: await element(args.element_id).getAttribute(requiredString(args.name, "name")) };
    case "selenium_screenshot":
      return { mime_type: "image/png", base64: await driver.takeScreenshot() };
    case "selenium_back":
      await driver.navigate().back();
      return { ok: true, url: await driver.getCurrentUrl() };
    case "selenium_forward":
      await driver.navigate().forward();
      return { ok: true, url: await driver.getCurrentUrl() };
    case "selenium_refresh":
      await driver.navigate().refresh();
      return { ok: true, url: await driver.getCurrentUrl() };
    default:
      throw coded("SELENIUM_TOOL_UNIMPLEMENTED", name);
  }
}

function by(using, value) {
  switch (String(using || "css")) {
    case "css": return By.css(value);
    case "xpath": return By.xpath(value);
    case "id": return By.id(value);
    case "name": return By.name(value);
    case "linkText": return By.linkText(value);
    case "partialLinkText": return By.partialLinkText(value);
    case "tagName": return By.tagName(value);
    default: throw coded("SELENIUM_LOCATOR_UNKNOWN", `Unknown locator: ${using}`);
  }
}

function element(id) {
  const value = seleniumElements.get(requiredString(id, "element_id"));
  if (!value) throw coded("SELENIUM_ELEMENT_UNKNOWN", "Unknown or expired element_id");
  return value;
}

function requiredString(value, field) {
  const out = String(value ?? "").trim();
  if (!out) throw coded("INVALID_ARGUMENT", `${field} is required`);
  return out;
}

function plainObject(value) {
  return value && typeof value === "object" && !Array.isArray(value) ? value : {};
}

function coded(code, message, data = undefined) {
  const error = new Error(message);
  error.code = code;
  error.data = data;
  return error;
}

async function dispatch(body) {
  const provider = String(body?.provider || "");
  const operation = String(body?.operation || "tools/call");
  if (!["chrome_devtools", "chrome_webmcp", "puppeteer", "selenium"].includes(provider)) {
    throw coded("PROVIDER_UNKNOWN", `Unknown provider: ${provider}`);
  }
  if (operation === "tools/list") {
    if (provider === "selenium") {
      return {
        provider,
        implementation: "selenium-webdriver@4.47.0 (official Selenium library adapter; not an official Selenium MCP server)",
        tools: SELENIUM_TOOLS
      };
    }
    return listChromeTools(provider);
  }
  if (operation !== "tools/call") throw coded("OPERATION_UNKNOWN", `Unknown operation: ${operation}`);
  if (provider === "selenium") {
    return {
      provider,
      implementation: "selenium-webdriver@4.47.0",
      tool: String(body?.tool || ""),
      result: await seleniumCall(String(body?.tool || ""), plainObject(body?.arguments))
    };
  }
  return callChrome(provider, String(body?.tool || ""), plainObject(body?.arguments));
}

function allowedOrigin(origin) {
  if (!origin) return true;
  return /^chrome-extension:\/\/[a-p]{32}$/i.test(origin);
}

function sendJson(res, status, value, origin = "") {
  const payload = JSON.stringify(value);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Content-Length": Buffer.byteLength(payload),
    "Cache-Control": "no-store",
    "X-Content-Type-Options": "nosniff",
    ...(origin && allowedOrigin(origin) ? { "Access-Control-Allow-Origin": origin, "Vary": "Origin" } : {})
  });
  res.end(payload);
}

async function readJson(req) {
  let size = 0;
  const chunks = [];
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) throw coded("PAYLOAD_TOO_LARGE", "Request body exceeds 1 MiB");
    chunks.push(chunk);
  }
  if (!chunks.length) return {};
  try {
    return JSON.parse(Buffer.concat(chunks).toString("utf8"));
  } catch {
    throw coded("INVALID_JSON", "Invalid JSON request body");
  }
}

const server = http.createServer(async (req, res) => {
  const origin = String(req.headers.origin || "");
  if (!allowedOrigin(origin)) return sendJson(res, 403, { ok: false, error: { code: "ORIGIN_FORBIDDEN", message: "Browser origins are not allowed." } });

  if (req.method === "OPTIONS") {
    res.writeHead(204, {
      "Access-Control-Allow-Origin": origin,
      "Access-Control-Allow-Methods": "GET,POST,OPTIONS",
      "Access-Control-Allow-Headers": "Content-Type",
      "Access-Control-Max-Age": "600"
    });
    return res.end();
  }

  if (req.method === "GET" && req.url === "/health") {
    return sendJson(res, 200, {
      ok: true,
      version: VERSION,
      host: HOST,
      port: PORT,
      chrome_mcp: "chrome-devtools-mcp@1.7.0",
      selenium: "selenium-webdriver@4.47.0",
      chrome_client_started: Boolean(chromeClientPromise),
      selenium_session_started: Boolean(seleniumDriver),
      providers: ["chrome_devtools", "chrome_webmcp", "puppeteer", "selenium"]
    }, origin);
  }

  if (req.method === "POST" && req.url === "/call") {
    try {
      const body = await readJson(req);
      const result = await dispatch(body);
      return sendJson(res, 200, { ok: true, ...result }, origin);
    } catch (error) {
      return sendJson(res, 500, {
        ok: false,
        error: {
          code: String(error?.code || error?.name || "BRIDGE_ERROR"),
          message: String(error?.message || error),
          data: error?.data
        }
      }, origin);
    }
  }

  return sendJson(res, 404, { ok: false, error: { code: "NOT_FOUND", message: "Not found" } }, origin);
});

server.listen(PORT, HOST, () => {
  console.log(`[PR STUDIO] Browser MCP bridge ${VERSION} listening on http://${HOST}:${PORT}`);
});

async function shutdown() {
  server.close();
  try { if (seleniumDriver) await seleniumDriver.quit(); } catch {}
  try { await chromeTransport?.close?.(); } catch {}
  process.exit(0);
}

process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
