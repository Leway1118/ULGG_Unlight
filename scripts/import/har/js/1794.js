{
Object.defineProperty(exports, "__esModule", ({ value: true }));
exports.EventData = exports.EventCardColor = void 0;
exports.EventCardColor = {
    SWORD: '#E1294E',
    GUN: '#64D23A',
    SHIELD: '#008EF1',
    MOVE: '#BB00F1',
    SPECIAL: '#E7B73B',
    CHANCE: '#FFFFFF',
    CURSE: '#000000',
};
var EventData = /** @class */ (function () {
    function EventData() {
    }
    EventData.init = function (eventJSON) {
        EventData.eventJSON = eventJSON;
    };
    EventData.get = function (index) {
        var _a;
        return (_a = EventData.eventJSON.frames[index]) !== null && _a !== void 0 ? _a : null;
    };
    return EventData;
}());
exports.EventData = EventData;
//# sourceURL=[module]
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoiMTc5NC5qcyIsIm1hcHBpbmdzIjoiQUFBYTtBQUNiLDhDQUE2QyxFQUFFLGFBQWEsRUFBQztBQUM3RCxpQkFBaUIsR0FBRyxzQkFBc0I7QUFDMUMsc0JBQXNCO0FBQ3RCO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0E7QUFDQTtBQUNBO0FBQ0EsQ0FBQztBQUNELGlCQUFpQiIsInNvdXJjZXMiOlsid2VicGFjazovL3VubGlnaHQtY29tbW9uLy4vc3JjL2NhcmQvZXZlbnQtY2FyZC50cz9mYjg1Il0sInNvdXJjZXNDb250ZW50IjpbIlwidXNlIHN0cmljdFwiO1xuT2JqZWN0LmRlZmluZVByb3BlcnR5KGV4cG9ydHMsIFwiX19lc01vZHVsZVwiLCB7IHZhbHVlOiB0cnVlIH0pO1xuZXhwb3J0cy5FdmVudERhdGEgPSBleHBvcnRzLkV2ZW50Q2FyZENvbG9yID0gdm9pZCAwO1xuZXhwb3J0cy5FdmVudENhcmRDb2xvciA9IHtcbiAgICBTV09SRDogJyNFMTI5NEUnLFxuICAgIEdVTjogJyM2NEQyM0EnLFxuICAgIFNISUVMRDogJyMwMDhFRjEnLFxuICAgIE1PVkU6ICcjQkIwMEYxJyxcbiAgICBTUEVDSUFMOiAnI0U3QjczQicsXG4gICAgQ0hBTkNFOiAnI0ZGRkZGRicsXG4gICAgQ1VSU0U6ICcjMDAwMDAwJyxcbn07XG52YXIgRXZlbnREYXRhID0gLyoqIEBjbGFzcyAqLyAoZnVuY3Rpb24gKCkge1xuICAgIGZ1bmN0aW9uIEV2ZW50RGF0YSgpIHtcbiAgICB9XG4gICAgRXZlbnREYXRhLmluaXQgPSBmdW5jdGlvbiAoZXZlbnRKU09OKSB7XG4gICAgICAgIEV2ZW50RGF0YS5ldmVudEpTT04gPSBldmVudEpTT047XG4gICAgfTtcbiAgICBFdmVudERhdGEuZ2V0ID0gZnVuY3Rpb24gKGluZGV4KSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgcmV0dXJuIChfYSA9IEV2ZW50RGF0YS5ldmVudEpTT04uZnJhbWVzW2luZGV4XSkgIT09IG51bGwgJiYgX2EgIT09IHZvaWQgMCA/IF9hIDogbnVsbDtcbiAgICB9O1xuICAgIHJldHVybiBFdmVudERhdGE7XG59KCkpO1xuZXhwb3J0cy5FdmVudERhdGEgPSBFdmVudERhdGE7XG4iXSwibmFtZXMiOltdLCJzb3VyY2VSb290IjoiIn0=
//# sourceURL=webpack-internal:///1794

}