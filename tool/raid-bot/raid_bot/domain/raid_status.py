from enum import Enum


class RaidStatus(str, Enum):
    ACTIVE = "active"
    DEFEATED = "defeated"
    EXPIRED = "expired"