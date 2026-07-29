(function (global) {
    "use strict";

    const FACE_KIND_TO_CARD_TYPE = Object.freeze({
        sword: "劍",
        gun: "槍",
        shield: "防",
        move: "移",
        special: "特"
    });

    function isObject(value) {
        return (
            value !== null &&
            typeof value === "object" &&
            !Array.isArray(value)
        );
    }

    function faceToTrackerToken(face) {
        if (
            !isObject(face) ||
            !Object.hasOwn(
                FACE_KIND_TO_CARD_TYPE,
                face.kind
            ) ||
            !Number.isInteger(face.value) ||
            face.value <= 0
        ) {
            return null;
        }
        return (
            FACE_KIND_TO_CARD_TYPE[face.kind] +
            String(face.value)
        );
    }

    function resolveCanonicalCardKey(
        card,
        fieldDeck
    ) {
        if (!isObject(card) || !isObject(fieldDeck)) {
            return { status: "invalid_card" };
        }
        const top = faceToTrackerToken(
            card.display_top
        );
        const bottom = faceToTrackerToken(
            card.display_bottom
        );
        if (!top || !bottom) {
            return { status: "invalid_card" };
        }

        const forward = top + bottom;
        const reverse = bottom + top;
        const matches = new Set();
        if (Object.hasOwn(fieldDeck, forward)) {
            matches.add(forward);
        }
        if (Object.hasOwn(fieldDeck, reverse)) {
            matches.add(reverse);
        }
        if (matches.size === 0) {
            return {
                status: "no_match",
                forward,
                reverse
            };
        }
        if (matches.size > 1) {
            return {
                status: "ambiguous_match",
                forward,
                reverse
            };
        }
        return {
            status: "unique_match",
            canonical_card_key: [...matches][0],
            forward,
            reverse
        };
    }

    function makeSyncKey(sessionId, eventId) {
        return JSON.stringify([
            sessionId,
            eventId
        ]);
    }

    function domainEventFromDurable(event) {
        if (
            !isObject(event) ||
            !isObject(event.payload) ||
            !isObject(event.payload.domain_event)
        ) {
            return null;
        }
        return event.payload.domain_event;
    }

    function createDeductionPlan({
        sessionId,
        event,
        projection,
        currentField,
        fieldDeck,
        remaining,
        processedEvents = {},
        activeSessionId = null,
        activeBattleStartedAt = null
    }) {
        if (
            typeof sessionId !== "string" ||
            sessionId.length === 0 ||
            !isObject(event) ||
            event.session_id !== sessionId ||
            typeof event.event_id !== "string" ||
            event.event_id.length === 0 ||
            !Number.isInteger(event.sequence) ||
            event.sequence < 1
        ) {
            return { status: "invalid_event" };
        }

        const domainEvent = domainEventFromDurable(
            event
        );
        if (
            !domainEvent ||
            domainEvent.event_type !==
                "hand.cards_dealt"
        ) {
            return { status: "ignored_event" };
        }
        if (
            !isObject(projection) ||
            projection.session_id !== sessionId ||
            projection.battle_status !== "active" ||
            typeof projection.battle_started_at !==
                "string" ||
            projection.battle_started_at.length === 0 ||
            !Number.isInteger(
                projection.last_sequence
            ) ||
            projection.last_sequence <
                event.sequence
        ) {
            return { status: "invalid_projection" };
        }

        const syncKey = makeSyncKey(
            sessionId,
            event.event_id
        );
        if (
            typeof activeSessionId === "string" &&
            activeSessionId.length > 0 &&
            activeSessionId !== sessionId
        ) {
            return {
                status: "session_transition_blocked",
                active_session_id: activeSessionId,
                incoming_session_id: sessionId
            };
        }
        if (
            typeof activeBattleStartedAt ===
                "string" &&
            activeBattleStartedAt.length > 0 &&
            activeBattleStartedAt !==
                projection.battle_started_at
        ) {
            return {
                status: "battle_transition_blocked",
                active_battle_started_at:
                    activeBattleStartedAt,
                incoming_battle_started_at:
                    projection.battle_started_at
            };
        }
        if (
            isObject(processedEvents) &&
            Object.hasOwn(processedEvents, syncKey)
        ) {
            return {
                status: "duplicate_event",
                sync_key: syncKey
            };
        }
        const cards = domainEvent.payload?.cards;
        if (!Array.isArray(cards) || cards.length === 0) {
            return { status: "invalid_event" };
        }
        if (
            typeof currentField !== "string" ||
            currentField.length === 0 ||
            !isObject(fieldDeck) ||
            !isObject(remaining)
        ) {
            return { status: "invalid_tracker_state" };
        }

        const deductions = {};
        const resolvedCards = [];
        for (
            let index = 0;
            index < cards.length;
            index += 1
        ) {
            const resolved =
                resolveCanonicalCardKey(
                    cards[index],
                    fieldDeck
                );
            if (resolved.status !== "unique_match") {
                return {
                    status: resolved.status,
                    card_index: index,
                    mapping: resolved
                };
            }
            const cardKey =
                resolved.canonical_card_key;
            deductions[cardKey] =
                Number(deductions[cardKey] || 0) +
                1;
            resolvedCards.push({
                card_key: (
                    typeof cards[index].card_key ===
                        "string"
                        ? cards[index].card_key
                        : null
                ),
                canonical_card_key: cardKey
            });
        }

        for (
            const [cardKey, count]
            of Object.entries(deductions)
        ) {
            if (
                !Number.isInteger(count) ||
                count <= 0 ||
                !Number.isInteger(
                    remaining[cardKey]
                ) ||
                remaining[cardKey] < count
            ) {
                return {
                    status: "insufficient_remaining",
                    canonical_card_key: cardKey,
                    required: count,
                    available: Number(
                        remaining[cardKey] || 0
                    )
                };
            }
        }

        return {
            status: "ready",
            sync_key: syncKey,
            session_id: sessionId,
            event_id: event.event_id,
            event_sequence: event.sequence,
            projection_sequence:
                projection.last_sequence,
            battle_started_at:
                projection.battle_started_at,
            field: currentField,
            deductions,
            cards: resolvedCards
        };
    }

    function createPendingRecord({
        sessionId,
        event,
        projection
    }) {
        const domainEvent = domainEventFromDurable(
            event
        );
        const cards = domainEvent?.payload?.cards;
        if (
            domainEvent?.event_type !==
                "hand.cards_dealt" ||
            typeof sessionId !== "string" ||
            event?.session_id !== sessionId ||
            typeof event.event_id !== "string" ||
            !Number.isInteger(event.sequence) ||
            !Array.isArray(cards) ||
            cards.length === 0 ||
            typeof projection?.battle_started_at !==
                "string"
        ) {
            return { status: "invalid_event" };
        }
        return {
            status: "pending_stage",
            sync_key: makeSyncKey(
                sessionId,
                event.event_id
            ),
            session_id: sessionId,
            event_id: event.event_id,
            event_sequence: event.sequence,
            projection_sequence:
                projection.last_sequence,
            battle_started_at:
                projection.battle_started_at,
            cards: JSON.parse(
                JSON.stringify(cards)
            ),
            received_at: (
                typeof event.occurred_at === "string"
                    ? event.occurred_at
                    : new Date().toISOString()
            )
        };
    }

    function handleCommittedBatch({
        sessionId,
        events,
        projection
    }) {
        const target =
            global.TrackerAutoSyncTarget;
        if (
            !target ||
            typeof target.getContext !== "function" ||
            typeof target.processBatch !== "function" ||
            !global.TrackerStageSync ||
            !Array.isArray(events)
        ) {
            return [];
        }

        const relevantEventTypes = new Set();
        const incomingPending = [];
        for (const event of events) {
            const domainEvent =
                domainEventFromDurable(event);
            if (typeof domainEvent?.event_type === "string") {
                relevantEventTypes.add(
                    domainEvent.event_type
                );
            }
            if (
                domainEvent?.event_type !==
                    "hand.cards_dealt"
            ) {
                continue;
            }
            const pending = createPendingRecord({
                sessionId,
                event,
                projection
            });
            if (pending.status !== "pending_stage") {
                return [pending];
            }
            incomingPending.push(pending);
        }
        const context = target.getContext();
        const shouldReconcile = (
            incomingPending.length > 0 ||
            Object.keys(
                context.pendingStageEvents || {}
            ).length > 0 ||
            relevantEventTypes.has("battle.started") ||
            relevantEventTypes.has("battle.finished")
        );
        if (!shouldReconcile) {
            return [];
        }
        const stageResolution =
            global.TrackerStageSync
                .resolveProjectionStage(
                    projection,
                    (
                        typeof FIELD_DECKS !==
                            "undefined"
                            ? FIELD_DECKS
                            : null
                    )
                );
        return [
            target.processBatch({
                sessionId,
                battleStartedAt:
                    projection?.battle_started_at,
                battleStatus:
                    projection?.battle_status,
                projectionSequence:
                    projection?.last_sequence,
                stageResolution,
                incomingPending
            })
        ];
    }

    function handleSessionClosed(sessionId) {
        return global.TrackerAutoSyncTarget
            ?.sessionClosed?.(sessionId) || {
                status: "unavailable"
            };
    }

    global.TrackerAutoCardSync = Object.freeze({
        FACE_KIND_TO_CARD_TYPE,
        faceToTrackerToken,
        resolveCanonicalCardKey,
        makeSyncKey,
        createDeductionPlan,
        createPendingRecord,
        handleCommittedBatch,
        handleSessionClosed
    });
})(
    typeof window !== "undefined"
        ? window
        : globalThis
);
