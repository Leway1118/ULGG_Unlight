(function (global) {
    "use strict";

    const DATABASE_NAME = "ulgg-unlight-card-tracker";
    const DATABASE_VERSION = 2;
    const CHECKPOINT_STORE = "consumer_checkpoints";
    const DOMAIN_PROJECTION_STORE = "domain_projections";
    const CONSUMER_SCHEMA_VERSION = 1;
    const PROJECTION_SCHEMA_VERSION = 1;

    class TrackerDatabaseError extends Error {
        constructor(
            code,
            message,
            { cause = null } = {}
        ) {
            super(message);
            this.name = "TrackerDatabaseError";
            this.code = code;
            this.cause = cause;
        }
    }

    function normalizeCheckpoint(value) {
        if (
            value === null ||
            typeof value !== "object" ||
            Array.isArray(value)
        ) {
            throw new TrackerDatabaseError(
                "INVALID_CHECKPOINT",
                "Checkpoint must be an object"
            );
        }
        if (
            typeof value.consumer_id !== "string" ||
            value.consumer_id.length === 0
        ) {
            throw new TrackerDatabaseError(
                "INVALID_CHECKPOINT",
                "consumer_id is required"
            );
        }
        if (
            value.session_id !== null &&
            (
                typeof value.session_id !== "string" ||
                value.session_id.length === 0
            )
        ) {
            throw new TrackerDatabaseError(
                "INVALID_CHECKPOINT",
                "session_id must be null or a non-empty string"
            );
        }
        if (
            !Number.isInteger(value.after_sequence) ||
            value.after_sequence < 0
        ) {
            throw new TrackerDatabaseError(
                "INVALID_CHECKPOINT",
                "after_sequence must be a non-negative integer"
            );
        }
        if (
            typeof value.updated_at !== "string" ||
            Number.isNaN(Date.parse(value.updated_at))
        ) {
            throw new TrackerDatabaseError(
                "INVALID_CHECKPOINT",
                "updated_at must be an ISO 8601 timestamp"
            );
        }
        if (
            typeof value.commit_token !== "string" ||
            value.commit_token.length === 0
        ) {
            throw new TrackerDatabaseError(
                "INVALID_CHECKPOINT",
                "commit_token is required"
            );
        }
        if (
            value.consumer_schema_version !==
            CONSUMER_SCHEMA_VERSION
        ) {
            throw new TrackerDatabaseError(
                "UNSUPPORTED_CONSUMER_SCHEMA",
                "Unsupported consumer checkpoint schema"
            );
        }

        return Object.freeze({
            consumer_id: value.consumer_id,
            session_id: value.session_id,
            after_sequence: value.after_sequence,
            updated_at: value.updated_at,
            commit_token: value.commit_token,
            consumer_schema_version:
                value.consumer_schema_version
        });
    }

    function normalizeDomainProjection(value) {
        if (
            value === null ||
            typeof value !== "object" ||
            Array.isArray(value) ||
            typeof value.session_id !== "string" ||
            value.session_id.length === 0 ||
            value.projection_schema_version !==
                PROJECTION_SCHEMA_VERSION ||
            !Number.isInteger(value.last_sequence) ||
            value.last_sequence < 0 ||
            !Array.isArray(value.applied_event_ids) ||
            !Array.isArray(value.diagnostics)
        ) {
            throw new TrackerDatabaseError(
                "INVALID_DOMAIN_PROJECTION",
                "Domain projection is invalid"
            );
        }
        return Object.freeze(
            JSON.parse(JSON.stringify(value))
        );
    }

    function createTrackerDatabase({
        indexedDBImpl = global.indexedDB
    } = {}) {
        let databasePromise = null;

        function unavailableError(cause = null) {
            return new TrackerDatabaseError(
                "INDEXEDDB_UNAVAILABLE",
                "IndexedDB is unavailable",
                { cause }
            );
        }

        function databaseError(
            message,
            cause = null
        ) {
            return new TrackerDatabaseError(
                "INDEXEDDB_ERROR",
                message,
                { cause }
            );
        }

        async function validateConsumerCheckpoint(
            value
        ) {
            return normalizeCheckpoint(value);
        }

        async function openTrackerDatabase() {
            if (!indexedDBImpl) {
                throw unavailableError();
            }
            if (databasePromise) {
                return databasePromise;
            }

            databasePromise = new Promise(
                (resolve, reject) => {
                    let request;
                    try {
                        request = indexedDBImpl.open(
                            DATABASE_NAME,
                            DATABASE_VERSION
                        );
                    } catch (error) {
                        reject(unavailableError(error));
                        return;
                    }

                    request.onupgradeneeded = () => {
                        const database = request.result;
                        if (
                            !database.objectStoreNames.contains(
                                CHECKPOINT_STORE
                            )
                        ) {
                            database.createObjectStore(
                                CHECKPOINT_STORE,
                                {
                                    keyPath: "consumer_id"
                                }
                            );
                        }
                        if (
                            !database.objectStoreNames.contains(
                                DOMAIN_PROJECTION_STORE
                            )
                        ) {
                            database.createObjectStore(
                                DOMAIN_PROJECTION_STORE,
                                {
                                    keyPath: "session_id"
                                }
                            );
                        }
                    };
                    request.onsuccess = () => {
                        resolve(request.result);
                    };
                    request.onerror = () => {
                        reject(
                            unavailableError(request.error)
                        );
                    };
                    request.onblocked = () => {
                        reject(
                            databaseError(
                                "IndexedDB upgrade is blocked"
                            )
                        );
                    };
                }
            );

            return databasePromise;
        }

        function transactionCompletion(
            transaction,
            message
        ) {
            return new Promise((resolve, reject) => {
                transaction.oncomplete = () => resolve();
                transaction.onerror = () => {
                    reject(
                        databaseError(
                            message,
                            transaction.error
                        )
                    );
                };
                transaction.onabort = () => {
                    reject(
                        databaseError(
                            message,
                            transaction.error
                        )
                    );
                };
            });
        }

        async function loadConsumerCheckpoint(
            consumerId
        ) {
            if (
                typeof consumerId !== "string" ||
                consumerId.length === 0
            ) {
                throw new TypeError(
                    "consumerId is required"
                );
            }

            const database =
                await openTrackerDatabase();
            const transaction = database.transaction(
                CHECKPOINT_STORE,
                "readonly"
            );
            const completed = transactionCompletion(
                transaction,
                "Unable to read consumer checkpoint"
            );
            const request = transaction
                .objectStore(CHECKPOINT_STORE)
                .get(consumerId);
            let value;
            request.onsuccess = () => {
                value = request.result;
            };
            await completed;

            if (value === undefined) {
                return null;
            }
            return normalizeCheckpoint(value);
        }

        async function saveConsumerCheckpoint(
            checkpoint
        ) {
            const normalized =
                normalizeCheckpoint(checkpoint);
            const database =
                await openTrackerDatabase();
            return new Promise((resolve, reject) => {
                const transaction = database.transaction(
                    CHECKPOINT_STORE,
                    "readwrite"
                );
                const store = transaction.objectStore(
                    CHECKPOINT_STORE
                );
                let checkpointError = null;

                transaction.oncomplete = () => {
                    resolve(normalized);
                };
                transaction.onerror = () => {
                    reject(
                        databaseError(
                            "Unable to save consumer checkpoint",
                            transaction.error
                        )
                    );
                };
                transaction.onabort = () => {
                    reject(
                        checkpointError ||
                        databaseError(
                            "Unable to save consumer checkpoint",
                            transaction.error
                        )
                    );
                };

                const readRequest = store.get(
                    normalized.consumer_id
                );
                readRequest.onsuccess = () => {
                    try {
                        const existing = (
                            readRequest.result === undefined
                                ? null
                                : normalizeCheckpoint(
                                    readRequest.result
                                )
                        );
                        if (
                            existing &&
                            existing.session_id ===
                                normalized.session_id &&
                            normalized.after_sequence <
                                existing.after_sequence
                        ) {
                            checkpointError =
                                new TrackerDatabaseError(
                                    "CHECKPOINT_REGRESSION",
                                    "Checkpoint cursor cannot move backwards"
                                );
                            transaction.abort();
                            return;
                        }
                        store.put({ ...normalized });
                    } catch (error) {
                        checkpointError = error;
                        transaction.abort();
                    }
                };
            });
        }

        async function clearConsumerCheckpoint(
            consumerId
        ) {
            if (
                typeof consumerId !== "string" ||
                consumerId.length === 0
            ) {
                throw new TypeError(
                    "consumerId is required"
                );
            }

            const database =
                await openTrackerDatabase();
            const transaction = database.transaction(
                CHECKPOINT_STORE,
                "readwrite"
            );
            const completed = transactionCompletion(
                transaction,
                "Unable to clear consumer checkpoint"
            );
            transaction
                .objectStore(CHECKPOINT_STORE)
                .delete(consumerId);
            await completed;
        }

        async function loadDomainProjection(
            sessionId
        ) {
            if (
                typeof sessionId !== "string" ||
                sessionId.length === 0
            ) {
                throw new TypeError(
                    "sessionId is required"
                );
            }
            const database =
                await openTrackerDatabase();
            const transaction = database.transaction(
                DOMAIN_PROJECTION_STORE,
                "readonly"
            );
            const completed = transactionCompletion(
                transaction,
                "Unable to read domain projection"
            );
            const request = transaction
                .objectStore(DOMAIN_PROJECTION_STORE)
                .get(sessionId);
            let value;
            request.onsuccess = () => {
                value = request.result;
            };
            await completed;
            return value === undefined
                ? null
                : normalizeDomainProjection(value);
        }

        async function applyDomainEventBatch({
            sessionId,
            events,
            expectedLastSequence,
            createInitialProjection,
            reduceEvent,
            updatedAt,
            commitToken
        }) {
            if (
                typeof sessionId !== "string" ||
                sessionId.length === 0 ||
                !Array.isArray(events) ||
                !Number.isInteger(expectedLastSequence) ||
                expectedLastSequence < 0 ||
                typeof createInitialProjection !== "function" ||
                typeof reduceEvent !== "function" ||
                typeof updatedAt !== "string" ||
                typeof commitToken !== "string" ||
                commitToken.length === 0
            ) {
                throw new TypeError(
                    "Invalid domain batch arguments"
                );
            }

            const database =
                await openTrackerDatabase();
            return new Promise((resolve, reject) => {
                const transaction = database.transaction(
                    DOMAIN_PROJECTION_STORE,
                    "readwrite"
                );
                const store = transaction.objectStore(
                    DOMAIN_PROJECTION_STORE
                );
                let result = null;
                let batchError = null;

                transaction.oncomplete = () => {
                    resolve(normalizeDomainProjection(result));
                };
                transaction.onerror = () => {
                    reject(
                        batchError ||
                        databaseError(
                            "Unable to apply domain event batch",
                            transaction.error
                        )
                    );
                };
                transaction.onabort = () => {
                    reject(
                        batchError ||
                        databaseError(
                            "Unable to apply domain event batch",
                            transaction.error
                        )
                    );
                };

                const readRequest = store.get(sessionId);
                readRequest.onsuccess = () => {
                    try {
                        let projection = (
                            readRequest.result === undefined
                                ? createInitialProjection(
                                    sessionId
                                )
                                : normalizeDomainProjection(
                                    readRequest.result
                                )
                        );
                        projection = JSON.parse(
                            JSON.stringify(projection)
                        );
                        if (
                            projection.last_sequence !==
                                expectedLastSequence
                        ) {
                            throw new TrackerDatabaseError(
                                "PROJECTION_CURSOR_CONFLICT",
                                "Stored projection cursor changed"
                            );
                        }

                        const applied = new Set(
                            projection.applied_event_ids
                        );
                        let sequence =
                            projection.last_sequence;
                        for (const event of events) {
                            if (
                                !event ||
                                typeof event.event_id !== "string" ||
                                !Number.isInteger(event.sequence)
                            ) {
                                throw new TrackerDatabaseError(
                                    "MALFORMED_DOMAIN_EVENT",
                                    "Durable event identity is invalid"
                                );
                            }
                            if (event.sequence <= sequence) {
                                if (
                                    applied.has(event.event_id)
                                ) {
                                    continue;
                                }
                                throw new TrackerDatabaseError(
                                    "DUPLICATE_SEQUENCE_CONFLICT",
                                    "Sequence was reused by another event"
                                );
                            }
                            if (event.sequence !== sequence + 1) {
                                throw new TrackerDatabaseError(
                                    "SEQUENCE_GAP",
                                    "Domain event sequence gap"
                                );
                            }
                            if (applied.has(event.event_id)) {
                                throw new TrackerDatabaseError(
                                    "DUPLICATE_EVENT_ID",
                                    "Event ID was reused at another sequence"
                                );
                            }

                            const reduced = reduceEvent(
                                projection,
                                event
                            );
                            if (
                                !reduced ||
                                typeof reduced !== "object" ||
                                !reduced.nextState
                            ) {
                                throw new TrackerDatabaseError(
                                    "INVALID_REDUCER_RESULT",
                                    "Reducer did not return nextState"
                                );
                            }
                            projection = JSON.parse(
                                JSON.stringify(
                                    reduced.nextState
                                )
                            );
                            sequence = event.sequence;
                            applied.add(event.event_id);
                            projection.last_sequence =
                                sequence;
                            projection.applied_event_ids =
                                [...applied];
                        }

                        projection.session_id = sessionId;
                        projection.projection_schema_version =
                            PROJECTION_SCHEMA_VERSION;
                        projection.last_sequence = sequence;
                        projection.updated_at = updatedAt;
                        projection.commit_token = commitToken;
                        result = projection;
                        store.put(projection);
                    } catch (error) {
                        batchError = (
                            error &&
                            typeof error === "object" &&
                            typeof error.code === "string"
                                ? error
                                : databaseError(
                                    "Unable to apply domain event batch",
                                    error
                                )
                        );
                        transaction.abort();
                    }
                };
            });
        }

        return Object.freeze({
            openTrackerDatabase,
            loadConsumerCheckpoint,
            saveConsumerCheckpoint,
            clearConsumerCheckpoint,
            validateConsumerCheckpoint,
            loadDomainProjection,
            applyDomainEventBatch
        });
    }

    const defaultDatabase = createTrackerDatabase();

    global.TrackerDb = Object.freeze({
        DATABASE_NAME,
        DATABASE_VERSION,
        CHECKPOINT_STORE,
        DOMAIN_PROJECTION_STORE,
        CONSUMER_SCHEMA_VERSION,
        PROJECTION_SCHEMA_VERSION,
        TrackerDatabaseError,
        createTrackerDatabase,
        ...defaultDatabase
    });
})(
    typeof window !== "undefined"
        ? window
        : globalThis
);
