# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {SANCTUM_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Получите токен через `POST /api/v1/auth/login`. Передавайте в заголовке: `Authorization: Bearer {token}`.
