const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");


function loadScript(name, sandbox) {
    vm.runInNewContext(
        fs.readFileSync(
            path.resolve(
                __dirname,
                "..",
                "..",
                "..",
                name
            ),
            "utf8"
        ),
        sandbox
    );
}


function loadModules() {
    const sandbox = {
        setTimeout,
        clearTimeout,
        AbortController
    };
    loadScript("tracker-domain-reducer.js", sandbox);
    loadScript("observation-poller.js", sandbox);
    return {
        poller: sandbox.ObservationPoller,
        reducer: sandbox.TrackerDomainReducer
    };
}


function view() {
    return {
        states: [],
        render(state) {
            this.states.push(state);
        }
    };
}


function page(
    sessionId,
    events = [],
    {
        nextSequence = (
            events.length
                ? events.at(-1).sequence
                : 0
        ),
        hasMore = false
    } = {}
) {
    return {
        session_id: sessionId,
        after_sequence: 0,
        events,
        next_sequence: nextSequence,
        has_more: hasMore,
        retention_start_sequence: 1
    };
}


function durable(
    sessionId,
    sequence,
    eventType = "battle.started",
    payload = {},
    overrides = {}
) {
    const eventId = `ws:${sequence}:0:${eventType}`;
    return {
        event_id: eventId,
        session_id: sessionId,
        sequence,
        event_type: eventType,
        payload: {
            domain_event: {
                domain_event_schema_version: 1,
                event_type: eventType,
                payload,
                source: "websocket",
                source_event: "test",
                source_sequence: sequence,
                source_observation_index: 0,
                source_direction: "received",
                idempotency_key: eventId,
                occurred_at: `time-${sequence}`,
                protocol_side: "A",
                resolved_side: "self",
                visibility: "public",
                confirmation: "confirmed",
                confidence: 1,
                authority: "authoritative",
                producer_session_id: sessionId,
                ...overrides
            }
        }
    };
}


function loadingEvent(sessionId, sequence) {
    return {
        event_id: `event-${sequence}`,
        session_id: sessionId,
        sequence,
        event_type: "loading_observed",
        payload: {
            is_loading: true,
            observation_mode: "change"
        },
        confidence: 0.9,
        occurred_at: `time-${sequence}`
    };
}


function memoryStore(reducer, {
    saveError = null
} = {}) {
    const projections = new Map();
    const checkpoints = new Map();
    const calls = [];
    return {
        projections,
        checkpoints,
        calls,
        async openTrackerDatabase() {},
        async loadConsumerCheckpoint(id) {
            return checkpoints.get(id) || null;
        },
        async saveConsumerCheckpoint(value) {
            if (saveError) {
                throw saveError;
            }
            const previous = checkpoints.get(
                value.consumer_id
            );
            if (
                previous &&
                previous.session_id === value.session_id &&
                value.after_sequence <
                    previous.after_sequence
            ) {
                const error = new Error("regression");
                error.code = "CHECKPOINT_REGRESSION";
                throw error;
            }
            checkpoints.set(value.consumer_id, value);
            return value;
        },
        async loadDomainProjection(sessionId) {
            const value = projections.get(sessionId);
            return value
                ? JSON.parse(JSON.stringify(value))
                : null;
        },
        async applyDomainEventBatch(options) {
            calls.push(options);
            if (saveError) {
                throw saveError;
            }
            let state = projections.get(
                options.sessionId
            ) || reducer.createInitialProjection(
                options.sessionId
            );
            state = JSON.parse(JSON.stringify(state));
            if (
                state.last_sequence !==
                    options.expectedLastSequence
            ) {
                const error = new Error("conflict");
                error.code =
                    "PROJECTION_CURSOR_CONFLICT";
                throw error;
            }
            const applied = new Set(
                state.applied_event_ids
            );
            let sequence = state.last_sequence;
            for (const event of options.events) {
                if (
                    event.sequence <= sequence &&
                    applied.has(event.event_id)
                ) {
                    continue;
                }
                if (event.sequence !== sequence + 1) {
                    const error = new Error("gap");
                    error.code = "SEQUENCE_GAP";
                    throw error;
                }
                if (applied.has(event.event_id)) {
                    const error = new Error("duplicate");
                    error.code = "DUPLICATE_EVENT_ID";
                    throw error;
                }
                state = options.reduceEvent(
                    state,
                    event
                ).nextState;
                sequence = event.sequence;
                applied.add(event.event_id);
                state.last_sequence = sequence;
                state.applied_event_ids = [...applied];
            }
            state.updated_at = options.updatedAt;
            state.commit_token = options.commitToken;
            projections.set(options.sessionId, state);
            return JSON.parse(JSON.stringify(state));
        }
    };
}


function activeSession(
    sessionId,
    producerType = "websocket_sniffer"
) {
    return {
        session_id: sessionId,
        producer_type: producerType
    };
}


function makePoller({
    api,
    store,
    reducer,
    targetView = view(),
    createCommitToken = () => "commit-1",
    schedule = () => null,
    cancel = () => {}
}) {
    const { poller } = loadModules();
    return {
        instance: poller.createObservationPoller({
            api,
            view: targetView,
            checkpointStore: store,
            domainReducer: reducer,
            createCommitToken,
            schedule,
            cancel
        }),
        targetView
    };
}


test("NO_ACTIVE_SESSION is an idle state with no fallback", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    let eventCalls = 0;
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                const error = new Error("none");
                error.code = "NO_ACTIVE_SESSION";
                throw error;
            },
            async getEvents() {
                eventCalls += 1;
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const state = await instance.pollOnce();

    assert.equal(state.connection, "waiting");
    assert.equal(state.sessionId, null);
    assert.equal(eventCalls, 0);
});


test("diagnostics remain visible while no Tracker session is active", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const renders = [];
    const instance = poller.createObservationPoller({
        api: {
            async getDiagnosticsStatus() {
                return {
                    room_observation: {
                        observed_room_state: "WAITING",
                        freshness_state: "FRESH"
                    },
                    cdp_diagnostic: {
                        connection_state: "CONNECTED",
                        injection_state: "INJECTED",
                        ruleset_apply_state: "WAITING_ASSET"
                    },
                    server_time: "2026-07-29T15:00:00Z"
                };
            },
            async getActiveSession() {
                const error = new Error("none");
                error.code = "NO_ACTIVE_SESSION";
                throw error;
            }
        },
        view: {
            render(state) {
                renders.push(state);
            }
        },
        checkpointStore: store,
        domainReducer: reducer
    });

    const state = await instance.pollOnce();

    assert.equal(state.connection, "waiting");
    assert.equal(
        state.diagnostics.room_observation.observed_room_state,
        "WAITING"
    );
    assert.equal(
        state.diagnostics.cdp_diagnostic.injection_state,
        "INJECTED"
    );
    assert.ok(renders.length > 0);
});


test("session close notifies the Auto Sync boundary once", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const cleared = [];
    let calls = 0;
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                calls += 1;
                if (calls === 1) {
                    return activeSession("session-1");
                }
                const error = new Error("none");
                error.code = "NO_ACTIVE_SESSION";
                throw error;
            },
            async getEvents() {
                return page("session-1", []);
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer,
        onSessionCleared(sessionId) {
            cleared.push(sessionId);
        }
    });

    await instance.pollOnce();
    await instance.pollOnce();
    await instance.pollOnce();

    assert.deepEqual(cleared, ["session-1"]);
});


test("active domain session starts at zero and commits atomically", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const committedBatches = [];
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession("session-1");
            },
            async getEvents(options) {
                assert.equal(options.afterSequence, 0);
                return page(
                    "session-1",
                    [durable("session-1", 1)]
                );
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer,
        createCommitToken: () => "commit-one",
        onDomainBatchCommitted(batch) {
            committedBatches.push(batch);
        }
    });

    const state = await instance.pollOnce();

    assert.equal(state.connection, "connected");
    assert.equal(state.cursor, 1);
    assert.equal(
        state.projection.battle_status,
        "active"
    );
    assert.equal(
        store.projections.get("session-1")
            .commit_token,
        "commit-one"
    );
    assert.equal(committedBatches.length, 1);
    assert.equal(
        committedBatches[0].sessionId,
        "session-1"
    );
    assert.equal(
        committedBatches[0].events[0].event_id,
        "ws:1:0:battle.started"
    );
    assert.equal(
        committedBatches[0]
            .projection.commit_token,
        "commit-one"
    );
});


test("refresh restores the session-scoped projection cursor", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const projection = reducer.createInitialProjection(
        "session-1"
    );
    projection.last_sequence = 7;
    projection.commit_token = "saved";
    store.projections.set("session-1", projection);
    let afterSequence = null;
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession("session-1");
            },
            async getEvents(options) {
                afterSequence = options.afterSequence;
                return page(
                    "session-1",
                    [],
                    { nextSequence: 7 }
                );
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const state = await instance.pollOnce();

    assert.equal(afterSequence, 7);
    assert.equal(state.cursor, 7);
    assert.equal(state.checkpointCommitToken, "saved");
});


test("active session switch preserves both projections", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    let active = "session-1";
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession(active);
            },
            async getEvents({ sessionId, afterSequence }) {
                if (afterSequence > 0) {
                    return page(
                        sessionId,
                        [],
                        { nextSequence: afterSequence }
                    );
                }
                return page(
                    sessionId,
                    [durable(sessionId, 1)]
                );
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    await instance.pollOnce();
    active = "session-2";
    await instance.pollOnce();
    active = "session-1";
    const state = await instance.pollOnce();

    assert.equal(state.sessionId, "session-1");
    assert.equal(state.cursor, 1);
    assert.equal(store.projections.size, 2);
});


test("sequence gap rolls back projection and cursor", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession("session-1");
            },
            async getEvents() {
                return page(
                    "session-1",
                    [durable("session-1", 2)]
                );
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const state = await instance.pollOnce();

    assert.equal(state.cursor, 0);
    assert.equal(state.connection, "warning");
    assert.equal(store.projections.size, 0);
});


test("wrong session and unsupported schema do not advance cursor", async () => {
    const { poller, reducer } = loadModules();
    for (const mutate of [
        event => {
            event.session_id = "other";
        },
        event => {
            event.payload.domain_event
                .domain_event_schema_version = 2;
        }
    ]) {
        const store = memoryStore(reducer);
        const event = durable("session-1", 1);
        mutate(event);
        const instance = poller.createObservationPoller({
            api: {
                async getActiveSession() {
                    return activeSession("session-1");
                },
                async getEvents() {
                    return page("session-1", [event]);
                }
            },
            view: view(),
            checkpointStore: store,
            domainReducer: reducer
        });

        const state = await instance.pollOnce();
        assert.equal(state.cursor, 0);
        assert.equal(store.projections.size, 0);
    }
});


test("unknown event type is consumed with a diagnostic", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession("session-1");
            },
            async getEvents() {
                return page(
                    "session-1",
                    [durable(
                        "session-1",
                        1,
                        "future.event"
                    )]
                );
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const state = await instance.pollOnce();

    assert.equal(state.cursor, 1);
    assert.equal(
        state.projection.diagnostics[0].code,
        "unsupported_event_type"
    );
});


test("IndexedDB failure does not report a committed cursor", async () => {
    const { poller, reducer } = loadModules();
    const error = new Error("write failed");
    error.code = "INDEXEDDB_ERROR";
    const committedBatches = [];
    const store = memoryStore(reducer, {
        saveError: error
    });
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession("session-1");
            },
            async getEvents() {
                return page(
                    "session-1",
                    [durable("session-1", 1)]
                );
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer,
        onDomainBatchCommitted(batch) {
            committedBatches.push(batch);
        }
    });

    const state = await instance.pollOnce();

    assert.equal(state.cursor, 0);
    assert.equal(state.persistence, "error");
    assert.equal(store.projections.size, 0);
    assert.equal(committedBatches.length, 0);
});


test("legacy detector uses active authority and scoped checkpoint", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession(
                    "detector-1",
                    "detector"
                );
            },
            async getEvents() {
                return page(
                    "detector-1",
                    [loadingEvent("detector-1", 1)]
                );
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const state = await instance.pollOnce();

    assert.equal(state.loading, true);
    assert.equal(state.cursor, 1);
    assert.ok(
        store.checkpoints.has(
            "observation-poller-v1:detector-1"
        )
    );
    assert.equal(store.projections.size, 0);
});


test("pollOnce never overlaps an in-flight request", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    let release;
    let calls = 0;
    const gate = new Promise(resolve => {
        release = resolve;
    });
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                calls += 1;
                await gate;
                return activeSession("session-1");
            },
            async getEvents() {
                return page("session-1");
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const first = instance.pollOnce();
    await new Promise(resolve => setImmediate(resolve));
    await instance.pollOnce();
    assert.equal(calls, 1);
    release();
    await first;
});


test("stop aborts the active request and renders stopped", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    let signal = null;
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession(options) {
                signal = options.signal;
                return new Promise((_resolve, reject) => {
                    signal.addEventListener("abort", () => {
                        const error = new Error("aborted");
                        error.name = "AbortError";
                        reject(error);
                    });
                });
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const pending = instance.pollOnce();
    await new Promise(resolve => setImmediate(resolve));
    instance.stop();
    await pending;

    assert.equal(signal.aborted, true);
    assert.equal(
        instance.getState().connection,
        "stopped"
    );
});


test("network failures use bounded exponential backoff", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                const error = new Error("offline");
                error.code = "NETWORK_ERROR";
                throw error;
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const delays = [];
    for (let index = 0; index < 5; index += 1) {
        delays.push(
            (await instance.pollOnce()).retryDelayMs
        );
    }
    assert.deepEqual(delays, [1000, 2000, 4000, 5000, 5000]);
});


test("has_more drains immediately and duplicate replay is idempotent", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const scheduled = [];
    const afterSequences = [];
    let eventCall = 0;
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession("session-1");
            },
            async getEvents({ afterSequence }) {
                afterSequences.push(afterSequence);
                eventCall += 1;
                if (eventCall === 1) {
                    return page(
                        "session-1",
                        [durable("session-1", 1)],
                        { hasMore: true }
                    );
                }
                return page(
                    "session-1",
                    [
                        durable("session-1", 1),
                        durable(
                            "session-1",
                            2,
                            "turn.started"
                        )
                    ],
                    { nextSequence: 2 }
                );
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer,
        schedule(callback, delay) {
            scheduled.push({ callback, delay });
            return scheduled.length;
        },
        cancel() {}
    });

    instance.start();
    const initialTick = scheduled.shift();
    assert.equal(initialTick.delay, 0);
    await initialTick.callback();
    const drainTick = scheduled.shift();
    assert.equal(drainTick.delay, 0);
    await drainTick.callback();
    const idleTick = scheduled.shift();

    assert.deepEqual(afterSequences, [0, 1]);
    assert.equal(idleTick.delay, 500);
    assert.equal(instance.getState().cursor, 2);
    assert.equal(
        instance.getState().projection
            .applied_event_ids.length,
        2
    );
    instance.stop();
});


test("hidden pages use the idle polling interval", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const scheduled = [];
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession("session-1");
            },
            async getEvents() {
                return page("session-1");
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer,
        isHidden: () => true,
        schedule(callback, delay) {
            scheduled.push({ callback, delay });
            return scheduled.length;
        },
        cancel() {}
    });

    instance.start();
    await scheduled.shift().callback();
    assert.equal(scheduled.shift().delay, 1000);
    instance.stop();
});


test("empty domain page cannot advance the cursor", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession("session-1");
            },
            async getEvents() {
                return page(
                    "session-1",
                    [],
                    { nextSequence: 1 }
                );
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const state = await instance.pollOnce();

    assert.equal(state.cursor, 0);
    assert.equal(state.connection, "warning");
    assert.equal(store.projections.size, 0);
});


test("legacy empty page does not create a checkpoint", async () => {
    const { poller, reducer } = loadModules();
    const store = memoryStore(reducer);
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                return activeSession(
                    "detector-1",
                    "detector"
                );
            },
            async getEvents() {
                return page("detector-1");
            }
        },
        view: view(),
        checkpointStore: store,
        domainReducer: reducer
    });

    const state = await instance.pollOnce();

    assert.equal(state.cursor, 0);
    assert.equal(state.connection, "connected");
    assert.equal(store.checkpoints.size, 0);
});


test("unavailable persistence fails closed before API polling", async () => {
    const { poller, reducer } = loadModules();
    let apiCalls = 0;
    const error = new Error("blocked");
    error.code = "INDEXEDDB_UNAVAILABLE";
    const instance = poller.createObservationPoller({
        api: {
            async getActiveSession() {
                apiCalls += 1;
                return activeSession("session-1");
            }
        },
        view: view(),
        checkpointStore: {
            async openTrackerDatabase() {
                throw error;
            }
        },
        domainReducer: reducer
    });

    const state = await instance.pollOnce();

    assert.equal(apiCalls, 0);
    assert.equal(state.cursor, 0);
    assert.equal(state.persistence, "unavailable");
    assert.equal(state.connection, "warning");
});
