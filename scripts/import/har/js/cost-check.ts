"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.costcheck = costcheck;
var event_card_1 = require("../card/event-card");
var chara_1 = require("../chara");
var avatar_item_1 = require("../item/avatar-item");
var UNKNOWN_COST = 99;
// eslint-disable-next-line complexity, max-lines-per-function
function costcheck(deck) {
    var _a, _b, _c;
    var result = {
        total: 0,
        event: 0,
        weapon: 0,
        cards: [null, null, null],
        penalties: [null, null, null],
    };
    var deckArray = [];
    for (var n = 0; n < 3; n++) {
        var chara = deck.chara[n];
        if (chara === null || chara === undefined) {
            continue;
        }
        var charaIndex = deck.charaIndex[n];
        if (charaIndex === null || charaIndex === undefined) {
            continue;
        }
        var asset = chara_1.Chara.getAsset(chara, charaIndex);
        var cost = (_a = asset === null || asset === void 0 ? void 0 : asset.cost) !== null && _a !== void 0 ? _a : UNKNOWN_COST;
        result.cards[n] = cost;
        deckArray.push(cost);
    }
    deckArray.sort(function (a, b) { return a - b; });
    // this could probably be done iteratively
    switch (deckArray.length) {
        case 2: {
            if (deckArray[1] - deckArray[0] >= 14) {
                result.penalties[0] = 10;
            }
            else if (deckArray[1] - deckArray[0] >= 7) {
                result.penalties[0] = 5;
            }
            break;
        }
        case 3: {
            if (deckArray[2] - deckArray[1] < 7) {
                if (deckArray[2] - deckArray[0] >= 7 && deckArray[1] - deckArray[0] >= 7) {
                    if (deckArray[2] - deckArray[0] >= 14) {
                        result.penalties[2] = 10;
                    }
                    else if (deckArray[2] - deckArray[0] >= 7) {
                        result.penalties[2] = 5;
                    }
                    if (deckArray[1] - deckArray[0] >= 14) {
                        result.penalties[1] = 10;
                    }
                    else if (deckArray[1] - deckArray[0] >= 7) {
                        result.penalties[1] = 5;
                    }
                }
                else {
                    if (deckArray[2] - deckArray[0] >= 14) {
                        result.penalties[0] = 10;
                    }
                    else if (deckArray[2] - deckArray[0] >= 7) {
                        result.penalties[0] = 5;
                    }
                    if (deckArray[1] - deckArray[0] >= 14) {
                        result.penalties[0] = 10;
                    }
                    else if (deckArray[1] - deckArray[0] >= 7) {
                        result.penalties[0] = 5;
                    }
                }
            }
            else {
                if (deckArray[1] - deckArray[0] < 7) {
                    if (deckArray[2] - deckArray[0] >= 14) {
                        result.penalties[0] = 10;
                    }
                    else if (deckArray[2] - deckArray[0] >= 7) {
                        result.penalties[0] = 5;
                    }
                    if (deckArray[2] - deckArray[1] >= 14) {
                        result.penalties[1] = 10;
                    }
                    else if (deckArray[2] - deckArray[1] >= 7) {
                        result.penalties[1] = 5;
                    }
                }
                else {
                    if (deckArray[2] - deckArray[0] >= 14) {
                        result.penalties[0] = 10;
                    }
                    else if (deckArray[2] - deckArray[0] >= 7) {
                        result.penalties[0] = 5;
                    }
                    if (deckArray[1] - deckArray[0] >= 14) {
                        result.penalties[1] = 10;
                    }
                    else if (deckArray[1] - deckArray[0] >= 7) {
                        result.penalties[1] = 5;
                    }
                    if (deckArray[2] - deckArray[1] >= 14) {
                        result.penalties[2] = 10;
                    }
                    else if (deckArray[2] - deckArray[1] >= 7) {
                        result.penalties[2] = 5;
                    }
                }
            }
            break;
        }
        // no default
    }
    for (var _i = 0, _d = deck.eventIndex; _i < _d.length; _i++) {
        var e = _d[_i];
        if (e === null) {
            continue;
        }
        var eventAsset = event_card_1.EventData.get(e);
        result.event += (_b = eventAsset === null || eventAsset === void 0 ? void 0 : eventAsset.cost) !== null && _b !== void 0 ? _b : UNKNOWN_COST;
    }
    for (var _e = 0, _f = deck.weapon; _e < _f.length; _e++) {
        var w = _f[_e];
        if (w === null) {
            continue;
        }
        var weaponAsset = avatar_item_1.AvatarItem.get('weapon', w);
        result.weapon += (_c = weaponAsset === null || weaponAsset === void 0 ? void 0 : weaponAsset.cost) !== null && _c !== void 0 ? _c : UNKNOWN_COST;
    }
    result.total += result.event;
    result.total += result.weapon;
    for (var _g = 0, _h = result.cards; _g < _h.length; _g++) {
        var c = _h[_g];
        if (c === null) {
            continue;
        }
        result.total += c;
    }
    for (var _j = 0, _k = result.penalties; _j < _k.length; _j++) {
        var p = _k[_j];
        if (p === null) {
            continue;
        }
        result.total += p;
    }
    return result;
}
