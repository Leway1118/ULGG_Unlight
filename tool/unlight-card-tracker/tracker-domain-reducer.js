(function (global) {
    "use strict";

    const PROJECTION_SCHEMA_VERSION = 1;
    const DOMAIN_EVENT_SCHEMA_VERSION = 1;
    const SUPPORTED_EVENT_TYPES = new Set([
        "battle.started",
        "hand.cards_dealt",
        "hand.event_card_received",
        "hand.card_drawn",
        "play.selection_changed",
        "play.cards_revealed",
        "battle.phase_ended",
        "battle.turn_ended",
        "battle.finished"
    ]);
    const ALLOWED_VISIBILITIES = new Set([
        "public",
        "self_private",
        "opponent_revealed"
    ]);
    const REQUIRED_DOMAIN_FIELDS = [
        "domain_event_schema_version",
        "event_type",
        "payload",
        "source",
        "idempotency_key",
        "producer_session_id",
        "occurred_at",
        "authority",
        "visibility",
        "confirmation",
        "resolved_side",
        "source_sequence"
    ];
    const FORBIDDEN_KEYS = new Set([
        "args",
        "args_summary",
        "raw",
        "room",
        "room_id",
        "session_token",
        "steamid",
        "token",
        "url",
        "websocket_url"
    ]);

    class DomainProjectionError extends Error {
        constructor(code, message) {
            super(message);
            this.name = "DomainProjectionError";
            this.code = code;
        }
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function isObject(value) {
        return (
            value !== null &&
            typeof value === "object" &&
            !Array.isArray(value)
        );
    }

    function containsForbiddenKey(value) {
        if (Array.isArray(value)) {
            return value.some(containsForbiddenKey);
        }
        if (!isObject(value)) {
            return false;
        }
        return Object.entries(value).some(([key, child]) => (
            FORBIDDEN_KEYS.has(
                key.toLowerCase().replaceAll("-", "_")
            ) ||
            containsForbiddenKey(child)
        ));
    }

    function createInitialProjection(sessionId) {
        if (
            typeof sessionId !== "string" ||
            sessionId.length === 0
        ) {
            throw new TypeError("sessionId is required");
        }
        return {
            projection_schema_version:
                PROJECTION_SCHEMA_VERSION,
            session_id: sessionId,
            battle_status: "idle",
            battle_started_at: null,
            battle_finished_at: null,
            stage_code: null,
            stage_source: null,
            stage_source_sequence: null,
            turn: 0,
            phase: null,
            outcome: null,
            self_hand: [],
            self_event_cards: [],
            self_pending: [],
            self_revealed: [],
            opponent_revealed: [],
            seen_card_ids: [],
            applied_event_ids: [],
            last_sequence: 0,
            updated_at: null,
            diagnostics: []
        };
    }

    function validateDurableDomainEvent(
        durableEvent,
        sessionId
    ) {
        if (!isObject(durableEvent)) {
            throw new DomainProjectionError(
                "MALFORMED_DOMAIN_EVENT",
                "Durable event must be an object"
            );
        }
        if (durableEvent.session_id !== sessionId) {
            throw new DomainProjectionError(
                "SESSION_MISMATCH",
                "Durable event session does not match active session"
            );
        }
        if (
            typeof durableEvent.event_id !== "string" ||
            durableEvent.event_id.length === 0 ||
            !Number.isInteger(durableEvent.sequence) ||
            durableEvent.sequence < 1
        ) {
            throw new DomainProjectionError(
                "MALFORMED_DOMAIN_EVENT",
                "Durable event identity is invalid"
            );
        }
        if (
            !isObject(durableEvent.payload) ||
            !isObject(durableEvent.payload.domain_event)
        ) {
            throw new DomainProjectionError(
                "MALFORMED_DOMAIN_EVENT",
                "payload.domain_event is required"
            );
        }

        const event = durableEvent.payload.domain_event;
        for (const field of REQUIRED_DOMAIN_FIELDS) {
            if (!Object.hasOwn(event, field)) {
                throw new DomainProjectionError(
                    "MALFORMED_DOMAIN_EVENT",
                    `Missing domain event field: ${field}`
                );
            }
        }
        if (
            event.domain_event_schema_version !==
                DOMAIN_EVENT_SCHEMA_VERSION
        ) {
            throw new DomainProjectionError(
                "UNSUPPORTED_DOMAIN_SCHEMA",
                "Unsupported domain event schema"
            );
        }
        if (
            event.producer_session_id !== sessionId ||
            event.idempotency_key !== durableEvent.event_id
        ) {
            throw new DomainProjectionError(
                "DOMAIN_IDENTITY_MISMATCH",
                "Domain event identity does not match durable event"
            );
        }
        if (
            typeof event.event_type !== "string" ||
            event.event_type.length === 0 ||
            !isObject(event.payload) ||
            typeof event.occurred_at !== "string" ||
            event.occurred_at.length === 0 ||
            !Number.isInteger(event.source_sequence) ||
            event.source_sequence < 0
        ) {
            throw new DomainProjectionError(
                "MALFORMED_DOMAIN_EVENT",
                "Domain event envelope is invalid"
            );
        }
        if (!ALLOWED_VISIBILITIES.has(event.visibility)) {
            throw new DomainProjectionError(
                "RESTRICTED_VISIBILITY",
                "Domain event visibility is not safe"
            );
        }
        if (event.source !== "websocket") {
            throw new DomainProjectionError(
                "UNSUPPORTED_DOMAIN_SOURCE",
                "Domain event source is not supported"
            );
        }
        if (containsForbiddenKey(event)) {
            throw new DomainProjectionError(
                "FORBIDDEN_DOMAIN_PAYLOAD",
                "Domain event contains raw or sensitive fields"
            );
        }
        return clone(event);
    }

    function diagnostic(event, code) {
        return {
            code,
            event_type: (
                typeof event?.event_type === "string"
                    ? event.event_type
                    : null
            ),
            source_sequence: (
                Number.isInteger(event?.source_sequence)
                    ? event.source_sequence
                    : null
            )
        };
    }

    function requireCard(value, event, index) {
        if (
            !isObject(value) ||
            !Number.isInteger(value.card_id) ||
            value.card_id < 0 ||
            (
                value.slot !== null &&
                (
                    !Number.isInteger(value.slot) ||
                    value.slot < 0
                )
            )
        ) {
            throw new DomainProjectionError(
                "INVALID_DOMAIN_PAYLOAD",
                "Card payload is invalid"
            );
        }
        return {
            ...clone(value),
            card_key: (
                value.slot !== null
                    ? `slot:${value.slot}`
                    : [
                        "card",
                        value.card_id,
                        event.source_sequence,
                        index
                    ].join(":")
            )
        };
    }

    function requireCards(event, field) {
        const raw = field === "card"
            ? [event.payload.card]
            : event.payload.cards;
        if (!Array.isArray(raw) || raw.length === 0) {
            throw new DomainProjectionError(
                "INVALID_DOMAIN_PAYLOAD",
                "Card list is required"
            );
        }
        return raw.map(
            (card, index) => requireCard(
                card,
                event,
                index
            )
        );
    }

    function appendUnique(state, zone, cards, emitted) {
        const keys = new Set(
            state[zone].map(card => card.card_key)
        );
        for (const card of cards) {
            if (keys.has(card.card_key)) {
                emitted.push({
                    code: "card_identity_already_present",
                    event_type: null,
                    source_sequence: null
                });
                continue;
            }
            state[zone].push(card);
            keys.add(card.card_key);
            if (!state.seen_card_ids.includes(card.card_id)) {
                state.seen_card_ids.push(card.card_id);
            }
        }
    }

    function stageContextFromEvent(event) {
        const payload = event.payload;
        if (Object.keys(payload).length === 0) {
            return null;
        }
        if (
            typeof payload.stage_code !== "string" ||
            !/^[0-9]{3}$/.test(payload.stage_code) ||
            payload.stage_source !== "db_quest_land_now" ||
            !Number.isInteger(
                payload.stage_source_sequence
            ) ||
            payload.stage_source_sequence < 0
        ) {
            throw new DomainProjectionError(
                "INVALID_STAGE_CONTEXT",
                "Battle stage context is invalid"
            );
        }
        return {
            stage_code: payload.stage_code,
            stage_source: payload.stage_source,
            stage_source_sequence:
                payload.stage_source_sequence
        };
    }

    function resetBattle(state, event) {
        const next = createInitialProjection(state.session_id);
        next.applied_event_ids =
            clone(state.applied_event_ids);
        next.last_sequence = state.last_sequence;
        next.diagnostics = clone(state.diagnostics);
        next.battle_status = "active";
        next.battle_started_at = event.occurred_at;
        const stageContext = stageContextFromEvent(event);
        if (stageContext) {
            Object.assign(next, stageContext);
        }
        return next;
    }

    function reduceDomainEvent(currentState, durableEvent) {
        if (
            !isObject(currentState) ||
            currentState.projection_schema_version !==
                PROJECTION_SCHEMA_VERSION ||
            typeof currentState.session_id !== "string"
        ) {
            throw new DomainProjectionError(
                "INVALID_PROJECTION",
                "Current projection is invalid"
            );
        }

        const event = validateDurableDomainEvent(
            durableEvent,
            currentState.session_id
        );
        let state = clone(currentState);
        const emitted = [];

        if (!SUPPORTED_EVENT_TYPES.has(event.event_type)) {
            emitted.push(diagnostic(
                event,
                "unsupported_event_type"
            ));
            state.diagnostics.push(...emitted);
            return { nextState: state, diagnostics: emitted };
        }

        const expectedAuthority = (
            event.event_type === "play.selection_changed"
                ? "provisional"
                : "authoritative"
        );
        const expectedConfirmation = (
            event.event_type === "play.selection_changed"
                ? "pending"
                : "confirmed"
        );
        if (
            event.authority !== expectedAuthority ||
            event.confirmation !== expectedConfirmation
        ) {
            throw new DomainProjectionError(
                "INVALID_DOMAIN_SEMANTICS",
                "Domain authority or confirmation is invalid"
            );
        }
        if (
            event.event_type === "play.selection_changed" &&
            (
                event.visibility !== "self_private" ||
                event.resolved_side !== "self"
            )
        ) {
            throw new DomainProjectionError(
                "INVALID_DOMAIN_SEMANTICS",
                "Selection must be self-private"
            );
        }
        if (
            [
                "hand.cards_dealt",
                "hand.event_card_received",
                "hand.card_drawn"
            ].includes(event.event_type) &&
            (
                event.visibility !== "self_private" ||
                !["self", "unknown"].includes(
                    event.resolved_side
                )
            )
        ) {
            throw new DomainProjectionError(
                "INVALID_DOMAIN_SEMANTICS",
                "Hand event visibility is invalid"
            );
        }
        if (
            ![
                "play.selection_changed",
                "hand.cards_dealt",
                "hand.event_card_received",
                "hand.card_drawn",
                "play.cards_revealed"
            ].includes(event.event_type) &&
            event.visibility !== "public"
        ) {
            throw new DomainProjectionError(
                "INVALID_DOMAIN_SEMANTICS",
                "Public event visibility is invalid"
            );
        }

        if (event.event_type === "battle.started") {
            const restarted =
                state.battle_status === "active";
            state = resetBattle(state, event);
            if (restarted) {
                emitted.push(diagnostic(
                    event,
                    "battle_restarted"
                ));
            }
        } else if (state.battle_status === "idle") {
            emitted.push(diagnostic(
                event,
                "event_before_battle_start"
            ));
        } else if (state.battle_status === "finished") {
            emitted.push(diagnostic(
                event,
                "late_event_after_finish"
            ));
        } else if (event.event_type === "hand.cards_dealt") {
            appendUnique(
                state,
                "self_hand",
                requireCards(event, "cards"),
                emitted
            );
        } else if (
            event.event_type === "hand.event_card_received"
        ) {
            appendUnique(
                state,
                "self_event_cards",
                requireCards(event, "card"),
                emitted
            );
        } else if (event.event_type === "hand.card_drawn") {
            appendUnique(
                state,
                "self_hand",
                requireCards(event, "card"),
                emitted
            );
        } else if (
            event.event_type === "play.selection_changed"
        ) {
            const { slot, selected, selection } =
                event.payload;
            if (
                !Number.isInteger(slot) ||
                slot < 0 ||
                typeof selected !== "boolean" ||
                selection !== (
                    selected ? "selected" : "returned"
                )
            ) {
                throw new DomainProjectionError(
                    "INVALID_DOMAIN_PAYLOAD",
                    "Selection payload is invalid"
                );
            }
            if (
                selected &&
                !state.self_pending.includes(slot)
            ) {
                state.self_pending.push(slot);
            } else if (!selected) {
                state.self_pending = state.self_pending.filter(
                    value => value !== slot
                );
            }
        } else if (
            event.event_type === "play.cards_revealed"
        ) {
            const cards = requireCards(event, "cards");
            if (event.resolved_side === "self") {
                state.self_pending = [];
                appendUnique(
                    state,
                    "self_revealed",
                    cards,
                    emitted
                );
                for (const revealed of cards) {
                    const matches = state.self_hand
                        .map((card, index) => ({
                            card,
                            index
                        }))
                        .filter(item => (
                            item.card.card_key ===
                                revealed.card_key
                        ));
                    const fallback = state.self_hand
                        .map((card, index) => ({
                            card,
                            index
                        }))
                        .filter(item => (
                            item.card.card_id ===
                                revealed.card_id
                        ));
                    const selected = matches.length === 1
                        ? matches[0]
                        : (
                            fallback.length === 1
                                ? fallback[0]
                                : null
                        );
                    if (selected) {
                        state.self_hand.splice(
                            selected.index,
                            1
                        );
                    }
                }
            } else if (
                event.resolved_side === "opponent" &&
                event.visibility === "opponent_revealed"
            ) {
                appendUnique(
                    state,
                    "opponent_revealed",
                    cards,
                    emitted
                );
            } else {
                throw new DomainProjectionError(
                    "RESTRICTED_VISIBILITY",
                    "Opponent cards were not publicly revealed"
                );
            }
        } else if (
            event.event_type === "battle.phase_ended"
        ) {
            if (
                event.payload.phase !== null &&
                typeof event.payload.phase !== "string"
            ) {
                throw new DomainProjectionError(
                    "INVALID_DOMAIN_PAYLOAD",
                    "Phase payload is invalid"
                );
            }
            state.phase = event.payload.phase;
        } else if (
            event.event_type === "battle.turn_ended"
        ) {
            const turn = event.payload.turn;
            if (turn === null) {
                state.turn += 1;
            } else if (
                Number.isInteger(turn) &&
                turn >= 0
            ) {
                state.turn = turn;
            } else {
                throw new DomainProjectionError(
                    "INVALID_DOMAIN_PAYLOAD",
                    "Turn payload is invalid"
                );
            }
        } else if (
            event.event_type === "battle.finished"
        ) {
            if (
                !["win", "lose", "draw"].includes(
                    event.payload.outcome
                )
            ) {
                throw new DomainProjectionError(
                    "INVALID_DOMAIN_PAYLOAD",
                    "Battle outcome is invalid"
                );
            }
            state.battle_status = "finished";
            state.battle_finished_at =
                event.occurred_at;
            state.outcome = event.payload.outcome;
            state.self_pending = [];
        }

        state.diagnostics.push(...emitted);
        return {
            nextState: state,
            diagnostics: emitted
        };
    }

    global.TrackerDomainReducer = Object.freeze({
        PROJECTION_SCHEMA_VERSION,
        DOMAIN_EVENT_SCHEMA_VERSION,
        DomainProjectionError,
        createInitialProjection,
        validateDurableDomainEvent,
        reduceDomainEvent
    });
})(
    typeof window !== "undefined"
        ? window
        : globalThis
);
