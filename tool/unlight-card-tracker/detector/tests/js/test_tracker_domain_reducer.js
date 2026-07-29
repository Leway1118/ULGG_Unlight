const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");


function loadReducer() {
    const sandbox = {};
    vm.runInNewContext(
        fs.readFileSync(
            path.resolve(
                __dirname,
                "..",
                "..",
                "..",
                "tracker-domain-reducer.js"
            ),
            "utf8"
        ),
        sandbox
    );
    return sandbox.TrackerDomainReducer;
}


function card(cardId, slot = null) {
    const face = { kind: "sword", value: 1 };
    return {
        card_id: cardId,
        slot,
        type: "event",
        rotate: false,
        used: false,
        clicked: false,
        face1: face,
        face2: face,
        display_top: face,
        display_bottom: face,
        display_order_confirmation: "confirmed"
    };
}


function durable(
    sequence,
    eventType,
    payload = {},
    overrides = {}
) {
    const eventId = `ws:${sequence}:0:${eventType}`;
    return {
        event_id: eventId,
        session_id: "session-1",
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
                producer_session_id: "session-1",
                ...overrides
            }
        }
    };
}


function apply(reducer, state, event) {
    return reducer.reduceDomainEvent(
        state,
        event
    ).nextState;
}


test("battle and hand events build a replayable projection", () => {
    const reducer = loadReducer();
    let state = reducer.createInitialProjection(
        "session-1"
    );
    state = apply(
        reducer,
        state,
        durable(1, "battle.started")
    );
    state = apply(
        reducer,
        state,
        durable(
            2,
            "hand.cards_dealt",
            { cards: [card(11, 0), card(12, 1)] },
            {
                visibility: "self_private",
                resolved_side: "self"
            }
        )
    );
    state = apply(
        reducer,
        state,
        durable(
            3,
            "hand.card_drawn",
            { card: card(13, 2) },
            {
                visibility: "self_private",
                resolved_side: "self"
            }
        )
    );
    state = apply(
        reducer,
        state,
        durable(
            4,
            "hand.event_card_received",
            { card: card(21) },
            {
                visibility: "self_private",
                resolved_side: "self"
            }
        )
    );

    assert.equal(state.battle_status, "active");
    assert.deepEqual(
        Array.from(state.self_hand, item => item.card_id),
        [11, 12, 13]
    );
    assert.equal(state.self_event_cards[0].card_id, 21);
});

test("battle.started stage context reaches the durable projection", () => {
    const reducer = loadReducer();
    const initial = reducer.createInitialProjection("session-1");
    const state = apply(
        reducer,
        initial,
        durable(
            1,
            "battle.started",
            {
                stage_code: "003",
                stage_source: "db_quest_land_now",
                stage_source_sequence: 1637
            }
        )
    );
    assert.equal(state.stage_code, "003");
    assert.equal(state.stage_source, "db_quest_land_now");
    assert.equal(state.stage_source_sequence, 1637);
});

test("battle.started without stage remains backward compatible", () => {
    const reducer = loadReducer();
    const state = apply(
        reducer,
        reducer.createInitialProjection("session-1"),
        durable(1, "battle.started")
    );
    assert.equal(state.stage_code, null);
    assert.equal(state.stage_source, null);
    assert.equal(state.stage_source_sequence, null);
});

test("malformed battle stage context fails closed", () => {
    const reducer = loadReducer();
    assert.throws(
        () => apply(
            reducer,
            reducer.createInitialProjection("session-1"),
            durable(
                1,
                "battle.started",
                {
                    stage_code: 3,
                    stage_source: "db_quest_land_now",
                    stage_source_sequence: 1637
                }
            )
        ),
        error => error.code === "INVALID_STAGE_CONTEXT"
    );
});


test("selection, reveal, phase, turn, and finish match v1 semantics", () => {
    const reducer = loadReducer();
    let state = reducer.createInitialProjection(
        "session-1"
    );
    state = apply(
        reducer,
        state,
        durable(1, "battle.started")
    );
    state = apply(
        reducer,
        state,
        durable(
            2,
            "hand.cards_dealt",
            { cards: [card(11, 0)] },
            {
                visibility: "self_private",
                resolved_side: "self"
            }
        )
    );
    state = apply(
        reducer,
        state,
        durable(
            3,
            "play.selection_changed",
            {
                slot: 0,
                selected: true,
                selection: "selected"
            },
            {
                visibility: "self_private",
                confirmation: "pending",
                authority: "provisional"
            }
        )
    );
    assert.deepEqual(Array.from(state.self_pending), [0]);

    state = apply(
        reducer,
        state,
        durable(
            4,
            "play.cards_revealed",
            { cards: [card(11, 0)] }
        )
    );
    state = apply(
        reducer,
        state,
        durable(
            5,
            "play.cards_revealed",
            { cards: [card(99, 5)] },
            {
                visibility: "opponent_revealed",
                resolved_side: "opponent"
            }
        )
    );
    state = apply(
        reducer,
        state,
        durable(
            6,
            "battle.phase_ended",
            { phase: "attack" }
        )
    );
    state = apply(
        reducer,
        state,
        durable(
            7,
            "battle.turn_ended",
            { turn: null }
        )
    );
    state = apply(
        reducer,
        state,
        durable(
            8,
            "battle.finished",
            { outcome: "win" }
        )
    );

    assert.equal(state.self_hand.length, 0);
    assert.equal(state.self_revealed.length, 1);
    assert.equal(state.opponent_revealed.length, 1);
    assert.equal(state.phase, "attack");
    assert.equal(state.turn, 1);
    assert.equal(state.battle_status, "finished");
    assert.equal(state.outcome, "win");
});


test("a second battle start resets battle fields", () => {
    const reducer = loadReducer();
    let state = reducer.createInitialProjection(
        "session-1"
    );
    state = apply(
        reducer,
        state,
        durable(1, "battle.started")
    );
    state = apply(
        reducer,
        state,
        durable(
            2,
            "battle.finished",
            { outcome: "lose" }
        )
    );
    state = apply(
        reducer,
        state,
        durable(3, "battle.started")
    );

    assert.equal(state.battle_status, "active");
    assert.equal(state.outcome, null);
    assert.equal(state.turn, 0);
});


test("unknown event is diagnostic-only", () => {
    const reducer = loadReducer();
    const state = reducer.createInitialProjection(
        "session-1"
    );
    const result = reducer.reduceDomainEvent(
        state,
        durable(1, "future.event")
    );

    assert.equal(result.nextState.battle_status, "idle");
    assert.equal(
        result.diagnostics[0].code,
        "unsupported_event_type"
    );
});


test("rejects wrong session, schema, and hidden payload", () => {
    const reducer = loadReducer();
    const state = reducer.createInitialProjection(
        "session-1"
    );

    const wrongSession = durable(1, "battle.started");
    wrongSession.session_id = "session-2";
    assert.throws(
        () => reducer.reduceDomainEvent(
            state,
            wrongSession
        ),
        { code: "SESSION_MISMATCH" }
    );

    const wrongSchema = durable(1, "battle.started");
    wrongSchema.payload.domain_event
        .domain_event_schema_version = 2;
    assert.throws(
        () => reducer.reduceDomainEvent(
            state,
            wrongSchema
        ),
        { code: "UNSUPPORTED_DOMAIN_SCHEMA" }
    );

    const hidden = durable(1, "battle.started");
    hidden.payload.domain_event.visibility =
        "opponent_hidden";
    assert.throws(
        () => reducer.reduceDomainEvent(state, hidden),
        { code: "RESTRICTED_VISIBILITY" }
    );
});


test("rejects raw websocket fields", () => {
    const reducer = loadReducer();
    const state = reducer.createInitialProjection(
        "session-1"
    );
    const event = durable(1, "battle.started", {
        raw: "secret"
    });

    assert.throws(
        () => reducer.reduceDomainEvent(state, event),
        { code: "FORBIDDEN_DOMAIN_PAYLOAD" }
    );
});
