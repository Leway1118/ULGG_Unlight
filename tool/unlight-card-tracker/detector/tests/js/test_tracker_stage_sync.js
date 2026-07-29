"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");


function loadStageSync(fieldDecks = {}) {
    const sandbox = {
        FIELD_DECKS: fieldDecks
    };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(
        fs.readFileSync(
            path.resolve(
                __dirname,
                "..",
                "..",
                "..",
                "tracker-stage-sync.js"
            ),
            "utf8"
        ),
        sandbox
    );
    return sandbox.TrackerStageSync;
}


test("003 resolves to 冰封湖畔 when its deck exists", () => {
    const sync = loadStageSync();
    const result = sync.resolveStage(
        "003",
        { "冰封湖畔": { card: 1 } }
    );

    assert.equal(result.status, "ready");
    assert.equal(result.stage_code, "003");
    assert.equal(result.field, "冰封湖畔");
});


test("002 resolves to 垃圾之街", () => {
    const sync = loadStageSync();
    const result = sync.resolveStage(
        "002",
        { "垃圾之街": { card: 1 } }
    );

    assert.equal(result.status, "ready");
    assert.equal(result.field, "垃圾之街");
});


test("010 fails closed when 魔女山谷 deck is missing", () => {
    const sync = loadStageSync();
    const result = sync.resolveStage("010", {});

    assert.equal(
        result.status,
        "field_deck_missing"
    );
    assert.equal(result.field, "魔女山谷");
});


test("011 is random and never resolves a fixed field", () => {
    const sync = loadStageSync();
    const result = sync.resolveStage(
        "011",
        { "隨機": { card: 1 } }
    );

    assert.equal(result.status, "random");
    assert.equal(result.field, null);
});


test("unknown and malformed stage codes fail closed", () => {
    const sync = loadStageSync();

    assert.equal(
        sync.resolveStage("999", {}).status,
        "unsupported"
    );
    assert.equal(
        sync.resolveStage("3", {}).status,
        "invalid_stage_code"
    );
    assert.equal(
        sync.resolveStage(3, {}).status,
        "invalid_stage_code"
    );
    assert.equal(
        sync.resolveStage(null, {}).status,
        "missing"
    );
});
