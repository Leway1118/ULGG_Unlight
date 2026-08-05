"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");


class FakeButton {
    constructor({
        cardName,
        className,
        title,
        ariaLabel,
        html
    }) {
        this.dataset = { card: cardName };
        this.className = className;
        this.title = title;
        this.ariaLabel = ariaLabel;
        this.html = html;
        this.listeners = new Map();
    }

    addEventListener(type, callback) {
        this.listeners.set(type, callback);
    }

    dispatch(type, overrides = {}) {
        const event = {
            pointerType: "mouse",
            button: 0,
            preventDefault() {},
            ...overrides
        };
        return this.listeners.get(type)?.(event);
    }
}


class FakeElement {
    constructor(id, listeners) {
        this.id = id;
        this.value = "";
        this.textContent = "";
        this.disabled = false;
        this.style = {};
        this.dataset = {};
        this.listeners = listeners;
        this.buttons = [];
        this._innerHTML = "";
    }

    set innerHTML(value) {
        this._innerHTML = String(value);
        this.buttons = [];
        const pattern =
            /<button\s+class="([\s\S]*?)"\s+data-card="([^"]+)"\s+title="([^"]*)"\s+aria-label="([^"]+)"[\s\S]*?<\/button>/g;
        let match;
        while (
            (match = pattern.exec(this._innerHTML))
            !== null
        ) {
            this.buttons.push(
                new FakeButton({
                    className:
                        match[1]
                            .replace(/\s+/g, " ")
                            .trim(),
                    cardName: match[2],
                    title: match[3],
                    ariaLabel: match[4],
                    html: match[0]
                })
            );
        }
    }

    get innerHTML() {
        return this._innerHTML;
    }

    addEventListener(type, callback) {
        this.listeners.set(
            `${this.id}:${type}`,
            callback
        );
    }

    querySelectorAll(selector) {
        return selector === ".card"
            ? this.buttons
            : [];
    }

    appendChild() {}

    remove() {}
}


function loadTracker() {
    const listeners = new Map();
    const elements = new Map();
    const element = id => {
        if (!elements.has(id)) {
            elements.set(
                id,
                new FakeElement(
                    id,
                    listeners
                )
            );
        }
        return elements.get(id);
    };
    const storage = new Map();
    const sandbox = {
        console,
        confirm: () => true,
        alert() {},
        navigator: {
            userAgent: "node-test",
            vibrate() {}
        },
        localStorage: {
            getItem(key) {
                return storage.get(key) ?? null;
            },
            setItem(key, value) {
                storage.set(
                    key,
                    String(value)
                );
            },
            removeItem(key) {
                storage.delete(key);
            }
        },
        document: {
            readyState: "complete",
            body: element("body"),
            getElementById: element,
            querySelectorAll() {
                return [];
            },
            addEventListener() {},
            createElement(tag) {
                return element(
                    `created-${tag}-${elements.size}`
                );
            }
        },
        FIELD_DECKS: {
            測試場地: {
                劍1槍1: 4,
                移1防1: 2
            }
        },
        FIELD_GROUPS: {
            test: {
                label: "測試",
                fields: ["測試場地"]
            }
        },
        structuredClone,
        clearTimeout,
        setTimeout(callback) {
            callback();
            return 1;
        }
    };
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(
        fs.readFileSync(
            path.resolve(
                __dirname,
                "..",
                "..",
                "..",
                "tracker.js"
            ),
            "utf8"
        ),
        sandbox,
        { filename: "tracker.js" }
    );
    return {
        element,
        run(code) {
            return vm.runInContext(
                code,
                sandbox
            );
        }
    };
}


function setZones(
    tracker,
    {
        remaining = {},
        myHand = {},
        enemyCandidates = {}
    }
) {
    tracker.run(`
        state.remaining = {
            "劍1槍1": ${remaining["劍1槍1"] || 0},
            "移1防1": ${remaining["移1防1"] || 0}
        };
        state.myHand = {
            "劍1槍1": ${myHand["劍1槍1"] || 0},
            "移1防1": ${myHand["移1防1"] || 0}
        };
        state.enemyCandidates = {
            "劍1槍1": ${enemyCandidates["劍1槍1"] || 0},
            "移1防1": ${enemyCandidates["移1防1"] || 0}
        };
        render();
    `);
}


test("one remaining card renders one image and one probability", () => {
    const tracker = loadTracker();
    setZones(tracker, {
        remaining: { 劍1槍1: 1 }
    });

    const buttons = tracker.element("cards").buttons;
    assert.equal(buttons.length, 1);
    assert.equal(
        buttons[0].dataset.card,
        "劍1槍1"
    );
    assert.equal(
        buttons[0].title,
        "劍1槍1"
    );
    assert.match(
        buttons[0].className,
        /\bgame-card-single\b/
    );
    assert.equal(
        (
            buttons[0].html.match(
                /game-card-preview/g
            ) || []
        ).length,
        1
    );
    assert.equal(
        (
            buttons[0].html.match(
                /game-card-probability/g
            ) || []
        ).length,
        1
    );
    assert.match(
        buttons[0].html,
        />\s*100\.0%\s*</
    );
    assert.doesNotMatch(
        buttons[0].html,
        /game-card-info|\d+\s*\/\s*\d+/
    );
    assert.match(
        buttons[0].ariaLabel,
        /劍1槍1.*100\.0%/
    );
});


test("four copies render as four adjacent buttons", () => {
    const tracker = loadTracker();
    setZones(tracker, {
        remaining: { 劍1槍1: 4 }
    });

    const buttons = tracker.element("cards").buttons;
    assert.equal(buttons.length, 4);
    assert.deepEqual(
        buttons.map(
            button => button.dataset.card
        ),
        [
            "劍1槍1",
            "劍1槍1",
            "劍1槍1",
            "劍1槍1"
        ]
    );
    assert.equal(
        buttons.filter(
            button => (
                />\s*100\.0%\s*</
            ).test(button.html)
        ).length,
        4
    );
});


test("multiple card names stay in contiguous runs", () => {
    const tracker = loadTracker();
    setZones(tracker, {
        remaining: {
            劍1槍1: 3,
            移1防1: 2
        }
    });

    const names = tracker
        .element("cards")
        .buttons
        .map(button => button.dataset.card);
    assert.equal(names.length, 5);
    for (const cardName of new Set(names)) {
        const positions = names
            .map((value, index) => (
                value === cardName
                    ? index
                    : -1
            ))
            .filter(index => index >= 0);
        assert.equal(
            positions.at(-1) -
                positions[0] +
                1,
            positions.length
        );
    }
});


test("public click removes exactly one rendered copy", () => {
    const tracker = loadTracker();
    setZones(tracker, {
        remaining: { 劍1槍1: 2 }
    });

    tracker
        .element("cards")
        .buttons[0]
        .dispatch("pointerup");

    assert.equal(
        tracker.run(
            'state.remaining["劍1槍1"]'
        ),
        1
    );
    assert.equal(
        tracker.element("cards").buttons.length,
        1
    );
});


test("right click moves exactly one copy into my hand", () => {
    const tracker = loadTracker();
    setZones(tracker, {
        remaining: { 劍1槍1: 2 }
    });

    tracker
        .element("cards")
        .buttons[0]
        .dispatch("contextmenu");

    assert.equal(
        tracker.run(
            'state.remaining["劍1槍1"]'
        ),
        1
    );
    assert.equal(
        tracker.run(
            'state.myHand["劍1槍1"]'
        ),
        1
    );
});


test("long press moves exactly one copy into my hand", () => {
    const tracker = loadTracker();
    setZones(tracker, {
        remaining: { 劍1槍1: 2 }
    });

    tracker
        .element("cards")
        .buttons[0]
        .dispatch(
            "pointerdown",
            { pointerType: "touch" }
        );

    assert.equal(
        tracker.run(
            'state.remaining["劍1槍1"]'
        ),
        1
    );
    assert.equal(
        tracker.run(
            'state.myHand["劍1槍1"]'
        ),
        1
    );
});


test("my hand renders per copy and click plays one", () => {
    const tracker = loadTracker();
    setZones(tracker, {
        myHand: { 劍1槍1: 2 }
    });

    const hand = tracker.element("myHand");
    assert.equal(hand.buttons.length, 2);
    assert.equal(
        hand.buttons.filter(
            button => (
                />\s*100\.0%\s*</
            ).test(button.html)
        ).length,
        2
    );
    hand.buttons[0].dispatch("click");
    assert.equal(
        tracker.run(
            'state.myHand["劍1槍1"]'
        ),
        1
    );
    assert.equal(hand.buttons.length, 1);
});


test("enemy candidates render per copy and click removes one", () => {
    const tracker = loadTracker();
    setZones(tracker, {
        enemyCandidates: { 移1防1: 2 }
    });

    const enemy =
        tracker.element("enemyCandidates");
    assert.equal(enemy.buttons.length, 2);
    enemy.buttons[0].dispatch("click");
    assert.equal(
        tracker.run(
            'state.enemyCandidates["移1防1"]'
        ),
        1
    );
    assert.equal(enemy.buttons.length, 1);
});


test("zero counts render no physical card", () => {
    const tracker = loadTracker();
    setZones(tracker, {
        remaining: {},
        myHand: {},
        enemyCandidates: {}
    });

    assert.equal(
        tracker.element("cards").buttons.length,
        0
    );
    assert.equal(
        tracker.element("myHand").buttons.length,
        0
    );
    assert.equal(
        tracker
            .element("enemyCandidates")
            .buttons.length,
        0
    );
});
