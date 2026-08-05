from __future__ import annotations

from contextlib import asynccontextmanager
from typing import Awaitable, Callable

from fastapi import FastAPI

from .routes import router


LifecycleCallback = Callable[[], Awaitable[None]]


def create_app(
    raid_service,
    *,
    on_startup: LifecycleCallback | None = None,
    on_shutdown: LifecycleCallback | None = None,
) -> FastAPI:
    @asynccontextmanager
    async def lifespan(app: FastAPI):
        if on_startup is not None:
            await on_startup()
        try:
            yield
        finally:
            if on_shutdown is not None:
                await on_shutdown()

    app = FastAPI(
        title="UL.GG Raid Bot",
        version="0.1.0",
        lifespan=lifespan,
    )
    app.state.raid_service = raid_service
    app.include_router(router)
    return app
