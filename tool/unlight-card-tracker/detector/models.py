from __future__ import annotations

from dataclasses import dataclass, field
from typing import Dict, List, Optional


@dataclass
class PlayerState:
    public_hand_known: List[str] = field(default_factory=list)
    public_hand_unknown_count: int = 0
    personal_card_count: int = 1
    dead_characters: int = 0
    selected_cards: List[str] = field(default_factory=list)

    def public_hand_target(self, base: int = 6) -> int:
        return base + self.dead_characters


@dataclass
class TrackerState:
    field_name: str = ""
    initial: Dict[str, int] = field(default_factory=dict)
    deck: Dict[str, int] = field(default_factory=dict)
    discard: Dict[str, int] = field(default_factory=dict)
    deck_left: int = 0
    reshuffle_count: int = 0
    top: PlayerState = field(default_factory=PlayerState)
    bottom: PlayerState = field(default_factory=PlayerState)
    pending_events: List[dict] = field(default_factory=list)
    history: List[dict] = field(default_factory=list)

    def register_unknown_draw(self, player: str, amount: int) -> None:
        if amount <= 0:
            return
        target = self.top if player == "top" else self.bottom
        target.public_hand_unknown_count += amount
        self.deck_left = max(0, self.deck_left - amount)
        self.history.append({
            "type": "unknown_draw",
            "player": player,
            "amount": amount
        })

    def reveal_unknown_card(self, player: str, card_name: str) -> None:
        target = self.top if player == "top" else self.bottom
        if target.public_hand_unknown_count > 0:
            target.public_hand_unknown_count -= 1
        target.public_hand_known.append(card_name)
        if card_name in self.deck:
            self.deck[card_name] = max(0, self.deck[card_name] - 1)
        self.history.append({
            "type": "reveal",
            "player": player,
            "card": card_name
        })

    def commit_selected_cards(self, player: str) -> None:
        target = self.top if player == "top" else self.bottom
        for card_name in target.selected_cards:
            self.discard[card_name] = self.discard.get(card_name, 0) + 1
            if card_name in target.public_hand_known:
                target.public_hand_known.remove(card_name)
        target.selected_cards.clear()

    def reshuffle(self) -> None:
        self.deck = dict(self.discard)
        self.deck_left = sum(self.deck.values())
        self.discard.clear()
        self.reshuffle_count += 1
        self.history.append({
            "type": "reshuffle",
            "deck_left": self.deck_left
        })
