const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");


function clone(value) {
    if (value === undefined) {
        return undefined;
    }
    return JSON.parse(JSON.stringify(value));
}


class FakeTransaction {
    constructor(database, storeName) {
        this.database = database;
        this.storeName = storeName;
        this.error = null;
        this.oncomplete = null;
        this.onerror = null;
        this.onabort = null;
        this.aborted = false;
    }

    objectStore(storeName) {
        if (storeName !== this.storeName) {
            throw new Error("Unexpected object store");
        }
        const store = this.database.stores.get(storeName);
        const finish = (request, operation) => {
            queueMicrotask(() => {
                if (this.aborted) {
                    return;
                }
                try {
                    request.result = operation();
                    request.onsuccess?.();
                    queueMicrotask(
                        () => {
                            if (!this.aborted) {
                                this.oncomplete?.();
                            }
                        }
                    );
                } catch (error) {
                    request.error = error;
                    this.error = error;
                    request.onerror?.();
                    this.onerror?.();
                }
            });
            return request;
        };

        return {
            get: key => finish(
                {},
                () => clone(store.records.get(key))
            ),
            put: value => finish(
                {},
                () => {
                    const key = value[store.keyPath];
                    store.records.set(key, clone(value));
                    return key;
                }
            ),
            delete: key => finish(
                {},
                () => store.records.delete(key)
            )
        };
    }

    abort() {
        this.aborted = true;
        queueMicrotask(() => this.onabort?.());
    }
}


class FakeDatabase {
    constructor(existingStoreNames = []) {
        this.version = 0;
        this.stores = new Map();
        for (const name of existingStoreNames) {
            this.createObjectStore(name, {
                keyPath: "id"
            });
        }
        this.objectStoreNames = {
            contains: name => this.stores.has(name)
        };
    }

    createObjectStore(name, { keyPath }) {
        if (this.stores.has(name)) {
            throw new Error("Store already exists");
        }
        this.stores.set(name, {
            keyPath,
            records: new Map()
        });
        return this.stores.get(name);
    }

    transaction(storeName) {
        if (!this.stores.has(storeName)) {
            throw new Error("Store does not exist");
        }
        return new FakeTransaction(this, storeName);
    }
}


class FakeIndexedDB {
    constructor(existingStoreNames = []) {
        this.database = new FakeDatabase(
            existingStoreNames
        );
        this.openCalls = 0;
    }

    open(name, version) {
        this.openCalls += 1;
        const request = {
            result: this.database,
            error: null,
            onupgradeneeded: null,
            onsuccess: null,
            onerror: null,
            onblocked: null
        };
        queueMicrotask(() => {
            if (version > this.database.version) {
                request.onupgradeneeded?.();
                this.database.version = version;
            }
            request.onsuccess?.();
        });
        return request;
    }

    setRaw(storeName, key, value) {
        this.database.stores
            .get(storeName)
            .records
            .set(key, clone(value));
    }
}


function loadTrackerDb(indexedDBImpl) {
    const sandbox = {
        indexedDB: indexedDBImpl
    };
    vm.runInNewContext(
        fs.readFileSync(
            path.resolve(
                __dirname,
                "..",
                "..",
                "..",
                "tracker-db.js"
            ),
            "utf8"
        ),
        sandbox
    );
    return sandbox.TrackerDb;
}


function checkpoint(sequence = 12) {
    return {
        consumer_id: "observation-poller-v1",
        session_id: "session-1",
        after_sequence: sequence,
        updated_at: "2026-07-27T00:00:00.000Z",
        commit_token: `commit-${sequence}`,
        consumer_schema_version: 1
    };
}


test("upgrade creates checkpoint store without deleting unknown stores", async () => {
    const indexedDBImpl = new FakeIndexedDB([
        "future_store"
    ]);
    const trackerDb = loadTrackerDb(
        indexedDBImpl
    );

    const database =
        await trackerDb.openTrackerDatabase();
    assert.equal(
        database.objectStoreNames.contains(
            "consumer_checkpoints"
        ),
        true
    );
    assert.equal(
        database.objectStoreNames.contains(
            "domain_projections"
        ),
        true
    );
    assert.equal(
        database.objectStoreNames.contains(
            "future_store"
        ),
        true
    );

    await trackerDb.openTrackerDatabase();
    assert.equal(indexedDBImpl.openCalls, 1);
});


test("saves, loads, and clears a checkpoint", async () => {
    const trackerDb = loadTrackerDb(
        new FakeIndexedDB()
    );
    const saved =
        await trackerDb.saveConsumerCheckpoint(
            checkpoint()
        );
    assert.equal(saved.after_sequence, 12);

    const loaded =
        await trackerDb.loadConsumerCheckpoint(
            "observation-poller-v1"
        );
    assert.deepEqual(
        JSON.parse(JSON.stringify(loaded)),
        checkpoint()
    );

    await trackerDb.clearConsumerCheckpoint(
        "observation-poller-v1"
    );
    assert.equal(
        await trackerDb.loadConsumerCheckpoint(
            "observation-poller-v1"
        ),
        null
    );
});


test("rejects malformed saved and stored checkpoints", async () => {
    const indexedDBImpl = new FakeIndexedDB();
    const trackerDb = loadTrackerDb(
        indexedDBImpl
    );

    await assert.rejects(
        trackerDb.saveConsumerCheckpoint({
            ...checkpoint(),
            after_sequence: -1
        }),
        error => error.code === "INVALID_CHECKPOINT"
    );

    await trackerDb.openTrackerDatabase();
    indexedDBImpl.setRaw(
        "consumer_checkpoints",
        "observation-poller-v1",
        {
            ...checkpoint(),
            consumer_schema_version: 99
        }
    );
    await assert.rejects(
        trackerDb.loadConsumerCheckpoint(
            "observation-poller-v1"
        ),
        error => (
            error.code ===
            "UNSUPPORTED_CONSUMER_SCHEMA"
        )
    );
});


test("same-session checkpoint cursor cannot move backwards", async () => {
    const trackerDb = loadTrackerDb(
        new FakeIndexedDB()
    );
    await trackerDb.saveConsumerCheckpoint(
        checkpoint(12)
    );

    await assert.rejects(
        trackerDb.saveConsumerCheckpoint({
            ...checkpoint(11),
            commit_token: "regression"
        }),
        error => (
            error.code === "CHECKPOINT_REGRESSION"
        )
    );

    const loaded =
        await trackerDb.loadConsumerCheckpoint(
            "observation-poller-v1"
        );
    assert.equal(loaded.after_sequence, 12);
    assert.equal(loaded.commit_token, "commit-12");
});


test("new session may start from cursor zero", async () => {
    const trackerDb = loadTrackerDb(
        new FakeIndexedDB()
    );
    await trackerDb.saveConsumerCheckpoint(
        checkpoint(12)
    );
    const reset = await trackerDb.saveConsumerCheckpoint({
        ...checkpoint(0),
        session_id: "session-2",
        commit_token: "new-session"
    });

    assert.equal(reset.session_id, "session-2");
    assert.equal(reset.after_sequence, 0);
});


test("reports unavailable IndexedDB explicitly", async () => {
    const trackerDb = loadTrackerDb(undefined);

    await assert.rejects(
        trackerDb.openTrackerDatabase(),
        error => (
            error.code === "INDEXEDDB_UNAVAILABLE"
        )
    );
});


function initialProjection(sessionId) {
    return {
        projection_schema_version: 1,
        session_id: sessionId,
        battle_status: "idle",
        applied_event_ids: [],
        last_sequence: 0,
        diagnostics: []
    };
}


function identityReducer(state) {
    return {
        nextState: {
            ...state,
            battle_status: "active"
        },
        diagnostics: []
    };
}


function durableEvent(sequence, eventId = `event-${sequence}`) {
    return {
        session_id: "session-1",
        sequence,
        event_id: eventId
    };
}


test("domain batch atomically saves projection, cursor, identity, and token", async () => {
    const trackerDb = loadTrackerDb(
        new FakeIndexedDB()
    );

    const saved = await trackerDb.applyDomainEventBatch({
        sessionId: "session-1",
        events: [durableEvent(1)],
        expectedLastSequence: 0,
        createInitialProjection: initialProjection,
        reduceEvent: identityReducer,
        updatedAt: "2026-07-28T00:00:00.000Z",
        commitToken: "commit-domain-1"
    });
    const loaded = await trackerDb.loadDomainProjection(
        "session-1"
    );

    assert.equal(saved.last_sequence, 1);
    assert.deepEqual(
        Array.from(saved.applied_event_ids),
        ["event-1"]
    );
    assert.equal(saved.commit_token, "commit-domain-1");
    assert.equal(loaded.last_sequence, 1);
});


test("domain cursor is monotonic and rejects gaps", async () => {
    const trackerDb = loadTrackerDb(
        new FakeIndexedDB()
    );
    await trackerDb.applyDomainEventBatch({
        sessionId: "session-1",
        events: [durableEvent(1)],
        expectedLastSequence: 0,
        createInitialProjection: initialProjection,
        reduceEvent: identityReducer,
        updatedAt: "2026-07-28T00:00:00.000Z",
        commitToken: "commit-1"
    });

    await assert.rejects(
        trackerDb.applyDomainEventBatch({
            sessionId: "session-1",
            events: [durableEvent(3)],
            expectedLastSequence: 1,
            createInitialProjection: initialProjection,
            reduceEvent: identityReducer,
            updatedAt: "2026-07-28T00:00:01.000Z",
            commitToken: "commit-3"
        }),
        error => error.code === "SEQUENCE_GAP"
    );
    await assert.rejects(
        trackerDb.applyDomainEventBatch({
            sessionId: "session-1",
            events: [],
            expectedLastSequence: 0,
            createInitialProjection: initialProjection,
            reduceEvent: identityReducer,
            updatedAt: "2026-07-28T00:00:01.000Z",
            commitToken: "regression"
        }),
        error => (
            error.code ===
            "PROJECTION_CURSOR_CONFLICT"
        )
    );

    const loaded = await trackerDb.loadDomainProjection(
        "session-1"
    );
    assert.equal(loaded.last_sequence, 1);
});


test("domain batch rejects duplicate event identity at a new sequence", async () => {
    const trackerDb = loadTrackerDb(
        new FakeIndexedDB()
    );
    await trackerDb.applyDomainEventBatch({
        sessionId: "session-1",
        events: [durableEvent(1, "same-event")],
        expectedLastSequence: 0,
        createInitialProjection: initialProjection,
        reduceEvent: identityReducer,
        updatedAt: "2026-07-28T00:00:00.000Z",
        commitToken: "commit-1"
    });

    await assert.rejects(
        trackerDb.applyDomainEventBatch({
            sessionId: "session-1",
            events: [durableEvent(2, "same-event")],
            expectedLastSequence: 1,
            createInitialProjection: initialProjection,
            reduceEvent: identityReducer,
            updatedAt: "2026-07-28T00:00:01.000Z",
            commitToken: "commit-2"
        }),
        error => error.code === "DUPLICATE_EVENT_ID"
    );
});


test("same event at the committed sequence is idempotent", async () => {
    const trackerDb = loadTrackerDb(
        new FakeIndexedDB()
    );
    const argumentsBase = {
        sessionId: "session-1",
        createInitialProjection: initialProjection,
        reduceEvent: identityReducer,
        updatedAt: "2026-07-28T00:00:00.000Z"
    };
    await trackerDb.applyDomainEventBatch({
        ...argumentsBase,
        events: [durableEvent(1)],
        expectedLastSequence: 0,
        commitToken: "first"
    });
    const repeated =
        await trackerDb.applyDomainEventBatch({
            ...argumentsBase,
            events: [durableEvent(1)],
            expectedLastSequence: 1,
            commitToken: "repeated"
        });

    assert.equal(repeated.last_sequence, 1);
    assert.equal(repeated.applied_event_ids.length, 1);
});


test("reducer failure rolls back cursor and projection", async () => {
    const trackerDb = loadTrackerDb(
        new FakeIndexedDB()
    );
    const reducerError = new Error("bad payload");
    reducerError.code = "INVALID_DOMAIN_PAYLOAD";

    await assert.rejects(
        trackerDb.applyDomainEventBatch({
            sessionId: "session-1",
            events: [durableEvent(1)],
            expectedLastSequence: 0,
            createInitialProjection: initialProjection,
            reduceEvent() {
                throw reducerError;
            },
            updatedAt: "2026-07-28T00:00:00.000Z",
            commitToken: "failed"
        }),
        error => error.code === "INVALID_DOMAIN_PAYLOAD"
    );
    assert.equal(
        await trackerDb.loadDomainProjection("session-1"),
        null
    );
});
