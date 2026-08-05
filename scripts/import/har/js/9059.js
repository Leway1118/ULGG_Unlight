{
Object.defineProperty(exports, "__esModule", ({ value: true }));
exports.Chara = void 0;
var card_1 = __webpack_require__(5039);
var logger_1 = __webpack_require__(2614);
var chara_id_1 = __webpack_require__(8609);
var types_1 = __webpack_require__(3605);
/**
 * キャラデータを参照するclass
 *
 * NOTE(andrew): 関数オーバロードはあんまり好きじゃないけど、使い手に便利かも
 *
 * デフォルトアセットのチェックは`__type === 'unknown'`でできる
 */
var Chara = /** @class */ (function () {
    function Chara() {
    }
    /**
     * キャラデータ初期化
     */
    Chara.initChara = function (charaAsset, charaProfile) {
        Chara.charaAsset = charaAsset;
        Chara.charaProfile = charaProfile;
        for (var _i = 0, _a = charaAsset.frames; _i < _a.length; _i++) {
            var a = _a[_i];
            // @ts-expect-error 値初期化
            a.__type = 'chara';
        }
        for (var _b = 0, _c = Object.entries(charaProfile); _b < _c.length; _b++) {
            var _d = _c[_b], chara = _d[0], profile = _d[1];
            // @ts-expect-error 値初期化
            profile.__type = 'chara';
            // @ts-expect-error 値初期化
            profile.__chara = chara;
        }
        Chara.isCharaInit = true;
    };
    /**
     * モンスデータ初期化
     */
    Chara.initMons = function (monsAsset, bossAsset, monsProfile) {
        Chara.monsAsset = monsAsset;
        Chara.bossAsset = bossAsset;
        Chara.monsProfile = monsProfile;
        for (var _i = 0, _a = monsAsset.frames; _i < _a.length; _i++) {
            var a = _a[_i];
            // @ts-expect-error 値初期化
            a.__type = 'mons';
        }
        for (var _b = 0, _c = bossAsset.frames; _b < _c.length; _b++) {
            var a = _c[_b];
            // @ts-expect-error 値初期化
            a.__type = 'boss';
        }
        for (var _d = 0, _e = Object.entries(monsProfile); _d < _e.length; _d++) {
            var _f = _e[_d], chara = _f[0], profile = _f[1];
            // @ts-expect-error 値初期化
            profile.__type = 'mons';
            // @ts-expect-error 値初期化
            profile.__chara = chara;
        }
        Chara.isMonsInit = true;
    };
    /**
     * charaIndexを使って、テーブルとcc???のインデックスをもらう
     */
    Chara.toTableIndex = function (charaIndex) {
        /*
         * 例
         *
         * 0  → 1
         * 9  → 1
         * 10 → 2
         * 19 → 2
         * 20 → 3
         */
        var tenths = Math.floor(charaIndex / 10);
        return [tenths + 1, charaIndex - tenths * 10];
    };
    Chara.getMonsterAssetCount = function () {
        return Chara.monsAsset.frames.length;
    };
    /**
     * キャラ インデックスでレイドモンスターか判別
     */
    Chara.isBoss = function (charaIndex) {
        return charaIndex >= 20000;
    };
    /**
     * デッキなどに使うキャラIDが貰える
     */
    Chara.getID = function (param1, param2) {
        var chara;
        var charaIndex;
        var type;
        if ((0, types_1.isCharaType)(param1)) {
            var data = Chara.getAsset(param1, param2);
            if (data === null) {
                type = 'unknown';
                chara = 'default';
                charaIndex = -1;
            }
            else {
                type = param1;
                charaIndex = param2;
                chara = data.chara;
            }
        }
        else {
            type = Chara.getCharaType(param1, param2);
            chara = param1;
            charaIndex = param2;
        }
        return new chara_id_1.CharaID(type, chara, charaIndex);
    };
    Chara.getCharaType = function (param1, param2) {
        if (typeof param1 === 'string') {
            if (param2 === undefined) {
                logger_1.Logger.error('chara index required when using chara');
                return 'unknown';
            }
            var chara = param1;
            var charaIndex = param2;
            if (chara.startsWith('cc')) {
                return 'chara';
            }
            if (chara.startsWith('mc')) {
                return Chara.isBoss(charaIndex) ? 'boss' : 'mons';
            }
            logger_1.Logger.error("bad chara: ".concat(chara));
            return 'unknown';
        }
        switch (param1) {
            case card_1.CardType.CHARA: {
                return 'chara';
            }
            case card_1.CardType.MONS: {
                return 'mons';
            }
            case card_1.CardType.BOSS: {
                return 'boss';
            }
            default: {
                return 'unknown';
            }
        }
    };
    Chara.getCardType = function (param1) {
        var type;
        if (typeof param1 === 'string') {
            type = param1;
        }
        else {
            type = param1.type;
        }
        switch (type) {
            case 'chara': {
                return card_1.CardType.CHARA;
            }
            case 'mons': {
                return card_1.CardType.MONS;
            }
            case 'boss': {
                return card_1.CardType.BOSS;
            }
            default: {
                return card_1.CardType.UNKNOWN;
            }
        }
    };
    /**
     * NOTE(andrew): 最適化が必要かも知れない
     */
    Chara.getAllProfiles = function (type) {
        switch (type) {
            case 'chara': {
                // @ts-expect-error タイプ判別できない
                return Object.values(Chara.charaProfile);
            }
            case 'mons':
            case 'boss': {
                // @ts-expect-error タイプ判別できない
                return Object.values(Chara.monsProfile);
            }
            default: {
                return [];
            }
        }
    };
    Chara.getCharaAsset = function (charaIndex) {
        var asset = Chara.charaAsset.frames[charaIndex];
        if (asset === undefined) {
            logger_1.Logger.error("bad chara index: charaIndex=".concat(charaIndex));
            return null;
        }
        return asset;
    };
    Chara.getMonsAsset = function (charaIndex) {
        var asset = Chara.monsAsset.frames[charaIndex];
        if (asset === undefined) {
            logger_1.Logger.error("bad mons index: charaIndex=".concat(charaIndex));
            return null;
        }
        return asset;
    };
    Chara.getBossAsset = function (charaIndex) {
        var asset = Chara.bossAsset.frames[charaIndex];
        if (asset === undefined) {
            logger_1.Logger.error("bad boss index: charaIndex=".concat(charaIndex));
            return null;
        }
        return asset;
    };
    /**
     * アセットデータが貰える
     *
     * `CharaID`か`CharaType`の引き数を使って呼び出したら自動タイプ変別できる
     */
    Chara.getAsset = function (param1, param2) {
        var chara;
        var charaIndex;
        var type;
        if (typeof param1 === 'string') {
            if (param2 === undefined) {
                logger_1.Logger.error("must provide charaIndex when not using CharaID: ".concat(param1));
                return null;
            }
            charaIndex = param2;
            if ((0, types_1.isCharaType)(param1)) {
                type = param1;
            }
            else {
                chara = param1;
                type = Chara.getCharaType(chara, charaIndex);
            }
        }
        else {
            chara = param1.chara;
            charaIndex = param1.charaIndex;
            type = Chara.getCharaType(chara, charaIndex);
        }
        switch (type) {
            case 'chara': {
                return Chara.getCharaAsset(charaIndex);
            }
            case 'mons': {
                return Chara.getMonsAsset(charaIndex);
            }
            case 'boss': {
                return Chara.getBossAsset(charaIndex);
            }
            default: {
                return null;
            }
        }
    };
    Chara.getCharaProfile = function (chara) {
        var profile = Chara.charaProfile[chara];
        if (profile === undefined) {
            logger_1.Logger.error("bad chara: ".concat(chara));
            return null;
        }
        return profile;
    };
    Chara.getMonsProfile = function (chara) {
        var profile = Chara.monsProfile[chara];
        if (profile === undefined) {
            logger_1.Logger.error("bad chara: ".concat(chara));
            return null;
        }
        return profile;
    };
    /**
     * プロフィールデータが貰える
     *
     * `CharaID`か`CharaType`の引き数を使って呼び出したら自動タイプ変別できる
     */
    Chara.getProfile = function (param1, param2) {
        var chara;
        var type;
        if (typeof param1 === 'string') {
            if ((0, types_1.isCharaType)(param1)) {
                if (param2 === undefined) {
                    logger_1.Logger.error("must provide charaIndex when not using CharaID: ".concat(param1));
                    return null;
                }
                var asset = Chara.getAsset(param1, param2);
                if (asset === null) {
                    type = 'unknown';
                    chara = 'default';
                }
                else {
                    type = param1;
                    chara = asset.chara;
                }
            }
            else {
                chara = param1;
                type = Chara.getCharaType(chara, 0);
            }
        }
        else {
            chara = param1.chara;
            type = param1.type;
        }
        /** NOTE(andrew): 0でmonsになるが、判別方法が変われば要修正 */
        switch (type) {
            case 'chara': {
                return Chara.getCharaProfile(chara);
            }
            case 'mons':
            case 'boss': {
                return Chara.getMonsProfile(chara);
            }
            default: {
                return null;
            }
        }
    };
    Chara.isCharaInit = false;
    Chara.isMonsInit = false;
    return Chara;
}());
exports.Chara = Chara;
//# sourceURL=[module]
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoiOTA1OS5qcyIsIm1hcHBpbmdzIjoiQUFBYTtBQUNiLDhDQUE2QyxFQUFFLGFBQWEsRUFBQztBQUM3RCxhQUFhO0FBQ2IsYUFBYSxtQkFBTyxDQUFDLElBQVM7QUFDOUIsZUFBZSxtQkFBTyxDQUFDLElBQVc7QUFDbEMsaUJBQWlCLG1CQUFPLENBQUMsSUFBWTtBQUNyQyxjQUFjLG1CQUFPLENBQUMsSUFBUztBQUMvQjtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBLGlEQUFpRCxnQkFBZ0I7QUFDakU7QUFDQTtBQUNBO0FBQ0E7QUFDQSw0REFBNEQsZ0JBQWdCO0FBQzVFO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBLGdEQUFnRCxnQkFBZ0I7QUFDaEU7QUFDQTtBQUNBO0FBQ0E7QUFDQSxnREFBZ0QsZ0JBQWdCO0FBQ2hFO0FBQ0E7QUFDQTtBQUNBO0FBQ0EsMkRBQTJELGdCQUFnQjtBQUMzRTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQSxDQUFDO0FBQ0QsYUFBYSIsInNvdXJjZXMiOlsid2VicGFjazovL3VubGlnaHQtY29tbW9uLy4vc3JjL2NoYXJhL2NoYXJhLnRzPzg5ZmYiXSwic291cmNlc0NvbnRlbnQiOlsiXCJ1c2Ugc3RyaWN0XCI7XG5PYmplY3QuZGVmaW5lUHJvcGVydHkoZXhwb3J0cywgXCJfX2VzTW9kdWxlXCIsIHsgdmFsdWU6IHRydWUgfSk7XG5leHBvcnRzLkNoYXJhID0gdm9pZCAwO1xudmFyIGNhcmRfMSA9IHJlcXVpcmUoXCIuLi9jYXJkXCIpO1xudmFyIGxvZ2dlcl8xID0gcmVxdWlyZShcIi4uL2xvZ2dlclwiKTtcbnZhciBjaGFyYV9pZF8xID0gcmVxdWlyZShcIi4vY2hhcmEtaWRcIik7XG52YXIgdHlwZXNfMSA9IHJlcXVpcmUoXCIuL3R5cGVzXCIpO1xuLyoqXG4gKiDjgq3jg6Pjg6njg4fjg7zjgr/jgpLlj4LnhafjgZnjgotjbGFzc1xuICpcbiAqIE5PVEUoYW5kcmV3KTog6Zai5pWw44Kq44O844OQ44Ot44O844OJ44Gv44GC44KT44G+44KK5aW944GN44GY44KD44Gq44GE44GR44Gp44CB5L2/44GE5omL44Gr5L6/5Yip44GL44KCXG4gKlxuICog44OH44OV44Kp44Or44OI44Ki44K744OD44OI44Gu44OB44Kn44OD44Kv44GvYF9fdHlwZSA9PT0gJ3Vua25vd24nYOOBp+OBp+OBjeOCi1xuICovXG52YXIgQ2hhcmEgPSAvKiogQGNsYXNzICovIChmdW5jdGlvbiAoKSB7XG4gICAgZnVuY3Rpb24gQ2hhcmEoKSB7XG4gICAgfVxuICAgIC8qKlxuICAgICAqIOOCreODo+ODqeODh+ODvOOCv+WIneacn+WMllxuICAgICAqL1xuICAgIENoYXJhLmluaXRDaGFyYSA9IGZ1bmN0aW9uIChjaGFyYUFzc2V0LCBjaGFyYVByb2ZpbGUpIHtcbiAgICAgICAgQ2hhcmEuY2hhcmFBc3NldCA9IGNoYXJhQXNzZXQ7XG4gICAgICAgIENoYXJhLmNoYXJhUHJvZmlsZSA9IGNoYXJhUHJvZmlsZTtcbiAgICAgICAgZm9yICh2YXIgX2kgPSAwLCBfYSA9IGNoYXJhQXNzZXQuZnJhbWVzOyBfaSA8IF9hLmxlbmd0aDsgX2krKykge1xuICAgICAgICAgICAgdmFyIGEgPSBfYVtfaV07XG4gICAgICAgICAgICAvLyBAdHMtZXhwZWN0LWVycm9yIOWApOWIneacn+WMllxuICAgICAgICAgICAgYS5fX3R5cGUgPSAnY2hhcmEnO1xuICAgICAgICB9XG4gICAgICAgIGZvciAodmFyIF9iID0gMCwgX2MgPSBPYmplY3QuZW50cmllcyhjaGFyYVByb2ZpbGUpOyBfYiA8IF9jLmxlbmd0aDsgX2IrKykge1xuICAgICAgICAgICAgdmFyIF9kID0gX2NbX2JdLCBjaGFyYSA9IF9kWzBdLCBwcm9maWxlID0gX2RbMV07XG4gICAgICAgICAgICAvLyBAdHMtZXhwZWN0LWVycm9yIOWApOWIneacn+WMllxuICAgICAgICAgICAgcHJvZmlsZS5fX3R5cGUgPSAnY2hhcmEnO1xuICAgICAgICAgICAgLy8gQHRzLWV4cGVjdC1lcnJvciDlgKTliJ3mnJ/ljJZcbiAgICAgICAgICAgIHByb2ZpbGUuX19jaGFyYSA9IGNoYXJhO1xuICAgICAgICB9XG4gICAgICAgIENoYXJhLmlzQ2hhcmFJbml0ID0gdHJ1ZTtcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIOODouODs+OCueODh+ODvOOCv+WIneacn+WMllxuICAgICAqL1xuICAgIENoYXJhLmluaXRNb25zID0gZnVuY3Rpb24gKG1vbnNBc3NldCwgYm9zc0Fzc2V0LCBtb25zUHJvZmlsZSkge1xuICAgICAgICBDaGFyYS5tb25zQXNzZXQgPSBtb25zQXNzZXQ7XG4gICAgICAgIENoYXJhLmJvc3NBc3NldCA9IGJvc3NBc3NldDtcbiAgICAgICAgQ2hhcmEubW9uc1Byb2ZpbGUgPSBtb25zUHJvZmlsZTtcbiAgICAgICAgZm9yICh2YXIgX2kgPSAwLCBfYSA9IG1vbnNBc3NldC5mcmFtZXM7IF9pIDwgX2EubGVuZ3RoOyBfaSsrKSB7XG4gICAgICAgICAgICB2YXIgYSA9IF9hW19pXTtcbiAgICAgICAgICAgIC8vIEB0cy1leHBlY3QtZXJyb3Ig5YCk5Yid5pyf5YyWXG4gICAgICAgICAgICBhLl9fdHlwZSA9ICdtb25zJztcbiAgICAgICAgfVxuICAgICAgICBmb3IgKHZhciBfYiA9IDAsIF9jID0gYm9zc0Fzc2V0LmZyYW1lczsgX2IgPCBfYy5sZW5ndGg7IF9iKyspIHtcbiAgICAgICAgICAgIHZhciBhID0gX2NbX2JdO1xuICAgICAgICAgICAgLy8gQHRzLWV4cGVjdC1lcnJvciDlgKTliJ3mnJ/ljJZcbiAgICAgICAgICAgIGEuX190eXBlID0gJ2Jvc3MnO1xuICAgICAgICB9XG4gICAgICAgIGZvciAodmFyIF9kID0gMCwgX2UgPSBPYmplY3QuZW50cmllcyhtb25zUHJvZmlsZSk7IF9kIDwgX2UubGVuZ3RoOyBfZCsrKSB7XG4gICAgICAgICAgICB2YXIgX2YgPSBfZVtfZF0sIGNoYXJhID0gX2ZbMF0sIHByb2ZpbGUgPSBfZlsxXTtcbiAgICAgICAgICAgIC8vIEB0cy1leHBlY3QtZXJyb3Ig5YCk5Yid5pyf5YyWXG4gICAgICAgICAgICBwcm9maWxlLl9fdHlwZSA9ICdtb25zJztcbiAgICAgICAgICAgIC8vIEB0cy1leHBlY3QtZXJyb3Ig5YCk5Yid5pyf5YyWXG4gICAgICAgICAgICBwcm9maWxlLl9fY2hhcmEgPSBjaGFyYTtcbiAgICAgICAgfVxuICAgICAgICBDaGFyYS5pc01vbnNJbml0ID0gdHJ1ZTtcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIGNoYXJhSW5kZXjjgpLkvb/jgaPjgabjgIHjg4bjg7zjg5bjg6vjgahjYz8/P+OBruOCpOODs+ODh+ODg+OCr+OCueOCkuOCguOCieOBhlxuICAgICAqL1xuICAgIENoYXJhLnRvVGFibGVJbmRleCA9IGZ1bmN0aW9uIChjaGFyYUluZGV4KSB7XG4gICAgICAgIC8qXG4gICAgICAgICAqIOS+i1xuICAgICAgICAgKlxuICAgICAgICAgKiAwICDihpIgMVxuICAgICAgICAgKiA5ICDihpIgMVxuICAgICAgICAgKiAxMCDihpIgMlxuICAgICAgICAgKiAxOSDihpIgMlxuICAgICAgICAgKiAyMCDihpIgM1xuICAgICAgICAgKi9cbiAgICAgICAgdmFyIHRlbnRocyA9IE1hdGguZmxvb3IoY2hhcmFJbmRleCAvIDEwKTtcbiAgICAgICAgcmV0dXJuIFt0ZW50aHMgKyAxLCBjaGFyYUluZGV4IC0gdGVudGhzICogMTBdO1xuICAgIH07XG4gICAgQ2hhcmEuZ2V0TW9uc3RlckFzc2V0Q291bnQgPSBmdW5jdGlvbiAoKSB7XG4gICAgICAgIHJldHVybiBDaGFyYS5tb25zQXNzZXQuZnJhbWVzLmxlbmd0aDtcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIOOCreODo+ODqSDjgqTjg7Pjg4fjg4Pjgq/jgrnjgafjg6zjgqTjg4njg6Ljg7Pjgrnjgr/jg7zjgYvliKTliKVcbiAgICAgKi9cbiAgICBDaGFyYS5pc0Jvc3MgPSBmdW5jdGlvbiAoY2hhcmFJbmRleCkge1xuICAgICAgICByZXR1cm4gY2hhcmFJbmRleCA+PSAyMDAwMDtcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIOODh+ODg+OCreOBquOBqeOBq+S9v+OBhuOCreODo+ODqUlE44GM6LKw44GI44KLXG4gICAgICovXG4gICAgQ2hhcmEuZ2V0SUQgPSBmdW5jdGlvbiAocGFyYW0xLCBwYXJhbTIpIHtcbiAgICAgICAgdmFyIGNoYXJhO1xuICAgICAgICB2YXIgY2hhcmFJbmRleDtcbiAgICAgICAgdmFyIHR5cGU7XG4gICAgICAgIGlmICgoMCwgdHlwZXNfMS5pc0NoYXJhVHlwZSkocGFyYW0xKSkge1xuICAgICAgICAgICAgdmFyIGRhdGEgPSBDaGFyYS5nZXRBc3NldChwYXJhbTEsIHBhcmFtMik7XG4gICAgICAgICAgICBpZiAoZGF0YSA9PT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIHR5cGUgPSAndW5rbm93bic7XG4gICAgICAgICAgICAgICAgY2hhcmEgPSAnZGVmYXVsdCc7XG4gICAgICAgICAgICAgICAgY2hhcmFJbmRleCA9IC0xO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgdHlwZSA9IHBhcmFtMTtcbiAgICAgICAgICAgICAgICBjaGFyYUluZGV4ID0gcGFyYW0yO1xuICAgICAgICAgICAgICAgIGNoYXJhID0gZGF0YS5jaGFyYTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHR5cGUgPSBDaGFyYS5nZXRDaGFyYVR5cGUocGFyYW0xLCBwYXJhbTIpO1xuICAgICAgICAgICAgY2hhcmEgPSBwYXJhbTE7XG4gICAgICAgICAgICBjaGFyYUluZGV4ID0gcGFyYW0yO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBuZXcgY2hhcmFfaWRfMS5DaGFyYUlEKHR5cGUsIGNoYXJhLCBjaGFyYUluZGV4KTtcbiAgICB9O1xuICAgIENoYXJhLmdldENoYXJhVHlwZSA9IGZ1bmN0aW9uIChwYXJhbTEsIHBhcmFtMikge1xuICAgICAgICBpZiAodHlwZW9mIHBhcmFtMSA9PT0gJ3N0cmluZycpIHtcbiAgICAgICAgICAgIGlmIChwYXJhbTIgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci5lcnJvcignY2hhcmEgaW5kZXggcmVxdWlyZWQgd2hlbiB1c2luZyBjaGFyYScpO1xuICAgICAgICAgICAgICAgIHJldHVybiAndW5rbm93bic7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB2YXIgY2hhcmEgPSBwYXJhbTE7XG4gICAgICAgICAgICB2YXIgY2hhcmFJbmRleCA9IHBhcmFtMjtcbiAgICAgICAgICAgIGlmIChjaGFyYS5zdGFydHNXaXRoKCdjYycpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuICdjaGFyYSc7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoY2hhcmEuc3RhcnRzV2l0aCgnbWMnKSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBDaGFyYS5pc0Jvc3MoY2hhcmFJbmRleCkgPyAnYm9zcycgOiAnbW9ucyc7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIuZXJyb3IoXCJiYWQgY2hhcmE6IFwiLmNvbmNhdChjaGFyYSkpO1xuICAgICAgICAgICAgcmV0dXJuICd1bmtub3duJztcbiAgICAgICAgfVxuICAgICAgICBzd2l0Y2ggKHBhcmFtMSkge1xuICAgICAgICAgICAgY2FzZSBjYXJkXzEuQ2FyZFR5cGUuQ0hBUkE6IHtcbiAgICAgICAgICAgICAgICByZXR1cm4gJ2NoYXJhJztcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNhc2UgY2FyZF8xLkNhcmRUeXBlLk1PTlM6IHtcbiAgICAgICAgICAgICAgICByZXR1cm4gJ21vbnMnO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY2FzZSBjYXJkXzEuQ2FyZFR5cGUuQk9TUzoge1xuICAgICAgICAgICAgICAgIHJldHVybiAnYm9zcyc7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBkZWZhdWx0OiB7XG4gICAgICAgICAgICAgICAgcmV0dXJuICd1bmtub3duJztcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH07XG4gICAgQ2hhcmEuZ2V0Q2FyZFR5cGUgPSBmdW5jdGlvbiAocGFyYW0xKSB7XG4gICAgICAgIHZhciB0eXBlO1xuICAgICAgICBpZiAodHlwZW9mIHBhcmFtMSA9PT0gJ3N0cmluZycpIHtcbiAgICAgICAgICAgIHR5cGUgPSBwYXJhbTE7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICB0eXBlID0gcGFyYW0xLnR5cGU7XG4gICAgICAgIH1cbiAgICAgICAgc3dpdGNoICh0eXBlKSB7XG4gICAgICAgICAgICBjYXNlICdjaGFyYSc6IHtcbiAgICAgICAgICAgICAgICByZXR1cm4gY2FyZF8xLkNhcmRUeXBlLkNIQVJBO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY2FzZSAnbW9ucyc6IHtcbiAgICAgICAgICAgICAgICByZXR1cm4gY2FyZF8xLkNhcmRUeXBlLk1PTlM7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjYXNlICdib3NzJzoge1xuICAgICAgICAgICAgICAgIHJldHVybiBjYXJkXzEuQ2FyZFR5cGUuQk9TUztcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGRlZmF1bHQ6IHtcbiAgICAgICAgICAgICAgICByZXR1cm4gY2FyZF8xLkNhcmRUeXBlLlVOS05PV047XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9O1xuICAgIC8qKlxuICAgICAqIE5PVEUoYW5kcmV3KTog5pyA6YGp5YyW44GM5b+F6KaB44GL44KC55+l44KM44Gq44GEXG4gICAgICovXG4gICAgQ2hhcmEuZ2V0QWxsUHJvZmlsZXMgPSBmdW5jdGlvbiAodHlwZSkge1xuICAgICAgICBzd2l0Y2ggKHR5cGUpIHtcbiAgICAgICAgICAgIGNhc2UgJ2NoYXJhJzoge1xuICAgICAgICAgICAgICAgIC8vIEB0cy1leHBlY3QtZXJyb3Ig44K/44Kk44OX5Yik5Yil44Gn44GN44Gq44GEXG4gICAgICAgICAgICAgICAgcmV0dXJuIE9iamVjdC52YWx1ZXMoQ2hhcmEuY2hhcmFQcm9maWxlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNhc2UgJ21vbnMnOlxuICAgICAgICAgICAgY2FzZSAnYm9zcyc6IHtcbiAgICAgICAgICAgICAgICAvLyBAdHMtZXhwZWN0LWVycm9yIOOCv+OCpOODl+WIpOWIpeOBp+OBjeOBquOBhFxuICAgICAgICAgICAgICAgIHJldHVybiBPYmplY3QudmFsdWVzKENoYXJhLm1vbnNQcm9maWxlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGRlZmF1bHQ6IHtcbiAgICAgICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9O1xuICAgIENoYXJhLmdldENoYXJhQXNzZXQgPSBmdW5jdGlvbiAoY2hhcmFJbmRleCkge1xuICAgICAgICB2YXIgYXNzZXQgPSBDaGFyYS5jaGFyYUFzc2V0LmZyYW1lc1tjaGFyYUluZGV4XTtcbiAgICAgICAgaWYgKGFzc2V0ID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci5lcnJvcihcImJhZCBjaGFyYSBpbmRleDogY2hhcmFJbmRleD1cIi5jb25jYXQoY2hhcmFJbmRleCkpO1xuICAgICAgICAgICAgcmV0dXJuIG51bGw7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGFzc2V0O1xuICAgIH07XG4gICAgQ2hhcmEuZ2V0TW9uc0Fzc2V0ID0gZnVuY3Rpb24gKGNoYXJhSW5kZXgpIHtcbiAgICAgICAgdmFyIGFzc2V0ID0gQ2hhcmEubW9uc0Fzc2V0LmZyYW1lc1tjaGFyYUluZGV4XTtcbiAgICAgICAgaWYgKGFzc2V0ID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIGxvZ2dlcl8xLkxvZ2dlci5lcnJvcihcImJhZCBtb25zIGluZGV4OiBjaGFyYUluZGV4PVwiLmNvbmNhdChjaGFyYUluZGV4KSk7XG4gICAgICAgICAgICByZXR1cm4gbnVsbDtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gYXNzZXQ7XG4gICAgfTtcbiAgICBDaGFyYS5nZXRCb3NzQXNzZXQgPSBmdW5jdGlvbiAoY2hhcmFJbmRleCkge1xuICAgICAgICB2YXIgYXNzZXQgPSBDaGFyYS5ib3NzQXNzZXQuZnJhbWVzW2NoYXJhSW5kZXhdO1xuICAgICAgICBpZiAoYXNzZXQgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLmVycm9yKFwiYmFkIGJvc3MgaW5kZXg6IGNoYXJhSW5kZXg9XCIuY29uY2F0KGNoYXJhSW5kZXgpKTtcbiAgICAgICAgICAgIHJldHVybiBudWxsO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBhc3NldDtcbiAgICB9O1xuICAgIC8qKlxuICAgICAqIOOCouOCu+ODg+ODiOODh+ODvOOCv+OBjOiysOOBiOOCi1xuICAgICAqXG4gICAgICogYENoYXJhSURg44GLYENoYXJhVHlwZWDjga7lvJXjgY3mlbDjgpLkvb/jgaPjgablkbzjgbPlh7rjgZfjgZ/jgonoh6rli5Xjgr/jgqTjg5flpInliKXjgafjgY3jgotcbiAgICAgKi9cbiAgICBDaGFyYS5nZXRBc3NldCA9IGZ1bmN0aW9uIChwYXJhbTEsIHBhcmFtMikge1xuICAgICAgICB2YXIgY2hhcmE7XG4gICAgICAgIHZhciBjaGFyYUluZGV4O1xuICAgICAgICB2YXIgdHlwZTtcbiAgICAgICAgaWYgKHR5cGVvZiBwYXJhbTEgPT09ICdzdHJpbmcnKSB7XG4gICAgICAgICAgICBpZiAocGFyYW0yID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIuZXJyb3IoXCJtdXN0IHByb3ZpZGUgY2hhcmFJbmRleCB3aGVuIG5vdCB1c2luZyBDaGFyYUlEOiBcIi5jb25jYXQocGFyYW0xKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuIG51bGw7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjaGFyYUluZGV4ID0gcGFyYW0yO1xuICAgICAgICAgICAgaWYgKCgwLCB0eXBlc18xLmlzQ2hhcmFUeXBlKShwYXJhbTEpKSB7XG4gICAgICAgICAgICAgICAgdHlwZSA9IHBhcmFtMTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgIGNoYXJhID0gcGFyYW0xO1xuICAgICAgICAgICAgICAgIHR5cGUgPSBDaGFyYS5nZXRDaGFyYVR5cGUoY2hhcmEsIGNoYXJhSW5kZXgpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgY2hhcmEgPSBwYXJhbTEuY2hhcmE7XG4gICAgICAgICAgICBjaGFyYUluZGV4ID0gcGFyYW0xLmNoYXJhSW5kZXg7XG4gICAgICAgICAgICB0eXBlID0gQ2hhcmEuZ2V0Q2hhcmFUeXBlKGNoYXJhLCBjaGFyYUluZGV4KTtcbiAgICAgICAgfVxuICAgICAgICBzd2l0Y2ggKHR5cGUpIHtcbiAgICAgICAgICAgIGNhc2UgJ2NoYXJhJzoge1xuICAgICAgICAgICAgICAgIHJldHVybiBDaGFyYS5nZXRDaGFyYUFzc2V0KGNoYXJhSW5kZXgpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY2FzZSAnbW9ucyc6IHtcbiAgICAgICAgICAgICAgICByZXR1cm4gQ2hhcmEuZ2V0TW9uc0Fzc2V0KGNoYXJhSW5kZXgpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY2FzZSAnYm9zcyc6IHtcbiAgICAgICAgICAgICAgICByZXR1cm4gQ2hhcmEuZ2V0Qm9zc0Fzc2V0KGNoYXJhSW5kZXgpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZGVmYXVsdDoge1xuICAgICAgICAgICAgICAgIHJldHVybiBudWxsO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfTtcbiAgICBDaGFyYS5nZXRDaGFyYVByb2ZpbGUgPSBmdW5jdGlvbiAoY2hhcmEpIHtcbiAgICAgICAgdmFyIHByb2ZpbGUgPSBDaGFyYS5jaGFyYVByb2ZpbGVbY2hhcmFdO1xuICAgICAgICBpZiAocHJvZmlsZSA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICBsb2dnZXJfMS5Mb2dnZXIuZXJyb3IoXCJiYWQgY2hhcmE6IFwiLmNvbmNhdChjaGFyYSkpO1xuICAgICAgICAgICAgcmV0dXJuIG51bGw7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHByb2ZpbGU7XG4gICAgfTtcbiAgICBDaGFyYS5nZXRNb25zUHJvZmlsZSA9IGZ1bmN0aW9uIChjaGFyYSkge1xuICAgICAgICB2YXIgcHJvZmlsZSA9IENoYXJhLm1vbnNQcm9maWxlW2NoYXJhXTtcbiAgICAgICAgaWYgKHByb2ZpbGUgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLmVycm9yKFwiYmFkIGNoYXJhOiBcIi5jb25jYXQoY2hhcmEpKTtcbiAgICAgICAgICAgIHJldHVybiBudWxsO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBwcm9maWxlO1xuICAgIH07XG4gICAgLyoqXG4gICAgICog44OX44Ot44OV44Kj44O844Or44OH44O844K/44GM6LKw44GI44KLXG4gICAgICpcbiAgICAgKiBgQ2hhcmFJRGDjgYtgQ2hhcmFUeXBlYOOBruW8leOBjeaVsOOCkuS9v+OBo+OBpuWRvOOBs+WHuuOBl+OBn+OCieiHquWLleOCv+OCpOODl+WkieWIpeOBp+OBjeOCi1xuICAgICAqL1xuICAgIENoYXJhLmdldFByb2ZpbGUgPSBmdW5jdGlvbiAocGFyYW0xLCBwYXJhbTIpIHtcbiAgICAgICAgdmFyIGNoYXJhO1xuICAgICAgICB2YXIgdHlwZTtcbiAgICAgICAgaWYgKHR5cGVvZiBwYXJhbTEgPT09ICdzdHJpbmcnKSB7XG4gICAgICAgICAgICBpZiAoKDAsIHR5cGVzXzEuaXNDaGFyYVR5cGUpKHBhcmFtMSkpIHtcbiAgICAgICAgICAgICAgICBpZiAocGFyYW0yID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgbG9nZ2VyXzEuTG9nZ2VyLmVycm9yKFwibXVzdCBwcm92aWRlIGNoYXJhSW5kZXggd2hlbiBub3QgdXNpbmcgQ2hhcmFJRDogXCIuY29uY2F0KHBhcmFtMSkpO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gbnVsbDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgdmFyIGFzc2V0ID0gQ2hhcmEuZ2V0QXNzZXQocGFyYW0xLCBwYXJhbTIpO1xuICAgICAgICAgICAgICAgIGlmIChhc3NldCA9PT0gbnVsbCkge1xuICAgICAgICAgICAgICAgICAgICB0eXBlID0gJ3Vua25vd24nO1xuICAgICAgICAgICAgICAgICAgICBjaGFyYSA9ICdkZWZhdWx0JztcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIHR5cGUgPSBwYXJhbTE7XG4gICAgICAgICAgICAgICAgICAgIGNoYXJhID0gYXNzZXQuY2hhcmE7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgY2hhcmEgPSBwYXJhbTE7XG4gICAgICAgICAgICAgICAgdHlwZSA9IENoYXJhLmdldENoYXJhVHlwZShjaGFyYSwgMCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICBjaGFyYSA9IHBhcmFtMS5jaGFyYTtcbiAgICAgICAgICAgIHR5cGUgPSBwYXJhbTEudHlwZTtcbiAgICAgICAgfVxuICAgICAgICAvKiogTk9URShhbmRyZXcpOiAw44GnbW9uc+OBq+OBquOCi+OBjOOAgeWIpOWIpeaWueazleOBjOWkieOCj+OCjOOBsOimgeS/ruatoyAqL1xuICAgICAgICBzd2l0Y2ggKHR5cGUpIHtcbiAgICAgICAgICAgIGNhc2UgJ2NoYXJhJzoge1xuICAgICAgICAgICAgICAgIHJldHVybiBDaGFyYS5nZXRDaGFyYVByb2ZpbGUoY2hhcmEpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY2FzZSAnbW9ucyc6XG4gICAgICAgICAgICBjYXNlICdib3NzJzoge1xuICAgICAgICAgICAgICAgIHJldHVybiBDaGFyYS5nZXRNb25zUHJvZmlsZShjaGFyYSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBkZWZhdWx0OiB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIG51bGw7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9O1xuICAgIENoYXJhLmlzQ2hhcmFJbml0ID0gZmFsc2U7XG4gICAgQ2hhcmEuaXNNb25zSW5pdCA9IGZhbHNlO1xuICAgIHJldHVybiBDaGFyYTtcbn0oKSk7XG5leHBvcnRzLkNoYXJhID0gQ2hhcmE7XG4iXSwibmFtZXMiOltdLCJzb3VyY2VSb290IjoiIn0=
//# sourceURL=webpack-internal:///9059

}