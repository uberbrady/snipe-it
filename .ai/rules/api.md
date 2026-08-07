---
paths:
  - 'app/Http/Controllers/Api/**'
---

# Api

## Wrap API responses in the standard envelope
Every API response goes through the shared envelope:

`return response()->json(Helper::formatStandardApiResponse('success', $payload, trans('...')));`

Use `'error'` with a `null` payload for failures, and a translation key for the message. There are no Eloquent API Resources in this project.

## Page API lists with offset and limit
API list endpoints page with `offset`/`limit` request params, resolved through the container as `app('api_offset_value')` and `app('api_limit_value')`, then applied with `->skip($offset)->take($limit)->get()`.

Do not use `paginate()`, `simplePaginate()`, or `cursorPaginate()` on API endpoints.
