if (typeof FIELD_DECKS === "undefined") {
    throw new Error(
        "FIELD_DECKS 尚未載入，請確認 field-decks.js 位於 tracker.js 之前。"
    );
}

const STORAGE_KEY = "ul_public_deck_tracker_v3";
const LEGACY_STORAGE_KEY = "ul_public_deck_tracker_v2";

const $ = id => document.getElementById(id);

const elements = {
    field: $("field"),
    undo: $("undo"),
    shuffle: $("shuffle"),
    reset: $("reset"),
    clear: $("clear"),
    confirmPendingField:
        $("confirm-pending-field"),
    cards: $("cards"),
    types: $("types"),
    history: $("history"),
    enemyCandidates: $("enemyCandidates"),
    myHand: $("myHand"),
    initial: $("initial"),
    deckLeft: $("deckLeft"),
    myHandCount: $("myHandCount"),
    enemyCandidateCount: $("enemyCandidateCount"),
    seen: $("seen")
};

const CARD_TYPE_CONFIG = {
    劍: {
        image: "./assets/cards/acswd.png",
        label: "ATTACK"
    },
    槍: {
        image: "./assets/cards/acbow.png",
        label: "ATTACK"
    },
    防: {
        image: "./assets/cards/acshi.png",
        label: "DEFENSE"
    },
    移: {
        image: "./assets/cards/acmov.png",
        label: "MOVE"
    },
    特: {
        image: "./assets/cards/acspe.png",
        label: "SPECIAL"
    }
};

const TYPE_ORDER = [
    "劍",
    "槍",
    "防",
    "移",
    "特"
];

function getTotal(deck) {
    return Object.values(
        deck || {}
    ).reduce(
        (
            sum,
            count
        ) => {
            return (
                sum +
                Number(count || 0)
            );
        },
        0
    );
}

function createZeroDeck(initialDeck) {
    return Object.fromEntries(
        Object.keys(
            initialDeck
        ).map(cardName => [
            cardName,
            0
        ])
    );
}

function cloneDeck(deck) {
    return structuredClone(
        deck
    );
}

function createAutoSyncState() {
    return {
        schema_version: 2,
        active_session_id: null,
        active_battle_started_at: null,
        pending_stage_events: {},
        stage_status: {
            stage_code: null,
            mapped_field: null,
            status: "missing",
            source: null,
            source_sequence: null,
            last_error: null
        },
        processed_events: {}
    };
}

function createFreshState(fieldName) {
    const initialDeck = cloneDeck(
        FIELD_DECKS[fieldName]
    );

    return {
        field: fieldName,
        initial: initialDeck,
        remaining: cloneDeck(
            initialDeck
        ),
        myHand: createZeroDeck(
            initialDeck
        ),
        enemyCandidates: createZeroDeck(
            initialDeck
        ),
        autoReserved: createZeroDeck(
            initialDeck
        ),
        autoSync: createAutoSyncState(),
        sortType: null,
        history: []
    };
}

function normalizeSavedDeck(
    savedDeck,
    initialDeck
) {
    const normalized = createZeroDeck(
        initialDeck
    );

    for (
        const cardName
        of Object.keys(initialDeck)
    ) {
        const maximum = Number(
            initialDeck[cardName] || 0
        );

        const value = Number(
            savedDeck?.[cardName] || 0
        );

        normalized[cardName] = Math.max(
            0,
            Math.min(
                maximum,
                value
            )
        );
    }

    return normalized;
}

function normalizeAutoSync(
    savedAutoSync,
    initialDeck
) {
    const normalized = createAutoSyncState();
    if (
        !savedAutoSync ||
        ![1, 2].includes(
            savedAutoSync.schema_version
        ) ||
        !savedAutoSync.processed_events ||
        typeof savedAutoSync.processed_events !==
            "object" ||
        Array.isArray(
            savedAutoSync.processed_events
        )
    ) {
        return normalized;
    }
    normalized.active_session_id = (
        typeof savedAutoSync.active_session_id ===
            "string" &&
        savedAutoSync.active_session_id.length > 0
            ? savedAutoSync.active_session_id
            : null
    );
    normalized.active_battle_started_at = (
        typeof savedAutoSync
            .active_battle_started_at === "string" &&
        savedAutoSync
            .active_battle_started_at.length > 0
            ? savedAutoSync
                .active_battle_started_at
            : null
    );

    for (
        const [syncKey, record]
        of Object.entries(
            savedAutoSync.processed_events
        )
    ) {
        if (
            typeof syncKey !== "string" ||
            !record ||
            typeof record !== "object" ||
            !["applied", "reverted"].includes(
                record.status
            ) ||
            !Number.isInteger(record.event_sequence) ||
            record.event_sequence < 1 ||
            !record.deductions ||
            typeof record.deductions !== "object" ||
            Array.isArray(record.deductions)
        ) {
            continue;
        }
        const deductions = {};
        let valid = true;
        for (
            const [cardName, count]
            of Object.entries(record.deductions)
        ) {
            if (
                !Object.hasOwn(
                    initialDeck,
                    cardName
                ) ||
                !Number.isInteger(count) ||
                count <= 0 ||
                count > initialDeck[cardName]
            ) {
                valid = false;
                break;
            }
            deductions[cardName] = count;
        }
        if (!valid) {
            continue;
        }
        normalized.processed_events[syncKey] = {
            status: record.status,
            session_id: (
                typeof record.session_id === "string"
                    ? record.session_id
                    : ""
            ),
            event_id: (
                typeof record.event_id === "string"
                    ? record.event_id
                    : ""
            ),
            event_sequence:
                record.event_sequence,
            projection_sequence: (
                Number.isInteger(
                    record.projection_sequence
                )
                    ? record.projection_sequence
                    : record.event_sequence
            ),
            battle_started_at: (
                typeof record.battle_started_at ===
                    "string"
                    ? record.battle_started_at
                    : null
            ),
            field: (
                typeof record.field === "string"
                    ? record.field
                    : ""
            ),
            deductions
        };
    }
    if (
        savedAutoSync.schema_version === 2 &&
        savedAutoSync.pending_stage_events &&
        typeof savedAutoSync
            .pending_stage_events === "object" &&
        !Array.isArray(
            savedAutoSync.pending_stage_events
        )
    ) {
        for (
            const [syncKey, record]
            of Object.entries(
                savedAutoSync.pending_stage_events
            )
        ) {
            if (
                typeof syncKey !== "string" ||
                !record ||
                typeof record !== "object" ||
                record.status !== "pending_stage" ||
                typeof record.session_id !== "string" ||
                typeof record.event_id !== "string" ||
                !Number.isInteger(record.event_sequence) ||
                record.event_sequence < 1 ||
                typeof record.battle_started_at !==
                    "string" ||
                !Array.isArray(record.cards) ||
                record.cards.length === 0
            ) {
                continue;
            }
            normalized.pending_stage_events[syncKey] = {
                status: "pending_stage",
                session_id: record.session_id,
                event_id: record.event_id,
                event_sequence: record.event_sequence,
                projection_sequence: (
                    Number.isInteger(
                        record.projection_sequence
                    )
                        ? record.projection_sequence
                        : record.event_sequence
                ),
                battle_started_at:
                    record.battle_started_at,
                cards: structuredClone(record.cards),
                received_at: (
                    typeof record.received_at === "string"
                        ? record.received_at
                        : null
                ),
                reason: (
                    typeof record.reason === "string"
                        ? record.reason
                        : "stage_missing"
                )
            };
        }
    }
    const pendingRecords = Object.values(
        normalized.pending_stage_events
    );
    if (
        pendingRecords.length > 0 &&
        normalized.active_session_id === null &&
        normalized.active_battle_started_at === null
    ) {
        const firstPending = pendingRecords[0];
        const hasSinglePendingIdentity =
            pendingRecords.every(
                record => (
                    record.session_id ===
                        firstPending.session_id &&
                    record.battle_started_at ===
                        firstPending.battle_started_at
                )
            );
        if (hasSinglePendingIdentity) {
            normalized.active_session_id =
                firstPending.session_id;
            normalized.active_battle_started_at =
                firstPending.battle_started_at;
        }
    }
    const savedStageStatus =
        savedAutoSync.schema_version === 2
            ? savedAutoSync.stage_status
            : null;
    if (
        savedStageStatus &&
        typeof savedStageStatus === "object" &&
        !Array.isArray(savedStageStatus)
    ) {
        normalized.stage_status = {
            stage_code: (
                typeof savedStageStatus.stage_code ===
                    "string"
                    ? savedStageStatus.stage_code
                    : null
            ),
            mapped_field: (
                typeof savedStageStatus.mapped_field ===
                    "string"
                    ? savedStageStatus.mapped_field
                    : null
            ),
            status: (
                typeof savedStageStatus.status === "string"
                    ? savedStageStatus.status
                    : "missing"
            ),
            source: (
                typeof savedStageStatus.source === "string"
                    ? savedStageStatus.source
                    : null
            ),
            source_sequence: (
                Number.isInteger(
                    savedStageStatus.source_sequence
                )
                    ? savedStageStatus.source_sequence
                    : null
            ),
            last_error: (
                typeof savedStageStatus.last_error ===
                    "string"
                    ? savedStageStatus.last_error
                    : null
            )
        };
    }
    return normalized;
}

function normalizeState(saved) {
    if (
        !saved ||
        !FIELD_DECKS[saved.field]
    ) {
        return null;
    }

    const initialDeck = cloneDeck(
        FIELD_DECKS[saved.field]
    );

    return {
        field: saved.field,

        initial: initialDeck,

        remaining: normalizeSavedDeck(
            saved.remaining,
            initialDeck
        ),

        myHand: normalizeSavedDeck(
            saved.myHand,
            initialDeck
        ),
        

        enemyCandidates: normalizeSavedDeck(
            saved.enemyCandidates,
            initialDeck
        ),

        autoReserved: normalizeSavedDeck(
            saved.autoReserved,
            initialDeck
        ),

        autoSync: normalizeAutoSync(
            saved.autoSync,
            initialDeck
        ),

        sortType: TYPE_ORDER.includes(
            saved.sortType
        )
            ? saved.sortType
            : null,

        history: Array.isArray(
            saved.history
        )
            ? saved.history.slice(
                0,
                100
            )
            : []
    };
}

function loadStateFromKey(key) {
    try {
        const raw = localStorage.getItem(
            key
        );

        if (!raw) {
            return null;
        }

        return normalizeState(
            JSON.parse(raw)
        );

    } catch (error) {
        console.warn(
            `讀取追蹤狀態失敗（${key}）：`,
            error
        );

        return null;
    }
}

function loadState() {
    return (
        loadStateFromKey(
            STORAGE_KEY
        ) ||
        loadStateFromKey(
            LEGACY_STORAGE_KEY
        )
    );
}

function saveState() {
    localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify(state)
    );
}

let state =
    loadState() ||
    createFreshState(
        Object.keys(
            FIELD_DECKS
        )[0]
    );
let pendingFieldSelection = null;

function getTimeText() {
    return new Date()
        .toLocaleTimeString(
            "zh-TW",
            {
                hour12: false
            }
        );
}

function addHistory(entry) {
    state.history.unshift({
        ...entry,
        time: getTimeText()
    });

    state.history =
        state.history.slice(
            0,
        100
    );
}

function decksEqual(left, right) {
    const keys = new Set([
        ...Object.keys(left || {}),
        ...Object.keys(right || {})
    ]);
    for (const key of keys) {
        if (
            Number(left?.[key] || 0) !==
            Number(right?.[key] || 0)
        ) {
            return false;
        }
    }
    return true;
}

function hasDeckValues(deck) {
    return Object.values(deck || {}).some(
        count => Number(count || 0) !== 0
    );
}

function isTrackerPristineForFieldSwitch(
    trackerState = state
) {
    return (
        decksEqual(
            trackerState.remaining,
            trackerState.initial
        ) &&
        !hasDeckValues(trackerState.myHand) &&
        !hasDeckValues(
            trackerState.enemyCandidates
        ) &&
        !hasDeckValues(
            trackerState.autoReserved
        ) &&
        !trackerState.history.some(
            action => [
                "reveal",
                "add_my_hand",
                "play_my_hand",
                "play_enemy_candidate",
                "shuffle",
                "auto_cards_dealt"
            ].includes(action?.action)
        ) &&
        Object.keys(
            trackerState.autoSync.processed_events
        ).length === 0
    );
}

function pendingStageSummary(
    trackerState = state
) {
    const records = Object.values(
        trackerState.autoSync
            .pending_stage_events || {}
    );
    return {
        events: records.length,
        cards: records.reduce(
            (
                total,
                record
            ) => total + (
                Array.isArray(record.cards)
                    ? record.cards.length
                    : 0
            ),
            0
        )
    };
}

function commitTrackerState(nextState) {
    const previousState = state;
    state = nextState;
    try {
        saveState();
    } catch (error) {
        state = previousState;
        throw error;
    }
    render();
}

function pendingReasonForStage(stageResolution) {
    const reasons = {
        missing: "stage_missing",
        random: "stage_random",
        unsupported: "stage_unsupported",
        invalid_stage_code: "stage_unsupported",
        field_deck_missing: "field_deck_missing",
        conflict: "stage_conflict"
    };
    return (
        reasons[stageResolution?.status] ||
        "awaiting_manual_field"
    );
}

function stageStatusFromResolution(
    stageResolution,
    lastError = null
) {
    return {
        stage_code:
            stageResolution?.stage_code || null,
        mapped_field:
            stageResolution?.field || null,
        status:
            stageResolution?.status || "missing",
        source:
            stageResolution?.source || null,
        source_sequence: (
            Number.isInteger(
                stageResolution?.source_sequence
            )
                ? stageResolution.source_sequence
                : null
        ),
        last_error: lastError
    };
}

function pendingRecordToEvent(record) {
    return {
        session_id: record.session_id,
        event_id: record.event_id,
        sequence: record.event_sequence,
        payload: {
            domain_event: {
                event_type: "hand.cards_dealt",
                payload: {
                    cards: structuredClone(
                        record.cards
                    )
                }
            }
        }
    };
}

function failPendingApply(
    baseState,
    stageResolution,
    failure
) {
    const failedState = structuredClone(
        baseState
    );
    failedState.autoSync.stage_status =
        stageStatusFromResolution(
            stageResolution,
            failure.status
        );
    for (
        const record
        of Object.values(
            failedState.autoSync
                .pending_stage_events
        )
    ) {
        record.reason = failure.status;
    }
    commitTrackerState(failedState);
    return {
        ...failure,
        pending: pendingStageSummary(
            failedState
        )
    };
}

function processAutoSyncBatch({
    sessionId,
    battleStartedAt,
    battleStatus,
    projectionSequence,
    stageResolution,
    incomingPending = [],
    forceRebase = false
}) {
    if (
        typeof sessionId !== "string" ||
        sessionId.length === 0 ||
        typeof battleStartedAt !== "string" ||
        battleStartedAt.length === 0 ||
        !stageResolution ||
        typeof stageResolution.status !== "string"
    ) {
        return { status: "invalid_batch" };
    }

    const baseState = structuredClone(state);
    let discardedPendingIdentity = false;
    for (
        const [syncKey, record]
        of Object.entries(
            baseState.autoSync.pending_stage_events
        )
    ) {
        if (
            record.session_id !== sessionId ||
            record.battle_started_at !==
                battleStartedAt
        ) {
            delete baseState.autoSync
                .pending_stage_events[syncKey];
            discardedPendingIdentity = true;
        }
    }
    if (discardedPendingIdentity) {
        pendingFieldSelection = null;
    }

    if (battleStatus === "ended") {
        pendingFieldSelection = null;
        baseState.autoSync.pending_stage_events = {};
        baseState.autoSync.stage_status =
            stageStatusFromResolution(
                stageResolution
            );
        commitTrackerState(baseState);
        return { status: "battle_ended" };
    }

    const duplicateResults = [];
    for (const record of incomingPending) {
        const syncKey = record?.sync_key;
        if (
            typeof syncKey !== "string" ||
            syncKey.length === 0
        ) {
            duplicateResults.push({
                status: "invalid_event"
            });
            continue;
        }
        if (
            Object.hasOwn(
                baseState.autoSync.processed_events,
                syncKey
            )
        ) {
            duplicateResults.push({
                status: "duplicate_event",
                sync_key: syncKey
            });
            continue;
        }
        if (
            Object.hasOwn(
                baseState.autoSync
                    .pending_stage_events,
                syncKey
            )
        ) {
            duplicateResults.push({
                status: "duplicate_pending",
                sync_key: syncKey
            });
            continue;
        }
        baseState.autoSync.pending_stage_events[
            syncKey
        ] = {
            status: "pending_stage",
            session_id: record.session_id,
            event_id: record.event_id,
            event_sequence: record.event_sequence,
            projection_sequence:
                record.projection_sequence,
            battle_started_at:
                record.battle_started_at,
            cards: structuredClone(record.cards),
            received_at: record.received_at,
            reason: pendingReasonForStage(
                stageResolution
            )
        };
    }

    const sessionTransition = (
        typeof baseState.autoSync
            .active_session_id === "string" &&
        baseState.autoSync.active_session_id.length > 0 &&
        baseState.autoSync.active_session_id !==
            sessionId
    );
    const battleTransition = (
        typeof baseState.autoSync
            .active_battle_started_at === "string" &&
        baseState.autoSync
            .active_battle_started_at.length > 0 &&
        baseState.autoSync
            .active_battle_started_at !==
            battleStartedAt
    );
    const confirmedManualStage = (
        !sessionTransition &&
        !battleTransition &&
        stageResolution.status !== "ready" &&
        baseState.autoSync.stage_status.status ===
            "ready" &&
        baseState.autoSync.stage_status.source ===
            "manual" &&
        typeof baseState.autoSync.stage_status
            .mapped_field === "string" &&
        Boolean(
            FIELD_DECKS[
                baseState.autoSync.stage_status
                    .mapped_field
            ]
        )
    );
    if (confirmedManualStage) {
        stageResolution = {
            status: "ready",
            stage_code:
                baseState.autoSync.stage_status
                    .stage_code,
            field:
                baseState.autoSync.stage_status
                    .mapped_field,
            source: "manual",
            source_sequence: null
        };
    }
    if (
        (sessionTransition || battleTransition) &&
        !forceRebase &&
        !isTrackerPristineForFieldSwitch(
            baseState
        )
    ) {
        const transitionStatus = sessionTransition
            ? "session_transition_blocked"
            : "battle_transition_blocked";
        baseState.autoSync.stage_status =
            stageStatusFromResolution(
                {
                    ...stageResolution,
                    status: "conflict"
                },
                transitionStatus
            );
        for (
            const record
            of Object.values(
                baseState.autoSync
                    .pending_stage_events
            )
        ) {
            record.reason = "stage_conflict";
        }
        commitTrackerState(baseState);
        return {
            status: transitionStatus,
            pending: pendingStageSummary(
                baseState
            )
        };
    }

    const ownsIncomingPending = Object.values(
        baseState.autoSync.pending_stage_events
    ).some(
        record => (
            record.session_id === sessionId &&
            record.battle_started_at ===
                battleStartedAt
        )
    );
    if (ownsIncomingPending) {
        baseState.autoSync.active_session_id =
            sessionId;
        baseState.autoSync.active_battle_started_at =
            battleStartedAt;
    }

    baseState.autoSync.stage_status =
        stageStatusFromResolution(
            stageResolution
        );

    if (stageResolution.status !== "ready") {
        commitTrackerState(baseState);
        return {
            status: "pending_stage",
            reason: pendingReasonForStage(
                stageResolution
            ),
            pending: pendingStageSummary(
                baseState
            ),
            duplicates: duplicateResults
        };
    }

    const targetField = stageResolution.field;
    if (
        typeof targetField !== "string" ||
        !FIELD_DECKS[targetField]
    ) {
        return failPendingApply(
            baseState,
            {
                ...stageResolution,
                status: "field_deck_missing"
            },
            { status: "field_deck_missing" }
        );
    }

    const fieldDiffers =
        baseState.field !== targetField;
    if (
        fieldDiffers &&
        !forceRebase &&
        !isTrackerPristineForFieldSwitch(
            baseState
        )
    ) {
        baseState.autoSync.stage_status =
            stageStatusFromResolution(
                {
                    ...stageResolution,
                    status: "conflict"
                },
                "stage_conflict"
            );
        for (
            const record
            of Object.values(
                baseState.autoSync
                    .pending_stage_events
            )
        ) {
            record.reason = "stage_conflict";
        }
        commitTrackerState(baseState);
        return {
            status: "stage_conflict",
            current_field: baseState.field,
            mapped_field: targetField,
            pending: pendingStageSummary(
                baseState
            )
        };
    }

    let candidate = baseState;
    if (
        fieldDiffers ||
        (
            forceRebase &&
            (sessionTransition || battleTransition)
        )
    ) {
        const carriedAutoSync = structuredClone(
            baseState.autoSync
        );
        candidate = createFreshState(targetField);
        candidate.autoSync = carriedAutoSync;
    }
    candidate.autoSync.stage_status =
        stageStatusFromResolution(
            stageResolution
        );

    const matchingPending = Object.entries(
        candidate.autoSync.pending_stage_events
    )
        .filter(
            ([
                _syncKey,
                record
            ]) => (
                record.session_id === sessionId &&
                record.battle_started_at ===
                    battleStartedAt
            )
        )
        .sort(
            (
                left,
                right
            ) => (
                left[1].event_sequence -
                right[1].event_sequence
            )
        );

    if (matchingPending.length === 0) {
        pendingFieldSelection = null;
        candidate.autoSync.active_session_id =
            sessionId;
        candidate.autoSync
            .active_battle_started_at =
                battleStartedAt;
        commitTrackerState(candidate);
        return {
            status: fieldDiffers
                ? "field_rebased"
                : "stage_ready",
            field: targetField,
            duplicates: duplicateResults
        };
    }

    const validationState = structuredClone(
        candidate
    );
    const plans = [];
    for (
        const [
            syncKey,
            record
        ] of matchingPending
    ) {
        const plan = window.TrackerAutoCardSync
            ?.createDeductionPlan({
                sessionId,
                event: pendingRecordToEvent(record),
                projection: {
                    session_id: sessionId,
                    battle_status: "active",
                    battle_started_at:
                        battleStartedAt,
                    last_sequence: Math.max(
                        projectionSequence || 0,
                        record.projection_sequence,
                        record.event_sequence
                    )
                },
                currentField: targetField,
                fieldDeck:
                    validationState.initial,
                remaining:
                    validationState.remaining,
                processedEvents:
                    validationState.autoSync
                        .processed_events,
                activeSessionId: null,
                activeBattleStartedAt: null
            });
        if (!plan || plan.status !== "ready") {
            return failPendingApply(
                baseState,
                stageResolution,
                plan || {
                    status: "invalid_event"
                }
            );
        }
        plans.push(plan);
        for (
            const [cardName, count]
            of Object.entries(plan.deductions)
        ) {
            validationState.remaining[
                cardName
            ] -= count;
        }
        plan.sync_key = syncKey;
    }

    const deductions = {};
    for (const plan of plans) {
        for (
            const [cardName, count]
            of Object.entries(plan.deductions)
        ) {
            candidate.remaining[cardName] -= count;
            candidate.myHand[cardName] += count;
            deductions[cardName] =
                Number(deductions[cardName] || 0) +
                count;
        }
        candidate.autoSync.processed_events[
            plan.sync_key
        ] = {
            status: "applied",
            session_id: plan.session_id,
            event_id: plan.event_id,
            event_sequence: plan.event_sequence,
            projection_sequence:
                plan.projection_sequence,
            battle_started_at:
                plan.battle_started_at,
            field: targetField,
            deductions: cloneDeck(
                plan.deductions
            )
        };
        delete candidate.autoSync
            .pending_stage_events[
                plan.sync_key
            ];
    }
    candidate.autoSync.active_session_id =
        sessionId;
    candidate.autoSync.active_battle_started_at =
        battleStartedAt;
    candidate.autoSync.stage_status =
        stageStatusFromResolution(
            stageResolution
        );
    candidate.history.unshift({
        action: "auto_cards_dealt",
        ownershipZone: "my_hand",
        deductions: cloneDeck(deductions),
        source: "pending_stage",
        syncKeys: plans.map(
            plan => plan.sync_key
        ),
        time: getTimeText()
    });
    candidate.history = candidate.history.slice(
        0,
        100
    );
    pendingFieldSelection = null;
    commitTrackerState(candidate);
    return {
        status: "applied",
        field: targetField,
        applied_events: plans.length,
        applied_cards: Object.values(
            deductions
        ).reduce(
            (sum, count) => sum + count,
            0
        ),
        deductions,
        duplicates: duplicateResults
    };
}

function handleAutoSyncSessionClosed(
    sessionId
) {
    const nextState = structuredClone(state);
    let changed = false;
    for (
        const [syncKey, record]
        of Object.entries(
            nextState.autoSync
                .pending_stage_events
        )
    ) {
        if (
            !sessionId ||
            record.session_id === sessionId
        ) {
            delete nextState.autoSync
                .pending_stage_events[syncKey];
            changed = true;
        }
    }
    if (changed) {
        pendingFieldSelection = null;
        nextState.autoSync.stage_status =
            stageStatusFromResolution({
                status: "missing"
            });
        commitTrackerState(nextState);
    }
    return {
        status: changed
            ? "pending_cleared"
            : "no_pending"
    };
}

function selectFieldSafely(fieldName) {
    if (!FIELD_DECKS[fieldName]) {
        elements.field.value =
            pendingFieldSelection ||
            state.field;
        return {
            status: "field_deck_missing"
        };
    }
    const pending = Object.values(
        state.autoSync.pending_stage_events
    ).sort(
        (
            left,
            right
        ) => (
            left.event_sequence -
            right.event_sequence
        )
    );
    if (pending.length === 0) {
        pendingFieldSelection = null;
        if (
            !isTrackerPristineForFieldSwitch() &&
            !confirm(
                "目前已有 Tracker 進度。是否清除目前進度並切換場地？"
            )
        ) {
            elements.field.value = state.field;
            return { status: "cancelled" };
        }
        resetField(fieldName, false);
        return { status: "field_reset" };
    }

    /*
     * Selecting a field only stages the user's choice.
     * Rebase and pending application remain one atomic
     * operation behind the explicit confirmation button.
     */
    pendingFieldSelection = fieldName;
    elements.field.value = fieldName;
    renderStageDiagnostics();
    return {
        status: "pending_field_selected",
        field: fieldName,
        pending: pendingStageSummary()
    };
}

function confirmCurrentFieldAndApplyPending() {
    const pending = Object.values(
        state.autoSync.pending_stage_events
    ).sort(
        (
            left,
            right
        ) => (
            left.event_sequence -
            right.event_sequence
        )
    );
    if (pending.length === 0) {
        return { status: "no_pending" };
    }
    const first = pending[0];
    const hasSingleIdentity = pending.every(
        record => (
            record.session_id === first.session_id &&
            record.battle_started_at ===
                first.battle_started_at
        )
    );
    if (!hasSingleIdentity) {
        return failPendingApply(
            state,
            {
                status:
                    state.autoSync.stage_status.status,
                stage_code:
                    state.autoSync.stage_status
                        .stage_code,
                field:
                    state.autoSync.stage_status
                        .mapped_field,
                source:
                    state.autoSync.stage_status.source,
                source_sequence:
                    state.autoSync.stage_status
                        .source_sequence
            },
            {
                status:
                    "pending_identity_mismatch"
            }
        );
    }
    const targetField =
        pendingFieldSelection ||
        state.field;
    const fieldDiffers =
        targetField !== state.field;
    const sessionDiffers = (
        typeof state.autoSync
            .active_session_id === "string" &&
        state.autoSync.active_session_id.length > 0 &&
        state.autoSync.active_session_id !==
            first.session_id
    );
    const battleDiffers = (
        typeof state.autoSync
            .active_battle_started_at === "string" &&
        state.autoSync
            .active_battle_started_at.length > 0 &&
        state.autoSync
            .active_battle_started_at !==
                first.battle_started_at
    );
    const requiresRebase = (
        fieldDiffers ||
        sessionDiffers ||
        battleDiffers
    );
    if (
        requiresRebase &&
        !isTrackerPristineForFieldSwitch() &&
        !confirm(
            "目前已有 Tracker 進度。是否清除目前進度並套用新場次的暫存收牌？"
        )
    ) {
        return { status: "cancelled" };
    }
    return processAutoSyncBatch({
        sessionId: first.session_id,
        battleStartedAt:
            first.battle_started_at,
        battleStatus: "active",
        projectionSequence: Math.max(
            ...pending.map(
                record =>
                    record.projection_sequence
            )
        ),
        stageResolution: {
            status: "ready",
            stage_code:
                state.autoSync.stage_status
                    .stage_code,
            field: targetField,
            source: "manual",
            source_sequence: null
        },
        forceRebase: requiresRebase
    });
}

function getAutoSyncContext() {
    return {
        field: state.field,
        fieldDeck: cloneDeck(
            state.initial
        ),
        remaining: cloneDeck(
            state.remaining
        ),
        processedEvents: structuredClone(
            state.autoSync.processed_events
        ),
        pendingStageEvents: structuredClone(
            state.autoSync
                .pending_stage_events
        ),
        stageStatus: structuredClone(
            state.autoSync.stage_status
        ),
        activeSessionId:
            state.autoSync.active_session_id,
        activeBattleStartedAt:
            state.autoSync
                .active_battle_started_at
    };
}

function applyAutoCardsDealtPlan(plan) {
    if (
        !plan ||
        plan.status !== "ready" ||
        typeof plan.sync_key !== "string" ||
        plan.sync_key.length === 0 ||
        plan.field !== state.field ||
        !plan.deductions ||
        typeof plan.deductions !== "object" ||
        Array.isArray(plan.deductions)
    ) {
        return { status: "invalid_plan" };
    }
    if (
        Object.hasOwn(
            state.autoSync.processed_events,
            plan.sync_key
        )
    ) {
        return {
            status: "duplicate_event",
            sync_key: plan.sync_key
        };
    }
    if (
        typeof state.autoSync.active_session_id ===
            "string" &&
        state.autoSync.active_session_id.length > 0 &&
        state.autoSync.active_session_id !==
            plan.session_id
    ) {
        return {
            status: "session_transition_blocked",
            active_session_id:
                state.autoSync.active_session_id,
            incoming_session_id:
                plan.session_id
        };
    }
    if (
        typeof state.autoSync
            .active_battle_started_at ===
                "string" &&
        state.autoSync
            .active_battle_started_at.length > 0 &&
        state.autoSync
            .active_battle_started_at !==
                plan.battle_started_at
    ) {
        return {
            status: "battle_transition_blocked",
            active_battle_started_at:
                state.autoSync
                    .active_battle_started_at,
            incoming_battle_started_at:
                plan.battle_started_at
        };
    }

    const deductions = {};
    for (
        const [cardName, count]
        of Object.entries(plan.deductions)
    ) {
        if (
            !Object.hasOwn(
                state.initial,
                cardName
            ) ||
            !Number.isInteger(count) ||
            count <= 0 ||
            state.remaining[cardName] < count
        ) {
            return {
                status: "insufficient_remaining",
                canonical_card_key: cardName
            };
        }
        deductions[cardName] = count;
    }
    if (Object.keys(deductions).length === 0) {
        return { status: "invalid_plan" };
    }

    const nextState = structuredClone(state);
    for (
        const [cardName, count]
        of Object.entries(deductions)
    ) {
        nextState.remaining[cardName] -= count;
        nextState.myHand[cardName] += count;
    }
    nextState.autoSync.processed_events[
        plan.sync_key
    ] = {
        status: "applied",
        session_id: plan.session_id,
        event_id: plan.event_id,
        event_sequence: plan.event_sequence,
        projection_sequence:
            plan.projection_sequence,
        battle_started_at:
            plan.battle_started_at,
        field: plan.field,
        deductions: cloneDeck(deductions)
    };
    nextState.autoSync.active_session_id =
        plan.session_id;
    nextState.autoSync.active_battle_started_at =
        plan.battle_started_at;
    nextState.history.unshift({
        action: "auto_cards_dealt",
        syncKey: plan.sync_key,
        deductions: cloneDeck(deductions),
        ownershipZone: "my_hand",
        time: getTimeText()
    });
    nextState.history = nextState.history.slice(
        0,
        100
    );

    const previousState = state;
    state = nextState;
    try {
        saveState();
    } catch (error) {
        state = previousState;
        throw error;
    }
    render();
    return {
        status: "applied",
        sync_key: plan.sync_key,
        deductions: cloneDeck(deductions)
    };
}

function resetField(
    fieldName,
    needConfirm = true
) {
    if (
        needConfirm &&
        !confirm(
            "確定清除目前進度並載入此場地？"
        )
    ) {
        elements.field.value =
            state.field;

        return;
    }

    pendingFieldSelection = null;
    state = createFreshState(
        fieldName
    );

    saveState();
    render();
}

/*
 * 公牌清單左鍵／點一下：
 * 將該牌視為已公開，從剩餘清單扣除。
 */
function revealCard(cardName) {
    if (
        state.remaining[cardName] <= 0
    ) {
        return;
    }

    state.remaining[cardName] -= 1;

    addHistory({
        action: "reveal",
        card: cardName
    });

    saveState();
    render();
}

/*
 * 手機長按或電腦右鍵：
 * 將公牌移至我方手牌。
 */
function addCardToMyHand(cardName) {
    const usesAutoReservation = (
        Number(
            state.autoReserved?.[cardName] || 0
        ) > 0
    );
    if (
        !usesAutoReservation &&
        state.remaining[cardName] <= 0
    ) {
        return;
    }

    if (usesAutoReservation) {
        state.autoReserved[cardName] -= 1;
    } else {
        state.remaining[cardName] -= 1;
    }
    state.myHand[cardName] += 1;

    addHistory({
        action: "add_my_hand",
        card: cardName,
        usedAutoReservation:
            usesAutoReservation
    });

    saveState();
    render();
}

/*
 * 點擊我方手牌：
 * 代表我方已經打出這張牌。
 *
 * 卡片只從我方手牌移除，
 * 不加回 remaining，
 * 因此公牌清單仍維持已扣除狀態。
 */
function playCardFromMyHand(
    cardName
) {
    if (
        state.myHand[cardName] <= 0
    ) {
        return;
    }

    state.myHand[cardName] -= 1;

    addHistory({
        action: "play_my_hand",
        card: cardName
    });

    saveState();
    render();
}

/*
 * 點擊敵方手牌候選：
 * 代表確認對方已打出這張牌。
 *
 * 卡片從敵方候選區移除，
 * 但不加回 remaining。
 */
function playCardFromEnemyCandidates(
    cardName
) {
    if (
        state.enemyCandidates[
            cardName
        ] <= 0
    ) {
        return;
    }

    state.enemyCandidates[
        cardName
    ] -= 1;

    addHistory({
        action: "play_enemy_candidate",
        card: cardName
    });

    saveState();
    render();
}
/*
 * 洗牌：
 * 以目前剩餘公牌建立敵方手牌候選。
 *
 * 我方手牌加入時已經從 remaining 扣除，
 * 所以 remaining 就是：
 *
 * 初始牌庫
 * - 已公開卡片
 * - 我方手牌
 */
function shuffleEnemyCandidates() {
    /*
     * 保存洗牌前狀態，供復原使用。
     */
    const previousRemaining = cloneDeck(
        state.remaining
    );

    const previousEnemyCandidates = cloneDeck(
        state.enemyCandidates
    );

    /*
     * 敵方候選不能覆蓋原本內容。
     *
     * 新候選：
     * 原本敵方候選
     * ＋目前尚未公開的公牌
     */
    const newEnemyCandidates =
        createZeroDeck(
            state.initial
        );

    for (
        const cardName
        of Object.keys(
            state.initial
        )
    ) {
        const oldEnemyCount =
            Number(
                state.enemyCandidates[
                    cardName
                ] || 0
            );

        const currentRemainingCount =
            Number(
                state.remaining[
                    cardName
                ] || 0
            );

        /*
         * 可被敵方持有的最大數量，
         * 必須扣除目前仍在我方手中的牌。
         */
        const maximumEnemyCount =
            Math.max(
                0,
                Number(
                    state.initial[
                        cardName
                    ] || 0
                ) -
                Number(
                    state.myHand[
                        cardName
                    ] || 0
                ) -
                Number(
                    state.autoReserved?.[
                        cardName
                    ] || 0
                )
            );

        newEnemyCandidates[
            cardName
        ] = Math.min(
            maximumEnemyCount,
            oldEnemyCount +
            currentRemainingCount
        );
    }

    /*
     * 重建公牌清單：
     *
     * 初始牌庫
     * －我方目前手牌
     * －累加後的敵方候選
     *
     * 先前已打出／公開的牌會重新回到公牌。
     */
    const resetDeck = cloneDeck(
        state.initial
    );

    for (
        const cardName
        of Object.keys(
            state.initial
        )
    ) {
        resetDeck[
            cardName
        ] = Math.max(
            0,
            Number(
                state.initial[
                    cardName
                ] || 0
            ) -
                Number(
                    state.myHand[
                        cardName
                    ] || 0
                ) -
                Number(
                    state.autoReserved?.[
                        cardName
                    ] || 0
                ) -
                Number(
                    newEnemyCandidates[
                        cardName
                ] || 0
            )
        );
    }

    state.enemyCandidates =
        newEnemyCandidates;

    state.remaining =
        resetDeck;

    addHistory({
        action: "shuffle",
        previousRemaining,
        previousEnemyCandidates
    });

    saveState();
    render();
}

function undoLastAction() {
    const lastAction =
        state.history.shift();

    if (!lastAction) {
        return;
    }

    const cardName =
        lastAction.card;

    switch (lastAction.action) {
        case "reveal":
            state.remaining[
                cardName
            ] += 1;
            break;

        case "add_my_hand":
            state.myHand[
                cardName
            ] -= 1;

            if (
                lastAction
                    .usedAutoReservation
            ) {
                state.autoReserved[
                    cardName
                ] += 1;
            } else {
                state.remaining[
                    cardName
                ] += 1;
            }
            break;

        case "play_my_hand":
            state.myHand[
                cardName
            ] += 1;
            break;

        case "play_enemy_candidate":
            state.enemyCandidates[
                cardName
            ] += 1;
            break;

        case "shuffle":
            state.remaining = cloneDeck(
                lastAction.previousRemaining ||
                state.initial
            );

            state.enemyCandidates = cloneDeck(
                lastAction.previousEnemyCandidates ||
                createZeroDeck(
                    state.initial
                )
            );
            break;

        case "auto_cards_dealt": {
            const isMyHandBatch =
                lastAction.ownershipZone ===
                    "my_hand";
            for (
                const [dealtCard, count]
                of Object.entries(
                    lastAction.deductions || {}
                )
            ) {
                const returnCount = Math.min(
                    Number(count || 0),
                    Number(
                        (
                            isMyHandBatch
                                ? state.myHand
                                : state.autoReserved
                        )?.[dealtCard] || 0
                    )
                );
                if (isMyHandBatch) {
                    state.myHand[
                        dealtCard
                    ] -= returnCount;
                } else {
                    /*
                     * v0.1 reservation-era history remains
                     * undoable after loading old localStorage.
                     */
                    state.autoReserved[
                        dealtCard
                    ] -= returnCount;
                }
                state.remaining[
                    dealtCard
                ] = Math.min(
                    state.initial[dealtCard],
                    state.remaining[
                        dealtCard
                    ] + returnCount
                );
            }
            const syncKeys = Array.isArray(
                lastAction.syncKeys
            )
                ? lastAction.syncKeys
                : [lastAction.syncKey];
            for (const syncKey of syncKeys) {
                if (
                    typeof syncKey === "string" &&
                    state.autoSync
                        .processed_events[syncKey]
                ) {
                    state.autoSync
                        .processed_events[
                            syncKey
                        ].status = "reverted";
                }
            }
            break;
        }

        default:
            console.warn(
                "無法復原的操作：",
                lastAction
            );
            break;
    }

    saveState();
    render();
}

function parseCardName(cardName) {
    const matches = [
        ...cardName.matchAll(
            /(劍|槍|防|移|特)(\d+)/g
        )
    ];

    return matches.map(
        match => ({
            type: match[1],
            value: Number(
                match[2]
            )
        })
    );
}

function cardContainsType(
    cardName,
    type
) {
    if (!type) {
        return false;
    }

    return parseCardName(
        cardName
    ).some(
        side =>
            side.type === type
    );
}

function createCardFaceHtml(
    cardName,
    preferredType = null
) {
    const sides = parseCardName(
        cardName
    );

    if (sides.length < 2) {
        return `
            <div class="game-card-fallback">
                ${cardName}
            </div>
        `;
    }

    let [
        top,
        bottom
    ] = sides;

    /*
     * 選擇屬性排序時：
     * 若指定屬性只出現在下半部，
     * 將卡片顯示方向上下交換，
     * 讓該屬性統一顯示在上半部。
     *
     * 例如選擇「劍」：
     * 槍1劍2 → 顯示成 劍2／槍1
     */
    if (
        preferredType &&
        bottom.type === preferredType &&
        top.type !== preferredType
    ) {
        [
            top,
            bottom
        ] = [
            bottom,
            top
        ];
    }

    const topConfig =
        CARD_TYPE_CONFIG[
            top.type
        ];

    const bottomConfig =
        CARD_TYPE_CONFIG[
            bottom.type
        ];

    if (
        !topConfig ||
        !bottomConfig
    ) {
        return `
            <div class="game-card-fallback">
                ${cardName}
            </div>
        `;
    }

    return `
        <div class="game-card">
            <div
                class="
                    game-card-half
                    game-card-top
                "
            >
                <img
                    src="${topConfig.image}"
                    alt="${top.type}${top.value}"
                    draggable="false"
                >

                <span class="game-card-value">
                    ${top.value}
                </span>
            </div>

            <div
                class="
                    game-card-half
                    game-card-bottom
                "
            >
                <img
                    src="${bottomConfig.image}"
                    alt="${bottom.type}${bottom.value}"
                    draggable="false"
                >

                <span class="game-card-value">
                    ${bottom.value}
                </span>
            </div>

            <div class="game-card-divider"></div>
        </div>
    `;
}

function sortCardNames(
    cardNames,
    countDeck
) {
    return [
        ...cardNames
    ].sort(
        (
            a,
            b
        ) => {
            const aCount = Number(
                countDeck[a] || 0
            );

            const bCount = Number(
                countDeck[b] || 0
            );

            const aEmpty =
                aCount === 0;

            const bEmpty =
                bCount === 0;

            /*
             * 數量為 0 的卡片固定排到最後。
             */
            if (
                aEmpty !== bEmpty
            ) {
                return aEmpty
                    ? 1
                    : -1;
            }

            /*
             * 有選擇屬性時，
             * 含該屬性的牌排在前方。
             */
            if (state.sortType) {
                const aMatch =
                    cardContainsType(
                        a,
                        state.sortType
                    );

                const bMatch =
                    cardContainsType(
                        b,
                        state.sortType
                    );

                if (
                    aMatch !== bMatch
                ) {
                    return aMatch
                        ? -1
                        : 1;
                }
            }

            return a.localeCompare(
                b,
                "zh-Hant"
            );
        }
    );
}

function createSingleCardButtonHtml({
    cardName,
    className = "",
    probabilityText
}) {
    const ariaLabel =
        `${cardName}，機率 ${probabilityText}`;

    return `
        <button
            class="
                card
                game-card-button
                game-card-single
                ${className}
            "
            data-card="${cardName}"
            title="${cardName}"
            aria-label="${ariaLabel}"
        >
            <div class="game-card-preview">
                ${
                    createCardFaceHtml(
                        cardName,
                        state.sortType
                    )
                }
            </div>

            <div class="game-card-probability">
                ${probabilityText}
            </div>
        </button>
    `;
}

function normalizeVisibleCardCount(value) {
    const count = Number(value);
    if (
        !Number.isFinite(count) ||
        count <= 0
    ) {
        return 0;
    }
    return Math.floor(count);
}

function createCardCopiesHtml({
    cardName,
    count,
    className = "",
    probabilityText
}) {
    return Array.from(
        {
            length:
                normalizeVisibleCardCount(
                    count
                )
        },
        () => {
            return createSingleCardButtonHtml({
                cardName,
                className,
                probabilityText
            });
        }
    ).join("");
}

function bindPublicCardActions() {
    elements.cards
        .querySelectorAll(".card")
        .forEach(button => {
            let longPressTimer = null;
            let longPressed = false;

            /*
             * 在任何畫面重繪前先保存這張按鈕
             * 所代表的原始牌名，避免排序改變後抓錯卡。
             */
            const cardName =
                button.dataset.card;

            const cancelLongPress = () => {
                if (longPressTimer) {
                    clearTimeout(
                        longPressTimer
                    );

                    longPressTimer = null;
                }
            };

            button.addEventListener(
                "pointerdown",
                event => {
                    /*
                     * 滑鼠只有左鍵需要偵測長按。
                     * 右鍵交給 contextmenu。
                     */
                    if (
                        event.pointerType === "mouse" &&
                        event.button !== 0
                    ) {
                        return;
                    }

                    longPressed = false;

                    longPressTimer =
                        window.setTimeout(
                            () => {
                                longPressed = true;

                                addCardToMyHand(
                                    cardName
                                );

                                if (
                                    navigator.vibrate
                                ) {
                                    navigator.vibrate(
                                        40
                                    );
                                }
                            },
                            600
                        );
                }
            );

            button.addEventListener(
                "pointerup",
                event => {
                    cancelLongPress();

                    /*
                     * 滑鼠右鍵不能執行公開扣除。
                     */
                    if (
                        event.pointerType === "mouse" &&
                        event.button !== 0
                    ) {
                        return;
                    }

                    if (longPressed) {
                        event.preventDefault();
                        return;
                    }

                    /*
                     * 左鍵／手機短按：
                     * 公開扣除。
                     */
                    revealCard(
                        cardName
                    );
                }
            );

            button.addEventListener(
                "pointerleave",
                cancelLongPress
            );

            button.addEventListener(
                "pointercancel",
                cancelLongPress
            );

            /*
             * 電腦右鍵：
             * 加入我方手牌。
             */
            button.addEventListener(
                "contextmenu",
                event => {
                    event.preventDefault();
                    cancelLongPress();

                    addCardToMyHand(
                        cardName
                    );
                }
            );
        });
}

function renderPublicCards() {
    const unknownTotal =
        getTotal(
            state.remaining
        );

    const cardNames =
        sortCardNames(
            Object.keys(
                state.remaining
            ).filter(cardName => {
                return (
                    normalizeVisibleCardCount(
                        state.remaining[
                            cardName
                        ]
                    ) > 0
                );
            }),
            state.remaining
        );

    elements.cards.innerHTML =
        cardNames
            .map(cardName => {
                const current =
                    state.remaining[
                        cardName
                    ];

                const maximum =
                    state.initial[
                        cardName
                    ];

                let className = "";

                if (
                    current /
                    maximum
                    <= 0.34
                ) {
                    className =
                        "low";
                }

                const probability =
                    unknownTotal > 0
                        ? (
                            current /
                            unknownTotal *
                            100
                        )
                        : 0;

                return createCardCopiesHtml({
                    cardName,
                    count: current,
                    className,
                    probabilityText:
                        probability
                            .toFixed(1) +
                        "%"
                });
            })
            .join("");

    bindPublicCardActions();
}

function renderMyHand() {
    const total = getTotal(
        state.myHand
    );
    const names =
        sortCardNames(
            Object.keys(
                state.myHand
            ).filter(
                cardName =>
                    state.myHand[
                        cardName
                    ] > 0
            ),
            state.myHand
        );

    if (!names.length) {
        elements.myHand.innerHTML = `
            <div class="zone-empty">
                
            </div>
        `;

        return;
    }

    elements.myHand.innerHTML =
        names
            .map(cardName => {
                const count =
                    state.myHand[
                        cardName
                    ];
                const probability =
                    total > 0
                        ? (
                            count /
                            total *
                            100
                        )
                        : 0;

                return createCardCopiesHtml({
                    cardName,
                    count,
                    className:
                        "in-hand",
                    probabilityText:
                        probability
                            .toFixed(1) +
                        "%"
                });
            })
            .join("");

    elements.myHand
        .querySelectorAll(".card")
        .forEach(button => {
            const cardName =
                button.dataset.card;

            button.addEventListener(
                "click",
                () => {
                    playCardFromMyHand(
                        cardName
                    );
                }
            );

            button.addEventListener(
                "contextmenu",
                event => {
                    event.preventDefault();
                }
            );
        });
}

function renderEnemyCandidates() {
    const names =
        sortCardNames(
            Object.keys(
                state.enemyCandidates
            ).filter(
                cardName =>
                    state.enemyCandidates[
                        cardName
                    ] > 0
            ),
            state.enemyCandidates
        );

    if (!names.length) {
        elements.enemyCandidates.innerHTML = `
            <div class="zone-empty">
                
            </div>
        `;

        return;
    }

    const total = getTotal(
        state.enemyCandidates
    );

    elements.enemyCandidates.innerHTML =
        names
            .map(cardName => {
                const count =
                    state.enemyCandidates[
                        cardName
                    ];

                const probability =
                    total > 0
                        ? (
                            count /
                            total *
                            100
                        )
                        : 0;

                return createCardCopiesHtml({
                    cardName,
                    count,
                    className:
                        "enemy-candidate",
                    probabilityText:
                        probability
                            .toFixed(1) +
                        "%"
                });
            })
            .join("");

    elements.enemyCandidates
        .querySelectorAll(".card")
        .forEach(button => {
            const cardName =
                button.dataset.card;

            button.addEventListener(
                "click",
                () => {
                    playCardFromEnemyCandidates(
                        cardName
                    );
                }
            );

            button.addEventListener(
                "contextmenu",
                event => {
                    event.preventDefault();
                }
            );
        });
}

function renderTypeStats() {
    const typeCounts =
        Object.fromEntries(
            TYPE_ORDER.map(
                type => [
                    type,
                    0
                ]
            )
        );

    for (
        const [
            cardName,
            count
        ]
        of Object.entries(
            state.remaining
        )
    ) {
        const matches =
            cardName.matchAll(
                /(劍|槍|防|移|特)\d+/g
            );

        for (
            const match
            of matches
        ) {
            typeCounts[
                match[1]
            ] += count;
        }
    }

    elements.types.innerHTML =
        TYPE_ORDER
            .map(type => {
                const active =
                    state.sortType
                    === type;

                return `
                    <button
                        class="
                            type-button
                            ${
                                active
                                    ? "active"
                                    : ""
                            }
                        "
                        data-type="${type}"
                    >
                        <strong>
                            ${type}
                        </strong>

                        <span>
                            ${typeCounts[type]}
                        </span>
                    </button>
                `;
            })
            .join("");

    elements.types
        .querySelectorAll(
            ".type-button"
        )
        .forEach(button => {
            button.addEventListener(
                "click",
                () => {
                    const type =
                        button
                            .dataset
                            .type;

                    state.sortType =
                        state.sortType
                        === type
                            ? null
                            : type;

                    saveState();
                    render();
                }
            );
        });
}

function getHistoryDescription(item) {
    switch (item.action) {
        case "reveal":
            return {
                title:
                    `公開扣除：${item.card}`,
                amount: "−1"
            };

        case "add_my_hand":
            return {
                title:
                    `加入我方手牌：${item.card}`,
                amount: "→手牌"
            };

        case "play_my_hand":
            return {
                title:
                    `我方出牌：${item.card}`,
                amount: "已出牌"
            };

        case "play_enemy_candidate":
            return {
                title:
                    `敵方出牌：${item.card}`,
                amount: "已出牌"
            };

        case "shuffle":
            return {
                title:
                    "重新建立敵方手牌候選",
                amount:
                    "洗牌"
            };

        case "auto_cards_dealt":
            return {
                title:
                    "自動加入我方手牌",
                amount:
                    `→手牌 ×${getTotal(
                        item.deductions
                    )}`
            };

        default:
            return {
                title:
                    "未知操作",
                amount:
                    ""
            };
    }
}

function renderHistory() {
    if (
        !state.history.length
    ) {
        elements.history.innerHTML =
            '<div class="empty">尚無操作紀錄</div>';

        return;
    }

    elements.history.innerHTML =
        state.history
            .slice(
                0,
                20
            )
            .map(item => {
                const description =
                    getHistoryDescription(
                        item
                    );

                return `
                    <div class="hist">
                        <div>
                            <b>
                                ${description.title}
                            </b>

                            <br>

                            <small>
                                ${item.time || ""}
                            </small>
                        </div>

                        <span>
                            ${description.amount}
                        </span>
                    </div>
                `;
            })
            .join("");
}

function renderFieldOptions() {
    elements.field.innerHTML =
        Object.values(
            FIELD_GROUPS
        )
            .map(group => {
                const options =
                    group.fields
                        .map(
                            fieldName => {
                                const hasDeck =
                                    Boolean(
                                        FIELD_DECKS[
                                            fieldName
                                        ]
                                    );

                                return `
                                    <option
                                        value="${fieldName}"
                                        ${
                                            hasDeck
                                                ? ""
                                                : "disabled"
                                        }
                                    >
                                        ${fieldName}
                                        ${
                                            hasDeck
                                                ? ""
                                                : "（尚無牌組資料）"
                                        }
                                    </option>
                                `;
                            }
                        )
                        .join("");

                return `
                    <optgroup
                        label="【${group.label}】"
                    >
                        ${options}
                    </optgroup>
                `;
            })
            .join("");

    const hasPending = Object.keys(
        state.autoSync.pending_stage_events
    ).length > 0;
    elements.field.value = (
        hasPending &&
        FIELD_DECKS[pendingFieldSelection]
            ? pendingFieldSelection
            : state.field
    );
}

function renderSummary() {
    const initialTotal =
        getTotal(
            state.initial
        );

    const deckLeft =
        getTotal(
            state.remaining
        );

    const myHandTotal =
        getTotal(
            state.myHand
        );

    const enemyCandidateTotal =
        getTotal(
            state.enemyCandidates
        );

    const seenTotal =
        Math.max(
            0,
            initialTotal -
            deckLeft -
            myHandTotal
        );

    elements.initial.textContent =
        initialTotal;

    elements.deckLeft.textContent =
        deckLeft;

    elements.myHandCount.textContent =
        myHandTotal;

    elements
        .enemyCandidateCount
        .textContent =
            enemyCandidateTotal;

    elements.seen.textContent =
        seenTotal;
}

function renderStageDiagnostics() {
    const stage =
        state.autoSync.stage_status;
    const pending = pendingStageSummary();
    const setText = (
        id,
        value
    ) => {
        const target = document.getElementById(id);
        if (target) {
            target.textContent = value;
        }
    };
    setText(
        "observation-stage-code",
        stage.stage_code || "-"
    );
    setText(
        "observation-stage-field",
        stage.mapped_field || "-"
    );
    setText(
        "observation-stage-status",
        (() => {
            const lastAction = state.history[0];
            if (
                stage.status === "ready" &&
                lastAction?.action ===
                    "auto_cards_dealt" &&
                lastAction.source ===
                    "pending_stage"
            ) {
                return (
                    "已套用暫存收牌 " +
                    `${lastAction.syncKeys?.length || 0}` +
                    " 筆／" +
                    `${getTotal(lastAction.deductions)}` +
                    " 張"
                );
            }
            const messages = {
                ready:
                    `已偵測場地：${
                        stage.mapped_field || "-"
                    }`,
                random:
                    "隨機場地，已暫存收牌，請選擇實際場地",
                field_deck_missing:
                    `已偵測${
                        stage.mapped_field || "場地"
                    }，但尚無牌庫資料`,
                missing:
                    "尚未取得場地，收牌事件已暫存",
                conflict:
                    "目前場地與偵測場地不同，已有進度，等待確認",
                unsupported:
                    "無法識別場地代碼，收牌事件已暫存",
                invalid_stage_code:
                    "場地代碼格式錯誤，收牌事件已暫存"
            };
            return (
                messages[stage.status] ||
                stage.status ||
                "missing"
            );
        })()
    );
    setText(
        "observation-pending-events",
        String(pending.events)
    );
    setText(
        "observation-pending-cards",
        String(pending.cards)
    );
    setText(
        "observation-stage-error",
        stage.last_error || "-"
    );
    if (elements.confirmPendingField) {
        elements.confirmPendingField.disabled =
            pending.events === 0;
    }
}

function render() {
    renderFieldOptions();
    renderSummary();
    renderEnemyCandidates();
    renderPublicCards();
    renderMyHand();
    renderTypeStats();
    renderHistory();
    renderStageDiagnostics();

    elements.undo.disabled =
        state.history.length === 0;
}

elements.field.addEventListener(
    "change",
    () => {
        selectFieldSafely(
            elements.field.value
        );
    }
);

elements.undo.addEventListener(
    "click",
    undoLastAction
);

elements.shuffle.addEventListener(
    "click",
    shuffleEnemyCandidates
);

elements.reset.addEventListener(
    "click",
    () => {
        resetField(
            state.field
        );
    }
);

elements.clear.addEventListener(
    "click",
    () => {
        state.history = [];

        saveState();
        render();
    }
);

elements.confirmPendingField?.addEventListener(
    "click",
    confirmCurrentFieldAndApplyPending
);

if (typeof window !== "undefined") {
    window.TrackerAutoSyncTarget =
        Object.freeze({
            getContext: getAutoSyncContext,
            applyPlan:
                applyAutoCardsDealtPlan,
            processBatch:
                processAutoSyncBatch,
            sessionClosed:
                handleAutoSyncSessionClosed,
            selectField:
                selectFieldSafely,
            confirmCurrentField:
                confirmCurrentFieldAndApplyPending,
            isPristine:
                isTrackerPristineForFieldSwitch
        });
}

render();
