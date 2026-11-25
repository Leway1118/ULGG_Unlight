"""Offline unittest guard: block non-loopback Python socket traffic."""
from __future__ import annotations

import ipaddress
import socket


_connect = socket.socket.connect
_connect_ex = socket.socket.connect_ex
_sendto = socket.socket.sendto


def _is_loopback(address: object) -> bool:
    # Unix sockets / named pipes are local.
    if not isinstance(address, tuple):
        return True
    if not address:
        return False
    host = address[0]
    if host in {"localhost", "127.0.0.1", "::1"}:
        return True
    try:
        return ipaddress.ip_address(host).is_loopback
    except (ValueError, TypeError):
        return False


def guarded_connect(self, address):
    if not _is_loopback(address):
        raise OSError("OFFLINE_TEST_EXTERNAL_CONNECTION_BLOCKED")
    return _connect(self, address)


def guarded_connect_ex(self, address):
    if not _is_loopback(address):
        raise OSError("OFFLINE_TEST_EXTERNAL_CONNECTION_BLOCKED")
    return _connect_ex(self, address)


def guarded_sendto(self, data, *args):
    if args and not _is_loopback(args[-1]):
        raise OSError("OFFLINE_TEST_EXTERNAL_DATAGRAM_BLOCKED")
    return _sendto(self, data, *args)


socket.socket.connect = guarded_connect
socket.socket.connect_ex = guarded_connect_ex
socket.socket.sendto = guarded_sendto
