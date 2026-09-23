const baseUrl = (import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1').replace(/\/$/, '')

export async function checkApiHealth(): Promise<void> {
  const response = await fetch(`${baseUrl}/health`, {
    headers: { Accept: 'application/json' },
    signal: AbortSignal.timeout(5000),
  })

  if (!response.ok) throw new Error('API health request failed')

  const body: unknown = await response.json()
  if (
    body === null || typeof body !== 'object' ||
    !('status' in body) || body.status !== 'ok' ||
    !('service' in body) || body.service !== 'finance-ledger-api'
  ) {
    throw new Error('Unexpected API health response')
  }
}
