{
Object.defineProperty(exports, "__esModule", ({ value: true }));
exports.deckcheck = deckcheck;
// eslint-disable-next-line max-statements, complexity
function deckcheck(deck) {
    var _a, _b, _c, _d, _e, _f, _g, _h, _j, _k, _l, _m, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z, _0, _1, _2, _3, _4, _5, _6, _7, _8, _9, _10, _11, _12, _13, _14, _15;
    if (deck.chara[0] === undefined) {
        if (deck.chara[1] !== undefined && deck.chara[2] === undefined) {
            deck.chara[0] = deck.chara[1];
            deck.charaIndex[0] = (_a = deck.charaIndex[1]) !== null && _a !== void 0 ? _a : null;
            deck.weapon[0] = (_b = deck.weapon[1]) !== null && _b !== void 0 ? _b : null;
            deck.eventIndex[0] = (_c = deck.eventIndex[6]) !== null && _c !== void 0 ? _c : null;
            deck.eventIndex[1] = (_d = deck.eventIndex[7]) !== null && _d !== void 0 ? _d : null;
            deck.eventIndex[2] = (_e = deck.eventIndex[8]) !== null && _e !== void 0 ? _e : null;
            deck.eventIndex[3] = (_f = deck.eventIndex[9]) !== null && _f !== void 0 ? _f : null;
            deck.eventIndex[4] = (_g = deck.eventIndex[10]) !== null && _g !== void 0 ? _g : null;
            deck.eventIndex[5] = (_h = deck.eventIndex[11]) !== null && _h !== void 0 ? _h : null;
            deck.chara[1] = null;
            deck.charaIndex[1] = null;
            deck.weapon[1] = null;
            deck.eventIndex[6] = null;
            deck.eventIndex[7] = null;
            deck.eventIndex[8] = null;
            deck.eventIndex[9] = null;
            deck.eventIndex[10] = null;
            deck.eventIndex[11] = null;
        }
        else if (deck.chara[1] === undefined && deck.chara[2] !== undefined) {
            deck.chara[0] = deck.chara[2];
            deck.charaIndex[0] = (_j = deck.charaIndex[2]) !== null && _j !== void 0 ? _j : null;
            deck.weapon[0] = (_k = deck.weapon[2]) !== null && _k !== void 0 ? _k : null;
            deck.eventIndex[0] = (_l = deck.eventIndex[12]) !== null && _l !== void 0 ? _l : null;
            deck.eventIndex[1] = (_m = deck.eventIndex[13]) !== null && _m !== void 0 ? _m : null;
            deck.eventIndex[2] = (_o = deck.eventIndex[14]) !== null && _o !== void 0 ? _o : null;
            deck.eventIndex[3] = (_p = deck.eventIndex[15]) !== null && _p !== void 0 ? _p : null;
            deck.eventIndex[4] = (_q = deck.eventIndex[16]) !== null && _q !== void 0 ? _q : null;
            deck.eventIndex[5] = (_r = deck.eventIndex[17]) !== null && _r !== void 0 ? _r : null;
            deck.chara[2] = null;
            deck.charaIndex[2] = null;
            deck.weapon[2] = null;
            deck.eventIndex[12] = null;
            deck.eventIndex[13] = null;
            deck.eventIndex[14] = null;
            deck.eventIndex[15] = null;
            deck.eventIndex[16] = null;
            deck.eventIndex[17] = null;
        }
        else if (deck.chara[1] !== undefined && deck.chara[2] !== undefined) {
            deck.chara[0] = deck.chara[1];
            deck.charaIndex[0] = (_s = deck.charaIndex[1]) !== null && _s !== void 0 ? _s : null;
            deck.weapon[0] = (_t = deck.weapon[1]) !== null && _t !== void 0 ? _t : null;
            deck.eventIndex[0] = (_u = deck.eventIndex[6]) !== null && _u !== void 0 ? _u : null;
            deck.eventIndex[1] = (_v = deck.eventIndex[7]) !== null && _v !== void 0 ? _v : null;
            deck.eventIndex[2] = (_w = deck.eventIndex[8]) !== null && _w !== void 0 ? _w : null;
            deck.eventIndex[3] = (_x = deck.eventIndex[9]) !== null && _x !== void 0 ? _x : null;
            deck.eventIndex[4] = (_y = deck.eventIndex[10]) !== null && _y !== void 0 ? _y : null;
            deck.eventIndex[5] = (_z = deck.eventIndex[11]) !== null && _z !== void 0 ? _z : null;
            deck.chara[1] = deck.chara[2];
            deck.charaIndex[1] = (_0 = deck.charaIndex[2]) !== null && _0 !== void 0 ? _0 : null;
            deck.weapon[1] = (_1 = deck.weapon[2]) !== null && _1 !== void 0 ? _1 : null;
            deck.eventIndex[6] = (_2 = deck.eventIndex[12]) !== null && _2 !== void 0 ? _2 : null;
            deck.eventIndex[7] = (_3 = deck.eventIndex[13]) !== null && _3 !== void 0 ? _3 : null;
            deck.eventIndex[8] = (_4 = deck.eventIndex[14]) !== null && _4 !== void 0 ? _4 : null;
            deck.eventIndex[9] = (_5 = deck.eventIndex[15]) !== null && _5 !== void 0 ? _5 : null;
            deck.eventIndex[10] = (_6 = deck.eventIndex[16]) !== null && _6 !== void 0 ? _6 : null;
            deck.eventIndex[11] = (_7 = deck.eventIndex[17]) !== null && _7 !== void 0 ? _7 : null;
            deck.chara[2] = null;
            deck.charaIndex[2] = null;
            deck.weapon[2] = null;
            deck.eventIndex[12] = null;
            deck.eventIndex[13] = null;
            deck.eventIndex[14] = null;
            deck.eventIndex[15] = null;
            deck.eventIndex[16] = null;
            deck.eventIndex[17] = null;
        }
    }
    else {
        if (deck.chara[1] === undefined && deck.chara[2] !== undefined) {
            deck.chara[1] = deck.chara[2];
            deck.charaIndex[1] = (_8 = deck.charaIndex[2]) !== null && _8 !== void 0 ? _8 : null;
            deck.weapon[1] = (_9 = deck.weapon[2]) !== null && _9 !== void 0 ? _9 : null;
            deck.eventIndex[6] = (_10 = deck.eventIndex[12]) !== null && _10 !== void 0 ? _10 : null;
            deck.eventIndex[7] = (_11 = deck.eventIndex[13]) !== null && _11 !== void 0 ? _11 : null;
            deck.eventIndex[8] = (_12 = deck.eventIndex[14]) !== null && _12 !== void 0 ? _12 : null;
            deck.eventIndex[9] = (_13 = deck.eventIndex[15]) !== null && _13 !== void 0 ? _13 : null;
            deck.eventIndex[10] = (_14 = deck.eventIndex[16]) !== null && _14 !== void 0 ? _14 : null;
            deck.eventIndex[11] = (_15 = deck.eventIndex[17]) !== null && _15 !== void 0 ? _15 : null;
            deck.chara[2] = null;
            deck.charaIndex[2] = null;
            deck.weapon[2] = null;
            deck.eventIndex[12] = null;
            deck.eventIndex[13] = null;
            deck.eventIndex[14] = null;
            deck.eventIndex[15] = null;
            deck.eventIndex[16] = null;
            deck.eventIndex[17] = null;
        }
    }
    return deck;
}
//# sourceURL=[module]
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoiMTI0Ni5qcyIsIm1hcHBpbmdzIjoiQUFBYTtBQUNiLDhDQUE2QyxFQUFFLGFBQWEsRUFBQztBQUM3RCxpQkFBaUI7QUFDakI7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSIsInNvdXJjZXMiOlsid2VicGFjazovL3VubGlnaHQtY29tbW9uLy4vc3JjL2RlY2svZGVjay1jaGVjay50cz8yYTQ1Il0sInNvdXJjZXNDb250ZW50IjpbIlwidXNlIHN0cmljdFwiO1xuT2JqZWN0LmRlZmluZVByb3BlcnR5KGV4cG9ydHMsIFwiX19lc01vZHVsZVwiLCB7IHZhbHVlOiB0cnVlIH0pO1xuZXhwb3J0cy5kZWNrY2hlY2sgPSBkZWNrY2hlY2s7XG4vLyBlc2xpbnQtZGlzYWJsZS1uZXh0LWxpbmUgbWF4LXN0YXRlbWVudHMsIGNvbXBsZXhpdHlcbmZ1bmN0aW9uIGRlY2tjaGVjayhkZWNrKSB7XG4gICAgdmFyIF9hLCBfYiwgX2MsIF9kLCBfZSwgX2YsIF9nLCBfaCwgX2osIF9rLCBfbCwgX20sIF9vLCBfcCwgX3EsIF9yLCBfcywgX3QsIF91LCBfdiwgX3csIF94LCBfeSwgX3osIF8wLCBfMSwgXzIsIF8zLCBfNCwgXzUsIF82LCBfNywgXzgsIF85LCBfMTAsIF8xMSwgXzEyLCBfMTMsIF8xNCwgXzE1O1xuICAgIGlmIChkZWNrLmNoYXJhWzBdID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgaWYgKGRlY2suY2hhcmFbMV0gIT09IHVuZGVmaW5lZCAmJiBkZWNrLmNoYXJhWzJdID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGRlY2suY2hhcmFbMF0gPSBkZWNrLmNoYXJhWzFdO1xuICAgICAgICAgICAgZGVjay5jaGFyYUluZGV4WzBdID0gKF9hID0gZGVjay5jaGFyYUluZGV4WzFdKSAhPT0gbnVsbCAmJiBfYSAhPT0gdm9pZCAwID8gX2EgOiBudWxsO1xuICAgICAgICAgICAgZGVjay53ZWFwb25bMF0gPSAoX2IgPSBkZWNrLndlYXBvblsxXSkgIT09IG51bGwgJiYgX2IgIT09IHZvaWQgMCA/IF9iIDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFswXSA9IChfYyA9IGRlY2suZXZlbnRJbmRleFs2XSkgIT09IG51bGwgJiYgX2MgIT09IHZvaWQgMCA/IF9jIDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxXSA9IChfZCA9IGRlY2suZXZlbnRJbmRleFs3XSkgIT09IG51bGwgJiYgX2QgIT09IHZvaWQgMCA/IF9kIDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsyXSA9IChfZSA9IGRlY2suZXZlbnRJbmRleFs4XSkgIT09IG51bGwgJiYgX2UgIT09IHZvaWQgMCA/IF9lIDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFszXSA9IChfZiA9IGRlY2suZXZlbnRJbmRleFs5XSkgIT09IG51bGwgJiYgX2YgIT09IHZvaWQgMCA/IF9mIDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFs0XSA9IChfZyA9IGRlY2suZXZlbnRJbmRleFsxMF0pICE9PSBudWxsICYmIF9nICE9PSB2b2lkIDAgPyBfZyA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbNV0gPSAoX2ggPSBkZWNrLmV2ZW50SW5kZXhbMTFdKSAhPT0gbnVsbCAmJiBfaCAhPT0gdm9pZCAwID8gX2ggOiBudWxsO1xuICAgICAgICAgICAgZGVjay5jaGFyYVsxXSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLmNoYXJhSW5kZXhbMV0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay53ZWFwb25bMV0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzZdID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFs3XSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbOF0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzldID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxMF0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzExXSA9IG51bGw7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAoZGVjay5jaGFyYVsxXSA9PT0gdW5kZWZpbmVkICYmIGRlY2suY2hhcmFbMl0gIT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgZGVjay5jaGFyYVswXSA9IGRlY2suY2hhcmFbMl07XG4gICAgICAgICAgICBkZWNrLmNoYXJhSW5kZXhbMF0gPSAoX2ogPSBkZWNrLmNoYXJhSW5kZXhbMl0pICE9PSBudWxsICYmIF9qICE9PSB2b2lkIDAgPyBfaiA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLndlYXBvblswXSA9IChfayA9IGRlY2sud2VhcG9uWzJdKSAhPT0gbnVsbCAmJiBfayAhPT0gdm9pZCAwID8gX2sgOiBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzBdID0gKF9sID0gZGVjay5ldmVudEluZGV4WzEyXSkgIT09IG51bGwgJiYgX2wgIT09IHZvaWQgMCA/IF9sIDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxXSA9IChfbSA9IGRlY2suZXZlbnRJbmRleFsxM10pICE9PSBudWxsICYmIF9tICE9PSB2b2lkIDAgPyBfbSA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbMl0gPSAoX28gPSBkZWNrLmV2ZW50SW5kZXhbMTRdKSAhPT0gbnVsbCAmJiBfbyAhPT0gdm9pZCAwID8gX28gOiBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzNdID0gKF9wID0gZGVjay5ldmVudEluZGV4WzE1XSkgIT09IG51bGwgJiYgX3AgIT09IHZvaWQgMCA/IF9wIDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFs0XSA9IChfcSA9IGRlY2suZXZlbnRJbmRleFsxNl0pICE9PSBudWxsICYmIF9xICE9PSB2b2lkIDAgPyBfcSA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbNV0gPSAoX3IgPSBkZWNrLmV2ZW50SW5kZXhbMTddKSAhPT0gbnVsbCAmJiBfciAhPT0gdm9pZCAwID8gX3IgOiBudWxsO1xuICAgICAgICAgICAgZGVjay5jaGFyYVsyXSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLmNoYXJhSW5kZXhbMl0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay53ZWFwb25bMl0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzEyXSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbMTNdID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxNF0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzE1XSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbMTZdID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxN10gPSBudWxsO1xuICAgICAgICB9XG4gICAgICAgIGVsc2UgaWYgKGRlY2suY2hhcmFbMV0gIT09IHVuZGVmaW5lZCAmJiBkZWNrLmNoYXJhWzJdICE9PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGRlY2suY2hhcmFbMF0gPSBkZWNrLmNoYXJhWzFdO1xuICAgICAgICAgICAgZGVjay5jaGFyYUluZGV4WzBdID0gKF9zID0gZGVjay5jaGFyYUluZGV4WzFdKSAhPT0gbnVsbCAmJiBfcyAhPT0gdm9pZCAwID8gX3MgOiBudWxsO1xuICAgICAgICAgICAgZGVjay53ZWFwb25bMF0gPSAoX3QgPSBkZWNrLndlYXBvblsxXSkgIT09IG51bGwgJiYgX3QgIT09IHZvaWQgMCA/IF90IDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFswXSA9IChfdSA9IGRlY2suZXZlbnRJbmRleFs2XSkgIT09IG51bGwgJiYgX3UgIT09IHZvaWQgMCA/IF91IDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxXSA9IChfdiA9IGRlY2suZXZlbnRJbmRleFs3XSkgIT09IG51bGwgJiYgX3YgIT09IHZvaWQgMCA/IF92IDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsyXSA9IChfdyA9IGRlY2suZXZlbnRJbmRleFs4XSkgIT09IG51bGwgJiYgX3cgIT09IHZvaWQgMCA/IF93IDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFszXSA9IChfeCA9IGRlY2suZXZlbnRJbmRleFs5XSkgIT09IG51bGwgJiYgX3ggIT09IHZvaWQgMCA/IF94IDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFs0XSA9IChfeSA9IGRlY2suZXZlbnRJbmRleFsxMF0pICE9PSBudWxsICYmIF95ICE9PSB2b2lkIDAgPyBfeSA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbNV0gPSAoX3ogPSBkZWNrLmV2ZW50SW5kZXhbMTFdKSAhPT0gbnVsbCAmJiBfeiAhPT0gdm9pZCAwID8gX3ogOiBudWxsO1xuICAgICAgICAgICAgZGVjay5jaGFyYVsxXSA9IGRlY2suY2hhcmFbMl07XG4gICAgICAgICAgICBkZWNrLmNoYXJhSW5kZXhbMV0gPSAoXzAgPSBkZWNrLmNoYXJhSW5kZXhbMl0pICE9PSBudWxsICYmIF8wICE9PSB2b2lkIDAgPyBfMCA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLndlYXBvblsxXSA9IChfMSA9IGRlY2sud2VhcG9uWzJdKSAhPT0gbnVsbCAmJiBfMSAhPT0gdm9pZCAwID8gXzEgOiBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzZdID0gKF8yID0gZGVjay5ldmVudEluZGV4WzEyXSkgIT09IG51bGwgJiYgXzIgIT09IHZvaWQgMCA/IF8yIDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFs3XSA9IChfMyA9IGRlY2suZXZlbnRJbmRleFsxM10pICE9PSBudWxsICYmIF8zICE9PSB2b2lkIDAgPyBfMyA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbOF0gPSAoXzQgPSBkZWNrLmV2ZW50SW5kZXhbMTRdKSAhPT0gbnVsbCAmJiBfNCAhPT0gdm9pZCAwID8gXzQgOiBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzldID0gKF81ID0gZGVjay5ldmVudEluZGV4WzE1XSkgIT09IG51bGwgJiYgXzUgIT09IHZvaWQgMCA/IF81IDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxMF0gPSAoXzYgPSBkZWNrLmV2ZW50SW5kZXhbMTZdKSAhPT0gbnVsbCAmJiBfNiAhPT0gdm9pZCAwID8gXzYgOiBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzExXSA9IChfNyA9IGRlY2suZXZlbnRJbmRleFsxN10pICE9PSBudWxsICYmIF83ICE9PSB2b2lkIDAgPyBfNyA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLmNoYXJhWzJdID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2suY2hhcmFJbmRleFsyXSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLndlYXBvblsyXSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbMTJdID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxM10gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzE0XSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbMTVdID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxNl0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzE3XSA9IG51bGw7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxzZSB7XG4gICAgICAgIGlmIChkZWNrLmNoYXJhWzFdID09PSB1bmRlZmluZWQgJiYgZGVjay5jaGFyYVsyXSAhPT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICBkZWNrLmNoYXJhWzFdID0gZGVjay5jaGFyYVsyXTtcbiAgICAgICAgICAgIGRlY2suY2hhcmFJbmRleFsxXSA9IChfOCA9IGRlY2suY2hhcmFJbmRleFsyXSkgIT09IG51bGwgJiYgXzggIT09IHZvaWQgMCA/IF84IDogbnVsbDtcbiAgICAgICAgICAgIGRlY2sud2VhcG9uWzFdID0gKF85ID0gZGVjay53ZWFwb25bMl0pICE9PSBudWxsICYmIF85ICE9PSB2b2lkIDAgPyBfOSA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbNl0gPSAoXzEwID0gZGVjay5ldmVudEluZGV4WzEyXSkgIT09IG51bGwgJiYgXzEwICE9PSB2b2lkIDAgPyBfMTAgOiBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzddID0gKF8xMSA9IGRlY2suZXZlbnRJbmRleFsxM10pICE9PSBudWxsICYmIF8xMSAhPT0gdm9pZCAwID8gXzExIDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFs4XSA9IChfMTIgPSBkZWNrLmV2ZW50SW5kZXhbMTRdKSAhPT0gbnVsbCAmJiBfMTIgIT09IHZvaWQgMCA/IF8xMiA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbOV0gPSAoXzEzID0gZGVjay5ldmVudEluZGV4WzE1XSkgIT09IG51bGwgJiYgXzEzICE9PSB2b2lkIDAgPyBfMTMgOiBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzEwXSA9IChfMTQgPSBkZWNrLmV2ZW50SW5kZXhbMTZdKSAhPT0gbnVsbCAmJiBfMTQgIT09IHZvaWQgMCA/IF8xNCA6IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbMTFdID0gKF8xNSA9IGRlY2suZXZlbnRJbmRleFsxN10pICE9PSBudWxsICYmIF8xNSAhPT0gdm9pZCAwID8gXzE1IDogbnVsbDtcbiAgICAgICAgICAgIGRlY2suY2hhcmFbMl0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5jaGFyYUluZGV4WzJdID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2sud2VhcG9uWzJdID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxMl0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzEzXSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbMTRdID0gbnVsbDtcbiAgICAgICAgICAgIGRlY2suZXZlbnRJbmRleFsxNV0gPSBudWxsO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4WzE2XSA9IG51bGw7XG4gICAgICAgICAgICBkZWNrLmV2ZW50SW5kZXhbMTddID0gbnVsbDtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZXR1cm4gZGVjaztcbn1cbiJdLCJuYW1lcyI6W10sInNvdXJjZVJvb3QiOiIifQ==
//# sourceURL=webpack-internal:///1246

}