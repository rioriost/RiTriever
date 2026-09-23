"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const path = require("node:path");

function element(value = "") {
  return {
    value, style: {}, textContent: "", disabled: false, checked: false,
    listeners: {}, attrs: {},
    getAttribute(key) { return this.attrs[key] || null; },
    addEventListener(event, callback) { this.listeners[event] = callback; },
    trigger(event) { this.listeners[event](); },
  };
}

function select(options, selected) {
  const field = element();
  field.options = options.map(([value, attrs]) => Object.assign(element(value), { attrs }));
  Object.defineProperty(field, "value", {
    get() { return field.options[field.selectedIndex]?.value || ""; },
    set(value) { field.selectedIndex = field.options.findIndex(option => option.value === value); },
  });
  Object.defineProperty(field, "selectedOptions", {
    get() { return field.selectedIndex < 0 ? [] : [field.options[field.selectedIndex]]; },
  });
  field.value = selected;
  return field;
}

function load(name, window, document) {
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../assets", name), "utf8"), { window, document });
}

function settingsTest() {
  const fields = {
    "ritriever-provider": element("azure_openai"),
    "ritriever-openai-model": select([
      ["small", { "data-dimensions": "1536" }],
      ["large", { "data-dimensions": "3072" }],
    ], "small"),
    "ritriever-dimensions": element("3"),
    "ritriever-custom-preset": select([
      ["custom", {}],
      ["azure", { "data-provider": "azure_openai", "data-endpoint": "https://YOUR-RESOURCE.invalid", "data-model": "azure-default", "data-dimensions": "1536", "data-format": "azure_openai" }],
      ["ollama", { "data-provider": "ollama", "data-endpoint": "http://local:11434/api/embed", "data-model": "nomic", "data-dimensions": "768", "data-format": "ollama" }],
    ], "azure"),
    "ritriever-custom-endpoint": element("https://edited.invalid/deployment"),
    "ritriever-custom-model": element("my-model"),
    "ritriever-custom-format": element("azure_openai"),
  };
  load("admin-settings.js", {}, {
    getElementById: id => fields[id] || null,
    querySelectorAll: () => [],
  });
  assert.equal(fields["ritriever-custom-endpoint"].value, "https://edited.invalid/deployment");
  assert.equal(fields["ritriever-custom-model"].value, "my-model");
  assert.equal(fields["ritriever-dimensions"].value, "3", "initial render never resets customization");
  assert.equal(fields["ritriever-custom-preset"].options[0].disabled, false, "manual preset available to named providers");
  fields["ritriever-provider"].value = "ollama";
  fields["ritriever-provider"].trigger("change");
  assert.equal(fields["ritriever-custom-preset"].value, "ollama");
  assert.equal(fields["ritriever-custom-model"].value, "nomic");
  assert.equal(fields["ritriever-dimensions"].value, "768");
  assert.equal(fields["ritriever-custom-format"].value, "ollama");
  fields["ritriever-custom-endpoint"].value = "http://edited.invalid";
  fields["ritriever-custom-preset"].trigger("change");
  assert.equal(fields["ritriever-custom-endpoint"].value, "http://local:11434/api/embed", "explicit preset selection applies");
  fields["ritriever-provider"].value = "openai";
  fields["ritriever-provider"].trigger("change");
  fields["ritriever-openai-model"].value = "large";
  fields["ritriever-openai-model"].trigger("change");
  assert.equal(fields["ritriever-dimensions"].value, "3072", "OpenAI dimension mapping maintained");
}

function backfillHarness() {
  const status = element(), detail = element(), bar = element(), percent = element();
  const controls = ["pause", "resume", "cancel"].map(action => Object.assign(element(), { attrs: { "data-ritriever-control": action } }));
  const nodes = {
    "[data-ritriever-status-text]": status,
    "[data-ritriever-detail-text]": detail,
    "[data-ritriever-progress-bar]": bar,
    "[data-ritriever-percent]": percent,
  };
  const root = { querySelector: key => nodes[key], querySelectorAll: () => controls };
  const requests = [];
  const timers = new Map();
  let timerId = 0;
  const window = {
    ritrieverBackfill: {
      ajaxUrl: "local", nonce: "test", autoStart: true, delayMs: 1, errorDelayMs: 2,
      concurrency: 3, maxConsecutiveErrors: 5,
      i18n: {
        progress: "%1$d/%2$d errors=%3$d", remaining: "pending=%1$d failed=%2$d",
        running: "running", complete: "complete", failed: "failed", idle: "idle",
        retrying: "retry %s", confirmCancel: "cancel?",
      },
    },
    FormData: class { constructor() { this.values = {}; } append(key, value) { this.values[key] = value; } },
    fetch(url, args) {
      return new Promise((resolve, reject) => requests.push({
        action: args.body.values.action,
        resolve: state => resolve({ ok: true, json: () => Promise.resolve({ success: true, data: state }) }),
        reject,
      }));
    },
    setTimeout(callback) { const id = ++timerId; timers.set(id, callback); return id; },
    clearTimeout(id) { timers.delete(id); },
    confirm: () => true,
  };
  load("admin-backfill.js", window, { getElementById: () => root });
  return {
    requests, timers, status, detail, controls,
    click: action => controls.find(button => button.attrs["data-ritriever-control"] === action).trigger("click"),
    tick() {
      const entry = timers.entries().next().value;
      assert.ok(entry, "one retry timer exists");
      timers.delete(entry[0]);
      entry[1]();
    },
  };
}

async function settle() {
  for (let i = 0; i < 12; i += 1) { await Promise.resolve(); }
}

async function backfillTests() {
  let h = backfillHarness();
  assert.equal(h.requests[0].action, "ritriever_backfill_status");
  h.requests[0].reject(new Error("offline"));
  await settle();
  h.tick();
  assert.equal(h.requests[1].action, "ritriever_backfill_status", "initial failure retries status, not idle worker");
  h.requests[1].resolve({ status: "running", total: 201, processed: 0, errors: 0 });
  await settle();
  assert.equal(h.requests[2].action, "ritriever_backfill_run");

  h.click("pause");
  assert.equal(h.requests[3].action, "ritriever_backfill_pause");
  h.requests[3].resolve({ status: "paused", total: 201, processed: 10, errors: 2 });
  await settle();
  h.requests[2].resolve({ status: "running", total: 201, processed: 2, errors: 0 });
  await settle();
  assert.equal(h.controls[1].disabled, false, "stale running response cannot disable Resume");
  assert.equal(h.status.textContent, "10/201 errors=2");
  assert.equal(h.timers.size, 0, "pause stops scheduling");

  h.click("resume");
  h.click("resume");
  assert.equal(h.requests.length, 5, "duplicate controls do not submit twice");
  h.requests[4].resolve({ status: "running", total: 201, processed: 10, errors: 2 });
  await settle();
  assert.equal(h.requests.length, 6, "resume starts exactly one worker");
  h.requests[5].resolve({ status: "running", total: 201, processed: 60, errors: 3, remaining: 141, remaining_failures: 144 });
  await settle();
  assert.equal(h.timers.size, 1, "one scheduling loop regardless of configured concurrency");
  assert.equal(h.status.textContent, "60/201 errors=3 pending=141 failed=144");
  h.click("cancel");
  h.requests[6].resolve({ status: "cancelled", total: 201, processed: 60, errors: 3 });
  await settle();
  assert.equal(h.timers.size, 0);
  assert.ok(h.controls.every(button => button.disabled), "cancel is terminal");

  h = backfillHarness();
  h.requests[0].resolve({ status: "running", total: 201, processed: 0, errors: 0 });
  await settle();
  h.click("pause");
  h.requests[2].resolve({ status: "paused", total: 201, processed: 0, errors: 0 });
  await settle();
  h.click("resume");
  h.requests[3].resolve({ status: "running", total: 201, processed: 0, errors: 0 });
  await settle();
  assert.equal(h.requests.length, 4, "resume waits for outstanding worker");
  h.requests[1].reject(new Error("old request failure"));
  await settle();
  assert.equal(h.timers.size, 1, "stale worker settlement starts one new loop");
  assert.equal(h.detail.textContent, "running", "stale failure cannot replace current state");
  h.tick();
  assert.equal(h.requests[4].action, "ritriever_backfill_run");
  h.requests[4].resolve({ status: "completed_with_errors", total: 201, processed: 201, errors: 1, remaining: 0, remaining_failures: 1 });
  await settle();
  assert.equal(h.timers.size, 0, "error completion is terminal");
  assert.equal(h.detail.textContent, "failed");
}

(async function () {
  settingsTest();
  await backfillTests();
  console.log("Admin preset/state regression checks passed");
})().catch(error => { console.error(error); process.exitCode = 1; });
