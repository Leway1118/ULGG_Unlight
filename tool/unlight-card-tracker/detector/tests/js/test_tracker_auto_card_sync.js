"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");


function loadScript(name, sandbox) {
    vm.runInContext(
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
        sandbox,
        { filename: name }
    );
}


function card(
    topKind,
    topValue,
    bottomKind,
    bottomValue,
    cardKey = "slot:0"
) {
    return {
        card_key: cardKey,
        display_top: {
            kind: topKind,
            value: topValue
        },
        display_bottom: {
            kind: bottomKind,
            value: bottomValue
        }
    };
}


function durableCardsDealt(
    cards,
    {
        sessionId = "session-1",
        eventId =
            "ws:2:0:hand.cards_dealt",
        sequence = 2
    } = {}
) {
    return {
        event_id: eventId,
        session_id: sessionId,
        sequence,
        payload: {
            domain_event: {
                event_type: "hand.cards_dealt",
                payload: { cards }
            }
        }
    };
}


function projection(
    sessionId = "session-1",
    sequence = 2,
    stageCode = "000"
) {
    return {
        session_id: sessionId,
        last_sequence: sequence,
        battle_status: "active",
        battle_started_at:
            "2026-07-29T00:00:00.000Z",
        stage_code: stageCode,
        stage_source: "db_quest_land_now",
        stage_source_sequence: 1
    };
}


function loadAdapter() {
    const sandbox = {
        console
    };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    loadScript(
        "tracker-stage-sync.js",
        sandbox
    );
    loadScript(
        "tracker-auto-card-sync.js",
        sandbox
    );
    return sandbox.TrackerAutoCardSync;
}


function trackerSandbox(savedState = null) {
    const listeners = new Map();
    const elements = new Map();
    function element(id) {
        if (!elements.has(id)) {
            elements.set(id, {
                id,
                value: "",
                innerHTML: "",
                textContent: "",
                disabled: false,
                style: {},
                dataset: {},
                addEventListener(type, callback) {
                    listeners.set(
                        `${id}:${type}`,
                        callback
                    );
                },
                querySelectorAll() {
                    return [];
                },
                appendChild() {},
                remove() {},
                click() {
                    return listeners.get(
                        `${id}:click`
                    )?.();
                },
                change() {
                    return listeners.get(
                        `${id}:change`
                    )?.();
                }
            });
        }
        return elements.get(id);
    }

    const storage = new Map();
    let storageWriteCount = 0;
    if (savedState !== null) {
        storage.set(
            "ul_public_deck_tracker_v3",
            savedState
        );
    }
    const document = {
        readyState: "complete",
        getElementById: element,
        querySelectorAll() {
            return [];
        },
        addEventListener(type, callback) {
            listeners.set(
                `document:${type}`,
                callback
            );
        },
        createElement(tag) {
            return element(
                `created-${tag}-${elements.size}`
            );
        },
        body: element("body")
    };
    const sandbox = {
        console,
        document,
        navigator: {
            userAgent: "node-test"
        },
        localStorage: {
            getItem(key) {
                return storage.has(key)
                    ? storage.get(key)
                    : null;
            },
            setItem(key, value) {
                storageWriteCount += 1;
                storage.set(key, String(value));
            },
            removeItem(key) {
                storage.delete(key);
            }
        },
        structuredClone,
        setTimeout,
        clearTimeout,
        confirm: () => true,
        alert() {}
    };
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    loadScript("field-decks.js", sandbox);
    loadScript(
        "tracker-stage-sync.js",
        sandbox
    );
    loadScript("tracker.js", sandbox);
    loadScript(
        "tracker-auto-card-sync.js",
        sandbox
    );
    return {
        sandbox,
        storage,
        getStorageWriteCount() {
            return storageWriteCount;
        },
        run(code) {
            return vm.runInContext(
                code,
                sandbox
            );
        }
    };
}


test("hand.cards_dealt maps and builds a deduction plan", () => {
    const adapter = loadAdapter();
    const event = durableCardsDealt([
        card(
            "sword",
            2,
            "shield",
            2
        )
    ]);
    const plan = adapter.createDeductionPlan({
        sessionId: "session-1",
        event,
        projection: projection(),
        currentField: "test-field",
        fieldDeck: {
            "劍2防2": 2
        },
        remaining: {
            "劍2防2": 2
        }
    });

    assert.equal(plan.status, "ready");
    assert.deepEqual(
        { ...plan.deductions },
        { "劍2防2": 1 }
    );
});


test("shield maps to 防", () => {
    const adapter = loadAdapter();
    assert.equal(
        adapter.faceToTrackerToken({
            kind: "shield",
            value: 3
        }),
        "防3"
    );
});


test("reverse card order uses the unique field-deck key", () => {
    const adapter = loadAdapter();
    const resolved =
        adapter.resolveCanonicalCardKey(
            card(
                "shield",
                2,
                "sword",
                2
            ),
            {
                "劍2防2": 1
            }
        );

    assert.equal(
        resolved.status,
        "unique_match"
    );
    assert.equal(
        resolved.canonical_card_key,
        "劍2防2"
    );
});


test("ambiguous card order fails closed", () => {
    const adapter = loadAdapter();
    const resolved =
        adapter.resolveCanonicalCardKey(
            card(
                "sword",
                2,
                "shield",
                2
            ),
            {
                "劍2防2": 1,
                "防2劍2": 1
            }
        );

    assert.equal(
        resolved.status,
        "ambiguous_match"
    );
});


test("missing field-deck card fails closed", () => {
    const adapter = loadAdapter();
    const resolved =
        adapter.resolveCanonicalCardKey(
            card(
                "sword",
                2,
                "shield",
                2
            ),
            {
                "移1槍1": 1
            }
        );

    assert.equal(resolved.status, "no_match");
});


test("non-deal hand events are ignored", () => {
    const adapter = loadAdapter();
    for (
        const eventType
        of [
            "hand.event_card_received",
            "hand.card_drawn"
        ]
    ) {
        const event = durableCardsDealt([
            card(
                "sword",
                2,
                "shield",
                2
            )
        ]);
        event.payload.domain_event.event_type =
            eventType;
        const plan = adapter.createDeductionPlan({
            sessionId: "session-1",
            event,
            projection: projection(),
            currentField: "test-field",
            fieldDeck: {
                "劍2防2": 2
            },
            remaining: {
                "劍2防2": 2
            }
        });
        assert.equal(
            plan.status,
            "ignored_event"
        );
    }
});


test("duplicate committed event does not deduct twice", () => {
    const { sandbox, run } = trackerSandbox();
    const event = durableCardsDealt([
        card(
            "sword",
            2,
            "shield",
            2
        )
    ]);
    const detail = {
        sessionId: "session-1",
        events: [event],
        projection: projection()
    };
    const before = run(
        'state.remaining["劍2防2"]'
    );

    sandbox.TrackerAutoCardSync
        .handleCommittedBatch(detail);
    sandbox.TrackerAutoCardSync
        .handleCommittedBatch(detail);

    assert.equal(
        run('state.remaining["劍2防2"]'),
        before - 1
    );
    assert.equal(
        run('state.myHand["劍2防2"]'),
        1
    );
    assert.equal(
        run('state.autoReserved["劍2防2"]'),
        0
    );
    assert.equal(
        run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        1
    );
});


test("dealt batch saves, renders, and records history once", () => {
    const {
        sandbox,
        getStorageWriteCount,
        run
    } = trackerSandbox();
    const writesBefore =
        getStorageWriteCount();
    run(`
        globalThis.__autoSyncRenderCount = 0;
        globalThis.__originalRender = render;
        render = function () {
            globalThis.__autoSyncRenderCount += 1;
            return globalThis.__originalRender();
        };
    `);

    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        2,
                        "shield",
                        2
                    )
                ])
            ],
            projection: projection()
        });

    assert.equal(
        getStorageWriteCount() - writesBefore,
        1
    );
    assert.equal(
        run("globalThis.__autoSyncRenderCount"),
        1
    );
    assert.equal(
        run("state.history.length"),
        1
    );
    assert.equal(
        run("state.history[0].action"),
        "auto_cards_dealt"
    );
    assert.deepEqual(
        JSON.parse(
            run(
                "JSON.stringify(" +
                "getHistoryDescription(" +
                "state.history[0]))"
            )
        ),
        {
            title: "自動加入我方手牌",
            amount: "→手牌 ×1"
        }
    );
});


test("persisted ledger prevents replay after reload", () => {
    const first = trackerSandbox();
    const event = durableCardsDealt([
        card(
            "sword",
            2,
            "shield",
            2
        )
    ]);
    const detail = {
        sessionId: "session-1",
        events: [event],
        projection: projection()
    };
    first.sandbox.TrackerAutoCardSync
        .handleCommittedBatch(detail);
    const saved = first.storage.get(
        "ul_public_deck_tracker_v3"
    );
    const second = trackerSandbox(saved);
    const before = second.run(
        'state.remaining["劍2防2"]'
    );
    const handBefore = second.run(
        'state.myHand["劍2防2"]'
    );

    second.sandbox.TrackerAutoCardSync
        .handleCommittedBatch(detail);

    assert.equal(
        second.run(
            'state.remaining["劍2防2"]'
        ),
        before
    );
    assert.equal(
        second.run(
            'state.myHand["劍2防2"]'
        ),
        handBefore
    );
});


test("playing an auto-added hand card does not restore remaining", () => {
    const { sandbox, run } = trackerSandbox();
    const event = durableCardsDealt([
        card(
            "sword",
            2,
            "shield",
            2
        )
    ]);
    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [event],
            projection: projection()
        });
    const afterAuto = run(
        'state.remaining["劍2防2"]'
    );

    run('playCardFromMyHand("劍2防2")');

    assert.equal(
        run('state.remaining["劍2防2"]'),
        afterAuto
    );
    assert.equal(
        run('state.myHand["劍2防2"]'),
        0
    );
    assert.equal(
        run('state.autoReserved["劍2防2"]'),
        0
    );

    run("undoLastAction()");

    assert.equal(
        run('state.remaining["劍2防2"]'),
        afterAuto
    );
    assert.equal(
        run('state.myHand["劍2防2"]'),
        1
    );
    assert.equal(
        run('state.autoReserved["劍2防2"]'),
        0
    );
});


test("shuffle keeps auto-added hand cards out of remaining", () => {
    const { sandbox, run } = trackerSandbox();
    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        2,
                        "shield",
                        2
                    )
                ])
            ],
            projection: projection()
        });

    run("shuffleEnemyCandidates()");

    assert.equal(
        run(
            'state.remaining["劍2防2"] +' +
            ' state.myHand["劍2防2"] +' +
            ' state.autoReserved["劍2防2"] +' +
            ' state.enemyCandidates["劍2防2"]'
        ),
        run('state.initial["劍2防2"]')
    );
    assert.equal(
        run('state.autoReserved["劍2防2"]'),
        0
    );
    assert.equal(
        run('state.myHand["劍2防2"]'),
        1
    );
});


test("undo restores an automatic deduction without allowing replay", () => {
    const { sandbox, run } = trackerSandbox();
    const event = durableCardsDealt([
        card(
            "sword",
            2,
            "shield",
            2
        )
    ]);
    const detail = {
        sessionId: "session-1",
        events: [event],
        projection: projection()
    };
    const before = run(
        'state.remaining["劍2防2"]'
    );
    sandbox.TrackerAutoCardSync
        .handleCommittedBatch(detail);

    run("undoLastAction()");

    assert.equal(
        run('state.remaining["劍2防2"]'),
        before
    );
    assert.equal(
        run('state.myHand["劍2防2"]'),
        0
    );
    assert.equal(
        run(
            "Object.values(" +
            "state.autoSync.processed_events" +
            ")[0].status"
        ),
        "reverted"
    );

    sandbox.TrackerAutoCardSync
        .handleCommittedBatch(detail);
    assert.equal(
        run('state.remaining["劍2防2"]'),
        before
    );
});


test("a second dealt batch in the same battle accumulates in my hand", () => {
    const { sandbox, run } = trackerSandbox();
    const before = run(
        'state.remaining["劍2防2"]'
    );
    const first = durableCardsDealt(
        [
            card(
                "sword",
                2,
                "shield",
                2
            )
        ],
        {
            eventId:
                "ws:2:0:hand.cards_dealt",
            sequence: 2
        }
    );
    const second = durableCardsDealt(
        [
            card(
                "sword",
                2,
                "shield",
                2
            )
        ],
        {
            eventId:
                "ws:20:0:hand.cards_dealt",
            sequence: 20
        }
    );

    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [first],
            projection: projection(
                "session-1",
                2
            )
        });
    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [second],
            projection: projection(
                "session-1",
                20
            )
        });

    assert.equal(
        run('state.remaining["劍2防2"]'),
        before - 2
    );
    assert.equal(
        run('state.myHand["劍2防2"]'),
        2
    );
    assert.equal(
        run('state.autoReserved["劍2防2"]'),
        0
    );
    assert.equal(
        run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        2
    );
});


test("duplicate canonical cards retain their batch count", () => {
    const { sandbox, run } = trackerSandbox();
    const before = run(
        'state.remaining["劍2防2"]'
    );
    const event = durableCardsDealt([
        card(
            "sword",
            2,
            "shield",
            2,
            "slot:0"
        ),
        card(
            "shield",
            2,
            "sword",
            2,
            "slot:1"
        )
    ]);

    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [event],
            projection: projection()
        });

    assert.equal(
        run('state.remaining["劍2防2"]'),
        before - 2
    );
    assert.equal(
        run('state.myHand["劍2防2"]'),
        2
    );
});


test("legacy autoReserved remains consumable without duplicate ownership", () => {
    const first = trackerSandbox();
    const before = first.run(
        'state.remaining["劍2防2"]'
    );
    first.run(`
        state.remaining["劍2防2"] -= 1;
        state.autoReserved["劍2防2"] = 1;
        saveState();
    `);
    const saved = first.storage.get(
        "ul_public_deck_tracker_v3"
    );
    const second = trackerSandbox(saved);

    assert.equal(
        second.run(
            'state.remaining["劍2防2"]'
        ),
        before - 1
    );
    assert.equal(
        second.run(
            'state.myHand["劍2防2"]'
        ),
        0
    );
    assert.equal(
        second.run(
            'state.autoReserved["劍2防2"]'
        ),
        1
    );

    second.run(
        'addCardToMyHand("劍2防2")'
    );

    assert.equal(
        second.run(
            'state.remaining["劍2防2"]'
        ),
        before - 1
    );
    assert.equal(
        second.run(
            'state.myHand["劍2防2"]'
        ),
        1
    );
    assert.equal(
        second.run(
            'state.autoReserved["劍2防2"]'
        ),
        0
    );
});


test("insufficient remaining rejects the whole batch", () => {
    const adapter = loadAdapter();
    const event = durableCardsDealt([
        card(
            "sword",
            2,
            "shield",
            2,
            "slot:0"
        ),
        card(
            "sword",
            2,
            "shield",
            2,
            "slot:1"
        )
    ]);
    const remaining = {
        "劍2防2": 1
    };
    const plan = adapter.createDeductionPlan({
        sessionId: "session-1",
        event,
        projection: projection(),
        currentField: "test-field",
        fieldDeck: {
            "劍2防2": 2
        },
        remaining
    });

    assert.equal(
        plan.status,
        "insufficient_remaining"
    );
    assert.deepEqual(
        remaining,
        { "劍2防2": 1 }
    );
});


test("no-match batch does not mutate tracker ownership", () => {
    const { sandbox, run } = trackerSandbox();
    const remainingBefore = run(
        "JSON.stringify(state.remaining)"
    );
    const handBefore = run(
        "JSON.stringify(state.myHand)"
    );
    const results = sandbox
        .TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        99,
                        "shield",
                        99
                    )
                ])
            ],
            projection: projection()
        });

    assert.equal(
        results[0].status,
        "no_match"
    );
    assert.equal(
        run("JSON.stringify(state.remaining)"),
        remainingBefore
    );
    assert.equal(
        run("JSON.stringify(state.myHand)"),
        handBefore
    );
    assert.equal(
        run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        0
    );
});


test("insufficient batch does not partially add ownership", () => {
    const { sandbox, run } = trackerSandbox();
    const available = run(
        'state.remaining["劍2防2"]'
    );
    const cards = Array.from(
        { length: available + 1 },
        (_, index) => card(
            "sword",
            2,
            "shield",
            2,
            `slot:${index}`
        )
    );
    const remainingBefore = run(
        "JSON.stringify(state.remaining)"
    );
    const handBefore = run(
        "JSON.stringify(state.myHand)"
    );
    const results = sandbox
        .TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(cards)
            ],
            projection: projection()
        });

    assert.equal(
        results[0].status,
        "insufficient_remaining"
    );
    assert.equal(
        run("JSON.stringify(state.remaining)"),
        remainingBefore
    );
    assert.equal(
        run("JSON.stringify(state.myHand)"),
        handBefore
    );
    assert.equal(
        run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        0
    );
});


test("a new session cannot deduct the active battle twice", () => {
    const { sandbox, run } = trackerSandbox();
    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        2,
                        "shield",
                        2
                    )
                ])
            ],
            projection: projection("session-1")
        });
    const afterFirst = run(
        'state.remaining["劍2防2"]'
    );
    const secondEvent = durableCardsDealt(
        [
            card(
                "sword",
                2,
                "shield",
                2
            )
        ],
        {
            sessionId: "session-2",
            eventId:
                "ws:2:0:hand.cards_dealt"
        }
    );

    const results = sandbox
        .TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-2",
            events: [secondEvent],
            projection: projection("session-2")
        });

    assert.equal(
        results[0].status,
        "session_transition_blocked"
    );
    assert.equal(
        run('state.remaining["劍2防2"]'),
        afterFirst
    );
});


test("a new battle requires an explicit tracker reset", () => {
    const { sandbox, run } = trackerSandbox();
    const firstEvent = durableCardsDealt([
        card(
            "sword",
            2,
            "shield",
            2
        )
    ]);
    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [firstEvent],
            projection: projection()
        });
    const afterFirst = run(
        'state.remaining["劍2防2"]'
    );
    const secondEvent = durableCardsDealt(
        [
            card(
                "sword",
                2,
                "shield",
                2
            )
        ],
        {
            eventId:
                "ws:20:0:hand.cards_dealt",
            sequence: 20
        }
    );
    const nextBattle = projection(
        "session-1",
        20
    );
    nextBattle.battle_started_at =
        "2026-07-29T01:00:00.000Z";

    const results = sandbox
        .TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [secondEvent],
            projection: nextBattle
        });

    assert.equal(
        results[0].status,
        "battle_transition_blocked"
    );
    assert.equal(
        run('state.remaining["劍2防2"]'),
        afterFirst
    );
});


function iceCards() {
    return [
        card("move", 1, "gun", 1, "ice:0"),
        card("move", 1, "gun", 1, "ice:1"),
        card("move", 1, "gun", 1, "ice:2"),
        card("move", 1, "gun", 1, "ice:3"),
        card("move", 1, "shield", 1, "ice:4")
    ];
}


test("schema 1 migrates to schema 2 without losing processed events", () => {
    const first = trackerSandbox();
    first.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        2,
                        "shield",
                        2
                    )
                ])
            ],
            projection: projection()
        });
    const saved = JSON.parse(
        first.storage.get(
            "ul_public_deck_tracker_v3"
        )
    );
    saved.autoSync.schema_version = 1;
    delete saved.autoSync.pending_stage_events;
    delete saved.autoSync.stage_status;

    const second = trackerSandbox(
        JSON.stringify(saved)
    );

    assert.equal(
        second.run("state.autoSync.schema_version"),
        2
    );
    assert.equal(
        second.run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        1
    );
    assert.equal(
        second.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        0
    );
});


test("stage 003 rebases pristine tracker and applies five cards", () => {
    const { sandbox, run } = trackerSandbox();
    const event = durableCardsDealt(iceCards());

    const results = sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [event],
            projection: projection(
                "session-1",
                2,
                "003"
            )
        });

    assert.equal(results[0].status, "applied");
    assert.equal(run("state.field"), "冰封湖畔");
    assert.equal(
        run("getTotal(state.initial)"),
        53
    );
    assert.equal(
        run("getTotal(state.remaining)"),
        48
    );
    assert.equal(run("getTotal(state.myHand)"), 5);
});


test("stage 002 rebases pristine tracker to 垃圾之街", () => {
    const { sandbox, run } = trackerSandbox();
    const event = durableCardsDealt([
        card("move", 1, "gun", 1)
    ]);

    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [event],
            projection: projection(
                "session-1",
                2,
                "002"
            )
        });

    assert.equal(run("state.field"), "垃圾之街");
});


test("hand before stage persists pending then applies once", () => {
    const first = trackerSandbox();
    const event = durableCardsDealt(iceCards());
    const missingStage = projection();
    missingStage.stage_code = null;
    missingStage.stage_source = null;
    missingStage.stage_source_sequence = null;

    const pendingResult =
        first.sandbox.TrackerAutoCardSync
            .handleCommittedBatch({
                sessionId: "session-1",
                events: [event],
                projection: missingStage
            });

    assert.equal(
        pendingResult[0].status,
        "pending_stage"
    );
    assert.equal(
        first.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        1
    );
    assert.equal(
        first.run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        0
    );
    assert.equal(
        first.run(
            "state.autoSync.active_session_id"
        ),
        "session-1"
    );
    assert.equal(
        first.run(
            "state.autoSync." +
            "active_battle_started_at"
        ),
        missingStage.battle_started_at
    );
    const saved = first.storage.get(
        "ul_public_deck_tracker_v3"
    );
    const reloaded = trackerSandbox(saved);
    assert.equal(
        reloaded.run(
            "state.autoSync.active_session_id"
        ),
        "session-1"
    );
    assert.equal(
        reloaded.run(
            "state.autoSync." +
            "active_battle_started_at"
        ),
        missingStage.battle_started_at
    );
    const applied =
        reloaded.sandbox.TrackerAutoCardSync
            .handleCommittedBatch({
                sessionId: "session-1",
                events: [],
                projection: projection(
                    "session-1",
                    3,
                    "003"
                )
            });

    assert.equal(applied[0].status, "applied");
    assert.equal(
        reloaded.run("getTotal(state.myHand)"),
        5
    );
    assert.equal(
        reloaded.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        0
    );
    assert.equal(
        reloaded.run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        1
    );
});


test("duplicate pending callback is deduplicated", () => {
    const { sandbox, run } = trackerSandbox();
    const event = durableCardsDealt(iceCards());
    const missingStage = projection();
    missingStage.stage_code = null;

    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [event],
            projection: missingStage
        });
    const duplicate =
        sandbox.TrackerAutoCardSync
            .handleCommittedBatch({
                sessionId: "session-1",
                events: [event],
                projection: missingStage
            });

    assert.equal(
        duplicate[0].duplicates[0].status,
        "duplicate_pending"
    );
    assert.equal(
        run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        1
    );
});


test("multiple pending events apply atomically", () => {
    const { sandbox, run } = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    const firstEvent = durableCardsDealt([
        card("move", 1, "gun", 1)
    ]);
    const secondEvent = durableCardsDealt(
        [card("move", 1, "shield", 1)],
        {
            eventId:
                "ws:3:0:hand.cards_dealt",
            sequence: 3
        }
    );
    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [firstEvent, secondEvent],
            projection: {
                ...missingStage,
                last_sequence: 3
            }
        });

    const result = sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [],
            projection: projection(
                "session-1",
                4,
                "003"
            )
        });

    assert.equal(result[0].status, "applied");
    assert.equal(result[0].applied_events, 2);
    assert.equal(run("getTotal(state.myHand)"), 2);
    assert.equal(
        run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        2
    );
    assert.equal(
        run("state.history[0].syncKeys.length"),
        2
    );
});


test("current-field confirmation applies pending atomically", () => {
    const fixture = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    const beforeRemaining = fixture.run(
        "getTotal(state.remaining)"
    );
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        2,
                        "shield",
                        2
                    )
                ])
            ],
            projection: missingStage
        });

    assert.equal(
        fixture.run(
            "document.getElementById(" +
            "'confirm-pending-field').disabled"
        ),
        false
    );
    const writesBefore =
        fixture.getStorageWriteCount();
    fixture.run(`
        globalThis.__pendingRenderCount = 0;
        globalThis.__pendingOriginalRender = render;
        render = function () {
            globalThis.__pendingRenderCount += 1;
            return globalThis.__pendingOriginalRender();
        };
    `);
    const result = fixture.run(
        "document.getElementById(" +
        "'confirm-pending-field').click()"
    );

    assert.equal(result.status, "applied");
    assert.equal(
        fixture.run("getTotal(state.remaining)"),
        beforeRemaining - 1
    );
    assert.equal(
        fixture.run("getTotal(state.myHand)"),
        1
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        0
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        1
    );
    assert.equal(
        fixture.run(
            "document.getElementById(" +
            "'confirm-pending-field').disabled"
        ),
        true
    );
    assert.equal(
        fixture.getStorageWriteCount() -
            writesBefore,
        1
    );
    assert.equal(
        fixture.run(
            "globalThis.__pendingRenderCount"
        ),
        1
    );
});


test("current-field confirmation keeps incompatible pending", () => {
    const fixture = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        99,
                        "shield",
                        99
                    )
                ])
            ],
            projection: missingStage
        });
    const before = fixture.run(
        "JSON.stringify({" +
        "remaining: state.remaining," +
        "myHand: state.myHand})"
    );

    const result = fixture.sandbox
        .TrackerAutoSyncTarget
        .confirmCurrentField();

    assert.equal(result.status, "no_match");
    assert.equal(
        fixture.run(
            "JSON.stringify({" +
            "remaining: state.remaining," +
            "myHand: state.myHand})"
        ),
        before
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        1
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        0
    );
});


test("different field selection keeps incompatible pending and state", () => {
    const fixture = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        99,
                        "shield",
                        99
                    )
                ])
            ],
            projection: missingStage
        });
    const before = fixture.run(
        "JSON.stringify({" +
        "field: state.field," +
        "remaining: state.remaining," +
        "myHand: state.myHand})"
    );
    const writesBefore =
        fixture.getStorageWriteCount();

    fixture.run(`
        document.getElementById("field").value =
            "冰封湖畔";
        document.getElementById("field").change();
    `);

    assert.equal(
        fixture.run("pendingFieldSelection"),
        "冰封湖畔"
    );
    assert.equal(
        fixture.getStorageWriteCount(),
        writesBefore
    );
    const result = fixture.sandbox
        .TrackerAutoSyncTarget
        .confirmCurrentField();

    assert.equal(result.status, "no_match");
    assert.equal(
        fixture.run(
            "JSON.stringify({" +
            "field: state.field," +
            "remaining: state.remaining," +
            "myHand: state.myHand})"
        ),
        before
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        1
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        0
    );
    assert.equal(
        fixture.run(
            "document.getElementById('field').value"
        ),
        "冰封湖畔"
    );
    assert.equal(
        fixture.run(
            "document.getElementById(" +
            "'confirm-pending-field').disabled"
        ),
        false
    );
});


test("second and third pending events retain one battle identity", () => {
    const fixture = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    for (const sequence of [2, 3, 4]) {
        fixture.sandbox.TrackerAutoCardSync
            .handleCommittedBatch({
                sessionId: "session-1",
                events: [
                    durableCardsDealt(
                        [
                            card(
                                "sword",
                                2,
                                "shield",
                                2,
                                `slot:${sequence}`
                            )
                        ],
                        {
                            eventId:
                                `ws:${sequence}:0:` +
                                "hand.cards_dealt",
                            sequence
                        }
                    )
                ],
                projection: {
                    ...missingStage,
                    last_sequence: sequence
                }
            });
    }

    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        3
    );
    assert.equal(
        fixture.run(
            "Object.values(" +
            "state.autoSync.pending_stage_events" +
            ").every(record => " +
            "record.session_id === 'session-1' && " +
            "record.battle_started_at === " +
            "'2026-07-29T00:00:00.000Z')"
        ),
        true
    );
    assert.equal(
        fixture.run(
            "state.autoSync.active_session_id"
        ),
        "session-1"
    );
});


test("reload derives missing ownership from one pending identity", () => {
    const first = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    first.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        2,
                        "shield",
                        2
                    )
                ])
            ],
            projection: missingStage
        });
    const saved = JSON.parse(
        first.storage.get(
            "ul_public_deck_tracker_v3"
        )
    );
    saved.autoSync.active_session_id = null;
    saved.autoSync.active_battle_started_at =
        null;

    const reloaded = trackerSandbox(
        JSON.stringify(saved)
    );

    assert.equal(
        reloaded.run(
            "state.autoSync.active_session_id"
        ),
        "session-1"
    );
    assert.equal(
        reloaded.run(
            "state.autoSync." +
            "active_battle_started_at"
        ),
        missingStage.battle_started_at
    );
    assert.equal(
        reloaded.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        1
    );
    assert.equal(
        reloaded.run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        0
    );
});


test("one invalid pending event keeps the entire batch pending", () => {
    const { sandbox, run } = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    const valid = durableCardsDealt([
        card("move", 1, "gun", 1)
    ]);
    const invalid = durableCardsDealt(
        [card("sword", 99, "shield", 99)],
        {
            eventId:
                "ws:3:0:hand.cards_dealt",
            sequence: 3
        }
    );
    sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [valid, invalid],
            projection: {
                ...missingStage,
                last_sequence: 3
            }
        });
    const beforeRemaining = run(
        "JSON.stringify(state.remaining)"
    );

    const result = sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [],
            projection: projection(
                "session-1",
                4,
                "003"
            )
        });

    assert.equal(result[0].status, "no_match");
    assert.equal(
        run("JSON.stringify(state.remaining)"),
        beforeRemaining
    );
    assert.equal(run("getTotal(state.myHand)"), 0);
    assert.equal(
        run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        2
    );
    assert.equal(
        run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        0
    );
});


test("unsupported, missing-deck, and random stages stay pending", () => {
    for (
        const [
            stageCode,
            expectedStatus
        ] of [
            ["010", "field_deck_missing"],
            ["011", "random"],
            ["999", "unsupported"]
        ]
    ) {
        const { sandbox, run } = trackerSandbox();
        const result =
            sandbox.TrackerAutoCardSync
                .handleCommittedBatch({
                    sessionId: "session-1",
                    events: [
                        durableCardsDealt(
                            iceCards()
                        )
                    ],
                    projection: projection(
                        "session-1",
                        2,
                        stageCode
                    )
                });

        assert.equal(
            result[0].status,
            "pending_stage"
        );
        assert.equal(
            run(
                "state.autoSync.stage_status.status"
            ),
            expectedStatus
        );
        assert.equal(
            run(
                "Object.keys(" +
                "state.autoSync.pending_stage_events" +
                ").length"
            ),
            1
        );
        assert.equal(
            run("getTotal(state.myHand)"),
            0
        );
    }
});


test("non-pristine stage conflict waits for explicit confirmation", () => {
    const { sandbox, run } = trackerSandbox();
    run('revealCard("移1槍1")');
    const before = run(
        "JSON.stringify(state.remaining)"
    );

    const result = sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(iceCards())
            ],
            projection: projection(
                "session-1",
                2,
                "003"
            )
        });

    assert.equal(
        result[0].status,
        "stage_conflict"
    );
    assert.equal(
        run("JSON.stringify(state.remaining)"),
        before
    );
    assert.equal(run("state.field"), "萊丁貝魯格城堡");
});


test("manual field confirmation cancellation leaves conflict unchanged", () => {
    const fixture = trackerSandbox();
    fixture.run('revealCard("移1槍1")');
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(iceCards())
            ],
            projection: projection(
                "session-1",
                2,
                "003"
            )
        });
    const before = fixture.run(
        "JSON.stringify(state)"
    );
    const selection = fixture.sandbox
        .TrackerAutoSyncTarget
        .selectField("冰封湖畔");

    assert.equal(
        selection.status,
        "pending_field_selected"
    );
    assert.equal(
        fixture.run("JSON.stringify(state)"),
        before
    );
    fixture.sandbox.confirm = () => false;
    const result = fixture.sandbox
        .TrackerAutoSyncTarget
        .confirmCurrentField();
    assert.equal(result.status, "cancelled");
    assert.equal(
        fixture.run("JSON.stringify(state)"),
        before
    );
});


test("manual confirmation rebases and applies retained pending", () => {
    const fixture = trackerSandbox();
    fixture.run('revealCard("移1槍1")');
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(iceCards())
            ],
            projection: projection(
                "session-1",
                2,
                "003"
            )
        });

    const before = fixture.run(
        "JSON.stringify(state)"
    );
    const selection = fixture.sandbox
        .TrackerAutoSyncTarget
        .selectField("冰封湖畔");

    assert.equal(
        selection.status,
        "pending_field_selected"
    );
    assert.equal(
        fixture.run("JSON.stringify(state)"),
        before
    );
    fixture.sandbox.confirm = () => true;
    const result = fixture.sandbox
        .TrackerAutoSyncTarget
        .confirmCurrentField();
    assert.equal(result.status, "applied");
    assert.equal(
        fixture.run("state.field"),
        "冰封湖畔"
    );
    assert.equal(
        fixture.run("getTotal(state.myHand)"),
        5
    );
});


test("explicit confirmation rebases a new session and applies all pending", () => {
    const fixture = trackerSandbox();
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "move",
                        1,
                        "gun",
                        1
                    )
                ])
            ],
            projection: projection()
        });
    const nextProjection = projection(
        "session-2",
        3,
        null
    );
    nextProjection.battle_started_at =
        "2026-07-29T02:00:00.000Z";
    const firstPending = durableCardsDealt(
        [
            card(
                "sword",
                2,
                "shield",
                2
            )
        ],
        {
            sessionId: "session-2",
            eventId:
                "ws:2:0:hand.cards_dealt",
            sequence: 2
        }
    );
    const secondPending = durableCardsDealt(
        [
            card(
                "move",
                1,
                "shield",
                1
            )
        ],
        {
            sessionId: "session-2",
            eventId:
                "ws:3:0:hand.cards_dealt",
            sequence: 3
        }
    );
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-2",
            events: [
                firstPending,
                secondPending
            ],
            projection: nextProjection
        });

    assert.equal(
        fixture.run(
            "state.autoSync.stage_status.last_error"
        ),
        "session_transition_blocked"
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        2
    );
    fixture.sandbox.TrackerAutoSyncTarget
        .selectField("冰封湖畔");
    fixture.sandbox.confirm = () => true;

    const result = fixture.sandbox
        .TrackerAutoSyncTarget
        .confirmCurrentField();

    assert.equal(result.status, "applied");
    assert.equal(result.applied_events, 2);
    assert.equal(
        fixture.run(
            "state.autoSync.active_session_id"
        ),
        "session-2"
    );
    assert.equal(
        fixture.run(
            "state.autoSync." +
            "active_battle_started_at"
        ),
        nextProjection.battle_started_at
    );
    assert.equal(
        fixture.run("state.field"),
        "冰封湖畔"
    );
    assert.equal(
        fixture.run("getTotal(state.myHand)"),
        2
    );
    assert.equal(
        fixture.run(
            "getTotal(state.remaining)"
        ),
        51
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        0
    );
});


test("explicit confirmation rebases a new session on the same field", () => {
    const fixture = trackerSandbox();
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "move",
                        1,
                        "gun",
                        1
                    )
                ])
            ],
            projection: projection()
        });
    const nextProjection = projection(
        "session-2",
        2,
        null
    );
    nextProjection.battle_started_at =
        "2026-07-29T02:00:00.000Z";
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-2",
            events: [
                durableCardsDealt(
                    [
                        card(
                            "sword",
                            2,
                            "shield",
                            2
                        )
                    ],
                    {
                        sessionId: "session-2"
                    }
                )
            ],
            projection: nextProjection
        });
    fixture.sandbox.confirm = () => true;

    const result = fixture.sandbox
        .TrackerAutoSyncTarget
        .confirmCurrentField();

    assert.equal(result.status, "applied");
    assert.equal(
        fixture.run("state.field"),
        "萊丁貝魯格城堡"
    );
    assert.equal(
        fixture.run("getTotal(state.myHand)"),
        1
    );
    assert.equal(
        fixture.run(
            "getTotal(state.remaining)"
        ),
        54
    );
    assert.equal(
        fixture.run(
            "state.autoSync.active_session_id"
        ),
        "session-2"
    );
});


test("manual stage confirmation remains valid within one battle", () => {
    const fixture = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        2,
                        "shield",
                        2
                    )
                ])
            ],
            projection: missingStage
        });
    fixture.sandbox.TrackerAutoSyncTarget
        .selectField("冰封湖畔");
    fixture.sandbox.TrackerAutoSyncTarget
        .confirmCurrentField();

    assert.equal(
        fixture.run(
            "state.autoSync.stage_status.source"
        ),
        "manual"
    );
    const laterProjection = {
        ...missingStage,
        last_sequence: 7
    };
    const result = fixture.sandbox
        .TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(
                    [
                        card(
                            "move",
                            1,
                            "shield",
                            1
                        )
                    ],
                    {
                        eventId:
                            "ws:7:0:hand.cards_dealt",
                        sequence: 7
                    }
                )
            ],
            projection: laterProjection
        });

    assert.equal(result[0].status, "applied");
    assert.equal(
        fixture.run(
            "state.autoSync.stage_status.status"
        ),
        "ready"
    );
    assert.equal(
        fixture.run(
            "state.autoSync.stage_status." +
            "mapped_field"
        ),
        "冰封湖畔"
    );
    assert.equal(
        fixture.run("getTotal(state.myHand)"),
        2
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        0
    );
});


test("manual stage confirmation never crosses a battle boundary", () => {
    const fixture = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt([
                    card(
                        "sword",
                        2,
                        "shield",
                        2
                    )
                ])
            ],
            projection: missingStage
        });
    fixture.sandbox.TrackerAutoSyncTarget
        .selectField("冰封湖畔");
    fixture.sandbox.TrackerAutoSyncTarget
        .confirmCurrentField();
    const nextBattle = {
        ...missingStage,
        battle_started_at:
            "2026-07-29T03:00:00.000Z",
        last_sequence: 8
    };

    const result = fixture.sandbox
        .TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(
                    [
                        card(
                            "move",
                            1,
                            "shield",
                            1
                        )
                    ],
                    {
                        eventId:
                            "ws:8:0:hand.cards_dealt",
                        sequence: 8
                    }
                )
            ],
            projection: nextBattle
        });

    assert.equal(
        result[0].status,
        "battle_transition_blocked"
    );
    assert.equal(
        fixture.run(
            "state.autoSync.stage_status.status"
        ),
        "conflict"
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        1
    );
});


test("reset and session close clear pending stage events", () => {
    const fixture = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(iceCards())
            ],
            projection: missingStage
        });

    fixture.sandbox.TrackerAutoCardSync
        .handleSessionClosed("session-1");
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        0
    );

    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(iceCards())
            ],
            projection: missingStage
        });
    fixture.run("resetField(state.field, false)");
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        0
    );
    assert.equal(
        fixture.run(
            "state.autoSync.stage_status.status"
        ),
        "missing"
    );
});


test("new battle and new session discard old pending identities", () => {
    for (const transition of ["battle", "session"]) {
        const fixture = trackerSandbox();
        const missingStage = projection();
        missingStage.stage_code = null;
        fixture.sandbox.TrackerAutoCardSync
            .handleCommittedBatch({
                sessionId: "session-1",
                events: [
                    durableCardsDealt(iceCards())
                ],
                projection: missingStage
            });
        const nextProjection = projection(
            transition === "session"
                ? "session-2"
                : "session-1",
            20,
            null
        );
        if (transition === "battle") {
            nextProjection.battle_started_at =
                "2026-07-29T02:00:00.000Z";
        }
        const nextEvent = durableCardsDealt(
            [
                card(
                    "sword",
                    2,
                    "shield",
                    2,
                    "next:0"
                )
            ],
            {
                sessionId:
                    nextProjection.session_id,
                eventId:
                    "ws:20:0:hand.cards_dealt",
                sequence: 20
            }
        );

        fixture.sandbox.TrackerAutoCardSync
            .handleCommittedBatch({
                sessionId:
                    nextProjection.session_id,
                events: [nextEvent],
                projection: nextProjection
            });

        assert.equal(
            fixture.run(
                "Object.values(" +
                "state.autoSync.pending_stage_events" +
                ").some(record => " +
                "record.session_id === 'session-1' && " +
                "record.battle_started_at === " +
                "'2026-07-29T00:00:00.000Z')"
            ),
            false
        );
        assert.equal(
            fixture.run(
                "Object.keys(" +
                "state.autoSync.pending_stage_events" +
                ").length"
            ),
            1
        );
        assert.equal(
            fixture.run(
                "state.autoSync.active_session_id"
            ),
            nextProjection.session_id
        );
        assert.equal(
            fixture.run(
                "state.autoSync." +
                "active_battle_started_at"
            ),
            nextProjection.battle_started_at
        );
    }
});


test("battle end clears retained pending", () => {
    const fixture = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(iceCards())
            ],
            projection: missingStage
        });
    const ended = {
        ...missingStage,
        last_sequence: 10,
        battle_status: "ended"
    };
    const finishEvent = {
        event_id: "ws:10:0:battle.finished",
        session_id: "session-1",
        sequence: 10,
        payload: {
            domain_event: {
                event_type: "battle.finished",
                payload: { outcome: "win" }
            }
        }
    };

    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [finishEvent],
            projection: ended
        });

    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        0
    );
});


test("manual field selection preserves random-stage pending until confirmation", () => {
    const fixture = trackerSandbox();
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [
                durableCardsDealt(iceCards())
            ],
            projection: projection(
                "session-1",
                2,
                "011"
            )
        });
    fixture.sandbox.confirm = () => {
        throw new Error(
            "pristine field selection must not confirm"
        );
    };

    const before = fixture.run(
        "JSON.stringify(state)"
    );
    const selection = fixture.sandbox
        .TrackerAutoSyncTarget
        .selectField("冰封湖畔");

    assert.equal(
        selection.status,
        "pending_field_selected"
    );
    assert.equal(
        fixture.run("JSON.stringify(state)"),
        before
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        1
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.processed_events" +
            ").length"
        ),
        0
    );
    const result = fixture.sandbox
        .TrackerAutoSyncTarget
        .confirmCurrentField();
    assert.equal(result.status, "applied");
    assert.equal(
        fixture.run("getTotal(state.myHand)"),
        5
    );
});


test("grouped pending undo reverts every processed key", () => {
    const fixture = trackerSandbox();
    const missingStage = projection();
    missingStage.stage_code = null;
    const events = [
        durableCardsDealt([
            card("move", 1, "gun", 1)
        ]),
        durableCardsDealt(
            [card("move", 1, "shield", 1)],
            {
                eventId:
                    "ws:3:0:hand.cards_dealt",
                sequence: 3
            }
        )
    ];
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events,
            projection: {
                ...missingStage,
                last_sequence: 3
            }
        });
    fixture.sandbox.TrackerAutoCardSync
        .handleCommittedBatch({
            sessionId: "session-1",
            events: [],
            projection: projection(
                "session-1",
                4,
                "003"
            )
        });

    fixture.run("undoLastAction()");

    assert.equal(
        fixture.run("getTotal(state.myHand)"),
        0
    );
    assert.equal(
        fixture.run(
            "Object.values(" +
            "state.autoSync.processed_events" +
            ").every(record => " +
            "record.status === 'reverted')"
        ),
        true
    );
    assert.equal(
        fixture.run(
            "Object.keys(" +
            "state.autoSync.pending_stage_events" +
            ").length"
        ),
        0
    );
});
