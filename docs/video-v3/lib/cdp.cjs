// Minimal Chrome DevTools Protocol client — no npm dependencies.
// Requires Node >= 18 (uses the built-in global `WebSocket` and `fetch`-free http module).
//
// Design choice: each tab gets its own direct WebSocket (from the `webSocketDebuggerUrl`
// returned by PUT /json/new), instead of one browser-level connection using the
// Target domain (Target.attachToTarget + sessionId "flattening"). The Target-domain
// approach has a real race: the `Target.attachedToTarget` event can arrive before the
// command's own reply, so a listener registered after sending the command misses it
// forever with no error. Per-tab sockets sidestep that whole class of bug.
//
// Usage:
//   const cdp = require('./cdp.js');
//   const tab = await cdp.openTab('http://localhost:8080', 9333);
//   await cdp.navigate(tab, 'http://localhost:8080/login');
//   await cdp.typeInto(tab, '#user', 'alice');
//   await cdp.click(tab, 'button[type=submit]');
//   await cdp.waitFor(tab, "document.querySelector('.app-content') !== null");
//   await cdp.screenshot(tab, './scratchpad/after-login.png');
//   await cdp.closeTab(tab);

'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

function httpRequest(port, urlPath, method = 'GET') {
  return new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port, path: urlPath, method }, (res) => {
      let data = '';
      res.on('data', (chunk) => (data += chunk));
      res.on('end', () => {
        if (res.statusCode >= 400) reject(new Error(`${method} ${urlPath} -> ${res.statusCode}: ${data}`));
        else resolve(data);
      });
    });
    req.on('error', reject);
    req.end();
  });
}

// Modern Chrome rejects GET on /json/new ("Using unsafe HTTP verb") — must be PUT.
async function openTab(url, port = 9333) {
  const raw = await httpRequest(port, `/json/new?${encodeURIComponent(url)}`, 'PUT');
  const info = JSON.parse(raw);

  const tab = {
    id: info.id,
    port,
    ws: new WebSocket(info.webSocketDebuggerUrl),
    nextId: 1,
    pending: new Map(),
    consoleMessages: [],
    exceptions: [],
    handlers: new Map(),
  };

  tab.ws.addEventListener('message', (event) => {
    const msg = JSON.parse(event.data);
    if (msg.id !== undefined && tab.pending.has(msg.id)) {
      const { resolve, reject } = tab.pending.get(msg.id);
      tab.pending.delete(msg.id);
      if (msg.error) reject(new Error(msg.error.message));
      else resolve(msg.result);
    } else if (msg.method === 'Runtime.consoleAPICalled') {
      tab.consoleMessages.push(msg.params);
    } else if (msg.method === 'Runtime.exceptionThrown') {
      tab.exceptions.push(msg.params);
    }
    // Ergaenzung dieser Kopie gegenueber dem browser-testing-Skill: frei
    // registrierbare Event-Handler. Die Bildschirmaufnahme (Page.screencastFrame)
    // braucht jedes einzelne Ereignis - ohne Handler waeren die Bilder verloren.
    if (msg.method && tab.handlers && tab.handlers.has(msg.method)) {
      for (const handler of tab.handlers.get(msg.method)) handler(msg.params);
    }
  });

  await new Promise((resolve, reject) => {
    tab.ws.addEventListener('open', resolve, { once: true });
    tab.ws.addEventListener('error', reject, { once: true });
  });

  await send(tab, 'Page.enable');
  await send(tab, 'Runtime.enable');
  await send(tab, 'Network.enable');
  await send(tab, 'DOM.enable'); // required later for DOM.requestNode / setFileInputFiles
  await send(tab, 'Network.setCacheDisabled', { cacheDisabled: true });

  return tab;
}

function send(tab, method, params = {}) {
  const id = tab.nextId++;
  return new Promise((resolve, reject) => {
    tab.pending.set(id, { resolve, reject });
    tab.ws.send(JSON.stringify({ id, method, params }));
  });
}

async function navigate(tab, url) {
  // Cache-Control: immutable assets (hashed JS/CSS bundles) can otherwise serve stale
  // content from Chrome's disk cache even with a fresh reload.
  await send(tab, 'Network.setCacheDisabled', { cacheDisabled: true });
  await send(tab, 'Page.navigate', { url });
  await waitFor(tab, 'document.readyState === "complete"');
}

async function evaluate(tab, expression) {
  // Wrapping in `return (\n...\n)` guards against ASI: a bare `return` immediately
  // followed by a newline before a multi-line expression silently becomes `return;`.
  const wrapped = `(() => {\nreturn (\n${expression}\n);\n})()`;
  const result = await send(tab, 'Runtime.evaluate', {
    expression: wrapped,
    returnByValue: true,
    awaitPromise: true,
  });
  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.exception?.description || JSON.stringify(result.exceptionDetails));
  }
  return result.result.value;
}

async function waitFor(tab, expression, timeoutMs = 10000, intervalMs = 250) {
  // Prefer this over a fixed setTimeout: apps with multi-stage mounted()/async data
  // loading need unpredictable amounts of time, and a fixed wait is either flaky or wasteful.
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    if (await evaluate(tab, expression)) return true;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  throw new Error(`waitFor timed out after ${timeoutMs}ms: ${expression}`);
}

async function screenshot(tab, filePath) {
  const result = await send(tab, 'Page.captureScreenshot', { format: 'png' });
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, Buffer.from(result.data, 'base64'));
  return filePath;
}

// Real .click() calls are trusted, native events and fire framework @click/onClick
// handlers reliably. Prefer this over Input.dispatchMouseEvent with coordinates,
// which is brittle across layout/viewport changes.
async function click(tab, selector) {
  return evaluate(
    tab,
    `(() => {
      const el = document.querySelector(${JSON.stringify(selector)});
      if (!el) throw new Error('click(): selector not found: ${selector}');
      el.click();
      return true;
    })()`
  );
}

async function clickByText(tab, text, tag = '*') {
  return evaluate(
    tab,
    `(() => {
      const els = Array.from(document.querySelectorAll(${JSON.stringify(tag)}));
      const el = els.find(e => e.textContent && e.textContent.trim().includes(${JSON.stringify(text)}));
      if (!el) throw new Error('clickByText(): no element containing text: ${text}');
      el.click();
      return true;
    })()`
  );
}

// Most frameworks (Vue, React) listen for the 'input' event, not just the raw value
// assignment, so dispatch it explicitly.
async function typeInto(tab, selector, value) {
  return evaluate(
    tab,
    `(() => {
      const el = document.querySelector(${JSON.stringify(selector)});
      if (!el) throw new Error('typeInto(): selector not found: ${selector}');
      el.value = ${JSON.stringify(value)};
      el.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    })()`
  );
}

async function setDeviceMetrics(tab, { width = 390, height = 844, deviceScaleFactor = 2, mobile = true } = {}) {
  await send(tab, 'Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor, mobile });
}

// Scope the file input lookup to a container identified by visible text (e.g. a
// dialog title) — a generic `input[type=file]` query silently hits the wrong input
// whenever a page has more than one upload control mounted at once.
async function setFileInput(tab, containerText, filePath, inputSelector = 'input[type=file]') {
  const objRes = await send(tab, 'Runtime.evaluate', {
    expression: `(() => {
      const all = Array.from(document.querySelectorAll('*'));
      const container = all.find(e => e.textContent && e.textContent.includes(${JSON.stringify(containerText)}));
      if (!container) throw new Error('setFileInput(): container text not found: ${containerText}');
      const input = container.querySelector(${JSON.stringify(inputSelector)}) || document.querySelector(${JSON.stringify(inputSelector)});
      if (!input) throw new Error('setFileInput(): no file input found');
      return input;
    })()`,
  });
  // DOM.requestNode returns nodeId: 0 (no error!) instead of failing if the document
  // tree hasn't been fetched yet — DOM.getDocument must run first.
  await send(tab, 'DOM.getDocument', { depth: -1, pierce: true });
  const { nodeId } = await send(tab, 'DOM.requestNode', { objectId: objRes.result.objectId });
  await send(tab, 'DOM.setFileInputFiles', { files: [path.resolve(filePath)], nodeId });
}

// Event-Handler an- und abmelden (Ergaenzung dieser Kopie, siehe message-Dispatch).
function on(tab, method, handler) {
  if (!tab.handlers.has(method)) tab.handlers.set(method, new Set());
  tab.handlers.get(method).add(handler);
  return () => off(tab, method, handler);
}

function off(tab, method, handler) {
  if (tab.handlers.has(method)) tab.handlers.get(method).delete(handler);
}

function closeTab(tab) {
  tab.ws.close();
  return httpRequest(tab.port, `/json/close/${tab.id}`).catch(() => {});
}

async function listTabs(port = 9333) {
  return JSON.parse(await httpRequest(port, '/json/list'));
}

module.exports = {
  openTab,
  send,
  on,
  off,
  navigate,
  evaluate,
  waitFor,
  screenshot,
  click,
  clickByText,
  typeInto,
  setDeviceMetrics,
  setFileInput,
  closeTab,
  listTabs,
};
