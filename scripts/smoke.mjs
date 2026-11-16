import { randomBytes } from "node:crypto";
import { request as httpRequest } from "node:http";
import { request as httpsRequest } from "node:https";

const [site, backend, key] = process.argv
  .slice(2)
  .map((value) => value?.replace(/\/+$/, ""));

if (!site || !backend || !key) {
  console.error(
    "usage: node scripts/smoke.mjs <site url> <backend url> <reverb app key>",
  );
  process.exit(2);
}

let failures = 0;

async function check(name, run) {
  try {
    const detail = await run();
    console.log(`PASS  ${name}${detail ? ` (${detail})` : ""}`);
  } catch (error) {
    failures++;
    console.log(`FAIL  ${name}: ${error.message}`);
  }
}

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

async function json(url, init = {}) {
  const response = await fetch(url, { redirect: "manual", ...init });
  const body = await response.json().catch(() => null);
  return { status: response.status, body };
}

function handshake(url, origin) {
  return new Promise((resolve, reject) => {
    const target = new URL(url);
    const secure = target.protocol === "wss:";
    const request = (secure ? httpsRequest : httpRequest)({
      host: target.hostname,
      port: target.port || (secure ? 443 : 80),
      path: `${target.pathname}${target.search}`,
      servername: target.hostname,
      headers: {
        Connection: "Upgrade",
        Upgrade: "websocket",
        "Sec-WebSocket-Version": "13",
        "Sec-WebSocket-Key": randomBytes(16).toString("base64"),
        Origin: origin,
      },
    });
    const timer = setTimeout(() => {
      request.destroy();
      reject(new Error("no answer within 10 s"));
    }, 10_000);
    request.on("upgrade", (_response, socket) => {
      socket.once("data", (frame) => {
        clearTimeout(timer);
        socket.destroy();
        resolve(frame.toString("utf8"));
      });
    });
    request.on("response", (response) => {
      clearTimeout(timer);
      reject(
        new Error(
          `answered ${response.statusCode} instead of switching protocols`,
        ),
      );
    });
    request.on("error", (error) => {
      clearTimeout(timer);
      reject(error);
    });
    request.end();
  });
}

await check("the site renders the greenhouse", async () => {
  const response = await fetch(site, { redirect: "manual" });
  expect(response.status === 200, `answered ${response.status}`);
  expect(
    (await response.text()).includes("Leave it for later"),
    "the greenhouse is not in the page",
  );
});

await check("the garden answers through the site", async () => {
  const { status, body } = await json(`${site}/api/garden`);
  expect(status === 200 && Array.isArray(body?.tasks), `answered ${status}`);
});

await check("the agent can reach its model", async () => {
  const { status, body } = await json(`${site}/api/agent`);
  expect(status === 200, `answered ${status}`);
  expect(body.mode !== "halted", body.message ?? "the agent is halted");
  return `${body.mode}${body.rest ? `, ${body.rest}` : ""}, ${body.driver} ${body.model}`;
});

await check("the examples and their timelines load", async () => {
  const { status, body } = await json(`${site}/api/examples`);
  expect(status === 200, `answered ${status}`);
  expect(body.examples.length >= 2, `${body.examples.length} examples`);
  for (const example of body.examples) {
    const timeline = await json(`${site}/api/tasks/${example.id}/timeline`);
    expect(
      timeline.status === 200,
      `the timeline of ${example.id} answered ${timeline.status}`,
    );
    expect(
      timeline.body.timeline.totals.attempts > 0,
      `the timeline of ${example.id} is empty`,
    );
  }
  return `${body.examples.length} examples`;
});

await check("the backend is healthy", async () => {
  const response = await fetch(`${backend}/up`, { redirect: "manual" });
  expect(response.status === 200, `answered ${response.status}`);
});

await check("the API refuses requests that bypass the site", async () => {
  const { status } = await json(`${backend}/api/garden`);
  expect(status === 403, `answered ${status}`);
});

await check("the API refuses changes from another site", async () => {
  const { status } = await json(`${site}/api/tasks`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      origin: "https://example.com",
      "sec-fetch-site": "cross-site",
    },
    body: JSON.stringify({ prompt: "A seed from somewhere else." }),
  });
  expect(status === 403, `answered ${status}`);
});

await check("planting without the visitor check is refused", async () => {
  const { status, body } = await json(`${site}/api/tasks`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      origin: site,
      "sec-fetch-site": "same-origin",
    },
    body: JSON.stringify({ prompt: "A seed without the visitor check." }),
  });
  expect(status === 422 && body?.errors?.turnstile, `answered ${status}`);
});

await check("Reverb accepts the site's WebSocket", async () => {
  const socket = `${backend.replace(/^http/, "ws")}/app/${key}?protocol=7&client=js&version=8.4.0&flash=false`;
  const frame = await handshake(socket, site);
  expect(
    frame.includes("pusher:connection_established"),
    "no connection_established event",
  );
});

console.log(
  failures === 0 ? "\nAll checks passed." : `\n${failures} check(s) failed.`,
);
process.exit(failures === 0 ? 0 : 1);
