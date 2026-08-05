"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");


const TRACKER_ROOT = path.resolve(__dirname, "..", "..", "..");
const ENTRY_FILES = ["control.html", "index.php"];
const REQUIRED_IDS = [
    "field",
    "initial",
    "deckLeft",
    "myHandCount",
    "enemyCandidateCount",
    "seen",
    "undo",
    "shuffle",
    "reset",
    "types",
    "observation-status",
    "confirm-pending-field",
    "enemyCandidates",
    "cards",
    "myHand",
    "clear",
    "history"
];


function readEntry(fileName) {
    return fs.readFileSync(
        path.join(TRACKER_ROOT, fileName),
        "utf8"
    );
}


for (const fileName of ENTRY_FILES) {
    test(`${fileName} keeps every runtime DOM id exactly once`, () => {
        const source = readEntry(fileName);

        for (const id of REQUIRED_IDS) {
            const matches = source.match(
                new RegExp(`id=["']${id}["']`, "g")
            ) || [];
            assert.equal(
                matches.length,
                1,
                `${id} must occur exactly once`
            );
        }
    });

    test(`${fileName} exposes the five dashboard areas`, () => {
        const source = readEntry(fileName);

        for (const area of [
            "dashboard-controls",
            "dashboard-enemy",
            "dashboard-public",
            "dashboard-public-side",
            "dashboard-hand",
            "dashboard-history"
        ]) {
            assert.match(source, new RegExp(`class="[^"]*${area}`));
        }

        assert.match(
            source,
            /"controls enemy enemy"\s*"public public public-side"\s*"hand hand hand"/
        );
    });

    test(`${fileName} collapses diagnostics by default`, () => {
        const source = readEntry(fileName);
        const details = source.match(
            /<details class="tracker-diagnostics"[^>]*>/
        );

        assert.ok(details);
        assert.doesNotMatch(details[0], /\sopen(?:\s|=|>)/);
        assert.match(source, /<summary>Tracker 診斷<\/summary>/);
    });

    test(`${fileName} defines tablet and mobile dashboard order`, () => {
        const source = readEntry(fileName);

        assert.match(source, /@media\s*\(max-width:\s*1100px\)/);
        assert.match(source, /@media\s*\(max-width:\s*700px\)/);
        assert.match(
            source,
            /"controls"\s*"enemy"\s*"public"\s*"public-side"\s*"hand"/
        );
    });
}
