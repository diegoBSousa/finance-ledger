import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import App from '../src/App.vue'

enableAutoUnmount(afterEach)
afterEach(() => vi.unstubAllGlobals())

function healthyResponse() {
  return new Response(JSON.stringify({ status: 'ok', service: 'finance-ledger-api' }), {
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('API connection screen', () => {
  it('shows the pending state and confirms a valid API response', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(healthyResponse()))
    const wrapper = mount(App)
    expect(wrapper.get('[role="status"]').text()).toBe('Verificando conexão…')
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toBe('Conexão estabelecida.')
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
  })

  it('lets the user retry after an HTTP failure', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response('', { status: 503 }))
      .mockResolvedValueOnce(healthyResponse())
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mount(App)
    await flushPromises()
    expect(wrapper.get('button').text()).toBe('Tentar novamente')
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toBe('Conexão estabelecida.')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('reports a network failure without a false success', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    const wrapper = mount(App)
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('Não foi possível conectar')
  })

  it('rejects a successful HTTP response from an unexpected service', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ status: 'ok' }))))
    const wrapper = mount(App)
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('Não foi possível conectar')
  })
})
