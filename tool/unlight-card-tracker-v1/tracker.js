if (typeof FIELD_DECKS === "undefined") {
    throw new Error(
        "FIELD_DECKS 尚未載入，請確認 field-decks.js 位於 tracker.js 之前。"
    );
}

const STORAGE_KEY = "ul_public_deck_tracker_v2";

const $ = id => document.getElementById(id);

const elements = {
    field: $("field"),
    deckLeftInput: $("deckLeftInput"),
    search: $("search"),
    undo: $("undo"),
    reset: $("reset"),
    clear: $("clear"),
    cards: $("cards"),
    types: $("types"),
    history: $("history"),
    initial: $("initial"),
    deckLeft: $("deckLeft"),
    seen: $("seen"),
    unknown: $("unknown"),
    progress: $("progress")
};

function getTotal(deck) {
    return Object.values(deck).reduce(
        (sum, count) => sum + Number(count || 0),
        0
    );
}

function createFreshState(fieldName) {
    const initialDeck = structuredClone(FIELD_DECKS[fieldName]);

    return {
        field: fieldName,
        initial: initialDeck,
        remaining: structuredClone(initialDeck),
        deckLeft: getTotal(initialDeck),
        history: []
    };
}

function loadState() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return null;
        }

        const saved = JSON.parse(raw);

        if (!saved || !FIELD_DECKS[saved.field]) {
            return null;
        }

        return saved;
    } catch (error) {
        console.warn("讀取牌庫追蹤狀態失敗：", error);
        return null;
    }
}

function saveState() {
    localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify(state)
    );
}

let state =
    loadState() ||
    createFreshState(Object.keys(FIELD_DECKS)[0]);

function resetField(fieldName, needConfirm = true) {
    if (
        needConfirm &&
        !confirm("確定清除目前進度並載入此場地？")
    ) {
        elements.field.value = state.field;
        return;
    }

    state = createFreshState(fieldName);

    saveState();
    render();
}

function changeCard(cardName, delta) {
    const current = state.remaining[cardName];
    const maximum = state.initial[cardName];

    if (delta < 0 && current <= 0) {
        return;
    }

    if (delta > 0 && current >= maximum) {
        return;
    }

    state.remaining[cardName] = Math.max(
        0,
        Math.min(maximum, current + delta)
    );

    state.history.unshift({
        card: cardName,
        delta,
        time: new Date().toLocaleTimeString(
            "zh-TW",
            { hour12: false }
        )
    });

    state.history = state.history.slice(0, 100);

    if (delta < 0 && state.deckLeft > 0) {
        state.deckLeft -= 1;
    }

    if (
        delta > 0 &&
        state.deckLeft < getTotal(state.initial)
    ) {
        state.deckLeft += 1;
    }

    saveState();
    render();
}

function undoLastAction() {
    const lastAction = state.history.shift();

    if (!lastAction) {
        return;
    }

    const cardName = lastAction.card;
    const maximum = state.initial[cardName];

    state.remaining[cardName] = Math.max(
        0,
        Math.min(
            maximum,
            state.remaining[cardName] - lastAction.delta
        )
    );

    if (
        lastAction.delta < 0 &&
        state.deckLeft < getTotal(state.initial)
    ) {
        state.deckLeft += 1;
    }

    if (
        lastAction.delta > 0 &&
        state.deckLeft > 0
    ) {
        state.deckLeft -= 1;
    }

    saveState();
    render();
}

function renderTypeStats() {
    const typeCounts = {
        劍: 0,
        槍: 0,
        防: 0,
        移: 0,
        特: 0
    };

    for (
        const [cardName, count]
        of Object.entries(state.remaining)
    ) {
        const matches = cardName.matchAll(
            /(劍|槍|防|移|特)\d+/g
        );

        for (const match of matches) {
            typeCounts[match[1]] += count;
        }
    }

    const maximum = Math.max(
        1,
        ...Object.values(typeCounts)
    );

    elements.types.innerHTML =
        Object.entries(typeCounts)
            .map(([type, count]) => {
                const width = count / maximum * 100;

                return `
          <div class="type">
            <b>${type}</b>

            <div class="bar">
              <i style="width:${width}%"></i>
            </div>

            <span>${count}</span>
          </div>
        `;
            })
            .join("");
}

function renderHistory() {
    if (!state.history.length) {
        elements.history.innerHTML =
            '<div class="empty">尚無操作紀錄</div>';

        return;
    }

    elements.history.innerHTML =
        state.history
            .slice(0, 20)
            .map(item => {
                const actionName =
                    item.delta < 0 ? "扣除" : "加回";

                const amount =
                    item.delta < 0 ? "−1" : "+1";

                return `
          <div class="hist">
            <div>
              <b>${actionName}：${item.card}</b>
              <br>
              <small>${item.time}</small>
            </div>

            <span>${amount}</span>
          </div>
        `;
            })
            .join("");
}
const CARD_TYPE_CONFIG = {
    劍: {
        image: "./assets/cards/acswd.png",
        label: "ATTACK"
    },
    槍: {
        image: "./assets/cards/acbow.png",
        label: "ATTACK"
    },
    防: {
        image: "./assets/cards/acshi.png",
        label: "DEFENSE"
    },
    移: {
        image: "./assets/cards/acmov.png",
        label: "MOVE"
    },
    特: {
        image: "./assets/cards/acspe.png",
        label: "SPECIAL"
    }
};

function parseCardName(cardName) {
    const matches = [
        ...cardName.matchAll(/(劍|槍|防|移|特)(\d+)/g)
    ];

    return matches.map(match => ({
        type: match[1],
        value: Number(match[2])
    }));
}

function createCardFaceHtml(cardName) {
    const sides = parseCardName(cardName);

    if (sides.length < 2) {
        return `
            <div class="game-card-fallback">
                ${cardName}
            </div>
        `;
    }

    const [top, bottom] = sides;

    const topConfig = CARD_TYPE_CONFIG[top.type];
    const bottomConfig = CARD_TYPE_CONFIG[bottom.type];

    if (!topConfig || !bottomConfig) {
        return `
            <div class="game-card-fallback">
                ${cardName}
            </div>
        `;
    }

    return `
        <div class="game-card">
            <div class="game-card-half game-card-top">
                <img
                    src="${topConfig.image}"
                    alt="${top.type}${top.value}"
                    draggable="false"
                >

                <span class="game-card-value">
                    ${top.value}
                </span>
            </div>

            <div class="game-card-half game-card-bottom">
                <img
                    src="${bottomConfig.image}"
                    alt="${bottom.type}${bottom.value}"
                    draggable="false"
                >

                <span class="game-card-value">
                    ${bottom.value}
                </span>
            </div>

            <div class="game-card-divider"></div>
        </div>
    `;
}

function renderCardButtons() {
    const searchText =
        elements.search.value
            .trim()
            .toLowerCase();

    const unknownTotal =
        getTotal(state.remaining);

    const cards =
        Object.keys(state.initial)
            .filter(cardName => {
                return (
                    !searchText ||
                    cardName
                        .toLowerCase()
                        .includes(searchText)
                );
            })
            .sort((a, b) => {
                const aEmpty =
                    state.remaining[a] === 0;

                const bEmpty =
                    state.remaining[b] === 0;

                if (aEmpty !== bEmpty) {
                    return aEmpty ? 1 : -1;
                }

                return a.localeCompare(
                    b,
                    "zh-Hant"
                );
            });

    elements.cards.innerHTML =
        cards
            .map(cardName => {
                const current =
                    state.remaining[cardName];

                const maximum =
                    state.initial[cardName];

                let className = "";

                if (current === 0) {
                    className = "zero";
                } else if (
                    current / maximum <= 0.34
                ) {
                    className = "low";
                }

                const probability =
                    unknownTotal > 0
                        ? current / unknownTotal * 100
                        : 0;

                return `
                    <button
                        class="card game-card-button ${className}"
                        data-card="${cardName}"
                        title="${cardName}"
                    >
                        <div class="game-card-preview">
                        ${createCardFaceHtml(cardName)}
                        </div>

                        <div class="game-card-info">
                        <div class="n">
                            ${cardName}
                        </div>

                        <div class="c">
                            ${current} / ${maximum}
                        </div>

                        <div class="m">
                            未公開占比
                            ${probability.toFixed(1)}%
                        </div>
                        </div>
                    </button>
                    `;
            })
            .join("");

    elements.cards
        .querySelectorAll(".card")
        .forEach(button => {
            let longPressTimer = null;
            let longPressed = false;

            button.addEventListener("pointerdown", event => {
                longPressed = false;

                longPressTimer = window.setTimeout(() => {
                    longPressed = true;
                    changeCard(button.dataset.card, 1);

                    if (navigator.vibrate) {
                        navigator.vibrate(40);
                    }
                }, 600);
            });

            button.addEventListener("pointerup", event => {
                if (longPressTimer) {
                    clearTimeout(longPressTimer);
                    longPressTimer = null;
                }

                if (longPressed) {
                    event.preventDefault();
                    return;
                }

                changeCard(
                    button.dataset.card,
                    event.shiftKey ? 1 : -1
                );
            });

            button.addEventListener("pointerleave", () => {
                if (longPressTimer) {
                    clearTimeout(longPressTimer);
                    longPressTimer = null;
                }
            });

            button.addEventListener("pointercancel", () => {
                if (longPressTimer) {
                    clearTimeout(longPressTimer);
                    longPressTimer = null;
                }
            });

            button.addEventListener("contextmenu", event => {
                event.preventDefault();
                changeCard(button.dataset.card, 1);
            });
        });
}

function render() {
    elements.field.innerHTML = Object.values(FIELD_GROUPS)
        .map(group => {
            const options = group.fields
                .map(fieldName => {
                    const hasDeck = Boolean(FIELD_DECKS[fieldName]);

                    return `
          <option
            value="${fieldName}"
            ${hasDeck ? "" : "disabled"}
          >
            ${fieldName}${hasDeck ? "" : "（尚無牌組資料）"}
          </option>
        `;
                })
                .join("");

            return `
      <optgroup label="【${group.label}】">
        ${options}
      </optgroup>
    `;
        })
        .join("");

    elements.field.value =
        state.field;

    const initialTotal =
        getTotal(state.initial);

    const unknownTotal =
        getTotal(state.remaining);

    const seenTotal =
        initialTotal - unknownTotal;

    const progress =
        initialTotal > 0
            ? Math.round(
                (
                    initialTotal -
                    state.deckLeft
                ) /
                initialTotal *
                100
            )
            : 0;

    elements.initial.textContent =
        initialTotal;

    elements.deckLeft.textContent =
        state.deckLeft;

    elements.deckLeftInput.value =
        state.deckLeft;

    elements.seen.textContent =
        seenTotal;

    elements.unknown.textContent =
        unknownTotal;

    elements.progress.textContent =
        `${Math.max(
            0,
            Math.min(100, progress)
        )}%`;

    renderCardButtons();
    renderTypeStats();
    renderHistory();

    elements.undo.disabled =
        state.history.length === 0;
}

elements.field.addEventListener(
    "change",
    () => {
        resetField(
            elements.field.value
        );
    }
);

elements.search.addEventListener(
    "input",
    render
);

elements.undo.addEventListener(
    "click",
    undoLastAction
);

elements.reset.addEventListener(
    "click",
    () => {
        resetField(state.field);
    }
);

elements.clear.addEventListener(
    "click",
    () => {
        state.history = [];

        saveState();
        render();
    }
);

elements.deckLeftInput.addEventListener(
    "change",
    () => {
        const value = Number.parseInt(
            elements.deckLeftInput.value,
            10
        );

        const maximum =
            getTotal(state.initial);

        if (Number.isFinite(value)) {
            state.deckLeft = Math.max(
                0,
                Math.min(maximum, value)
            );
        }

        saveState();
        render();
    }
);

render();