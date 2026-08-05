const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");


function loadApi() {
    const sandbox = {
        URLSearchParams,
        AbortController,
        setTimeout,
        clearTimeout
    };
    vm.runInNewContext(
        fs.readFileSync(
            path.resolve(
                __dirname,
                "..",
                "..",
                "..",
                "tracker-api.js"
            ),
            "utf8"
        ),
        sandbox
    );
    return sandbox.TrackerApi;
}


function response({
    status = 200,
    body
}) {
    return {
        ok: status >= 200 && status < 300,
        status,
        async text() {
            return (
                typeof body === "string"
                    ? body
                    : JSON.stringify(body)
            );
        }
    };
}


test("reads current session", async () => {
    const TrackerApi = loadApi();
    const api = TrackerApi.createTrackerApi({
        fetchImpl: async url => {
            assert.equal(
                url,
                "/api/v1/sessions/current"
            );
            return response({
                body: {
                    api_version: "v1",
                    session: {
                        session_id: "session-1"
                    }
                }
            });
        }
    });

    const session = await api.getCurrentSession();
    assert.equal(session.session_id, "session-1");
});


test("reads active session without falling back to current", async () => {
    const TrackerApi = loadApi();
    const calls = [];
    const api = TrackerApi.createTrackerApi({
        fetchImpl: async (url, options) => {
            calls.push({ url, options });
            return response({
                body: {
                    api_version: "v1",
                    session: {
                        session_id: "sniffer-1",
                        producer_type:
                            "websocket_sniffer"
                    }
                }
            });
        }
    });
    const controller = new AbortController();

    const session = await api.getActiveSession({
        signal: controller.signal
    });

    assert.equal(session.session_id, "sniffer-1");
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, "/api/v1/sessions/active");
    assert.notEqual(
        calls[0].options.signal,
        controller.signal
    );
    assert.equal(calls[0].options.signal.aborted, false);
});


test("reads the diagnostics aggregate without mutation credentials", async () => {
    const TrackerApi = loadApi();
    const calls = [];
    const api = TrackerApi.createTrackerApi({
        fetchImpl: async (url, options) => {
            calls.push({ url, options });
            return response({
                body: {
                    api_version: "v1",
                    room_observation: {
                        observed_room_state: "WAITING"
                    },
                    cdp_diagnostic: {
                        connection_state: "CONNECTED"
                    },
                    server_time: "2026-07-29T15:00:00Z"
                }
            });
        }
    });

    const body = await api.getDiagnosticsStatus();

    assert.equal(
        calls[0].url,
        "/api/v1/diagnostics/status"
    );
    assert.equal(
        calls[0].options.headers.Accept,
        "application/json"
    );
    assert.equal(
        calls[0].options.headers.Authorization,
        undefined
    );
    assert.equal(
        body.room_observation.observed_room_state,
        "WAITING"
    );
});


test("sends cursor event query and validates page", async () => {
    const TrackerApi = loadApi();
    const api = TrackerApi.createTrackerApi({
        fetchImpl: async url => {
            const parsed = new URL(
                url,
                "http://tracker.test"
            );
            assert.equal(
                parsed.pathname,
                "/api/v1/events"
            );
            assert.equal(
                parsed.searchParams.get("session_id"),
                "session-1"
            );
            assert.equal(
                parsed.searchParams.get("after_sequence"),
                "7"
            );
            return response({
                body: {
                    session_id: "session-1",
                    after_sequence: 7,
                    events: [],
                    next_sequence: 7,
                    has_more: false,
                    retention_start_sequence: 1
                }
            });
        }
    });

    const page = await api.getEvents({
        sessionId: "session-1",
        afterSequence: 7
    });
    assert.equal(page.next_sequence, 7);
});


test("preserves structured API errors", async () => {
    const TrackerApi = loadApi();
    const api = TrackerApi.createTrackerApi({
        fetchImpl: async () => response({
            status: 409,
            body: {
                error: {
                    code: "CURSOR_EXPIRED",
                    message: "expired",
                    details: {
                        retention_start_sequence: 5
                    }
                }
            }
        })
    });

    await assert.rejects(
        api.getEvents({
            sessionId: "session-1"
        }),
        error => (
            error.code === "CURSOR_EXPIRED" &&
            error.details.retention_start_sequence === 5
        )
    );
});


test("reports network and invalid JSON failures", async () => {
    const TrackerApi = loadApi();
    const networkApi = TrackerApi.createTrackerApi({
        fetchImpl: async () => {
            throw new Error("offline");
        }
    });
    await assert.rejects(
        networkApi.getCurrentSession(),
        error => error.code === "NETWORK_ERROR"
    );

    const invalidJsonApi = TrackerApi.createTrackerApi({
        fetchImpl: async () => response({
            body: "not-json"
        })
    });
    await assert.rejects(
        invalidJsonApi.getCurrentSession(),
        error => error.code === "INVALID_JSON"
    );
});


test("times out a hung request and aborts fetch", async () => {
    const TrackerApi = loadApi();
    let fetchSignal = null;
    const api = TrackerApi.createTrackerApi({
        requestTimeoutMs: 5,
        fetchImpl: async (_url, options) => {
            fetchSignal = options.signal;
            return new Promise((_resolve, reject) => {
                fetchSignal.addEventListener(
                    "abort",
                    () => {
                        const error = new Error("aborted");
                        error.name = "AbortError";
                        reject(error);
                    },
                    { once: true }
                );
            });
        }
    });

    await assert.rejects(
        api.getCurrentSession(),
        error => error.code === "REQUEST_TIMEOUT"
    );
    assert.equal(fetchSignal.aborted, true);
});


test("preserves caller cancellation as AbortError", async () => {
    const TrackerApi = loadApi();
    const controller = new AbortController();
    const api = TrackerApi.createTrackerApi({
        fetchImpl: async (_url, options) =>
            new Promise((_resolve, reject) => {
                options.signal.addEventListener(
                    "abort",
                    () => {
                        const error = new Error("aborted");
                        error.name = "AbortError";
                        reject(error);
                    },
                    { once: true }
                );
            })
    });

    const pending = api.getActiveSession({
        signal: controller.signal
    });
    controller.abort();
    await assert.rejects(
        pending,
        error => error.name === "AbortError"
    );
});
