# API Documentation

The FastAPI service automatically exposes OpenAPI/Swagger documentation.

- Swagger UI: `GET /docs`
- OpenAPI schema: `GET /openapi.json`

To run the API locally and view the docs:

```bash
uvicorn python_api.main:app --reload
# visit http://localhost:8000/docs
```

The OpenAPI description lists all routes such as `/orders`, `/psbt/*`, `/tx/*` and
includes request/response models for integration.
