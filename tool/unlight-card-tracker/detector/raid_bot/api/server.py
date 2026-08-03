from __future__ import annotations

from fastapi import FastAPI

from .routes import router


def create_app(
    raid_service,
) -> FastAPI:

    app = FastAPI(
        title="UL.GG Raid Bot",
        version="0.1.0",
    )


    app.state.raid_service = (
        raid_service
    )


    app.include_router(
        router
    )


    return app