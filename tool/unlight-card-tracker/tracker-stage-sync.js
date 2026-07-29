(function (global) {
    "use strict";

    const STAGE_FIELD_MAP = Object.freeze({
        "000": "萊丁貝魯格城堡",
        "001": "誘惑森林",
        "002": "垃圾之街",
        "003": "冰封湖畔",
        "004": "人魂墓地",
        "005": "盡頭之村",
        "006": "風暴荒野",
        "007": "藩骸兒的遺跡",
        "008": "魔都羅占布爾克",
        "009": "瘋狂山脈",
        "010": "魔女山谷",
        "011": "隨機",
        "012": "烏波斯黑湖"
    });

    function isObject(value) {
        return (
            value !== null &&
            typeof value === "object" &&
            !Array.isArray(value)
        );
    }

    function resolveStage(stageCode, fieldDecks) {
        if (stageCode === null || stageCode === undefined) {
            return {
                status: "missing",
                stage_code: null,
                field: null
            };
        }
        if (
            typeof stageCode !== "string" ||
            !/^[0-9]{3}$/.test(stageCode)
        ) {
            return {
                status: "invalid_stage_code",
                stage_code: (
                    typeof stageCode === "string"
                        ? stageCode
                        : null
                ),
                field: null
            };
        }
        if (!Object.hasOwn(STAGE_FIELD_MAP, stageCode)) {
            return {
                status: "unsupported",
                stage_code: stageCode,
                field: null
            };
        }
        const field = STAGE_FIELD_MAP[stageCode];
        if (stageCode === "011") {
            return {
                status: "random",
                stage_code: stageCode,
                field: null
            };
        }
        if (
            !isObject(fieldDecks) ||
            !isObject(fieldDecks[field])
        ) {
            return {
                status: "field_deck_missing",
                stage_code: stageCode,
                field
            };
        }
        return {
            status: "ready",
            stage_code: stageCode,
            field
        };
    }

    function resolveProjectionStage(projection, fieldDecks) {
        const resolved = resolveStage(
            projection?.stage_code,
            fieldDecks
        );
        return {
            ...resolved,
            source: (
                projection?.stage_source ===
                    "db_quest_land_now"
                    ? projection.stage_source
                    : null
            ),
            source_sequence: (
                Number.isInteger(
                    projection?.stage_source_sequence
                )
                    ? projection.stage_source_sequence
                    : null
            )
        };
    }

    global.TrackerStageSync = Object.freeze({
        STAGE_FIELD_MAP,
        resolveStage,
        resolveProjectionStage
    });
})(
    typeof window !== "undefined"
        ? window
        : globalThis
);
