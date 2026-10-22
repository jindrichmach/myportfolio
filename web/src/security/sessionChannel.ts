export type SessionChannelEvent = 'locked' | 'unlocked' | 'logout'

const CHANNEL_NAME = 'myportfolio-session-state'
let channel: BroadcastChannel | null | undefined

function getChannel(): BroadcastChannel | null {
  if (channel !== undefined) return channel
  channel = typeof BroadcastChannel === 'undefined'
    ? null
    : new BroadcastChannel(CHANNEL_NAME)
  return channel
}

export function broadcastSessionEvent(type: SessionChannelEvent): void {
  getChannel()?.postMessage({ type })
}

export function subscribeSessionEvents(
  listener: (type: SessionChannelEvent) => void,
): () => void {
  const current = getChannel()
  if (!current) return () => undefined

  const receive = (event: MessageEvent<unknown>) => {
    const type = (event.data as { type?: unknown } | null)?.type
    if (type === 'locked' || type === 'unlocked' || type === 'logout') {
      listener(type)
    }
  }
  current.addEventListener('message', receive)
  return () => current.removeEventListener('message', receive)
}
