{
Object.defineProperty(exports, "__esModule", ({ value: true }));
exports.Deck = void 0;
var event_card_1 = __webpack_require__(1794);
var types_1 = __webpack_require__(4778);
var chara_1 = __webpack_require__(4004);
var avatar_item_1 = __webpack_require__(8653);
var logger_1 = __webpack_require__(2614);
var cost_check_1 = __webpack_require__(770);
var deck_check_1 = __webpack_require__(1246);
var types_2 = __webpack_require__(6709);
/**
 * デッキデータのインテグリティを守るため
 *
 * データを参照するとき`CharaID`をもらうので、`DeckData`よりこのClassを使うほうが良い
 *
 * `AvatarItem`・`CharaData`・`EventData`に依存するので、初期化が必要
 *
 * デッキにあるキャラカードは`chara`と`charaIndex`が必要なので、`CharaID`の形のデータが必要
 */
var Deck = /** @class */ (function () {
    function Deck(data) {
        var deck;
        if (data instanceof Deck) {
            deck = data.raw;
        }
        else if ('chara1' in data) {
            deck = Deck.toDeckData(data);
        }
        else {
            deck = data;
        }
        this.data = deck;
    }
    Object.defineProperty(Deck.prototype, "cost", {
        get: function () {
            return this.data.cost;
        },
        enumerable: false,
        configurable: true
    });
    Object.defineProperty(Deck.prototype, "raw", {
        /**
         * データインテグリティのため、コピーをもらう
         */
        get: function () {
            return Deck.copy(this.data);
        },
        enumerable: false,
        configurable: true
    });
    /**
     * @param data 生デッキデータ (SQLから)
     */
    Deck.toDeckData = function (data) {
        var deck = {
            chara: [null, null, null],
            charaIndex: [null, null, null],
            weapon: [null, null, null],
            eventIndex: [
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
            ],
            card_effect: [],
            cost: 0,
        };
        for (var _i = 0, DeckCardIndex_1 = types_2.DeckCardIndex; _i < DeckCardIndex_1.length; _i++) {
            var i = DeckCardIndex_1[_i];
            deck.chara[i - 1] = data["chara".concat(i)];
            deck.charaIndex[i - 1] = data["charaIndex".concat(i)];
            deck.weapon[i - 1] = data["weapon".concat(i)];
        }
        for (var _a = 0, DeckEventIndex_1 = types_2.DeckEventIndex; _a < DeckEventIndex_1.length; _a++) {
            var i = DeckEventIndex_1[_a];
            deck.eventIndex[i - 1] = data["event".concat(i)];
        }
        deck.cost = data.cost;
        return deck;
    };
    /* NOTE(andrew) - Cocos doesn't support structuredClone... */
    Deck.copy = function (source) {
        var _a, _b, _c, _d;
        var copy = {
            chara: [],
            charaIndex: [],
            weapon: [],
            eventIndex: [],
            cost: source.cost,
            card_effect: [],
        };
        for (var i = 0; i < Deck.CARD_COUNT; i++) {
            copy.chara[i] = (_a = source.chara[i]) !== null && _a !== void 0 ? _a : null;
            copy.charaIndex[i] = (_b = source.charaIndex[i]) !== null && _b !== void 0 ? _b : null;
            copy.weapon[i] = (_c = source.weapon[i]) !== null && _c !== void 0 ? _c : null;
        }
        for (var i = 0; i < Deck.CARD_COUNT * Deck.EVENT_CARD_COUNT; i++) {
            copy.eventIndex[i] = (_d = source.eventIndex[i]) !== null && _d !== void 0 ? _d : null;
        }
        return copy;
    };
    /**
     * デッキデータをチェックする。空きがない確認し、コストを更新する
     *
     * check and normalize data
     */
    Deck.prototype.check = function () {
        this.data = (0, deck_check_1.deckcheck)(this.data);
        this.updateCost();
    };
    Deck.prototype.updateCost = function () {
        var cost = (0, cost_check_1.costcheck)(this.data);
        this.data.cost = cost.total;
    };
    /* TODO(andrew): cache w/ dirty flag to avoid recalculating if the deck hasn't changed */
    Deck.prototype.getCost = function () {
        return (0, cost_check_1.costcheck)(this.data);
    };
    Deck.prototype.clear = function () {
        for (var i = 0; i < Deck.CARD_COUNT; i++) {
            this.data.chara[i] = null;
            this.data.charaIndex[i] = null;
            this.data.weapon[i] = null;
            for (var j = 0; j < Deck.EVENT_CARD_COUNT; j++) {
                this.data.eventIndex[i * Deck.EVENT_CARD_COUNT + j] = null;
            }
        }
    };
    /**
     * スロットにあるキャラをもらう
     */
    Deck.prototype.getChara = function (slot) {
        var chara = this.data.chara[slot];
        var charaIndex = this.data.charaIndex[slot];
        if (chara === undefined || charaIndex === undefined) {
            logger_1.Logger.error("bad slot: ".concat(slot));
            return null;
        }
        if (chara === null || charaIndex === null) {
            return null;
        }
        return chara_1.Chara.getID(chara, charaIndex);
    };
    /**
     * スロットにある武器をもらう
     */
    Deck.prototype.getWeapon = function (slot) {
        var weapon = this.data.weapon[slot];
        if (weapon === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slot));
            return null;
        }
        return weapon;
    };
    Deck.prototype.getEvent = function (slotA, slotB) {
        var slot = slotB === undefined ? slotA : slotA * Deck.EVENT_CARD_COUNT + slotB;
        var event = this.data.eventIndex[slot];
        if (event === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slot));
            return null;
        }
        return event;
    };
    /**
     * スロットにあるキャラのイベントをもらう
     */
    Deck.prototype.getEventsForCharaSlot = function (slot) {
        var events = [];
        for (var i = 0; i < Deck.EVENT_CARD_COUNT; i++) {
            var event_1 = this.data.eventIndex[slot * Deck.EVENT_CARD_COUNT + i];
            if (event_1 === undefined) {
                logger_1.Logger.warn("bad slot: ".concat(slot));
                return [null, null, null, null, null, null];
            }
            events.push(event_1);
        }
        return events;
    };
    /**
     * 全てのキャラをもらう
     */
    Deck.prototype.getCharas = function () {
        var _a, _b;
        var charas = [];
        for (var i = 0; i < Deck.CARD_COUNT; i++) {
            var chara = (_a = this.data.chara[i]) !== null && _a !== void 0 ? _a : null;
            var charaIndex = (_b = this.data.charaIndex[i]) !== null && _b !== void 0 ? _b : null;
            if (chara === null || charaIndex === null) {
                charas.push(null);
                continue;
            }
            charas.push(chara_1.Chara.getID(chara, charaIndex));
        }
        return charas;
    };
    /**
     * 全ての武器をもらう
     */
    Deck.prototype.getWeapons = function () {
        var _a;
        var weapons = [];
        for (var i = 0; i < Deck.CARD_COUNT; i++) {
            var weapon = (_a = this.data.weapon[i]) !== null && _a !== void 0 ? _a : null;
            weapons.push(weapon);
        }
        return weapons;
    };
    /**
     * 全てのイベントをもらう
     */
    Deck.prototype.getEvents = function () {
        var _a;
        var events = [];
        for (var i = 0; i < Deck.CARD_COUNT * Deck.EVENT_CARD_COUNT; i++) {
            var event_2 = (_a = this.data.eventIndex[i]) !== null && _a !== void 0 ? _a : null;
            events.push(event_2);
        }
        return events;
    };
    /**
     * @param slot 入れるスロット
     * @param charaID 入れるキャラの`CharaID`
     * @param [ignore=null] 条件に無視するスロット (あれば)
     *
     * @returns デッキにキャラが入れるかどうか
     */
    // eslint-disable-next-line complexity
    Deck.prototype.isValidCharaSlot = function (slot, charaID, ignore) {
        var _a, _b;
        if (ignore === void 0) { ignore = null; }
        /* TODO - check the bears, duplicate cards, etc. */
        if (charaID.type !== 'chara' && slot === 0) {
            return false;
        }
        for (var i = 0; i < Deck.CARD_COUNT; i++) {
            if (i === slot) {
                continue;
            }
            if (i === ignore) {
                continue;
            }
            var otherChara = (_a = this.data.chara[i]) !== null && _a !== void 0 ? _a : null;
            var otherIndex = (_b = this.data.charaIndex[i]) !== null && _b !== void 0 ? _b : null;
            if (otherChara === null || otherIndex === null) {
                continue;
            }
            var baseChara = charaID.chara.split('_')[0];
            var baseOther = otherChara.split('_')[0];
            if (baseChara === baseOther) {
                return false;
            }
            if ((baseChara === 'cc055' || baseChara === 'cc056')
                && (baseOther === 'cc055' || baseOther === 'cc056')) {
                return false;
            }
        }
        return true;
    };
    /**
     * @param slot 入れるスロット
     * @param charaID 入れるキャラの`CharaID`
     * @returns デッキにキャラを入れたかどうか
     */
    Deck.prototype.addChara = function (slot, charaID) {
        var currentChara = this.data.chara[slot];
        var currentCharaIndex = this.data.charaIndex[slot];
        if (currentChara === undefined || currentCharaIndex === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slot));
            return false;
        }
        if (currentChara !== null || currentCharaIndex !== null) {
            logger_1.Logger.warn("chara already exists: slot=".concat(slot, ", chara=").concat(currentChara, ", charaIndex=").concat(currentCharaIndex));
            return false;
        }
        var isValid = this.isValidCharaSlot(slot, charaID);
        if (!isValid) {
            return false;
        }
        this.data.chara[slot] = charaID.chara;
        this.data.charaIndex[slot] = charaID.charaIndex;
        return true;
    };
    /**
     * @param slot 抜くスロット
     * @returns デッキからキャラを抜いたかどうか
     */
    Deck.prototype.removeChara = function (slot) {
        var chara = this.data.chara[slot];
        var charaIndex = this.data.charaIndex[slot];
        if (chara === undefined || charaIndex === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slot));
            return false;
        }
        if (chara === null || charaIndex === null) {
            return false;
        }
        this.data.chara[slot] = null;
        this.data.charaIndex[slot] = null;
        this.data.weapon[slot] = null;
        for (var i = 0; i < Deck.EVENT_CARD_COUNT; i++) {
            var eventSlot = slot * Deck.EVENT_CARD_COUNT + i;
            this.data.eventIndex[eventSlot] = null;
        }
        return true;
    };
    /* FIXME(andrew) - rewrite with smaller operations */
    /**
     * @param slotA スロットA
     * @param slotB スロットA
     *
     * @returns キャラを交代したどうか
     *
     * isValidCharaSlot()を使う
     */
    // eslint-disable-next-line complexity
    Deck.prototype.swapCharas = function (slotA, slotB) {
        if (slotA === slotB) {
            return false;
        }
        var charaA = this.data.chara[slotA];
        var charaB = this.data.chara[slotB];
        var charaIndexA = this.data.charaIndex[slotA];
        var charaIndexB = this.data.charaIndex[slotB];
        if (charaA === undefined || charaIndexA === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slotA));
            return false;
        }
        if (charaB === undefined || charaIndexB === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slotB));
            return false;
        }
        if (charaA === null || charaIndexA === null) {
            logger_1.Logger.warn("slotA chara doesn't exist: ".concat(slotA));
            return false;
        }
        var charaIDA = chara_1.Chara.getID(charaA, charaIndexA);
        var isValidPlace = this.isValidCharaSlot(slotB, charaIDA, slotA);
        var isValidSwap = true;
        if (charaB !== null && charaIndexB !== null) {
            var charaIDB = chara_1.Chara.getID(charaB, charaIndexB);
            isValidSwap = this.isValidCharaSlot(slotA, charaIDB, slotB);
        }
        if (!isValidPlace || !isValidSwap) {
            return false;
        }
        this.data.chara[slotA] = charaB;
        this.data.chara[slotB] = charaA;
        this.data.charaIndex[slotA] = charaIndexB;
        this.data.charaIndex[slotB] = charaIndexA;
        var weaponA = this.data.weapon[slotA];
        var weaponB = this.data.weapon[slotB];
        if (weaponA === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slotA));
            return false;
        }
        if (weaponB === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slotB));
            return false;
        }
        var eventA = [];
        var eventB = [];
        for (var i = 0; i < Deck.EVENT_CARD_COUNT; i++) {
            var eA = this.data.eventIndex[Deck.EVENT_CARD_COUNT * slotA + i];
            var eB = this.data.eventIndex[Deck.EVENT_CARD_COUNT * slotB + i];
            if (eA === undefined) {
                logger_1.Logger.warn("bad slot: ".concat(slotA));
                return false;
            }
            if (eB === undefined) {
                logger_1.Logger.warn("bad slot: ".concat(slotA));
                return false;
            }
            eventA.push(eA);
            eventB.push(eB);
        }
        this.data.weapon[slotA] = weaponB;
        this.data.weapon[slotB] = weaponA;
        for (var i = 0; i < Deck.EVENT_CARD_COUNT; i++) {
            this.data.eventIndex[Deck.EVENT_CARD_COUNT * slotA + i] = eventB[i];
            this.data.eventIndex[Deck.EVENT_CARD_COUNT * slotB + i] = eventA[i];
        }
        return true;
    };
    /**
     * @param slot 入れるスロット
     * @param weaponIndex 入れる武器のインデックス
     *
     * @returns デッキに武器が入れるかどうか
     */
    Deck.prototype.isValidWeaponSlot = function (slot, weaponIndex) {
        var chara = this.data.chara[slot];
        var charaIndex = this.data.charaIndex[slot];
        if (chara === null || chara === undefined
            || charaIndex === null || charaIndex === undefined) {
            return false;
        }
        var weaponData = avatar_item_1.AvatarItem.get('weapon', weaponIndex);
        if (weaponData === null) {
            logger_1.Logger.warn("unknown weapon: ".concat(weaponIndex));
            return false;
        }
        if (weaponData.chara === null) {
            return true;
        }
        return weaponData.chara === chara;
    };
    /**
     * @param slot 入れるスロット
     * @param weaponIndex 入れる武器のインデックス
     * @returns デッキに武器を入れたかどうか
     */
    Deck.prototype.addWeapon = function (slot, weaponIndex) {
        var currentWeapon = this.data.weapon[slot];
        if (currentWeapon === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slot));
            return false;
        }
        if (currentWeapon !== null) {
            logger_1.Logger.warn("weapon already exists: slot=".concat(slot, ", weapon=").concat(currentWeapon));
            return false;
        }
        var isValid = this.isValidWeaponSlot(slot, weaponIndex);
        if (!isValid) {
            return false;
        }
        this.data.weapon[slot] = weaponIndex;
        return true;
    };
    /**
     * @param slot 抜くスロット
     * @returns デッキから武器を抜いたかどうか
     */
    Deck.prototype.removeWeapon = function (slot) {
        var weapon = this.data.weapon[slot];
        if (weapon === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slot));
            return false;
        }
        if (weapon === null) {
            return false;
        }
        this.data.weapon[slot] = null;
        return true;
    };
    /**
     * @param slotA スロットA
     * @param slotB スロットA
     *
     * @returns 武器を交代したどうか
     *
     * isValidWeaponSlot()を使う
     */
    Deck.prototype.swapWeapons = function (slotA, slotB) {
        if (slotA === slotB) {
            return false;
        }
        var weaponA = this.data.weapon[slotA];
        var weaponB = this.data.weapon[slotB];
        if (weaponA === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slotA));
            return false;
        }
        if (weaponB === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slotB));
            return false;
        }
        if (weaponA === null) {
            logger_1.Logger.warn("slotA weapon doesn't exist: ".concat(slotA));
            return false;
        }
        var isValidPlace = this.isValidWeaponSlot(slotB, weaponA);
        var isValidSwap = weaponB === null ? true : this.isValidWeaponSlot(slotA, weaponB);
        if (!isValidPlace || !isValidSwap) {
            return false;
        }
        this.data.weapon[slotA] = weaponB;
        this.data.weapon[slotB] = weaponA;
        return true;
    };
    /**
     * @param slot 入れるスロット
     * @param eventIndex 入れるイベントのインデックス
     *
     * @returns デッキにイベントが入れるかどうか
     */
    Deck.prototype.isValidEventSlot = function (slot, eventIndex) {
        var charaSlot = Math.floor(slot / Deck.EVENT_CARD_COUNT);
        var chara = this.data.chara[charaSlot];
        var charaIndex = this.data.charaIndex[charaSlot];
        if (chara === null || chara === undefined
            || charaIndex === null || charaIndex === undefined) {
            return false;
        }
        var asset = chara_1.Chara.getAsset(chara, charaIndex);
        if (asset === null) {
            logger_1.Logger.warn("unknown chara: chara=".concat(chara, ", charaIndex=").concat(charaIndex));
            return false;
        }
        var eventSlot = slot % Deck.EVENT_CARD_COUNT;
        var slotType = asset.slot[eventSlot];
        if (slotType === undefined) {
            return false;
        }
        var eventData = event_card_1.EventData.get(eventIndex);
        if (eventData === null) {
            logger_1.Logger.warn("unknown event card: ".concat(eventIndex));
            return false;
        }
        if (eventData.type === types_1.EventCardType.ANY) {
            return true;
        }
        return eventData.type === slotType;
    };
    /**
     * @param slot 入れるスロット
     * @param eventIndex 入れるイベントのインデックス
     * @returns デッキにイベントを入れたかどうか
     */
    Deck.prototype.addEvent = function (slot, eventIndex) {
        var currentEvent = this.data.eventIndex[slot];
        if (currentEvent === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slot));
            return false;
        }
        if (currentEvent !== null) {
            logger_1.Logger.warn("weapon already exists: slot=".concat(slot, ", event=").concat(currentEvent));
            return false;
        }
        var isValid = this.isValidEventSlot(slot, eventIndex);
        if (!isValid) {
            return false;
        }
        this.data.eventIndex[slot] = eventIndex;
        return true;
    };
    /**
     * @param slot 抜くスロット
     * @returns デッキからイベントを抜いたかどうか
     */
    Deck.prototype.removeEvent = function (slot) {
        var event = this.data.eventIndex[slot];
        if (event === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slot));
            return false;
        }
        if (event === null) {
            return false;
        }
        this.data.eventIndex[slot] = null;
        return true;
    };
    /**
     * @param slotA スロットA
     * @param slotB スロットA
     *
     * @returns イベントを交代したどうか
     *
     * isValidEventSlot()を使う
     */
    Deck.prototype.swapEvents = function (slotA, slotB) {
        if (slotA === slotB) {
            return false;
        }
        var eventA = this.data.eventIndex[slotA];
        var eventB = this.data.eventIndex[slotB];
        if (eventA === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slotA));
            return false;
        }
        if (eventB === undefined) {
            logger_1.Logger.warn("bad slot: ".concat(slotB));
            return false;
        }
        if (eventA === null) {
            logger_1.Logger.warn("slotA event doesn't exist: ".concat(slotA));
            return false;
        }
        var isValidPlace = this.isValidEventSlot(slotB, eventA);
        var isValidSwap = eventB === null ? true : this.isValidEventSlot(slotA, eventB);
        if (!isValidPlace || !isValidSwap) {
            return false;
        }
        this.data.eventIndex[slotA] = eventB;
        this.data.eventIndex[slotB] = eventA;
        return true;
    };
    Deck.CARD_COUNT = 3;
    Deck.EVENT_CARD_COUNT = 6;
    return Deck;
}());
exports.Deck = Deck;
//# sourceURL=[module]
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoiMTYxOS5qcyIsIm1hcHBpbmdzIjoiQUFBYTtBQUNiLDhDQUE2QyxFQUFFLGFBQWEsRUFBQztBQUM3RCxZQUFZO0FBQ1osbUJBQW1CLG1CQUFPLENBQUMsSUFBb0I7QUFDL0MsY0FBYyxtQkFBTyxDQUFDLElBQWU7QUFDckMsY0FBYyxtQkFBTyxDQUFDLElBQVU7QUFDaEMsb0JBQW9CLG1CQUFPLENBQUMsSUFBcUI7QUFDakQsZUFBZSxtQkFBTyxDQUFDLElBQVc7QUFDbEMsbUJBQW1CLG1CQUFPLENBQUMsR0FBYztBQUN6QyxtQkFBbUIsbUJBQU8sQ0FBQyxJQUFjO0FBQ3pDLGNBQWMsbUJBQU8sQ0FBQyxJQUFTO0FBQy9CO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSxTQUFTO0FBQ1Q7QUFDQTtBQUNBLEtBQUs7QUFDTDtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSxTQUFTO0FBQ1Q7QUFDQTtBQUNBLEtBQUs7QUFDTDtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBLGtFQUFrRSw2QkFBNkI7QUFDL0Y7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBLG9FQUFvRSw4QkFBOEI7QUFDbEc7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBLHdCQUF3QixxQkFBcUI7QUFDN0M7QUFDQTtBQUNBO0FBQ0E7QUFDQSx3QkFBd0IsNkNBQTZDO0FBQ3JFO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0Esd0JBQXdCLHFCQUFxQjtBQUM3QztBQUNBO0FBQ0E7QUFDQSw0QkFBNEIsMkJBQTJCO0FBQ3ZEO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSx3QkFBd0IsMkJBQTJCO0FBQ25EO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBLHdCQUF3QixxQkFBcUI7QUFDN0M7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSx3QkFBd0IscUJBQXFCO0FBQzdDO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSx3QkFBd0IsNkNBQTZDO0FBQ3JFO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBLGlDQUFpQztBQUNqQztBQUNBO0FBQ0E7QUFDQTtBQUNBLHdCQUF3QixxQkFBcUI7QUFDN0M7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSx3QkFBd0IsMkJBQTJCO0FBQ25EO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0Esd0JBQXdCLDJCQUEyQjtBQUNuRDtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSx3QkFBd0IsMkJBQTJCO0FBQ25EO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0EsQ0FBQztBQUNELFlBQVkiLCJzb3VyY2VzIjpbIndlYnBhY2s6Ly91bmxpZ2h0LWNvbW1vbi8uL3NyYy9kZWNrL2RlY2sudHM/NmNlYSJdLCJzb3VyY2VzQ29udGVudCI6WyJcInVzZSBzdHJpY3RcIjtcbk9iamVjdC5kZWZpbmVQcm9wZXJ0eShleHBvcnRzLCBcIl9fZXNNb2R1bGVcIiwgeyB2YWx1ZTogdHJ1ZSB9KTtcbmV4cG9ydHMuRGVjayA9IHZvaWQgMDtcbnZhciBldmVudF9jYXJkXzEgPSByZXF1aXJlKFwiLi4vY2FyZC9ldmVudC1jYXJkXCIpO1xudmFyIHR5cGVzXzEgPSByZXF1aXJlKFwiLi4vY2FyZC90eXBlc1wiKTtcbnZhciBjaGFyYV8xID0gcmVxdWlyZShcIi4uL2NoYXJhXCIpO1xudmFyIGF2YXRhcl9pdGVtXzEgPSByZXF1aXJlKFwiLi4vaXRlbS9hdmF0YXItaXRlbVwiKTtcbnZhciBsb2dnZXJfMSA9IHJlcXVpcmUoXCIuLi9sb2dnZXJcIik7XG52YXIgY29zdF9jaGVja18xID0gcmVxdWlyZShcIi4vY29zdC1jaGVja1wiKTtcbnZhciBkZWNrX2NoZWNrXzEgPSByZXF1aXJlKFwiLi9kZWNrLWNoZWNrXCIpO1xudmFyIHR5cGVzXzIgPSByZXF1aXJlKFwiLi90eXBlc1wiKTtcbi8qKlxuICog44OH44OD44Kt44OH44O844K/44Gu44Kk44Oz44OG44Kw44Oq44OG44Kj44KS5a6I44KL44Gf44KBXG4gKlxuICog44OH44O844K/44KS5Y+C54Wn44GZ44KL44Go44GNYENoYXJhSURg44KS44KC44KJ44GG44Gu44Gn44CBYERlY2tEYXRhYOOCiOOCiuOBk+OBrkNsYXNz44KS5L2/44GG44G744GG44GM6Imv44GEXG4gKlxuICogYEF2YXRhckl0ZW1g44O7YENoYXJhRGF0YWDjg7tgRXZlbnREYXRhYOOBq+S+neWtmOOBmeOCi+OBruOBp+OAgeWIneacn+WMluOBjOW/heimgVxuICpcbiAqIOODh+ODg+OCreOBq+OBguOCi+OCreODo+ODqeOCq+ODvOODieOBr2BjaGFyYWDjgahgY2hhcmFJbmRleGDjgYzlv4XopoHjgarjga7jgafjgIFgQ2hhcmFJRGDjga7lvaLjga7jg4fjg7zjgr/jgYzlv4XopoFcbiAqL1xudmFyIERlY2sgPSAvKiogQGNsYXNzICovIChmdW5jdGlvbiAoKSB7XG4gICAgZnVuY3Rpb24gRGVjayhkYXRhKSB7XG4gICAgICAgIHZhciBkZWNrO1xuICAgICAgICBpZiAoZGF0YSBpbnN0YW5jZW9mIERlY2spIHtcbiAgICAgICAgICAgIGRlY2sgPSBkYXRhLnJhdztcbiAgICAgICAgfVxuICAgICAgICBlbHNlIGlmICgnY2hhcmExJyBpbiBkYXRhKSB7XG4gICAgICAgICAgICBkZWNrID0gRGVjay50b0RlY2tEYXRhKGRhdGEpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgZGVjayA9IGRhdGE7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5kYXRhID0gZGVjaztcbiAgICB9XG4gICAgT2JqZWN0LmRlZmluZVByb3BlcnR5KERlY2sucHJvdG90eXBlLCBcImNvc3RcIiwge1xuICAgICAgICBnZXQ6IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLmRhdGEuY29zdDtcbiAgICAgICAgfSxcbiAgICAgICAgZW51bWVyYWJsZTogZmFsc2UsXG4gICAgICAgIGNvbmZpZ3VyYWJsZTogdHJ1ZVxuICAgIH0pO1xuICAgIE9iamVjdC5kZWZpbmVQcm9wZXJ0eShEZWNrLnByb3RvdHlwZSwgXCJyYXdcIiwge1xuICAgICAgICAvKipcbiAgICAgICAgICog44OH44O844K/44Kk44Oz44OG44Kw44Oq44OG44Kj44Gu44Gf44KB44CB44Kz44OU44O844KS44KC44KJ44GGXG4gICAgICAgICAqL1xuICAgICAgICBnZXQ6IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHJldHVybiBEZWNrLmNvcHkodGhpcy5kYXRhKTtcbiAgICAgICAgfSxcbiAgICAgICAgZW51bWVyYWJsZTogZmFsc2UsXG4gICAgICAgIGNvbmZpZ3VyYWJsZTogdHJ1ZVxuICAgIH0pO1xuICAgIC8qKlxuICAgICAqIEBwYXJhbSBkYXRhIOeUn+ODh+ODg+OCreODh+ODvOOCvyAoU1FM44GL44KJKVxuICAgICAqL1xuICAgIERlY2sudG9EZWNrRGF0YSA9IGZ1bmN0aW9uIChkYXRhKSB7XG4gICAgICAgIHZhciBkZWNrID0ge1xuICAgICAgICAgICAgY2hhcmE6IFtudWxsLCBudWxsLCBudWxsXSxcbiAgICAgICAgICAgIGNoYXJhSW5kZXg6IFtudWxsLCBudWxsLCBudWxsXSxcbiAgICAgICAgICAgIHdlYXBvbjogW251bGwsIG51bGwsIG51bGxdLFxuICAgICAgICAgICAgZXZlbnRJbmRleDogW1xuICAgICAgICAgICAgICAgIG51bGwsXG4gICAgICAgICAgICAgICAgbnVsbCxcbiAgICAgICAgICAgICAgICBudWxsLFxuICAgICAgICAgICAgICAgIG51bGwsXG4gICAgICAgICAgICAgICAgbnVsbCxcbiAgICAgICAgICAgICAgICBudWxsLFxuICAgICAgICAgICAgICAgIG51bGwsXG4gICAgICAgICAgICAgICAgbnVsbCxcbiAgICAgICAgICAgICAgICBudWxsLFxuICAgICAgICAgICAgICAgIG51bGwsXG4gICAgICAgICAgICAgICAgbnVsbCxcbiAgICAgICAgICAgICAgICBudWxsLFxuICAgICAgICAgICAgICAgIG51bGwsXG4gICAgICAgICAgICAgICAgbnVsbCxcbiAgICAgICAgICAgICAgICBudWxsLFxuICAgICAgICAgICAgICAgIG51bGwsXG4gICAgICAgICAgICAgICAgbnVsbCxcbiAgICAgICAgICAgICAgICBudWxsLFxuICAgICAgICAgICAgXSxcbiAgICAgICAgICAgIGNhcmRfZWZmZWN0OiBbXSxcbiAgICAgICAgICAgIGNvc3Q6IDAsXG4gICAgICAgIH07XG4gICAgICAgIGZvciAodmFyIF9pID0gMCwgRGVja0NhcmRJbmRleF8xID0gdHlwZXNfMi5EZWNrQ2FyZEluZGV4OyBfaSA8IERlY2tDYXJkSW5kZXhfMS5sZW5ndGg7IF9pKyspIHtcbiAgICAgICAgICAgIHZhciBpID0gRGVja0NhcmRJbmRleF8xW19pXTtcbiAgICAgICAgICAgIGRlY2suY2hhcmFbaSAtIDFdID0gZGF0YVtcImNoYXJhXCIuY29uY2F0KGkpXTtcbiAgICAgICAgICAgIGRlY2suY2hhcmFJbmRleFtpIC0gMV0gPSBkYXRhW1wiY2hhcmFJbmRleFwiLmNvbmNhdChpKV07XG4gICAgICAgICAgICBkZWNrLndlYXBvbltpIC0gMV0gPSBkYXRhW1wid2VhcG9uXCIuY29uY2F0KGkpXTtcbiAgICAgICAgfVxuICAgICAgICBmb3IgKHZhciBfYSA9IDAsIERlY2tFdmVudEluZGV4XzEgPSB0eXBlc18yLkRlY2tFdmVudEluZGV4OyBfYSA8IERlY2tFdmVudEluZGV4XzEubGVuZ3RoOyBfYSsrKSB7XG4gICAgICAgICAgICB2YXIgaSA9IERlY2tFdmVudEluZGV4XzFbX2FdO1xuICAgICAgICAgICAgZGVjay5ldmVudEluZGV4W2kgLSAxXSA9IGRhdGFbXCJldmVudFwiLmNvbmNhdChpKV07XG4gICAgICAgIH1cbiAgICAgICAgZGVjay5jb3N0ID0gZGF0YS5jb3N0O1xuICAgICAgICByZXR1cm4gZGVjaztcbiAgICB9O1xuICAgIC8qIE5PVEUoYW5kcmV3KSAtIENvY29zIGRvZXNuJ3Qgc3VwcG9ydCBzdHJ1Y3R1cmVkQ2xvbmUuLi4gKi9cbiAgICBEZWNrLmNvcHkgPSBmdW5jdGlvbiAoc291cmNlKSB7XG4gICAgICAgIHZhciBfYSwgX2IsIF9jLCBfZDtcbiAgICAgICAgdmFyIGNvcHkgPSB7XG4gICAgICAgICAgICBjaGFyYTogW10sXG4gICAgICAgICAgICBjaGFyYUluZGV4OiBbXSxcbiAgICAgICAgICAgIHdlYXBvbjogW10sXG4gICAgICAgICAgICBldmVudEluZGV4OiBbXSxcbiAgICAgICAgICAgIGNvc3Q6IHNvdXJjZS5jb3N0LFxuICAgICAgICAgICAgY2FyZF9lZmZlY3Q6IFtdLFxuICAgICAgICB9O1xuICAgICAgICBmb3IgKHZhciBpID0gMDsgaSA8IERlY2suQ0FSRF9DT1VOVDsgaSsrKSB7XG4gICAgICAgICAgICBjb3B5LmNoYXJhW2ldID0gKF9hID0gc291cmNlLmNoYXJhW2ldKSAhPT0gbnVsbCAmJiBfYSAhPT0gdm9pZCAwID8gX2EgOiBudWxsO1xuICAgICAgICAgICAgY29weS5jaGFyYUluZGV4W2ldID0gKF9iID0gc291cmNlLmNoYXJhSW5kZXhbaV0pICE9PSBudWxsICYmIF9iICE9PSB2b2lkIDAgPyBfYiA6IG51bGw7XG4gICAgICAgICAgICBjb3B5LndlYXBvbltpXSA9IChfYyA9IHNvdXJjZS53ZWFwb25baV0pICE9PSBudWxsICYmIF9jICE9PSB2b2lkIDAgPyBfYyA6IG51bGw7XG4gICAgICAgIH1cbiAgICAgICAgZm9yICh2YXIgaSA9IDA7IGkgPCBEZWNrLkNBUkRfQ09VTlQgKiBEZWNrLkVWRU5UX0NBUkRfQ09VTlQ7IGkrKykge1xuICAgICAgICAgICAgY29weS5ldmVudEluZGV4W2ldID0gKF9kID0gc291cmNlLmV2ZW50SW5kZXhbaV0pICE9PSBudWxsICYmIF9kICE9PSB2b2lkIDAgPyBfZCA6IG51bGw7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGNvcHk7XG4gICAgfTtcbiAgICAvKipcbiAgICAgKiDjg4fjg4Pjgq3jg4fjg7zjgr/jgpLjg4Hjgqfjg4Pjgq/jgZnjgovjgILnqbrjgY3jgYzjgarjgYTnorroqo3jgZfjgIHjgrPjgrnjg4jjgpLmm7TmlrDjgZnjgotcbiAgICAgKlxuICAgICAqIGNoZWNrIGFuZCBub3JtYWxpemUgZGF0YVxuICAgICAqL1xuICAgIERlY2sucHJvdG90eXBlLmNoZWNrID0gZnVuY3Rpb24gKCkge1xuICAgICAgICB0aGlzLmRhdGEgPSAoMCwgZGVja19jaGVja18xLmRlY2tjaGVjaykodGhpcy5kYXRhKTtcbiAgICAgICAgdGhpcy51cGRhdGVDb3N0KCk7XG4gICAgfTtcbiAgICBEZWNrLnByb3RvdHlwZS51cGRhdGVDb3N0ID0gZnVuY3Rpb24gKCkge1xuICAgICAgICB2YXIgY29zdCA9ICgwLCBjb3N0X2NoZWNrXzEuY29zdGNoZWNrKSh0aGlzLmRhdGEpO1xuICAgICAgICB0aGlzLmRhdGEuY29zdCA9IGNvc3QudG90YWw7XG4gICAgfTtcbiAgICAvKiBUT0RPKGFuZHJldyk6IGNhY2hlIHcvIGRpcnR5IGZsYWcgdG8gYXZvaWQgcmVjYWxjdWxhdGluZyBpZiB0aGUgZGVjayBoYXNuJ3QgY2hhbmdlZCAqL1xuICAgIERlY2sucHJvdG90eXBlLmdldENvc3QgPSBmdW5jdGlvbiAoKSB7XG4gICAgICAgIHJldHVybiAoMCwgY29zdF9jaGVja18xLmNvc3RjaGVjaykodGhpcy5kYXRhKTtcbiAgICB9O1xuICAgIERlY2sucHJvdG90eXBlLmNsZWFyID0gZnVuY3Rpb24gKCkge1xuICAgICAgICBmb3IgKHZhciBpID0gMDsgaSA8IERlY2suQ0FSRF9DT1VOVDsgaSsrKSB7XG4gICAgICAgICAgICB0aGlzLmRhdGEuY2hhcmFbaV0gPSBudWxsO1xuICAgICAgICAgICAgdGhpcy5kYXRhLmNoYXJhSW5kZXhbaV0gPSBudWxsO1xuICAgICAgICAgICAgdGhpcy5kYXRhLndlYXBvbltpXSA9IG51bGw7XG4gICAgICAgICAgICBmb3IgKHZhciBqID0gMDsgaiA8IERlY2suRVZFTlRfQ0FSRF9DT1VOVDsgaisrKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kYXRhLmV2ZW50SW5kZXhbaSAqIERlY2suRVZFTlRfQ0FSRF9DT1VOVCArIGpdID0gbnVsbDtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH07XG4gICAgLyoqXG4gICAgICog44K544Ot44OD44OI44Gr44GC44KL44Kt44Oj44Op44KS44KC44KJ44GGXG4gICAgICovXG4gICAgRGVjay5wcm90b3R5cGUuZ2V0Q2hhcmEgPSBmdW5jdGlvbiAoc2xvdCkge1xuICAgICAgICB2YXIgY2hhcmEgPSB0aGlzLmRhdGEuY2hhcmFbc2xvdF07XG4gICAgICAgIHZhciBjaGFyYUluZGV4ID0gdGhpcy5kYXRhLmNoYXJhSW5kZXhbc2xvdF07XG4gICAgICAgIGlmIChjaGFyYSA9PT0gdW5kZWZpbmVkIHx8IGNoYXJhSW5kZXggPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLmVycm9yKFwiYmFkIHNsb3Q6IFwiLmNvbmNhdChzbG90KSk7XG4gICAgICAgICAgICByZXR1cm4gbnVsbDtcbiAgICAgICAgfVxuICAgICAgICBpZiAoY2hhcmEgPT09IG51bGwgfHwgY2hhcmFJbmRleCA9PT0gbnVsbCkge1xuICAgICAgICAgICAgcmV0dXJuIG51bGw7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGNoYXJhXzEuQ2hhcmEuZ2V0SUQoY2hhcmEsIGNoYXJhSW5kZXgpO1xuICAgIH07XG4gICAgLyoqXG4gICAgICog44K544Ot44OD44OI44Gr44GC44KL5q2m5Zmo44KS44KC44KJ44GGXG4gICAgICovXG4gICAgRGVjay5wcm90b3R5cGUuZ2V0V2VhcG9uID0gZnVuY3Rpb24gKHNsb3QpIHtcbiAgICAgICAgdmFyIHdlYXBvbiA9IHRoaXMuZGF0YS53ZWFwb25bc2xvdF07XG4gICAgICAgIGlmICh3ZWFwb24gPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJiYWQgc2xvdDogXCIuY29uY2F0KHNsb3QpKTtcbiAgICAgICAgICAgIHJldHVybiBudWxsO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiB3ZWFwb247XG4gICAgfTtcbiAgICBEZWNrLnByb3RvdHlwZS5nZXRFdmVudCA9IGZ1bmN0aW9uIChzbG90QSwgc2xvdEIpIHtcbiAgICAgICAgdmFyIHNsb3QgPSBzbG90QiA9PT0gdW5kZWZpbmVkID8gc2xvdEEgOiBzbG90QSAqIERlY2suRVZFTlRfQ0FSRF9DT1VOVCArIHNsb3RCO1xuICAgICAgICB2YXIgZXZlbnQgPSB0aGlzLmRhdGEuZXZlbnRJbmRleFtzbG90XTtcbiAgICAgICAgaWYgKGV2ZW50ID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci53YXJuKFwiYmFkIHNsb3Q6IFwiLmNvbmNhdChzbG90KSk7XG4gICAgICAgICAgICByZXR1cm4gbnVsbDtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZXZlbnQ7XG4gICAgfTtcbiAgICAvKipcbiAgICAgKiDjgrnjg63jg4Pjg4jjgavjgYLjgovjgq3jg6Pjg6njga7jgqTjg5njg7Pjg4jjgpLjgoLjgonjgYZcbiAgICAgKi9cbiAgICBEZWNrLnByb3RvdHlwZS5nZXRFdmVudHNGb3JDaGFyYVNsb3QgPSBmdW5jdGlvbiAoc2xvdCkge1xuICAgICAgICB2YXIgZXZlbnRzID0gW107XG4gICAgICAgIGZvciAodmFyIGkgPSAwOyBpIDwgRGVjay5FVkVOVF9DQVJEX0NPVU5UOyBpKyspIHtcbiAgICAgICAgICAgIHZhciBldmVudF8xID0gdGhpcy5kYXRhLmV2ZW50SW5kZXhbc2xvdCAqIERlY2suRVZFTlRfQ0FSRF9DT1VOVCArIGldO1xuICAgICAgICAgICAgaWYgKGV2ZW50XzEgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci53YXJuKFwiYmFkIHNsb3Q6IFwiLmNvbmNhdChzbG90KSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuIFtudWxsLCBudWxsLCBudWxsLCBudWxsLCBudWxsLCBudWxsXTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGV2ZW50cy5wdXNoKGV2ZW50XzEpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudHM7XG4gICAgfTtcbiAgICAvKipcbiAgICAgKiDlhajjgabjga7jgq3jg6Pjg6njgpLjgoLjgonjgYZcbiAgICAgKi9cbiAgICBEZWNrLnByb3RvdHlwZS5nZXRDaGFyYXMgPSBmdW5jdGlvbiAoKSB7XG4gICAgICAgIHZhciBfYSwgX2I7XG4gICAgICAgIHZhciBjaGFyYXMgPSBbXTtcbiAgICAgICAgZm9yICh2YXIgaSA9IDA7IGkgPCBEZWNrLkNBUkRfQ09VTlQ7IGkrKykge1xuICAgICAgICAgICAgdmFyIGNoYXJhID0gKF9hID0gdGhpcy5kYXRhLmNoYXJhW2ldKSAhPT0gbnVsbCAmJiBfYSAhPT0gdm9pZCAwID8gX2EgOiBudWxsO1xuICAgICAgICAgICAgdmFyIGNoYXJhSW5kZXggPSAoX2IgPSB0aGlzLmRhdGEuY2hhcmFJbmRleFtpXSkgIT09IG51bGwgJiYgX2IgIT09IHZvaWQgMCA/IF9iIDogbnVsbDtcbiAgICAgICAgICAgIGlmIChjaGFyYSA9PT0gbnVsbCB8fCBjaGFyYUluZGV4ID09PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgY2hhcmFzLnB1c2gobnVsbCk7XG4gICAgICAgICAgICAgICAgY29udGludWU7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjaGFyYXMucHVzaChjaGFyYV8xLkNoYXJhLmdldElEKGNoYXJhLCBjaGFyYUluZGV4KSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGNoYXJhcztcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIOWFqOOBpuOBruatpuWZqOOCkuOCguOCieOBhlxuICAgICAqL1xuICAgIERlY2sucHJvdG90eXBlLmdldFdlYXBvbnMgPSBmdW5jdGlvbiAoKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgdmFyIHdlYXBvbnMgPSBbXTtcbiAgICAgICAgZm9yICh2YXIgaSA9IDA7IGkgPCBEZWNrLkNBUkRfQ09VTlQ7IGkrKykge1xuICAgICAgICAgICAgdmFyIHdlYXBvbiA9IChfYSA9IHRoaXMuZGF0YS53ZWFwb25baV0pICE9PSBudWxsICYmIF9hICE9PSB2b2lkIDAgPyBfYSA6IG51bGw7XG4gICAgICAgICAgICB3ZWFwb25zLnB1c2god2VhcG9uKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gd2VhcG9ucztcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIOWFqOOBpuOBruOCpOODmeODs+ODiOOCkuOCguOCieOBhlxuICAgICAqL1xuICAgIERlY2sucHJvdG90eXBlLmdldEV2ZW50cyA9IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICB2YXIgZXZlbnRzID0gW107XG4gICAgICAgIGZvciAodmFyIGkgPSAwOyBpIDwgRGVjay5DQVJEX0NPVU5UICogRGVjay5FVkVOVF9DQVJEX0NPVU5UOyBpKyspIHtcbiAgICAgICAgICAgIHZhciBldmVudF8yID0gKF9hID0gdGhpcy5kYXRhLmV2ZW50SW5kZXhbaV0pICE9PSBudWxsICYmIF9hICE9PSB2b2lkIDAgPyBfYSA6IG51bGw7XG4gICAgICAgICAgICBldmVudHMucHVzaChldmVudF8yKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZXZlbnRzO1xuICAgIH07XG4gICAgLyoqXG4gICAgICogQHBhcmFtIHNsb3Qg5YWl44KM44KL44K544Ot44OD44OIXG4gICAgICogQHBhcmFtIGNoYXJhSUQg5YWl44KM44KL44Kt44Oj44Op44GuYENoYXJhSURgXG4gICAgICogQHBhcmFtIFtpZ25vcmU9bnVsbF0g5p2h5Lu244Gr54Sh6KaW44GZ44KL44K544Ot44OD44OIICjjgYLjgozjgbApXG4gICAgICpcbiAgICAgKiBAcmV0dXJucyDjg4fjg4Pjgq3jgavjgq3jg6Pjg6njgYzlhaXjgozjgovjgYvjganjgYbjgYtcbiAgICAgKi9cbiAgICAvLyBlc2xpbnQtZGlzYWJsZS1uZXh0LWxpbmUgY29tcGxleGl0eVxuICAgIERlY2sucHJvdG90eXBlLmlzVmFsaWRDaGFyYVNsb3QgPSBmdW5jdGlvbiAoc2xvdCwgY2hhcmFJRCwgaWdub3JlKSB7XG4gICAgICAgIHZhciBfYSwgX2I7XG4gICAgICAgIGlmIChpZ25vcmUgPT09IHZvaWQgMCkgeyBpZ25vcmUgPSBudWxsOyB9XG4gICAgICAgIC8qIFRPRE8gLSBjaGVjayB0aGUgYmVhcnMsIGR1cGxpY2F0ZSBjYXJkcywgZXRjLiAqL1xuICAgICAgICBpZiAoY2hhcmFJRC50eXBlICE9PSAnY2hhcmEnICYmIHNsb3QgPT09IDApIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBmb3IgKHZhciBpID0gMDsgaSA8IERlY2suQ0FSRF9DT1VOVDsgaSsrKSB7XG4gICAgICAgICAgICBpZiAoaSA9PT0gc2xvdCkge1xuICAgICAgICAgICAgICAgIGNvbnRpbnVlO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGkgPT09IGlnbm9yZSkge1xuICAgICAgICAgICAgICAgIGNvbnRpbnVlO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIG90aGVyQ2hhcmEgPSAoX2EgPSB0aGlzLmRhdGEuY2hhcmFbaV0pICE9PSBudWxsICYmIF9hICE9PSB2b2lkIDAgPyBfYSA6IG51bGw7XG4gICAgICAgICAgICB2YXIgb3RoZXJJbmRleCA9IChfYiA9IHRoaXMuZGF0YS5jaGFyYUluZGV4W2ldKSAhPT0gbnVsbCAmJiBfYiAhPT0gdm9pZCAwID8gX2IgOiBudWxsO1xuICAgICAgICAgICAgaWYgKG90aGVyQ2hhcmEgPT09IG51bGwgfHwgb3RoZXJJbmRleCA9PT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIGNvbnRpbnVlO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIGJhc2VDaGFyYSA9IGNoYXJhSUQuY2hhcmEuc3BsaXQoJ18nKVswXTtcbiAgICAgICAgICAgIHZhciBiYXNlT3RoZXIgPSBvdGhlckNoYXJhLnNwbGl0KCdfJylbMF07XG4gICAgICAgICAgICBpZiAoYmFzZUNoYXJhID09PSBiYXNlT3RoZXIpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoKGJhc2VDaGFyYSA9PT0gJ2NjMDU1JyB8fCBiYXNlQ2hhcmEgPT09ICdjYzA1NicpXG4gICAgICAgICAgICAgICAgJiYgKGJhc2VPdGhlciA9PT0gJ2NjMDU1JyB8fCBiYXNlT3RoZXIgPT09ICdjYzA1NicpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIHJldHVybiB0cnVlO1xuICAgIH07XG4gICAgLyoqXG4gICAgICogQHBhcmFtIHNsb3Qg5YWl44KM44KL44K544Ot44OD44OIXG4gICAgICogQHBhcmFtIGNoYXJhSUQg5YWl44KM44KL44Kt44Oj44Op44GuYENoYXJhSURgXG4gICAgICogQHJldHVybnMg44OH44OD44Kt44Gr44Kt44Oj44Op44KS5YWl44KM44Gf44GL44Gp44GG44GLXG4gICAgICovXG4gICAgRGVjay5wcm90b3R5cGUuYWRkQ2hhcmEgPSBmdW5jdGlvbiAoc2xvdCwgY2hhcmFJRCkge1xuICAgICAgICB2YXIgY3VycmVudENoYXJhID0gdGhpcy5kYXRhLmNoYXJhW3Nsb3RdO1xuICAgICAgICB2YXIgY3VycmVudENoYXJhSW5kZXggPSB0aGlzLmRhdGEuY2hhcmFJbmRleFtzbG90XTtcbiAgICAgICAgaWYgKGN1cnJlbnRDaGFyYSA9PT0gdW5kZWZpbmVkIHx8IGN1cnJlbnRDaGFyYUluZGV4ID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci53YXJuKFwiYmFkIHNsb3Q6IFwiLmNvbmNhdChzbG90KSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKGN1cnJlbnRDaGFyYSAhPT0gbnVsbCB8fCBjdXJyZW50Q2hhcmFJbmRleCAhPT0gbnVsbCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJjaGFyYSBhbHJlYWR5IGV4aXN0czogc2xvdD1cIi5jb25jYXQoc2xvdCwgXCIsIGNoYXJhPVwiKS5jb25jYXQoY3VycmVudENoYXJhLCBcIiwgY2hhcmFJbmRleD1cIikuY29uY2F0KGN1cnJlbnRDaGFyYUluZGV4KSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgdmFyIGlzVmFsaWQgPSB0aGlzLmlzVmFsaWRDaGFyYVNsb3Qoc2xvdCwgY2hhcmFJRCk7XG4gICAgICAgIGlmICghaXNWYWxpZCkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIHRoaXMuZGF0YS5jaGFyYVtzbG90XSA9IGNoYXJhSUQuY2hhcmE7XG4gICAgICAgIHRoaXMuZGF0YS5jaGFyYUluZGV4W3Nsb3RdID0gY2hhcmFJRC5jaGFyYUluZGV4O1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIEBwYXJhbSBzbG90IOaKnOOBj+OCueODreODg+ODiFxuICAgICAqIEByZXR1cm5zIOODh+ODg+OCreOBi+OCieOCreODo+ODqeOCkuaKnOOBhOOBn+OBi+OBqeOBhuOBi1xuICAgICAqL1xuICAgIERlY2sucHJvdG90eXBlLnJlbW92ZUNoYXJhID0gZnVuY3Rpb24gKHNsb3QpIHtcbiAgICAgICAgdmFyIGNoYXJhID0gdGhpcy5kYXRhLmNoYXJhW3Nsb3RdO1xuICAgICAgICB2YXIgY2hhcmFJbmRleCA9IHRoaXMuZGF0YS5jaGFyYUluZGV4W3Nsb3RdO1xuICAgICAgICBpZiAoY2hhcmEgPT09IHVuZGVmaW5lZCB8fCBjaGFyYUluZGV4ID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci53YXJuKFwiYmFkIHNsb3Q6IFwiLmNvbmNhdChzbG90KSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKGNoYXJhID09PSBudWxsIHx8IGNoYXJhSW5kZXggPT09IG51bGwpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB0aGlzLmRhdGEuY2hhcmFbc2xvdF0gPSBudWxsO1xuICAgICAgICB0aGlzLmRhdGEuY2hhcmFJbmRleFtzbG90XSA9IG51bGw7XG4gICAgICAgIHRoaXMuZGF0YS53ZWFwb25bc2xvdF0gPSBudWxsO1xuICAgICAgICBmb3IgKHZhciBpID0gMDsgaSA8IERlY2suRVZFTlRfQ0FSRF9DT1VOVDsgaSsrKSB7XG4gICAgICAgICAgICB2YXIgZXZlbnRTbG90ID0gc2xvdCAqIERlY2suRVZFTlRfQ0FSRF9DT1VOVCArIGk7XG4gICAgICAgICAgICB0aGlzLmRhdGEuZXZlbnRJbmRleFtldmVudFNsb3RdID0gbnVsbDtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9O1xuICAgIC8qIEZJWE1FKGFuZHJldykgLSByZXdyaXRlIHdpdGggc21hbGxlciBvcGVyYXRpb25zICovXG4gICAgLyoqXG4gICAgICogQHBhcmFtIHNsb3RBIOOCueODreODg+ODiEFcbiAgICAgKiBAcGFyYW0gc2xvdEIg44K544Ot44OD44OIQVxuICAgICAqXG4gICAgICogQHJldHVybnMg44Kt44Oj44Op44KS5Lqk5Luj44GX44Gf44Gp44GG44GLXG4gICAgICpcbiAgICAgKiBpc1ZhbGlkQ2hhcmFTbG90KCnjgpLkvb/jgYZcbiAgICAgKi9cbiAgICAvLyBlc2xpbnQtZGlzYWJsZS1uZXh0LWxpbmUgY29tcGxleGl0eVxuICAgIERlY2sucHJvdG90eXBlLnN3YXBDaGFyYXMgPSBmdW5jdGlvbiAoc2xvdEEsIHNsb3RCKSB7XG4gICAgICAgIGlmIChzbG90QSA9PT0gc2xvdEIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB2YXIgY2hhcmFBID0gdGhpcy5kYXRhLmNoYXJhW3Nsb3RBXTtcbiAgICAgICAgdmFyIGNoYXJhQiA9IHRoaXMuZGF0YS5jaGFyYVtzbG90Ql07XG4gICAgICAgIHZhciBjaGFyYUluZGV4QSA9IHRoaXMuZGF0YS5jaGFyYUluZGV4W3Nsb3RBXTtcbiAgICAgICAgdmFyIGNoYXJhSW5kZXhCID0gdGhpcy5kYXRhLmNoYXJhSW5kZXhbc2xvdEJdO1xuICAgICAgICBpZiAoY2hhcmFBID09PSB1bmRlZmluZWQgfHwgY2hhcmFJbmRleEEgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJiYWQgc2xvdDogXCIuY29uY2F0KHNsb3RBKSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKGNoYXJhQiA9PT0gdW5kZWZpbmVkIHx8IGNoYXJhSW5kZXhCID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci53YXJuKFwiYmFkIHNsb3Q6IFwiLmNvbmNhdChzbG90QikpO1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChjaGFyYUEgPT09IG51bGwgfHwgY2hhcmFJbmRleEEgPT09IG51bGwpIHtcbiAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci53YXJuKFwic2xvdEEgY2hhcmEgZG9lc24ndCBleGlzdDogXCIuY29uY2F0KHNsb3RBKSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgdmFyIGNoYXJhSURBID0gY2hhcmFfMS5DaGFyYS5nZXRJRChjaGFyYUEsIGNoYXJhSW5kZXhBKTtcbiAgICAgICAgdmFyIGlzVmFsaWRQbGFjZSA9IHRoaXMuaXNWYWxpZENoYXJhU2xvdChzbG90QiwgY2hhcmFJREEsIHNsb3RBKTtcbiAgICAgICAgdmFyIGlzVmFsaWRTd2FwID0gdHJ1ZTtcbiAgICAgICAgaWYgKGNoYXJhQiAhPT0gbnVsbCAmJiBjaGFyYUluZGV4QiAhPT0gbnVsbCkge1xuICAgICAgICAgICAgdmFyIGNoYXJhSURCID0gY2hhcmFfMS5DaGFyYS5nZXRJRChjaGFyYUIsIGNoYXJhSW5kZXhCKTtcbiAgICAgICAgICAgIGlzVmFsaWRTd2FwID0gdGhpcy5pc1ZhbGlkQ2hhcmFTbG90KHNsb3RBLCBjaGFyYUlEQiwgc2xvdEIpO1xuICAgICAgICB9XG4gICAgICAgIGlmICghaXNWYWxpZFBsYWNlIHx8ICFpc1ZhbGlkU3dhcCkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIHRoaXMuZGF0YS5jaGFyYVtzbG90QV0gPSBjaGFyYUI7XG4gICAgICAgIHRoaXMuZGF0YS5jaGFyYVtzbG90Ql0gPSBjaGFyYUE7XG4gICAgICAgIHRoaXMuZGF0YS5jaGFyYUluZGV4W3Nsb3RBXSA9IGNoYXJhSW5kZXhCO1xuICAgICAgICB0aGlzLmRhdGEuY2hhcmFJbmRleFtzbG90Ql0gPSBjaGFyYUluZGV4QTtcbiAgICAgICAgdmFyIHdlYXBvbkEgPSB0aGlzLmRhdGEud2VhcG9uW3Nsb3RBXTtcbiAgICAgICAgdmFyIHdlYXBvbkIgPSB0aGlzLmRhdGEud2VhcG9uW3Nsb3RCXTtcbiAgICAgICAgaWYgKHdlYXBvbkEgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJiYWQgc2xvdDogXCIuY29uY2F0KHNsb3RBKSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKHdlYXBvbkIgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJiYWQgc2xvdDogXCIuY29uY2F0KHNsb3RCKSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgdmFyIGV2ZW50QSA9IFtdO1xuICAgICAgICB2YXIgZXZlbnRCID0gW107XG4gICAgICAgIGZvciAodmFyIGkgPSAwOyBpIDwgRGVjay5FVkVOVF9DQVJEX0NPVU5UOyBpKyspIHtcbiAgICAgICAgICAgIHZhciBlQSA9IHRoaXMuZGF0YS5ldmVudEluZGV4W0RlY2suRVZFTlRfQ0FSRF9DT1VOVCAqIHNsb3RBICsgaV07XG4gICAgICAgICAgICB2YXIgZUIgPSB0aGlzLmRhdGEuZXZlbnRJbmRleFtEZWNrLkVWRU5UX0NBUkRfQ09VTlQgKiBzbG90QiArIGldO1xuICAgICAgICAgICAgaWYgKGVBID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIud2FybihcImJhZCBzbG90OiBcIi5jb25jYXQoc2xvdEEpKTtcbiAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoZUIgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci53YXJuKFwiYmFkIHNsb3Q6IFwiLmNvbmNhdChzbG90QSkpO1xuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGV2ZW50QS5wdXNoKGVBKTtcbiAgICAgICAgICAgIGV2ZW50Qi5wdXNoKGVCKTtcbiAgICAgICAgfVxuICAgICAgICB0aGlzLmRhdGEud2VhcG9uW3Nsb3RBXSA9IHdlYXBvbkI7XG4gICAgICAgIHRoaXMuZGF0YS53ZWFwb25bc2xvdEJdID0gd2VhcG9uQTtcbiAgICAgICAgZm9yICh2YXIgaSA9IDA7IGkgPCBEZWNrLkVWRU5UX0NBUkRfQ09VTlQ7IGkrKykge1xuICAgICAgICAgICAgdGhpcy5kYXRhLmV2ZW50SW5kZXhbRGVjay5FVkVOVF9DQVJEX0NPVU5UICogc2xvdEEgKyBpXSA9IGV2ZW50QltpXTtcbiAgICAgICAgICAgIHRoaXMuZGF0YS5ldmVudEluZGV4W0RlY2suRVZFTlRfQ0FSRF9DT1VOVCAqIHNsb3RCICsgaV0gPSBldmVudEFbaV07XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfTtcbiAgICAvKipcbiAgICAgKiBAcGFyYW0gc2xvdCDlhaXjgozjgovjgrnjg63jg4Pjg4hcbiAgICAgKiBAcGFyYW0gd2VhcG9uSW5kZXgg5YWl44KM44KL5q2m5Zmo44Gu44Kk44Oz44OH44OD44Kv44K5XG4gICAgICpcbiAgICAgKiBAcmV0dXJucyDjg4fjg4Pjgq3jgavmrablmajjgYzlhaXjgozjgovjgYvjganjgYbjgYtcbiAgICAgKi9cbiAgICBEZWNrLnByb3RvdHlwZS5pc1ZhbGlkV2VhcG9uU2xvdCA9IGZ1bmN0aW9uIChzbG90LCB3ZWFwb25JbmRleCkge1xuICAgICAgICB2YXIgY2hhcmEgPSB0aGlzLmRhdGEuY2hhcmFbc2xvdF07XG4gICAgICAgIHZhciBjaGFyYUluZGV4ID0gdGhpcy5kYXRhLmNoYXJhSW5kZXhbc2xvdF07XG4gICAgICAgIGlmIChjaGFyYSA9PT0gbnVsbCB8fCBjaGFyYSA9PT0gdW5kZWZpbmVkXG4gICAgICAgICAgICB8fCBjaGFyYUluZGV4ID09PSBudWxsIHx8IGNoYXJhSW5kZXggPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIHZhciB3ZWFwb25EYXRhID0gYXZhdGFyX2l0ZW1fMS5BdmF0YXJJdGVtLmdldCgnd2VhcG9uJywgd2VhcG9uSW5kZXgpO1xuICAgICAgICBpZiAod2VhcG9uRGF0YSA9PT0gbnVsbCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJ1bmtub3duIHdlYXBvbjogXCIuY29uY2F0KHdlYXBvbkluZGV4KSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKHdlYXBvbkRhdGEuY2hhcmEgPT09IG51bGwpIHtcbiAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiB3ZWFwb25EYXRhLmNoYXJhID09PSBjaGFyYTtcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIEBwYXJhbSBzbG90IOWFpeOCjOOCi+OCueODreODg+ODiFxuICAgICAqIEBwYXJhbSB3ZWFwb25JbmRleCDlhaXjgozjgovmrablmajjga7jgqTjg7Pjg4fjg4Pjgq/jgrlcbiAgICAgKiBAcmV0dXJucyDjg4fjg4Pjgq3jgavmrablmajjgpLlhaXjgozjgZ/jgYvjganjgYbjgYtcbiAgICAgKi9cbiAgICBEZWNrLnByb3RvdHlwZS5hZGRXZWFwb24gPSBmdW5jdGlvbiAoc2xvdCwgd2VhcG9uSW5kZXgpIHtcbiAgICAgICAgdmFyIGN1cnJlbnRXZWFwb24gPSB0aGlzLmRhdGEud2VhcG9uW3Nsb3RdO1xuICAgICAgICBpZiAoY3VycmVudFdlYXBvbiA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIud2FybihcImJhZCBzbG90OiBcIi5jb25jYXQoc2xvdCkpO1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChjdXJyZW50V2VhcG9uICE9PSBudWxsKSB7XG4gICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIud2FybihcIndlYXBvbiBhbHJlYWR5IGV4aXN0czogc2xvdD1cIi5jb25jYXQoc2xvdCwgXCIsIHdlYXBvbj1cIikuY29uY2F0KGN1cnJlbnRXZWFwb24pKTtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB2YXIgaXNWYWxpZCA9IHRoaXMuaXNWYWxpZFdlYXBvblNsb3Qoc2xvdCwgd2VhcG9uSW5kZXgpO1xuICAgICAgICBpZiAoIWlzVmFsaWQpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB0aGlzLmRhdGEud2VhcG9uW3Nsb3RdID0gd2VhcG9uSW5kZXg7XG4gICAgICAgIHJldHVybiB0cnVlO1xuICAgIH07XG4gICAgLyoqXG4gICAgICogQHBhcmFtIHNsb3Qg5oqc44GP44K544Ot44OD44OIXG4gICAgICogQHJldHVybnMg44OH44OD44Kt44GL44KJ5q2m5Zmo44KS5oqc44GE44Gf44GL44Gp44GG44GLXG4gICAgICovXG4gICAgRGVjay5wcm90b3R5cGUucmVtb3ZlV2VhcG9uID0gZnVuY3Rpb24gKHNsb3QpIHtcbiAgICAgICAgdmFyIHdlYXBvbiA9IHRoaXMuZGF0YS53ZWFwb25bc2xvdF07XG4gICAgICAgIGlmICh3ZWFwb24gPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJiYWQgc2xvdDogXCIuY29uY2F0KHNsb3QpKTtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAod2VhcG9uID09PSBudWxsKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5kYXRhLndlYXBvbltzbG90XSA9IG51bGw7XG4gICAgICAgIHJldHVybiB0cnVlO1xuICAgIH07XG4gICAgLyoqXG4gICAgICogQHBhcmFtIHNsb3RBIOOCueODreODg+ODiEFcbiAgICAgKiBAcGFyYW0gc2xvdEIg44K544Ot44OD44OIQVxuICAgICAqXG4gICAgICogQHJldHVybnMg5q2m5Zmo44KS5Lqk5Luj44GX44Gf44Gp44GG44GLXG4gICAgICpcbiAgICAgKiBpc1ZhbGlkV2VhcG9uU2xvdCgp44KS5L2/44GGXG4gICAgICovXG4gICAgRGVjay5wcm90b3R5cGUuc3dhcFdlYXBvbnMgPSBmdW5jdGlvbiAoc2xvdEEsIHNsb3RCKSB7XG4gICAgICAgIGlmIChzbG90QSA9PT0gc2xvdEIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB2YXIgd2VhcG9uQSA9IHRoaXMuZGF0YS53ZWFwb25bc2xvdEFdO1xuICAgICAgICB2YXIgd2VhcG9uQiA9IHRoaXMuZGF0YS53ZWFwb25bc2xvdEJdO1xuICAgICAgICBpZiAod2VhcG9uQSA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIud2FybihcImJhZCBzbG90OiBcIi5jb25jYXQoc2xvdEEpKTtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAod2VhcG9uQiA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIud2FybihcImJhZCBzbG90OiBcIi5jb25jYXQoc2xvdEIpKTtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAod2VhcG9uQSA9PT0gbnVsbCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJzbG90QSB3ZWFwb24gZG9lc24ndCBleGlzdDogXCIuY29uY2F0KHNsb3RBKSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgdmFyIGlzVmFsaWRQbGFjZSA9IHRoaXMuaXNWYWxpZFdlYXBvblNsb3Qoc2xvdEIsIHdlYXBvbkEpO1xuICAgICAgICB2YXIgaXNWYWxpZFN3YXAgPSB3ZWFwb25CID09PSBudWxsID8gdHJ1ZSA6IHRoaXMuaXNWYWxpZFdlYXBvblNsb3Qoc2xvdEEsIHdlYXBvbkIpO1xuICAgICAgICBpZiAoIWlzVmFsaWRQbGFjZSB8fCAhaXNWYWxpZFN3YXApIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB0aGlzLmRhdGEud2VhcG9uW3Nsb3RBXSA9IHdlYXBvbkI7XG4gICAgICAgIHRoaXMuZGF0YS53ZWFwb25bc2xvdEJdID0gd2VhcG9uQTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfTtcbiAgICAvKipcbiAgICAgKiBAcGFyYW0gc2xvdCDlhaXjgozjgovjgrnjg63jg4Pjg4hcbiAgICAgKiBAcGFyYW0gZXZlbnRJbmRleCDlhaXjgozjgovjgqTjg5njg7Pjg4jjga7jgqTjg7Pjg4fjg4Pjgq/jgrlcbiAgICAgKlxuICAgICAqIEByZXR1cm5zIOODh+ODg+OCreOBq+OCpOODmeODs+ODiOOBjOWFpeOCjOOCi+OBi+OBqeOBhuOBi1xuICAgICAqL1xuICAgIERlY2sucHJvdG90eXBlLmlzVmFsaWRFdmVudFNsb3QgPSBmdW5jdGlvbiAoc2xvdCwgZXZlbnRJbmRleCkge1xuICAgICAgICB2YXIgY2hhcmFTbG90ID0gTWF0aC5mbG9vcihzbG90IC8gRGVjay5FVkVOVF9DQVJEX0NPVU5UKTtcbiAgICAgICAgdmFyIGNoYXJhID0gdGhpcy5kYXRhLmNoYXJhW2NoYXJhU2xvdF07XG4gICAgICAgIHZhciBjaGFyYUluZGV4ID0gdGhpcy5kYXRhLmNoYXJhSW5kZXhbY2hhcmFTbG90XTtcbiAgICAgICAgaWYgKGNoYXJhID09PSBudWxsIHx8IGNoYXJhID09PSB1bmRlZmluZWRcbiAgICAgICAgICAgIHx8IGNoYXJhSW5kZXggPT09IG51bGwgfHwgY2hhcmFJbmRleCA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgdmFyIGFzc2V0ID0gY2hhcmFfMS5DaGFyYS5nZXRBc3NldChjaGFyYSwgY2hhcmFJbmRleCk7XG4gICAgICAgIGlmIChhc3NldCA9PT0gbnVsbCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJ1bmtub3duIGNoYXJhOiBjaGFyYT1cIi5jb25jYXQoY2hhcmEsIFwiLCBjaGFyYUluZGV4PVwiKS5jb25jYXQoY2hhcmFJbmRleCkpO1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIHZhciBldmVudFNsb3QgPSBzbG90ICUgRGVjay5FVkVOVF9DQVJEX0NPVU5UO1xuICAgICAgICB2YXIgc2xvdFR5cGUgPSBhc3NldC5zbG90W2V2ZW50U2xvdF07XG4gICAgICAgIGlmIChzbG90VHlwZSA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgdmFyIGV2ZW50RGF0YSA9IGV2ZW50X2NhcmRfMS5FdmVudERhdGEuZ2V0KGV2ZW50SW5kZXgpO1xuICAgICAgICBpZiAoZXZlbnREYXRhID09PSBudWxsKSB7XG4gICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIud2FybihcInVua25vd24gZXZlbnQgY2FyZDogXCIuY29uY2F0KGV2ZW50SW5kZXgpKTtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAoZXZlbnREYXRhLnR5cGUgPT09IHR5cGVzXzEuRXZlbnRDYXJkVHlwZS5BTlkpIHtcbiAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudERhdGEudHlwZSA9PT0gc2xvdFR5cGU7XG4gICAgfTtcbiAgICAvKipcbiAgICAgKiBAcGFyYW0gc2xvdCDlhaXjgozjgovjgrnjg63jg4Pjg4hcbiAgICAgKiBAcGFyYW0gZXZlbnRJbmRleCDlhaXjgozjgovjgqTjg5njg7Pjg4jjga7jgqTjg7Pjg4fjg4Pjgq/jgrlcbiAgICAgKiBAcmV0dXJucyDjg4fjg4Pjgq3jgavjgqTjg5njg7Pjg4jjgpLlhaXjgozjgZ/jgYvjganjgYbjgYtcbiAgICAgKi9cbiAgICBEZWNrLnByb3RvdHlwZS5hZGRFdmVudCA9IGZ1bmN0aW9uIChzbG90LCBldmVudEluZGV4KSB7XG4gICAgICAgIHZhciBjdXJyZW50RXZlbnQgPSB0aGlzLmRhdGEuZXZlbnRJbmRleFtzbG90XTtcbiAgICAgICAgaWYgKGN1cnJlbnRFdmVudCA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIud2FybihcImJhZCBzbG90OiBcIi5jb25jYXQoc2xvdCkpO1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChjdXJyZW50RXZlbnQgIT09IG51bGwpIHtcbiAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci53YXJuKFwid2VhcG9uIGFscmVhZHkgZXhpc3RzOiBzbG90PVwiLmNvbmNhdChzbG90LCBcIiwgZXZlbnQ9XCIpLmNvbmNhdChjdXJyZW50RXZlbnQpKTtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB2YXIgaXNWYWxpZCA9IHRoaXMuaXNWYWxpZEV2ZW50U2xvdChzbG90LCBldmVudEluZGV4KTtcbiAgICAgICAgaWYgKCFpc1ZhbGlkKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5kYXRhLmV2ZW50SW5kZXhbc2xvdF0gPSBldmVudEluZGV4O1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIEBwYXJhbSBzbG90IOaKnOOBj+OCueODreODg+ODiFxuICAgICAqIEByZXR1cm5zIOODh+ODg+OCreOBi+OCieOCpOODmeODs+ODiOOCkuaKnOOBhOOBn+OBi+OBqeOBhuOBi1xuICAgICAqL1xuICAgIERlY2sucHJvdG90eXBlLnJlbW92ZUV2ZW50ID0gZnVuY3Rpb24gKHNsb3QpIHtcbiAgICAgICAgdmFyIGV2ZW50ID0gdGhpcy5kYXRhLmV2ZW50SW5kZXhbc2xvdF07XG4gICAgICAgIGlmIChldmVudCA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIud2FybihcImJhZCBzbG90OiBcIi5jb25jYXQoc2xvdCkpO1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChldmVudCA9PT0gbnVsbCkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIHRoaXMuZGF0YS5ldmVudEluZGV4W3Nsb3RdID0gbnVsbDtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfTtcbiAgICAvKipcbiAgICAgKiBAcGFyYW0gc2xvdEEg44K544Ot44OD44OIQVxuICAgICAqIEBwYXJhbSBzbG90QiDjgrnjg63jg4Pjg4hBXG4gICAgICpcbiAgICAgKiBAcmV0dXJucyDjgqTjg5njg7Pjg4jjgpLkuqTku6PjgZfjgZ/jganjgYbjgYtcbiAgICAgKlxuICAgICAqIGlzVmFsaWRFdmVudFNsb3QoKeOCkuS9v+OBhlxuICAgICAqL1xuICAgIERlY2sucHJvdG90eXBlLnN3YXBFdmVudHMgPSBmdW5jdGlvbiAoc2xvdEEsIHNsb3RCKSB7XG4gICAgICAgIGlmIChzbG90QSA9PT0gc2xvdEIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB2YXIgZXZlbnRBID0gdGhpcy5kYXRhLmV2ZW50SW5kZXhbc2xvdEFdO1xuICAgICAgICB2YXIgZXZlbnRCID0gdGhpcy5kYXRhLmV2ZW50SW5kZXhbc2xvdEJdO1xuICAgICAgICBpZiAoZXZlbnRBID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci53YXJuKFwiYmFkIHNsb3Q6IFwiLmNvbmNhdChzbG90QSkpO1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChldmVudEIgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJiYWQgc2xvdDogXCIuY29uY2F0KHNsb3RCKSk7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKGV2ZW50QSA9PT0gbnVsbCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLndhcm4oXCJzbG90QSBldmVudCBkb2Vzbid0IGV4aXN0OiBcIi5jb25jYXQoc2xvdEEpKTtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB2YXIgaXNWYWxpZFBsYWNlID0gdGhpcy5pc1ZhbGlkRXZlbnRTbG90KHNsb3RCLCBldmVudEEpO1xuICAgICAgICB2YXIgaXNWYWxpZFN3YXAgPSBldmVudEIgPT09IG51bGwgPyB0cnVlIDogdGhpcy5pc1ZhbGlkRXZlbnRTbG90KHNsb3RBLCBldmVudEIpO1xuICAgICAgICBpZiAoIWlzVmFsaWRQbGFjZSB8fCAhaXNWYWxpZFN3YXApIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICB0aGlzLmRhdGEuZXZlbnRJbmRleFtzbG90QV0gPSBldmVudEI7XG4gICAgICAgIHRoaXMuZGF0YS5ldmVudEluZGV4W3Nsb3RCXSA9IGV2ZW50QTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfTtcbiAgICBEZWNrLkNBUkRfQ09VTlQgPSAzO1xuICAgIERlY2suRVZFTlRfQ0FSRF9DT1VOVCA9IDY7XG4gICAgcmV0dXJuIERlY2s7XG59KCkpO1xuZXhwb3J0cy5EZWNrID0gRGVjaztcbiJdLCJuYW1lcyI6W10sInNvdXJjZVJvb3QiOiIifQ==
//# sourceURL=webpack-internal:///1619

}