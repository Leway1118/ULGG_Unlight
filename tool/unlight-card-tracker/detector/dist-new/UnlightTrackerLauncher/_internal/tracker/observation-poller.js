(function (global) {
    "use strict";

    const ACTIVE_INTERVAL_MS = 500;
    const IDLE_INTERVAL_MS = 1000;
    const MAX_RETRY_MS = 5000;
    const LEGACY_CONSUMER_ID = "observation-poller-v1";
    const CONSUMER_SCHEMA_VERSION = 1;
    const DOMAIN_PRODUCER = "websocket_sniffer";
    let fallbackCommitCounter = 0;

    function defaultCommitToken() {
        if (
            typeof global.crypto?.randomUUID ===
            "function"
        ) {
            return global.crypto.randomUUID();
        }
        fallbackCommitCounter += 1;
        return [
            Date.now().toString(36),
            fallbackCommitCounter.toString(36)
        ].join("-");
    }

    function initialState() {
        return {
            connection: "connecting",
            sessionId: null,
            producerType: null,
            cursor: 0,
            loading: null,
            observationMode: null,
            confidence: null,
            lastEventTime: null,
            error: null,
            sessionChanged: false,
            hasMore: false,
            retryDelayMs: 0,
            persistence: "initializing",
            persistenceError: null,
            checkpointSessionId: null,
            checkpointCursor: 0,
            checkpointCommitToken: null,
            projection: null,
            diagnostics: null,
            diagnosticsError: null
        };
    }

    function copyState(state) {
        return {
            ...state,
            projection: state.projection
                ? JSON.parse(JSON.stringify(state.projection))
                : null,
            diagnostics: state.diagnostics
                ? JSON.parse(JSON.stringify(state.diagnostics))
                : null
        };
    }

    function createObservationPoller({
        api,
        view,
        schedule = (callback, delay) =>
            global.setTimeout(callback, delay),
        cancel = timer =>
            global.clearTimeout(timer),
        isHidden = () =>
            Boolean(global.document?.hidden),
        eventLimit = 100,
        checkpointStore = null,
        domainReducer = null,
        now = () => new Date().toISOString(),
        createCommitToken = defaultCommitToken,
        onDomainBatchCommitted = null,
        onSessionCleared = null,
        createAbortController = () => (
            typeof global.AbortController === "function"
                ? new global.AbortController()
                : null
        )
    }) {
        if (!api || !view) {
            throw new TypeError(
                "api and view are required"
            );
        }
        if (
            onDomainBatchCommitted !== null &&
            typeof onDomainBatchCommitted !==
                "function"
        ) {
            throw new TypeError(
                "onDomainBatchCommitted must be a function"
            );
        }
        if (
            onSessionCleared !== null &&
            typeof onSessionCleared !== "function"
        ) {
            throw new TypeError(
                "onSessionCleared must be a function"
            );
        }

        const state = initialState();
        let running = false;
        let inFlight = false;
        let timer = null;
        let retryDelayMs = 0;
        let persistenceInitialized = false;
        let activeRequestController = null;
        let legacyMigrationCheckpoint = null;

        function render() {
            view.render(copyState(state));
        }

        function formatError(error) {
            return (
                error && error.code
                    ? `${error.code}: ${error.message}`
                    : String(error)
            );
        }

        function setError(error) {
            state.error = formatError(error);
        }

        function setPersistenceFailure(error) {
            state.persistence = (
                error?.code === "INDEXEDDB_UNAVAILABLE"
                    ? "unavailable"
                    : "error"
            );
            state.persistenceError = formatError(error);
        }

        async function initializePersistence() {
            if (persistenceInitialized) {
                return;
            }
            persistenceInitialized = true;
            if (!checkpointStore) {
                const error = new Error(
                    "IndexedDB checkpoint store is required"
                );
                error.code = "INDEXEDDB_UNAVAILABLE";
                setPersistenceFailure(error);
                throw error;
            }
            try {
                await checkpointStore.openTrackerDatabase();
                legacyMigrationCheckpoint =
                    await checkpointStore
                        .loadConsumerCheckpoint(
                            LEGACY_CONSUMER_ID
                        );
                state.persistence = "ready";
                state.persistenceError = null;
            } catch (error) {
                setPersistenceFailure(error);
                throw error;
            }
        }

        function legacyConsumerId(sessionId) {
            return `${LEGACY_CONSUMER_ID}:${sessionId}`;
        }

        async function loadLegacyCursor(sessionId) {
            const scoped = await checkpointStore
                .loadConsumerCheckpoint(
                    legacyConsumerId(sessionId)
                );
            const checkpoint = scoped || (
                legacyMigrationCheckpoint?.session_id ===
                    sessionId
                    ? legacyMigrationCheckpoint
                    : null
            );
            state.checkpointSessionId =
                checkpoint?.session_id || sessionId;
            state.checkpointCursor =
                checkpoint?.after_sequence || 0;
            state.checkpointCommitToken =
                checkpoint?.commit_token || null;
            return checkpoint?.after_sequence || 0;
        }

        async function saveLegacyCursor(
            sessionId,
            afterSequence
        ) {
            const checkpoint = await checkpointStore
                .saveConsumerCheckpoint({
                    consumer_id:
                        legacyConsumerId(sessionId),
                    session_id: sessionId,
                    after_sequence: afterSequence,
                    updated_at: now(),
                    commit_token: createCommitToken(),
                    consumer_schema_version:
                        CONSUMER_SCHEMA_VERSION
                });
            state.checkpointSessionId =
                checkpoint.session_id;
            state.checkpointCursor =
                checkpoint.after_sequence;
            state.checkpointCommitToken =
                checkpoint.commit_token;
        }

        async function loadSession(session) {
            const previousSessionId = state.sessionId;
            state.sessionChanged = (
                previousSessionId !== null &&
                previousSessionId !== session.session_id
            );
            state.sessionId = session.session_id;
            state.producerType =
                session.producer_type || "detector";
            state.loading = null;
            state.observationMode = null;
            state.confidence = null;
            state.lastEventTime = null;
            state.projection = null;

            if (state.producerType === DOMAIN_PRODUCER) {
                if (
                    !domainReducer ||
                    typeof checkpointStore
                        .loadDomainProjection !== "function" ||
                    typeof checkpointStore
                        .applyDomainEventBatch !== "function"
                ) {
                    const error = new Error(
                        "Domain projection dependencies are unavailable"
                    );
                    error.code =
                        "DOMAIN_PROJECTION_UNAVAILABLE";
                    throw error;
                }
                const stored = await checkpointStore
                    .loadDomainProjection(state.sessionId);
                state.projection = stored || domainReducer
                    .createInitialProjection(state.sessionId);
                state.cursor =
                    state.projection.last_sequence;
                state.checkpointSessionId =
                    state.sessionId;
                state.checkpointCursor = state.cursor;
                state.checkpointCommitToken =
                    state.projection.commit_token || null;
            } else {
                state.cursor = await loadLegacyCursor(
                    state.sessionId
                );
            }
        }

        function validateLegacyEvent(event) {
            if (
                !event ||
                typeof event !== "object" ||
                event.session_id !== state.sessionId ||
                !Number.isInteger(event.sequence) ||
                event.sequence < 1 ||
                typeof event.event_type !== "string"
            ) {
                const error = new Error(
                    "Malformed observation event"
                );
                error.code = "MALFORMED_RESPONSE";
                throw error;
            }
        }

        function consumeLegacyEvent(event) {
            if (event.event_type !== "loading_observed") {
                return;
            }
            const payload = event.payload;
            if (
                !payload ||
                typeof payload.is_loading !== "boolean" ||
                typeof payload.observation_mode !== "string" ||
                typeof event.confidence !== "number"
            ) {
                const error = new Error(
                    "Malformed loading_observed payload"
                );
                error.code = "MALFORMED_RESPONSE";
                throw error;
            }
            state.loading = payload.is_loading;
            state.observationMode =
                payload.observation_mode;
            state.confidence = event.confidence;
            state.lastEventTime =
                event.occurred_at || null;
        }

        function verifyPage(page) {
            if (page.session_id !== state.sessionId) {
                const error = new Error(
                    "Events page session mismatch"
                );
                error.code = "SESSION_MISMATCH";
                throw error;
            }
            if (
                !Number.isInteger(page.next_sequence) ||
                page.next_sequence < state.cursor
            ) {
                const error = new Error(
                    "Events page cursor is invalid"
                );
                error.code = "MALFORMED_RESPONSE";
                throw error;
            }
        }

        async function applyLegacyPage(page) {
            let processedSequence = state.cursor;
            for (const event of page.events) {
                validateLegacyEvent(event);
                if (event.sequence <= processedSequence) {
                    continue;
                }
                if (event.sequence !== processedSequence + 1) {
                    const error = new Error(
                        "Observation sequence gap"
                    );
                    error.code = "SEQUENCE_GAP";
                    throw error;
                }
                consumeLegacyEvent(event);
                processedSequence = event.sequence;
            }
            if (page.next_sequence !== processedSequence) {
                const error = new Error(
                    "next_sequence does not match processed events"
                );
                error.code = "MALFORMED_RESPONSE";
                throw error;
            }
            if (processedSequence !== state.cursor) {
                await saveLegacyCursor(
                    state.sessionId,
                    processedSequence
                );
                state.cursor = processedSequence;
            }
        }

        async function applyDomainPage(page) {
            if (
                page.events.length === 0 &&
                page.next_sequence !== state.cursor
            ) {
                const error = new Error(
                    "Empty page advanced the cursor"
                );
                error.code = "MALFORMED_RESPONSE";
                throw error;
            }
            if (page.events.length === 0) {
                return;
            }
            const committed = await checkpointStore
                .applyDomainEventBatch({
                    sessionId: state.sessionId,
                    events: page.events,
                    expectedLastSequence: state.cursor,
                    createInitialProjection:
                        domainReducer
                            .createInitialProjection,
                    reduceEvent:
                        domainReducer.reduceDomainEvent,
                    updatedAt: now(),
                    commitToken: createCommitToken()
                });
            if (
                committed.last_sequence !==
                    page.next_sequence
            ) {
                const error = new Error(
                    "Committed cursor does not match API page"
                );
                error.code = "MALFORMED_RESPONSE";
                throw error;
            }
            state.projection = committed;
            state.cursor = committed.last_sequence;
            state.checkpointSessionId =
                committed.session_id;
            state.checkpointCursor =
                committed.last_sequence;
            state.checkpointCommitToken =
                committed.commit_token || null;
            if (onDomainBatchCommitted) {
                try {
                    onDomainBatchCommitted({
                        sessionId: state.sessionId,
                        events: JSON.parse(
                            JSON.stringify(page.events)
                        ),
                        projection: JSON.parse(
                            JSON.stringify(committed)
                        )
                    });
                } catch (error) {
                    global.console?.warn(
                        "Auto card sync callback failed",
                        error
                    );
                }
            }
        }

        async function pollPage(signal) {
            if (
                typeof api.getDiagnosticsStatus ===
                "function"
            ) {
                try {
                    state.diagnostics =
                        await api.getDiagnosticsStatus({
                            signal
                        });
                    state.diagnosticsError = null;
                } catch (error) {
                    state.diagnosticsError =
                        formatError(error);
                }
            }
            await initializePersistence();
            const session = await api.getActiveSession({
                signal
            });
            if (state.sessionId !== session.session_id) {
                await loadSession(session);
            }

            const page = await api.getEvents({
                sessionId: state.sessionId,
                afterSequence: state.cursor,
                limit: eventLimit,
                signal
            });
            verifyPage(page);
            if (state.producerType === DOMAIN_PRODUCER) {
                await applyDomainPage(page);
            } else {
                await applyLegacyPage(page);
            }

            state.hasMore = page.has_more;
            state.connection = "connected";
            state.error = (
                state.sessionChanged
                    ? "session changed"
                    : null
            );
            state.retryDelayMs = 0;
            retryDelayMs = 0;
            state.persistence = "ready";
            state.persistenceError = null;
            render();
        }

        function clearActiveDisplay() {
            const previousSessionId =
                state.sessionId;
            state.sessionId = null;
            state.producerType = null;
            state.cursor = 0;
            state.projection = null;
            state.hasMore = false;
            state.connection = "waiting";
            state.error = null;
            state.checkpointSessionId = null;
            state.checkpointCursor = 0;
            state.checkpointCommitToken = null;
            if (
                previousSessionId &&
                onSessionCleared
            ) {
                onSessionCleared(
                    previousSessionId
                );
            }
        }

        async function handleFailure(error) {
            state.hasMore = false;
            if (error?.code === "NO_ACTIVE_SESSION") {
                clearActiveDisplay();
                retryDelayMs = IDLE_INTERVAL_MS;
            } else if (error?.name === "AbortError") {
                state.connection = running
                    ? "connecting"
                    : "stopped";
                retryDelayMs = 0;
            } else {
                setError(error);
                state.connection = (
                    error?.code === "NETWORK_ERROR"
                        ? "offline"
                        : "warning"
                );
                retryDelayMs = Math.min(
                    retryDelayMs > 0
                        ? retryDelayMs * 2
                        : 1000,
                    MAX_RETRY_MS
                );
                if (
                    error?.code?.includes("INDEXEDDB") ||
                    error?.code ===
                        "PROJECTION_CURSOR_CONFLICT"
                ) {
                    setPersistenceFailure(error);
                }
            }
            state.retryDelayMs = retryDelayMs;
            render();
        }

        async function pollOnce() {
            if (inFlight) {
                return copyState(state);
            }
            inFlight = true;
            activeRequestController =
                createAbortController();
            try {
                await pollPage(
                    activeRequestController?.signal || null
                );
            } catch (error) {
                await handleFailure(error);
            } finally {
                activeRequestController = null;
                inFlight = false;
            }
            return copyState(state);
        }

        function nextDelay() {
            if (state.hasMore) {
                return 0;
            }
            if (retryDelayMs > 0) {
                return retryDelayMs;
            }
            return isHidden()
                ? IDLE_INTERVAL_MS
                : ACTIVE_INTERVAL_MS;
        }

        async function tick() {
            if (!running) {
                return;
            }
            await pollOnce();
            if (running) {
                timer = schedule(tick, nextDelay());
            }
        }

        function start() {
            if (running) {
                return;
            }
            running = true;
            state.connection = "connecting";
            render();
            timer = schedule(tick, 0);
        }

        function stop() {
            running = false;
            activeRequestController?.abort();
            activeRequestController = null;
            if (timer !== null) {
                cancel(timer);
                timer = null;
            }
            state.connection = "stopped";
            render();
        }

        return Object.freeze({
            start,
            stop,
            pollOnce,
            getState: () => copyState(state)
        });
    }

    function createDomView(documentObject) {
        const element = id =>
            documentObject.getElementById(id);
        const elements = {
            root: element("observation-status"),
            connection: element("observation-connection"),
            session: element("observation-session"),
            cursor: element("observation-cursor"),
            loading: element("observation-loading"),
            mode: element("observation-mode"),
            confidence: element("observation-confidence"),
            lastEvent: element("observation-last-event"),
            error: element("observation-error"),
            persistence: element("observation-persistence"),
            checkpointSession: element(
                "observation-checkpoint-session"
            ),
            checkpointCursor: element(
                "observation-checkpoint-cursor"
            ),
            checkpointCommitToken: element(
                "observation-checkpoint-token"
            ),
            persistenceError: element(
                "observation-persistence-error"
            ),
            diagnosticsError: element(
                "diagnostics-read-error"
            )
        };

        return {
            render(state) {
                const setText = (id, value) => {
                    const target = element(id);
                    if (target) {
                        target.textContent = (
                            value === null ||
                            value === undefined ||
                            value === ""
                                ? "-"
                                : String(value)
                        );
                    }
                };
                const room =
                    state.diagnostics?.room_observation ||
                    {};
                const cdp =
                    state.diagnostics?.cdp_diagnostic ||
                    {};
                elements.root.dataset.status =
                    state.connection;
                elements.connection.textContent =
                    state.connection;
                elements.session.textContent =
                    state.sessionId
                        ? state.sessionId.slice(0, 8)
                        : "-";
                elements.cursor.textContent =
                    String(state.cursor);
                elements.loading.textContent = (
                    state.loading === null
                        ? "-"
                        : String(state.loading)
                );
                elements.mode.textContent =
                    state.producerType ||
                    state.observationMode ||
                    "-";
                elements.confidence.textContent = (
                    state.confidence === null
                        ? "-"
                        : state.confidence.toFixed(3)
                );
                elements.lastEvent.textContent =
                    state.lastEventTime || "-";
                elements.error.textContent =
                    state.error || "-";
                elements.persistence.textContent =
                    state.persistence;
                elements.checkpointSession.textContent =
                    state.checkpointSessionId
                        ? state.checkpointSessionId.slice(0, 8)
                        : "-";
                elements.checkpointCursor.textContent =
                    String(state.checkpointCursor);
                elements.checkpointCommitToken.textContent =
                    state.checkpointCommitToken || "-";
                elements.persistenceError.textContent =
                    state.persistenceError || "-";
                if (elements.diagnosticsError) {
                    elements.diagnosticsError.textContent =
                        state.diagnosticsError || "-";
                }

                setText(
                    "room-observed-state",
                    room.observed_room_state || "UNKNOWN"
                );
                setText(
                    "room-freshness",
                    room.freshness_state || "EXPIRED"
                );
                setText(
                    "room-observation-status",
                    room.observation_status
                );
                setText("room-name", room.room_name);
                setText("room-platform", room.platform);
                setText("room-channel", room.channel);
                setText("room-game-id", room.game_room_id);
                setText(
                    "room-host",
                    room.host_display_name ||
                        room.game_host_player_id
                );
                setText(
                    "room-opponent",
                    room.opponent_display_name ||
                        room.game_opponent_player_id
                );
                setText(
                    "room-ruleset-version",
                    room.rule_version_id
                );
                setText(
                    "room-content-hash",
                    room.content_hash
                );
                setText(
                    "room-tracker-session",
                    room.tracker_session_id
                );
                setText(
                    "room-last-update",
                    room.server_received_at
                );
                setText(
                    "room-age",
                    room.age_seconds === null ||
                        room.age_seconds === undefined
                        ? null
                        : `${room.age_seconds}s`
                );
                setText(
                    "room-error",
                    room.error
                        ? `${room.error.code}: ${room.error.message}`
                        : null
                );

                setText(
                    "cdp-connection",
                    cdp.connection_state || "DISCONNECTED"
                );
                setText(
                    "cdp-freshness",
                    cdp.freshness_state || "EXPIRED"
                );
                setText(
                    "cdp-context-state",
                    cdp.context_state
                );
                setText("cdp-target", cdp.target);
                setText("cdp-context-id", cdp.context_id);
                setText(
                    "cdp-injection-state",
                    cdp.injection_state
                );
                setText(
                    "cdp-ruleset-apply-state",
                    cdp.ruleset_apply_state
                );
                setText(
                    "cdp-ruleset-version",
                    cdp.rule_version_id
                );
                setText(
                    "cdp-content-hash",
                    cdp.content_hash
                );
                setText(
                    "cdp-format-valid",
                    cdp.format_valid
                );
                for (const field of [
                    "expected_count",
                    "matched_count",
                    "changed_count",
                    "already_compliant_count",
                    "verified_count",
                    "missing_count",
                    "invalid_count"
                ]) {
                    setText(
                        `cdp-${field.replaceAll("_", "-")}`,
                        cdp[field]
                    );
                }
                setText(
                    "cdp-last-update",
                    cdp.server_received_at
                );
                setText(
                    "cdp-age",
                    cdp.age_seconds === null ||
                        cdp.age_seconds === undefined
                        ? null
                        : `${cdp.age_seconds}s`
                );
                setText(
                    "cdp-error",
                    cdp.error
                        ? `${cdp.error.code}: ${cdp.error.message}`
                        : null
                );
            }
        };
    }

    function bootstrap() {
        if (
            !global.document ||
            !global.TrackerApi ||
            !global.document.getElementById(
                "observation-status"
            )
        ) {
            return;
        }
        const poller = createObservationPoller({
            api: global.TrackerApi.createTrackerApi(),
            view: createDomView(global.document),
            checkpointStore: global.TrackerDb || null,
            domainReducer:
                global.TrackerDomainReducer || null,
            onDomainBatchCommitted: batch => {
                global.TrackerAutoCardSync
                    ?.handleCommittedBatch(batch);
            },
            onSessionCleared: sessionId => {
                global.TrackerAutoCardSync
                    ?.handleSessionClosed(sessionId);
            }
        });
        global.observationPoller = poller;
        poller.start();
        global.addEventListener(
            "beforeunload",
            () => poller.stop()
        );
    }

    global.ObservationPoller = Object.freeze({
        createObservationPoller,
        createDomView
    });

    if (global.document) {
        if (global.document.readyState === "loading") {
            global.document.addEventListener(
                "DOMContentLoaded",
                bootstrap,
                { once: true }
            );
        } else {
            bootstrap();
        }
    }
})(
    typeof window !== "undefined"
        ? window
        : globalThis
);
