{
Object.defineProperty(exports, "__esModule", ({ value: true }));
exports.costcheck = costcheck;
var event_card_1 = __webpack_require__(1794);
var chara_1 = __webpack_require__(4004);
var avatar_item_1 = __webpack_require__(8653);
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
//# sourceURL=[module]
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoiNzcwLmpzIiwibWFwcGluZ3MiOiJBQUFhO0FBQ2IsOENBQTZDLEVBQUUsYUFBYSxFQUFDO0FBQzdELGlCQUFpQjtBQUNqQixtQkFBbUIsbUJBQU8sQ0FBQyxJQUFvQjtBQUMvQyxjQUFjLG1CQUFPLENBQUMsSUFBVTtBQUNoQyxvQkFBb0IsbUJBQU8sQ0FBQyxJQUFxQjtBQUNqRDtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSxvQkFBb0IsT0FBTztBQUMzQjtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBLHFDQUFxQyxlQUFlO0FBQ3BEO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0EsMkNBQTJDLGdCQUFnQjtBQUMzRDtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBLHVDQUF1QyxnQkFBZ0I7QUFDdkQ7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0Esd0NBQXdDLGdCQUFnQjtBQUN4RDtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSw0Q0FBNEMsZ0JBQWdCO0FBQzVEO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0EiLCJzb3VyY2VzIjpbIndlYnBhY2s6Ly91bmxpZ2h0LWNvbW1vbi8uL3NyYy9kZWNrL2Nvc3QtY2hlY2sudHM/MWNiZSJdLCJzb3VyY2VzQ29udGVudCI6WyJcInVzZSBzdHJpY3RcIjtcbk9iamVjdC5kZWZpbmVQcm9wZXJ0eShleHBvcnRzLCBcIl9fZXNNb2R1bGVcIiwgeyB2YWx1ZTogdHJ1ZSB9KTtcbmV4cG9ydHMuY29zdGNoZWNrID0gY29zdGNoZWNrO1xudmFyIGV2ZW50X2NhcmRfMSA9IHJlcXVpcmUoXCIuLi9jYXJkL2V2ZW50LWNhcmRcIik7XG52YXIgY2hhcmFfMSA9IHJlcXVpcmUoXCIuLi9jaGFyYVwiKTtcbnZhciBhdmF0YXJfaXRlbV8xID0gcmVxdWlyZShcIi4uL2l0ZW0vYXZhdGFyLWl0ZW1cIik7XG52YXIgVU5LTk9XTl9DT1NUID0gOTk7XG4vLyBlc2xpbnQtZGlzYWJsZS1uZXh0LWxpbmUgY29tcGxleGl0eSwgbWF4LWxpbmVzLXBlci1mdW5jdGlvblxuZnVuY3Rpb24gY29zdGNoZWNrKGRlY2spIHtcbiAgICB2YXIgX2EsIF9iLCBfYztcbiAgICB2YXIgcmVzdWx0ID0ge1xuICAgICAgICB0b3RhbDogMCxcbiAgICAgICAgZXZlbnQ6IDAsXG4gICAgICAgIHdlYXBvbjogMCxcbiAgICAgICAgY2FyZHM6IFtudWxsLCBudWxsLCBudWxsXSxcbiAgICAgICAgcGVuYWx0aWVzOiBbbnVsbCwgbnVsbCwgbnVsbF0sXG4gICAgfTtcbiAgICB2YXIgZGVja0FycmF5ID0gW107XG4gICAgZm9yICh2YXIgbiA9IDA7IG4gPCAzOyBuKyspIHtcbiAgICAgICAgdmFyIGNoYXJhID0gZGVjay5jaGFyYVtuXTtcbiAgICAgICAgaWYgKGNoYXJhID09PSBudWxsIHx8IGNoYXJhID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGNvbnRpbnVlO1xuICAgICAgICB9XG4gICAgICAgIHZhciBjaGFyYUluZGV4ID0gZGVjay5jaGFyYUluZGV4W25dO1xuICAgICAgICBpZiAoY2hhcmFJbmRleCA9PT0gbnVsbCB8fCBjaGFyYUluZGV4ID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGNvbnRpbnVlO1xuICAgICAgICB9XG4gICAgICAgIHZhciBhc3NldCA9IGNoYXJhXzEuQ2hhcmEuZ2V0QXNzZXQoY2hhcmEsIGNoYXJhSW5kZXgpO1xuICAgICAgICB2YXIgY29zdCA9IChfYSA9IGFzc2V0ID09PSBudWxsIHx8IGFzc2V0ID09PSB2b2lkIDAgPyB2b2lkIDAgOiBhc3NldC5jb3N0KSAhPT0gbnVsbCAmJiBfYSAhPT0gdm9pZCAwID8gX2EgOiBVTktOT1dOX0NPU1Q7XG4gICAgICAgIHJlc3VsdC5jYXJkc1tuXSA9IGNvc3Q7XG4gICAgICAgIGRlY2tBcnJheS5wdXNoKGNvc3QpO1xuICAgIH1cbiAgICBkZWNrQXJyYXkuc29ydChmdW5jdGlvbiAoYSwgYikgeyByZXR1cm4gYSAtIGI7IH0pO1xuICAgIC8vIHRoaXMgY291bGQgcHJvYmFibHkgYmUgZG9uZSBpdGVyYXRpdmVseVxuICAgIHN3aXRjaCAoZGVja0FycmF5Lmxlbmd0aCkge1xuICAgICAgICBjYXNlIDI6IHtcbiAgICAgICAgICAgIGlmIChkZWNrQXJyYXlbMV0gLSBkZWNrQXJyYXlbMF0gPj0gMTQpIHtcbiAgICAgICAgICAgICAgICByZXN1bHQucGVuYWx0aWVzWzBdID0gMTA7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIGlmIChkZWNrQXJyYXlbMV0gLSBkZWNrQXJyYXlbMF0gPj0gNykge1xuICAgICAgICAgICAgICAgIHJlc3VsdC5wZW5hbHRpZXNbMF0gPSA1O1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgYnJlYWs7XG4gICAgICAgIH1cbiAgICAgICAgY2FzZSAzOiB7XG4gICAgICAgICAgICBpZiAoZGVja0FycmF5WzJdIC0gZGVja0FycmF5WzFdIDwgNykge1xuICAgICAgICAgICAgICAgIGlmIChkZWNrQXJyYXlbMl0gLSBkZWNrQXJyYXlbMF0gPj0gNyAmJiBkZWNrQXJyYXlbMV0gLSBkZWNrQXJyYXlbMF0gPj0gNykge1xuICAgICAgICAgICAgICAgICAgICBpZiAoZGVja0FycmF5WzJdIC0gZGVja0FycmF5WzBdID49IDE0KSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXN1bHQucGVuYWx0aWVzWzJdID0gMTA7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgZWxzZSBpZiAoZGVja0FycmF5WzJdIC0gZGVja0FycmF5WzBdID49IDcpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHJlc3VsdC5wZW5hbHRpZXNbMl0gPSA1O1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIGlmIChkZWNrQXJyYXlbMV0gLSBkZWNrQXJyYXlbMF0gPj0gMTQpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHJlc3VsdC5wZW5hbHRpZXNbMV0gPSAxMDtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBlbHNlIGlmIChkZWNrQXJyYXlbMV0gLSBkZWNrQXJyYXlbMF0gPj0gNykge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmVzdWx0LnBlbmFsdGllc1sxXSA9IDU7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIGlmIChkZWNrQXJyYXlbMl0gLSBkZWNrQXJyYXlbMF0gPj0gMTQpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHJlc3VsdC5wZW5hbHRpZXNbMF0gPSAxMDtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBlbHNlIGlmIChkZWNrQXJyYXlbMl0gLSBkZWNrQXJyYXlbMF0gPj0gNykge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmVzdWx0LnBlbmFsdGllc1swXSA9IDU7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgaWYgKGRlY2tBcnJheVsxXSAtIGRlY2tBcnJheVswXSA+PSAxNCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmVzdWx0LnBlbmFsdGllc1swXSA9IDEwO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIGVsc2UgaWYgKGRlY2tBcnJheVsxXSAtIGRlY2tBcnJheVswXSA+PSA3KSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXN1bHQucGVuYWx0aWVzWzBdID0gNTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgIGlmIChkZWNrQXJyYXlbMV0gLSBkZWNrQXJyYXlbMF0gPCA3KSB7XG4gICAgICAgICAgICAgICAgICAgIGlmIChkZWNrQXJyYXlbMl0gLSBkZWNrQXJyYXlbMF0gPj0gMTQpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHJlc3VsdC5wZW5hbHRpZXNbMF0gPSAxMDtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBlbHNlIGlmIChkZWNrQXJyYXlbMl0gLSBkZWNrQXJyYXlbMF0gPj0gNykge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmVzdWx0LnBlbmFsdGllc1swXSA9IDU7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgaWYgKGRlY2tBcnJheVsyXSAtIGRlY2tBcnJheVsxXSA+PSAxNCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmVzdWx0LnBlbmFsdGllc1sxXSA9IDEwO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIGVsc2UgaWYgKGRlY2tBcnJheVsyXSAtIGRlY2tBcnJheVsxXSA+PSA3KSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXN1bHQucGVuYWx0aWVzWzFdID0gNTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgaWYgKGRlY2tBcnJheVsyXSAtIGRlY2tBcnJheVswXSA+PSAxNCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmVzdWx0LnBlbmFsdGllc1swXSA9IDEwO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIGVsc2UgaWYgKGRlY2tBcnJheVsyXSAtIGRlY2tBcnJheVswXSA+PSA3KSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXN1bHQucGVuYWx0aWVzWzBdID0gNTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBpZiAoZGVja0FycmF5WzFdIC0gZGVja0FycmF5WzBdID49IDE0KSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXN1bHQucGVuYWx0aWVzWzFdID0gMTA7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgZWxzZSBpZiAoZGVja0FycmF5WzFdIC0gZGVja0FycmF5WzBdID49IDcpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHJlc3VsdC5wZW5hbHRpZXNbMV0gPSA1O1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIGlmIChkZWNrQXJyYXlbMl0gLSBkZWNrQXJyYXlbMV0gPj0gMTQpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHJlc3VsdC5wZW5hbHRpZXNbMl0gPSAxMDtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBlbHNlIGlmIChkZWNrQXJyYXlbMl0gLSBkZWNrQXJyYXlbMV0gPj0gNykge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmVzdWx0LnBlbmFsdGllc1syXSA9IDU7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBicmVhaztcbiAgICAgICAgfVxuICAgICAgICAvLyBubyBkZWZhdWx0XG4gICAgfVxuICAgIGZvciAodmFyIF9pID0gMCwgX2QgPSBkZWNrLmV2ZW50SW5kZXg7IF9pIDwgX2QubGVuZ3RoOyBfaSsrKSB7XG4gICAgICAgIHZhciBlID0gX2RbX2ldO1xuICAgICAgICBpZiAoZSA9PT0gbnVsbCkge1xuICAgICAgICAgICAgY29udGludWU7XG4gICAgICAgIH1cbiAgICAgICAgdmFyIGV2ZW50QXNzZXQgPSBldmVudF9jYXJkXzEuRXZlbnREYXRhLmdldChlKTtcbiAgICAgICAgcmVzdWx0LmV2ZW50ICs9IChfYiA9IGV2ZW50QXNzZXQgPT09IG51bGwgfHwgZXZlbnRBc3NldCA9PT0gdm9pZCAwID8gdm9pZCAwIDogZXZlbnRBc3NldC5jb3N0KSAhPT0gbnVsbCAmJiBfYiAhPT0gdm9pZCAwID8gX2IgOiBVTktOT1dOX0NPU1Q7XG4gICAgfVxuICAgIGZvciAodmFyIF9lID0gMCwgX2YgPSBkZWNrLndlYXBvbjsgX2UgPCBfZi5sZW5ndGg7IF9lKyspIHtcbiAgICAgICAgdmFyIHcgPSBfZltfZV07XG4gICAgICAgIGlmICh3ID09PSBudWxsKSB7XG4gICAgICAgICAgICBjb250aW51ZTtcbiAgICAgICAgfVxuICAgICAgICB2YXIgd2VhcG9uQXNzZXQgPSBhdmF0YXJfaXRlbV8xLkF2YXRhckl0ZW0uZ2V0KCd3ZWFwb24nLCB3KTtcbiAgICAgICAgcmVzdWx0LndlYXBvbiArPSAoX2MgPSB3ZWFwb25Bc3NldCA9PT0gbnVsbCB8fCB3ZWFwb25Bc3NldCA9PT0gdm9pZCAwID8gdm9pZCAwIDogd2VhcG9uQXNzZXQuY29zdCkgIT09IG51bGwgJiYgX2MgIT09IHZvaWQgMCA/IF9jIDogVU5LTk9XTl9DT1NUO1xuICAgIH1cbiAgICByZXN1bHQudG90YWwgKz0gcmVzdWx0LmV2ZW50O1xuICAgIHJlc3VsdC50b3RhbCArPSByZXN1bHQud2VhcG9uO1xuICAgIGZvciAodmFyIF9nID0gMCwgX2ggPSByZXN1bHQuY2FyZHM7IF9nIDwgX2gubGVuZ3RoOyBfZysrKSB7XG4gICAgICAgIHZhciBjID0gX2hbX2ddO1xuICAgICAgICBpZiAoYyA9PT0gbnVsbCkge1xuICAgICAgICAgICAgY29udGludWU7XG4gICAgICAgIH1cbiAgICAgICAgcmVzdWx0LnRvdGFsICs9IGM7XG4gICAgfVxuICAgIGZvciAodmFyIF9qID0gMCwgX2sgPSByZXN1bHQucGVuYWx0aWVzOyBfaiA8IF9rLmxlbmd0aDsgX2orKykge1xuICAgICAgICB2YXIgcCA9IF9rW19qXTtcbiAgICAgICAgaWYgKHAgPT09IG51bGwpIHtcbiAgICAgICAgICAgIGNvbnRpbnVlO1xuICAgICAgICB9XG4gICAgICAgIHJlc3VsdC50b3RhbCArPSBwO1xuICAgIH1cbiAgICByZXR1cm4gcmVzdWx0O1xufVxuIl0sIm5hbWVzIjpbXSwic291cmNlUm9vdCI6IiJ9
//# sourceURL=webpack-internal:///770

}