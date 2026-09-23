const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { spawnSync } = require("node:child_process");

const root = path.resolve(__dirname, "..");
const temp = fs.mkdtempSync(path.join(os.tmpdir(), "ritriever-runtime-"));
const marker = path.join(temp, "forbidden-runtime");
const calls = path.join(temp, "container-calls");
const env = { ...process.env, PATH: `${temp}:/usr/bin:/bin`, MARKER: marker, CALLS: calls };
for (const key of Object.keys(env)) {
  if (key.startsWith("APPLE_CONTAINER_") || key.startsWith("RITRIEVER_") ||
      key.startsWith("WP_") || ["COMPOSE", "COMPOSE_FILE", "WPCLI_COMMAND", "LOCAL_WP_PATH"].includes(key)) {
    delete env[key];
  }
}
function writeExecutable(name, script) {
  fs.writeFileSync(path.join(temp, name), `#!/bin/sh\n${script}\n`, { mode: 0o755 });
}
function run(script, args = [], overrides = {}) {
  return spawnSync("/bin/sh", [script, ...args], {
    cwd: root, env: { ...env, ...overrides }, encoding: "utf8",
  });
}
try {
  for (const name of ["docker", "docker-compose", "container-compose"]) {
    writeExecutable(name, 'echo invoked >> "$MARKER"; exit 99');
  }
  writeExecutable("container", `
echo "$*" >> "$CALLS"
case "$*" in
  --version) echo 'container CLI version 1.0.0' ;;
  'system status') echo "status \${TEST_RUNTIME_STATE:-running}"; exit "\${TEST_RUNTIME_STATUS:-0}" ;;
  *) exit 98 ;;
esac`);
  const entries = [
    ["scripts/apple-container-wordpress.sh", ["status"]],
    ["scripts/apple-container-setup-stack.sh", ["mariadb"]],
    ["scripts/apple-container-smoke-test.sh", ["mariadb"]],
    ["scripts/apple-container-vector-probe.sh", ["mariadb"]],
    ["scripts/apple-container-import-wxr.sh", ["mariadb", "missing.xml"]],
    ["scripts/apple-container-reset-stack.sh", ["mariadb"]],
    ["scripts/test-wordpress-compat.sh", ["7.1", "mariadb"]],
    ["scripts/test-apple-regression.sh", []],
    ["scripts/run-plugin-check.sh", []],
    ["scripts/apple-container-runtime.sh", ["--check"]],
  ];
  for (const [script, args] of entries) {
    for (const overrides of [
      { COMPOSE: "docker compose" },
      { COMPOSE: "container-compose" },
      { COMPOSE_FILE: "docker-compose.yml" },
      { WPCLI_COMMAND: "docker run wp" },
    ]) {
      const result = run(script, args, overrides);
      assert.equal(result.status, 2, `${script}: ${result.stderr}`);
      assert.match(result.stderr, /no longer supported/);
    }
  }
  assert.equal(fs.existsSync(calls), false, "Reject overrides before any runtime invocation");
  assert.equal(run("scripts/apple-container-runtime.sh", ["--check"]).status, 0);
  assert.notEqual(run("scripts/apple-container-runtime.sh", ["--check"], { TEST_RUNTIME_STATUS: "1" }).status, 0);
  assert.notEqual(run("scripts/apple-container-runtime.sh", ["--check"], { TEST_RUNTIME_STATE: "stopped" }).status, 0);
  writeExecutable("container", "echo 'not the Apple CLI'");
  assert.notEqual(run("scripts/apple-container-runtime.sh", ["--check"]).status, 0);
  fs.unlinkSync(path.join(temp, "container"));
  assert.notEqual(run("scripts/apple-container-runtime.sh", ["--check"]).status, 0);
  writeExecutable("container", `
echo "$*" >> "$CALLS"
case "$*" in
  --version) echo 'container CLI version 1.0.0' ;;
  'system status') echo 'status running' ;;
  exec*import*) exit "\${TEST_IMPORT_STATUS:-0}" ;;
esac`);
  const wxr = path.join(temp, "synthetic.xml");
  fs.writeFileSync(wxr, "<rss/>");
  assert.equal(run("scripts/apple-container-import-wxr.sh", ["mariadb", wxr, "--delete-after-import"]).status, 0);
  assert.equal(fs.existsSync(wxr), false, "Successful import may delete the explicitly selected file");
  fs.writeFileSync(wxr, "<rss/>");
  assert.notEqual(run("scripts/apple-container-import-wxr.sh", ["mariadb", wxr, "--delete-after-import"], { TEST_IMPORT_STATUS: "1" }).status, 0);
  assert.equal(fs.existsSync(wxr), true, "Failed import must retain the original file");
  assert.match(fs.readFileSync(calls, "utf8"), /cp .*synthetic\.xml ritriever-mariadb-wp:\/tmp\/ritriever-import-/);
  assert.equal(fs.existsSync(marker), false, "No Docker or alternate runtime may execute");
  const scripts = fs.readdirSync(path.join(root, "scripts")).filter(name => name.endsWith(".sh"));
  for (const name of scripts) {
    assert.ok(!name.startsWith("docker-"), `Legacy runnable script: ${name}`);
    const source = fs.readFileSync(path.join(root, "scripts", name), "utf8");
    assert.doesNotMatch(source, /(?:^|[;&|]\s*|\n\s*)(?:exec\s+)?docker(?:-compose)?\s/m);
    assert.doesNotMatch(source, /\$(?:COMPOSE|WPCLI_COMMAND)\s/);
  }
  for (const name of ["docker-compose.yml", "docker-compose.default.yml", "compose.yaml", "compose.yml"]) {
    assert.equal(fs.existsSync(path.join(root, name)), false, `Unexpected legacy manifest: ${name}`);
  }
  const makefile = fs.readFileSync(path.join(root, "Makefile"), "utf8");
  assert.doesNotMatch(makefile, /\bdocker(?:-compose)?\s/);
  assert.match(makefile, /static-check:.*apple-container-check/);
  console.log("Apple-only runtime guards passed (no Docker/Compose execution)");
} finally {
  fs.rmSync(temp, { recursive: true, force: true });
}
