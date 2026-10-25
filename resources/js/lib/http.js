/*
 | JSON requests outside Inertia's page visits — the edit preview and the
 | pending-users list. Laravel's XSRF-TOKEN cookie carries the CSRF token.
 */
function xsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

export async function requestJson(method, url, body) {
  const response = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': xsrfToken(),
    },
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  const data = await response.json().catch(() => null)

  return { ok: response.ok, status: response.status, data }
}

/** A file upload outside Inertia's visits: the Excel import's first step. */
export async function uploadFile(url, field, file) {
  const body = new FormData()
  body.append(field, file)

  const response = await fetch(url, {
    method: 'POST',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    credentials: 'same-origin',
    body,
  })

  const data = await response.json().catch(() => null)

  return { ok: response.ok, status: response.status, data }
}
