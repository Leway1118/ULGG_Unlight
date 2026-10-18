import json


TYPE_MAP = {
    "acswd": "sword",
    "acshi": "shield",
    "acbow": "gun",
    "acmov": "move",
    "acspe": "special",
}


READ_CARDS_JS = r"""
(() => {
const main = game.scene.keys.MainA;
let cards=[];
const resourceFields = [
    ["sword", "swd"],
    ["gun", "gun"],
    ["shield", "shi"],
    ["move", "mov"],
    ["special", "spe"]
];

const normalizedAngle = object => {
 const numeric = Number(object?.angle);
 return Number.isFinite(numeric)
    ? ((numeric % 360) + 360) % 360
    : null;
};
const near = (angle, target) =>
    angle !== null && Math.abs(angle - target) < 0.001;
const upright = object => {
 const angle = normalizedAngle(object);
 return near(angle, 0) || near(angle, 360);
};
const inverted = object => near(normalizedAngle(object), 180);
const listenerCount = (object, event) => {
 try {
    if (typeof object?.listenerCount === "function") return object.listenerCount(event);
    if (typeof object?.listeners === "function") return object.listeners(event).length;
 } catch (_) {}
 const stored = object?._events?.[event];
 return Array.isArray(stored) ? stored.length : stored ? 1 : 0;
};
const actionFace = (card, face, iconIndex, valueIndex) => {
 const icon = card?.[iconIndex];
 const value = card?.[valueIndex];
 return {
    face,
    type: icon?.texture?.key || null,
    value: Number(value?.text || 0),
    resources: [{type: icon?.texture?.key || null, value: Number(value?.text || 0)}],
    angle: normalizedAngle(icon)
 };
};
const eventFace = (info, face, side) => {
 const resources = resourceFields
    .map(([type, field]) => ({type, value: Number(info?.[`${field}${side}`] || 0)}))
    .filter(value => value.value > 0);
 return {
    face,
    type: resources.length === 1 ? resources[0].type : "neutral",
    value: resources.length === 1 ? resources[0].value : 0,
    resources
 };
};

for (let i = 0; i < (main.arr1?.length || 0); i++) {
 const card = main.arr1[i];
 if (!Array.isArray(card)) continue;
 const frame = card[0];
 if (!frame) continue;

 const eventCard = card.length === 1 && frame?.texture?.key === "event_asset";
 let source, face, orientations, type, value, faceReady;
 if (eventCard) {
    const eventIndex = Number(frame?.frame?.name);
    const info = Number.isInteger(eventIndex) ? main.eventInfo?.frames?.[eventIndex] : null;
    const front = eventFace(info, "front", 1);
    const reverse = eventFace(info, "reverse", 2);
    face = upright(frame) ? "front" : inverted(frame) ? "reverse" : null;
    faceReady = face !== null && Boolean(info);
    const current = face === "front" ? front : face === "reverse" ? reverse : null;
    source = "event";
    orientations = [front, reverse];
    type = current?.type || null;
    value = Number(current?.value || 0);
 } else {
    const front = actionFace(card, "front", 1, 2);
    const reverse = actionFace(card, "reverse", 3, 4);
    const frontUp = upright(card[1]);
    const reverseUp = upright(card[3]);
    faceReady = frontUp !== reverseUp;
    face = faceReady ? (frontUp ? "front" : "reverse") : null;
    const current = frontUp ? front : reverseUp ? reverse : null;
    source = "action";
    orientations = [front, reverse];
    type = current?.type || null;
    value = Number(current?.value || 0);
 }

 cards.push({
    index: i,
    raw_hand_count: main.arr1?.length || 0,
    socket_index: i,
    source,
    selectable: Boolean(
        faceReady && frame?.active === true && frame?.visible === true &&
        frame?.input?.enabled && listenerCount(frame, "pointerdown") > 0
    ),
    active: frame?.active === true,
    visible: frame?.visible === true,
    input_enabled: frame?.input?.enabled === true,
    pointerdown_listener: listenerCount(frame, "pointerdown") > 0,
    pointerdown_listener_count: listenerCount(frame, "pointerdown"),
    face,
    orientations,
    texture: type,
    type,
    value
 });
}
return JSON.stringify(cards);
})();
"""


READ_HAND_PROBE_JS = r"""
(() => {
const main = globalThis.game?.scene?.keys?.MainA || null;
if (!main) return JSON.stringify({error: "MainA missing", containers: [], cards: []});
const angle = object => {
    const value = Number(object?.angle);
    return Number.isFinite(value) ? ((value % 360) + 360) % 360 : null;
};
const listenerCount = (object, event) => {
    try {
        if (typeof object?.listenerCount === "function") return object.listenerCount(event);
        if (typeof object?.listeners === "function") return object.listeners(event).length;
    } catch (_) {}
    const stored = object?._events?.[event];
    return Array.isArray(stored) ? stored.length : stored ? 1 : 0;
};
const objectEvidence = (object, container, runtimeIndex, socketIndex, kind) => ({
    container,
    runtime_index: runtimeIndex,
    socket_index: socketIndex,
    kind,
    texture: object?.texture?.key || null,
    frame: object?.frame?.name ?? null,
    angle: angle(object),
    visible: object?.visible === true,
    active: object?.active === true,
    input_enabled: object?.input?.enabled === true,
    pointerdown_listener: listenerCount(object, "pointerdown") > 0,
    pointerdown_listener_count: listenerCount(object, "pointerdown"),
    pointerup_listener: listenerCount(object, "pointerup") > 0
});
const cards = [];
for (let index = 0; index < (main.arr1?.length || 0); index++) {
    const row = main.arr1[index];
    if (!Array.isArray(row) || !row[0]) continue;
    const eventCard = row.length === 1 && row[0]?.texture?.key === "event_asset";
    const eventIndex = eventCard ? Number(row[0]?.frame?.name) : null;
    const eventInfo = Number.isInteger(eventIndex) ? main.eventInfo?.frames?.[eventIndex] : null;
    cards.push({
        ...objectEvidence(row[0], "MainA.arr1", index, index, eventCard ? "event" : "action"),
        row_length: row.length,
        face_shape: eventCard ? "event_dual" : row.length >= 5 ? "action_dual" : "unknown",
        event_info: eventInfo ? {
            swd1: Number(eventInfo.swd1 || 0), gun1: Number(eventInfo.gun1 || 0),
            shi1: Number(eventInfo.shi1 || 0), mov1: Number(eventInfo.mov1 || 0),
            spe1: Number(eventInfo.spe1 || 0), swd2: Number(eventInfo.swd2 || 0),
            gun2: Number(eventInfo.gun2 || 0), shi2: Number(eventInfo.shi2 || 0),
            mov2: Number(eventInfo.mov2 || 0), spe2: Number(eventInfo.spe2 || 0)
        } : null,
        children: row.slice(1).map((object, childIndex) =>
            objectEvidence(object, `MainA.arr1[${index}]`, childIndex + 1, index, "child")),
        rotate_button: objectEvidence(main.button?.[index], "MainA.button", index, index, "rotate")
    });
}
const containers = Object.keys(main)
    .filter(name => /(arr1|hand|card|deck|event)/i.test(name))
    .sort()
    .map(name => {
        const value = main[name];
        return {
            container: `MainA.${name}`,
            kind: Array.isArray(value) ? "array" : value === null ? "null" : typeof value,
            length: Array.isArray(value) ? value.length : null,
            safe_detail: name === "arr1" ? "local canonical hand"
                : name === "button" ? "local rotation controls"
                : name === "eventInfo" ? "event metadata (used frames only listed above)"
                : "contents withheld"
        };
    });
return JSON.stringify({
    scene: "MainA",
    player: main.PLAYER === "A" || main.PLAYER === "B" ? main.PLAYER : null,
    containers,
    cards
});
})();
"""


def _canonical_card(card):
    def canonical_type(value):
        return TYPE_MAP.get(value, value if value in {
            "sword", "shield", "gun", "move", "special", "neutral"
        } else None)

    return {
        "index": card["index"],
        "raw_hand_count": int(card.get("raw_hand_count") or 0),
        "source": card.get("source", "action"),
        "type": canonical_type(card.get("type") or card.get("texture")),
        "value": int(card.get("value") or 0),
        "selectable": card.get("selectable") is True,
        "active": card.get("active") is True,
        "visible": card.get("visible") is True,
        "input_enabled": card.get("input_enabled") is True,
        "pointerdown_listener": card.get("pointerdown_listener") is True,
        "pointerdown_listener_count": int(card.get("pointerdown_listener_count") or 0),
        "face": card.get("face"),
        "orientations": [
            {
                "face": face.get("face"),
                "type": canonical_type(face.get("type")) or "neutral",
                "value": int(face.get("value") or 0),
                "resources": [
                    {
                        "type": canonical_type(resource.get("type")),
                        "value": int(resource.get("value") or 0),
                    }
                    for resource in face.get("resources", [])
                    if canonical_type(resource.get("type")) is not None
                    and int(resource.get("value") or 0) > 0
                ],
            }
            for face in card.get("orientations", [])
        ],
    }


async def read_cards(sniffer):
    result, error = await sniffer.evaluate(READ_CARDS_JS, await_promise=False)
    if error:
        raise RuntimeError(str(error))
    if not result:
        return []
    return [_canonical_card(card) for card in json.loads(result)]


async def read_hand_probe(sniffer):
    result, error = await sniffer.evaluate(READ_HAND_PROBE_JS, await_promise=False)
    if error:
        raise RuntimeError(str(error))
    if not result:
        return {"error": "empty Runtime.evaluate result", "containers": [], "cards": []}
    return json.loads(result)
